<?php

declare(strict_types=1);

namespace Kode\Server\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * 内置 PSR-3 日志器：把 master / worker 的运行日志写到 stdout 或文件。
 *
 * 特意做得很薄——生产环境应当注入业务自己的 PSR-3 实现，
 * 这里只保证"没有日志库时服务依然能说话"。
 */
final class Logger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var resource|null */
    private $stream = null;

    private int $threshold;

    private string $role = 'server';

    public function __construct(
        private readonly ?string $file = null,
        string $level = LogLevel::INFO,
        private readonly bool $colors = true,
    ) {
        $this->threshold = self::LEVELS[strtolower($level)] ?? 1;
    }

    /**
     * 供 Master / Worker 标注自身身份，日志里能一眼看出是谁打的。
     */
    public function withRole(string $role): self
    {
        $clone = clone $this;
        $clone->role = $role;
        $clone->stream = null; // 子进程重新打开，避免共享文件指针

        return $clone;
    }

    /**
     * 日志轮转后重新打开文件（响应 SIGUSR2）。
     */
    public function reopen(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = \is_string($level) ? strtolower($level) : LogLevel::INFO;
        if ((self::LEVELS[$level] ?? 1) < $this->threshold) {
            return;
        }

        $line = sprintf(
            "[%s] %s.%-9s [pid:%d] %s%s\n",
            date('Y-m-d H:i:s'),
            $this->role,
            strtoupper($level),
            Sys::pid(),
            $this->interpolate((string) $message, $context),
            $this->formatContext($context),
        );

        $stream = $this->stream();
        if ($stream === null) {
            return;
        }

        if ($this->file === null && $this->colors && stream_isatty($stream)) {
            $line = $this->colorize($level, $line);
        }

        @fwrite($stream, $line);
    }

    /**
     * @return resource|null
     */
    private function stream()
    {
        if (\is_resource($this->stream)) {
            return $this->stream;
        }

        if ($this->file === null) {
            $handle = \defined('STDOUT') ? \STDOUT : @fopen('php://stdout', 'wb');
        } else {
            Sys::ensureDirFor($this->file);
            $handle = @fopen($this->file, 'ab');
        }

        return $this->stream = \is_resource($handle) ? $handle : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array &$context): string
    {
        if ($context === [] || !str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (!str_contains($message, $placeholder)) {
                continue;
            }
            if ($value === null || \is_scalar($value) || $value instanceof Stringable) {
                $replace[$placeholder] = (string) $value;
                unset($context[$key]);
            }
        }

        return strtr($message, $replace);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function formatContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $parts = [];
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $parts[] = sprintf('%s=%s: %s @ %s:%d', $key, $value::class, $value->getMessage(), $value->getFile(), $value->getLine());
                continue;
            }
            $parts[] = $key . '=' . (\is_scalar($value) || $value === null
                ? var_export($value, true)
                : json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        }

        return ' {' . implode(', ', $parts) . '}';
    }

    private function colorize(string $level, string $line): string
    {
        $color = match ($level) {
            LogLevel::DEBUG => "\033[90m",
            LogLevel::INFO => "\033[32m",
            LogLevel::NOTICE => "\033[36m",
            LogLevel::WARNING => "\033[33m",
            default => "\033[31m",
        };

        return $color . rtrim($line, "\n") . "\033[0m\n";
    }
}
