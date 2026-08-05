<?php

declare(strict_types=1);

namespace Kode\Server\Exception;

use Kode\Server\Enum\State;

/**
 * 生命周期状态不允许当前操作，例如对已在运行的实例重复 start()。
 */
final class StateException extends ServerException
{
    public static function alreadyRunning(int $pid): self
    {
        return new self(sprintf('服务已在运行中（master pid=%d）。', $pid));
    }

    public static function notRunning(): self
    {
        return new self('服务未在运行（未找到有效的 PID 文件或进程已退出）。');
    }

    public static function illegalTransition(State $from, string $action): self
    {
        return new self(sprintf('当前状态 %s 下不允许执行 %s()。', $from->value, $action));
    }
}
