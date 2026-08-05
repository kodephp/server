<?php

declare(strict_types=1);

namespace Kode\Server\Driver;

use Kode\Server\Config\Limits;
use Kode\Server\Http\Factories;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Swoole 报文 ⇄ PSR-7 的双向桥。
 *
 * 独立成类有两个原因：Swoole 的类在没装扩展时不存在（这里全部用 object + 动态调用规避），
 * 以及这层转换逻辑本身值得单独测试。
 */
final class SwooleBridge
{
    /**
     * Swoole\Http\Request → PSR-7 ServerRequest。
     *
     * @param object               $request Swoole\Http\Request
     * @param array<string, mixed> $extra   附加的 server 参数
     */
    public static function toPsr(object $request, Factories $factories, array $extra = []): ServerRequestInterface
    {
        /** @var array<string, mixed> $swooleServer */
        $swooleServer = (array) ($request->server ?? []);
        /** @var array<string, string> $headers */
        $headers = (array) ($request->header ?? []);

        $method = strtoupper((string) ($swooleServer['request_method'] ?? 'GET'));
        $target = (string) ($swooleServer['request_uri'] ?? '/');
        $query = (string) ($swooleServer['query_string'] ?? '');
        $protocol = (string) ($swooleServer['server_protocol'] ?? 'HTTP/1.1');

        $scheme = ($swooleServer['https'] ?? null) === 'on' ? 'https' : 'http';
        $authority = $headers['host'] ?? sprintf(
            '%s:%s',
            (string) ($swooleServer['server_addr'] ?? '127.0.0.1'),
            (string) ($swooleServer['server_port'] ?? '80'),
        );

        $uri = $factories->uri->createUri($scheme . '://' . $authority . $target . ($query !== '' ? '?' . $query : ''));

        $serverParams = self::serverParams($swooleServer, $headers) + $extra;

        $psr = $factories->serverRequest->createServerRequest($method, $uri, $serverParams);

        foreach ($headers as $name => $value) {
            $psr = $psr->withHeader((string) $name, $value);
        }

        $body = (string) ($request->rawContent() ?: '');
        if ($body !== '') {
            $psr = $psr->withBody($factories->stream->createStream($body));
        }

        /** @var array<string, mixed> $get */
        $get = (array) ($request->get ?? []);
        /** @var array<string, mixed> $post */
        $post = (array) ($request->post ?? []);
        /** @var array<string, mixed> $cookie */
        $cookie = (array) ($request->cookie ?? []);
        /** @var array<string, mixed> $files */
        $files = (array) ($request->files ?? []);

        $psr = $psr
            ->withProtocolVersion(str_starts_with($protocol, 'HTTP/') ? substr($protocol, 5) : '1.1')
            ->withQueryParams($get)
            ->withCookieParams($cookie);

        if ($post !== []) {
            $psr = $psr->withParsedBody($post);
        }
        if ($files !== []) {
            $psr = $psr->withUploadedFiles(self::normalizeFiles($files, $factories));
        }

        return $psr;
    }

    /**
     * PSR-7 Response → Swoole\Http\Response。
     *
     * 大响应体分块写出：一次性 end() 一个几百 MB 的字符串会让 Worker 内存直接翻倍。
     *
     * @param object $response Swoole\Http\Response
     */
    public static function send(ResponseInterface $psr, object $response, Limits $limits): void
    {
        /** @var callable $status */
        $status = [$response, 'status'];
        $reason = $psr->getReasonPhrase();
        $reason === '' ? $status($psr->getStatusCode()) : $status($psr->getStatusCode(), $reason);

        /** @var callable $header */
        $header = [$response, 'header'];
        foreach ($psr->getHeaders() as $name => $values) {
            // Set-Cookie 等多值头需要逐条下发，合并成一行会被客户端当成单个值
            if (\count($values) === 1) {
                $header((string) $name, $values[0]);

                continue;
            }
            foreach ($values as $i => $value) {
                $header((string) $name, $value, true, $i === 0);
            }
        }

        if ($limits->sendServerHeader && !$psr->hasHeader('Server')) {
            $header('Server', $limits->serverSignature);
        }

        $body = $psr->getBody();
        /** @var callable $end */
        $end = [$response, 'end'];

        $size = $body->getSize();
        if ($size !== null && $size === 0) {
            $end();

            return;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        // 小响应直接 end()，省一次系统调用
        if ($size !== null && $size <= $limits->bodyMemoryLimit) {
            $end($body->getContents());

            return;
        }

        /** @var callable $write */
        $write = [$response, 'write'];
        while (!$body->eof()) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $write($chunk);
        }
        $end();
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>  $swooleServer
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private static function serverParams(array $swooleServer, array $headers): array
    {
        $params = [];

        foreach ($swooleServer as $key => $value) {
            $params[strtoupper((string) $key)] = $value;
        }

        // 补齐 CGI 风格键名，让依赖 $_SERVER 习惯的代码不至于抓瞎
        $params['REQUEST_METHOD'] ??= strtoupper((string) ($swooleServer['request_method'] ?? 'GET'));
        $params['REQUEST_URI'] ??= (string) ($swooleServer['request_uri'] ?? '/');
        $params['QUERY_STRING'] ??= (string) ($swooleServer['query_string'] ?? '');
        $params['SERVER_PROTOCOL'] ??= (string) ($swooleServer['server_protocol'] ?? 'HTTP/1.1');

        foreach ($headers as $name => $value) {
            $params['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = $value;
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $files
     *
     * @return array<string, UploadedFileInterface|array<string, mixed>>
     */
    private static function normalizeFiles(array $files, Factories $factories): array
    {
        $result = [];

        foreach ($files as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }

            if (isset($value['tmp_name']) && !\is_array($value['tmp_name'])) {
                $result[(string) $key] = self::createUploadedFile($value, $factories);

                continue;
            }

            // 嵌套结构（file[a][b]）递归处理
            /** @var array<string, mixed> $value */
            $result[(string) $key] = self::normalizeFiles($value, $factories);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $file
     */
    private static function createUploadedFile(array $file, Factories $factories): UploadedFileInterface
    {
        $error = (int) ($file['error'] ?? \UPLOAD_ERR_OK);
        $tmpName = (string) ($file['tmp_name'] ?? '');

        $stream = $error === \UPLOAD_ERR_OK && $tmpName !== '' && is_file($tmpName)
            ? $factories->stream->createStreamFromFile($tmpName, 'rb')
            : $factories->stream->createStream('');

        return $factories->uploadedFile->createUploadedFile(
            $stream,
            isset($file['size']) ? (int) $file['size'] : null,
            $error,
            isset($file['name']) ? (string) $file['name'] : null,
            isset($file['type']) ? (string) $file['type'] : null,
        );
    }
}
