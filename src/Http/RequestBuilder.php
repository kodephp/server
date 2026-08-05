<?php

declare(strict_types=1);

namespace Kode\Server\Http;

use Kode\Server\Config\Limits;
use Kode\Server\Exception\ProtocolException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * 把解析结果装配成 PSR-7 ServerRequest。
 *
 * 这一层刻意只做"HTTP 语义内"的事：URI、头、Cookie、查询串、
 * 表单与上传文件。JSON 之类的反序列化留给业务中间件，
 * 服务器不应该替应用决定 body 怎么解读。
 */
final class RequestBuilder
{
    public function __construct(
        private readonly Limits $limits,
        private readonly Factories $factories,
    ) {
    }

    /**
     * @param array<string, mixed> $serverParams 连接层提供的 REMOTE_ADDR 等信息
     */
    public function build(ParsedRequest $raw, array $serverParams = []): ServerRequestInterface
    {
        $https = ($serverParams['HTTPS'] ?? '') === 'on';
        $uri = $this->buildUri($raw, $https, $serverParams);

        $serverParams = array_merge($this->baseServerParams($raw, $uri), $serverParams);

        $request = $this->factories->serverRequest
            ->createServerRequest($raw->method, $uri, $serverParams)
            ->withProtocolVersion($raw->protocolVersion);

        foreach ($raw->headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        $request = $request->withBody($this->factories->stream->createStreamFromResource($raw->body));

        $query = [];
        if ($uri->getQuery() !== '') {
            parse_str($uri->getQuery(), $query);
        }
        $request = $request->withQueryParams($query);

        $cookies = $this->parseCookies($raw->header('Cookie'));
        if ($cookies !== []) {
            $request = $request->withCookieParams($cookies);
        }

        return $this->applyBody($request, $raw);
    }

    /**
     * @param array<string, mixed> $serverParams
     */
    private function buildUri(ParsedRequest $raw, bool $https, array $serverParams): UriInterface
    {
        $target = $raw->target;
        $scheme = $https ? 'https' : 'http';

        // absolute-form: GET http://host/path HTTP/1.1（代理场景）
        if (preg_match('#^https?://#i', $target)) {
            return $this->factories->uri->createUri($target);
        }

        // asterisk-form: OPTIONS * HTTP/1.1
        if ($target === '*') {
            $target = '/';
        }

        if ($target[0] !== '/') {
            throw ProtocolException::badRequest('请求目标必须以 / 开头');
        }

        $authority = $raw->header('Host') ?? (string) ($serverParams['SERVER_NAME'] ?? 'localhost');
        $authority = trim(explode(',', $authority)[0]);
        if ($authority !== '' && !preg_match('/^[\w.\-]+(:\d+)?$|^\[[0-9a-fA-F:]+\](:\d+)?$/', $authority)) {
            throw ProtocolException::badRequest('Host 头非法');
        }

        $path = $target;
        $query = '';
        $mark = strpos($target, '?');
        if ($mark !== false) {
            $path = substr($target, 0, $mark);
            $query = substr($target, $mark + 1);
        }

        $uri = $this->factories->uri->createUri('')
            ->withScheme($scheme)
            ->withPath($path);

        if ($query !== '') {
            $uri = $uri->withQuery($query);
        }

        if ($authority !== '') {
            $host = $authority;
            $port = null;
            if (preg_match('/^\[(.+)\](?::(\d+))?$/', $authority, $m)) {
                $host = '[' . $m[1] . ']';
                $port = isset($m[2]) ? (int) $m[2] : null;
            } elseif (substr_count($authority, ':') === 1) {
                [$host, $portPart] = explode(':', $authority, 2);
                $port = (int) $portPart;
            }
            $uri = $uri->withHost(trim($host, '[]'));
            if ($port !== null && $port > 0 && $port <= 65535) {
                $defaultPort = $https ? 443 : 80;
                $uri = $uri->withPort($port === $defaultPort ? null : $port);
            }
        }

        return $uri;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseServerParams(ParsedRequest $raw, UriInterface $uri): array
    {
        $now = microtime(true);
        $params = [
            'REQUEST_METHOD' => $raw->method,
            'REQUEST_URI' => $raw->target,
            'REQUEST_TIME' => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
            'SERVER_PROTOCOL' => 'HTTP/' . $raw->protocolVersion,
            'QUERY_STRING' => $uri->getQuery(),
            'SERVER_SOFTWARE' => $this->limits->serverSignature,
            'SERVER_NAME' => $uri->getHost(),
        ];

        // 兼容习惯读 $_SERVER['HTTP_*'] 的既有代码
        foreach ($raw->headers as $name => $values) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $params[$key] = implode(', ', $values);
        }
        if (isset($params['HTTP_CONTENT_TYPE'])) {
            $params['CONTENT_TYPE'] = $params['HTTP_CONTENT_TYPE'];
        }
        if (isset($params['HTTP_CONTENT_LENGTH'])) {
            $params['CONTENT_LENGTH'] = $params['HTTP_CONTENT_LENGTH'];
        }

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function parseCookies(?string $header): array
    {
        if ($header === null || $header === '') {
            return [];
        }

        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $cookies[$pair] = '';
                continue;
            }
            $name = trim(substr($pair, 0, $eq));
            if ($name === '') {
                continue;
            }
            $cookies[$name] = urldecode(trim(substr($pair, $eq + 1), " \t\""));
        }

        return $cookies;
    }

    private function applyBody(ServerRequestInterface $request, ParsedRequest $raw): ServerRequestInterface
    {
        if ($raw->bodyLength === 0) {
            return $request;
        }

        // boundary 大小写敏感，因此只把 mime 部分转小写
        $contentType = $raw->contentType() ?? '';
        $mime = strtolower(trim(explode(';', $contentType)[0]));

        if ($mime === 'application/x-www-form-urlencoded') {
            rewind($raw->body);
            $content = stream_get_contents($raw->body, $this->limits->bodyMemoryLimit + 1);
            rewind($raw->body);
            if ($content !== false && $content !== '') {
                if (\strlen($content) > $this->limits->bodyMemoryLimit) {
                    throw ProtocolException::payloadTooLarge($this->limits->bodyMemoryLimit);
                }
                $parsed = [];
                parse_str($content, $parsed);
                if (\count($parsed) > $this->limits->maxFormFields) {
                    throw ProtocolException::badRequest('表单字段数量超限');
                }
                $request = $request->withParsedBody($parsed);
            }

            return $request;
        }

        if ($mime === 'multipart/form-data' && $this->limits->parseMultipart) {
            $boundary = $this->extractBoundary($contentType);
            [$fields, $files] = (new MultipartParser($this->limits, $this->factories))->parse($raw->body, $boundary);
            rewind($raw->body);

            /** @var array<string, mixed> $fields */
            return $request->withParsedBody($fields === [] ? null : $fields)->withUploadedFiles($files);
        }

        return $request;
    }

    private function extractBoundary(string $contentType): string
    {
        if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentType, $m)) {
            return $m[1];
        }
        if (preg_match('/boundary\s*=\s*([^;\s]+)/i', $contentType, $m)) {
            return $m[1];
        }

        throw ProtocolException::badRequest('multipart 请求缺少 boundary');
    }
}
