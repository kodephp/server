<?php

declare(strict_types=1);

namespace Kode\Server\Http;

/**
 * 解析完成的原始 HTTP 请求（尚未变成 PSR-7 对象）。
 *
 * 拆成独立 DTO 的意义：解析器可以被单独测试，
 * 也让"换一个 PSR-7 实现"这件事不影响协议解析。
 */
final class ParsedRequest
{
    /**
     * @param array<string, list<string>> $headers   保留原始大小写的头字段
     * @param resource                    $body      请求体流（php://temp，超过阈值自动落盘）
     * @param int                         $bodyLength 请求体字节数
     */
    public function __construct(
        public readonly string $method,
        public readonly string $target,
        public readonly string $protocolVersion,
        public readonly array $headers,
        public readonly mixed $body,
        public readonly int $bodyLength,
        public readonly bool $keepAlive,
    ) {
    }

    public function header(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $lower) {
                return implode(', ', $values);
            }
        }

        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    public function contentType(): ?string
    {
        return $this->header('Content-Type');
    }

    /**
     * 关闭请求体流，释放临时文件。
     */
    public function close(): void
    {
        if (\is_resource($this->body)) {
            fclose($this->body);
        }
    }
}
