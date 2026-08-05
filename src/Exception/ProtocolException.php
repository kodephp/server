<?php

declare(strict_types=1);

namespace Kode\Server\Exception;

/**
 * HTTP 报文非法或超出限额。
 *
 * 该异常携带应当回给客户端的状态码，由连接层转成响应后关闭连接，
 * 不会中断 Worker 进程。
 */
final class ProtocolException extends ServerException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly bool $closeConnection = true,
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $message): self
    {
        return new self($message, 400);
    }

    public static function uriTooLong(int $limit): self
    {
        return new self(sprintf('请求 URI 超过 %d 字节限制', $limit), 414);
    }

    public static function headerTooLarge(int $limit): self
    {
        return new self(sprintf('请求头超过 %d 字节限制', $limit), 431);
    }

    public static function payloadTooLarge(int $limit): self
    {
        return new self(sprintf('请求体超过 %d 字节限制', $limit), 413);
    }

    public static function versionNotSupported(string $version): self
    {
        return new self(sprintf('不支持的 HTTP 版本 "%s"', $version), 505);
    }
}
