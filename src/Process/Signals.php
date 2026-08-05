<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Process\Signal;
use Kode\Server\Exception\ProcessException;
use Kode\Server\Support\Sys;

/**
 * 信号安装的薄封装。
 *
 * 信号**语义**（取值与名称）统一取自 kode/process 的 Kode\Process\Signal，
 * 避免各包各自维护一份 OS 常量镜像；注册行为仍由本类直接调用
 * pcntl_signal 完成 —— 因为 kode/server 的 Worker 跑在 stream_select 循环里、
 * Master 自己手动 pcntl_signal_dispatch()，二者都不适合套用 kode/process
 * SignalDispatcher 的内部队列模型（那需要额外的 drain 循环才会真正派发）。
 *
 * 统一开启异步信号（PHP 7.1+），这样即使进程正阻塞在 stream_select 上
 * 也能立刻响应 SIGTERM，不必依赖 tick 或轮询 dispatch。
 */
final class Signals
{
    /**
     * @param array<int, callable(int): void> $handlers 信号 => 处理器
     */
    public static function install(array $handlers, ?\Psr\Log\LoggerInterface $logger = null): void
    {
        if (!Sys::hasPcntl()) {
            return;
        }

        pcntl_async_signals(true);

        foreach ($handlers as $signal => $handler) {
            if (!pcntl_signal($signal, $handler)) {
                throw new ProcessException(sprintf('安装信号处理器失败：%s', Signal::getName($signal)));
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
     * 常用终止信号集合，便于 Master / Worker 复用。
     *
     * @return list<int>
     */
    public static function terminationSignals(): array
    {
        return [Signal::TERM, Signal::INT, Signal::QUIT];
    }
}
