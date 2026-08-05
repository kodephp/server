<?php

declare(strict_types=1);

namespace Kode\Server\Http;

use Kode\Server\Config\Limits;
use Psr\Http\Message\ResponseInterface;

/**
 * 响应序列化：决定分帧方式并生成响应头。
 *
 * 分帧判定是 HTTP/1.1 最容易写错的地方，这里集中处理：
 *  - 204/304/1xx 与 HEAD 一律无 body；
 *  - body 长度已知 → Content-Length；
 *  - 长度未知且 HTTP/1.1 → chunked；
 *  - 长度未知且 HTTP/1.0 → 只能靠关闭连接来标记结束。
 */
final class Responder
{
    private const REASONS = [
        100 => 'Continue', 101 => 'Switching Protocols',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 206 => 'Partial Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        408 => 'Request Timeout', 409 => 'Conflict', 411 => 'Length Required', 413 => 'Content Too Large', 414 => 'URI Too Long',
        415 => 'Unsupported Media Type', 421 => 'Misdirected Request', 422 => 'Unprocessable Content', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported',
    ];

    public function __construct(private readonly Limits $limits)
    {
    }

    /**
     * @return array{head: string, chunked: bool, sendBody: bool, keepAlive: bool}
     */
    public function prepare(
        ResponseInterface $response,
        string $protocolVersion,
        bool $keepAlive,
        bool $headRequest,
    ): array {
        $status = $response->getStatusCode();
        $bodyless = $headRequest || $status === 204 || $status === 304 || ($status >= 100 && $status < 200);

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if (\in_array($lower, ['connection', 'transfer-encoding', 'content-length', 'keep-alive'], true)) {
                continue; // 分帧相关的头由服务器统一决定
            }
            $headers[$name] = $values;
        }

        $chunked = false;
        $size = null;

        if ($bodyless) {
            if ($headRequest) {
                $size = $response->getBody()->getSize();
                if ($size !== null) {
                    $headers['Content-Length'] = [(string) $size];
                }
            }
        } else {
            $size = $response->getBody()->getSize();
            if ($size !== null) {
                $headers['Content-Length'] = [(string) $size];
            } elseif ($protocolVersion === '1.1') {
                $chunked = true;
                $headers['Transfer-Encoding'] = ['chunked'];
            } else {
                $keepAlive = false; // HTTP/1.0 无法表达未知长度
            }
        }

        if ($this->limits->sendDateHeader && !isset($headers['Date']) && !$this->hasHeader($headers, 'date')) {
            $headers['Date'] = [gmdate('D, d M Y H:i:s') . ' GMT'];
        }
        if ($this->limits->sendServerHeader && !$this->hasHeader($headers, 'server')) {
            $headers['Server'] = [$this->limits->serverSignature];
        }
        $headers['Connection'] = [$keepAlive ? 'keep-alive' : 'close'];

        $reason = $response->getReasonPhrase();
        if ($reason === '') {
            $reason = self::REASONS[$status] ?? 'Unknown';
        }

        $head = sprintf("HTTP/%s %d %s\r\n", $protocolVersion, $status, $reason);
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                // 防响应头注入
                $value = str_replace(["\r", "\n"], '', (string) $value);
                $head .= $name . ': ' . $value . "\r\n";
            }
        }
        $head .= "\r\n";

        return [
            'head' => $head,
            'chunked' => $chunked,
            'sendBody' => !$bodyless,
            'keepAlive' => $keepAlive,
        ];
    }

    /**
     * 生成一个不依赖 PSR-7 实现的极简错误响应，用于解析阶段就失败的场景。
     */
    public static function rawError(int $status, string $message = '', string $protocolVersion = '1.1'): string
    {
        $reason = self::REASONS[$status] ?? 'Error';
        $body = sprintf(
            "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>%d %s</title></head>"
            . "<body><h1>%d %s</h1><p>%s</p></body></html>",
            $status,
            $reason,
            $status,
            $reason,
            htmlspecialchars($message, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
        );

        return sprintf("HTTP/%s %d %s\r\n", $protocolVersion, $status, $reason)
            . "Content-Type: text/html; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
    }

    public static function reason(int $status): string
    {
        return self::REASONS[$status] ?? 'Unknown';
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function hasHeader(array $headers, string $lookup): bool
    {
        foreach (array_keys($headers) as $name) {
            if (strtolower($name) === $lookup) {
                return true;
            }
        }

        return false;
    }
}
