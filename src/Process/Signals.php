<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Server\Enum\Signal;
use Kode\Server\Exception\ProcessException;
use Kode\Server\Support\Sys;

/**
 * 信号安装的薄封装。
 *
 * 统一开启异步信号（PHP 7.1+），这样即使进程正阻塞在 stream_select 上
 * 也能立刻响应 SIGTERM，不必依赖 tick 或轮询 dispatch。
 */
final class Signals
{
    /**
     * @param array<int, callable(int): void> $handlers 信号 => 处理器
     */
    public static function install(array $handlers): void
    {
        if (!Sys::hasPcntl()) {
            return;
        }

        pcntl_async_signals(true);

        foreach ($handlers as $signal => $handler) {
            if (!pcntl_signal($signal, $handler)) {
                throw new ProcessException(sprintf('安装信号处理器失败：%s', Signal::name($signal)));
            }
        }
    }

    public static function ignore(int ...$signals): void
    {
        if (!Sys::hasPcntl()) {
            return;
        }
        foreach ($signals as $signal) {
            pcntl_signal($signal, \SIG_IGN);
        }
    }

    public static function reset(int ...$signals): void
    {
        if (!Sys::hasPcntl()) {
            return;
        }
        foreach ($signals as $signal) {
            pcntl_signal($signal, \SIG_DFL);
        }
    }

    /**
     * 常用信号集合，便于 Master / Worker 复用。
     *
     * @return list<int>
     */
    public static function terminationSignals(): array
    {
        return [Signal::Term->value, Signal::Int->value, Signal::Quit->value];
    }
}
