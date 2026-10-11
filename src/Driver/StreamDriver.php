<?php

declare(strict_types=1);

namespace Kode\Server\Driver;

use Closure;
use Kode\Server\Process\Master;
use Kode\Server\Process\Worker;
use Kode\Server\Support\Sys;

/**
 * 纯 PHP 的 prefork 驱动——本包的默认与主力实现。
 *
 * 模型：Master fork 出 N 个 Worker，所有 Worker 争抢同一个监听套接字
 *（或各自 SO_REUSEPORT 独立监听），每个 Worker 内部跑 stream_select 事件循环。
 *
 * 为什么不是"每连接一进程"：fork 的代价远高于一次 accept，
 * prefork + 单进程内多路复用才能在不依赖任何扩展的前提下跑出可用的吞吐。
 *
 * 唯一硬依赖是 ext-pcntl；没有它请用 {@see SingleDriver}。
 */
final class StreamDriver extends AbstractDriver
{
    private ?Master $master = null;

    #[\Override]
    public function name(): string
    {
        return 'stream';
    }

    #[\Override]
    public static function isSupported(): bool
    {
        return Sys::hasPcntl() && !Sys::isWindows();
    }

    #[\Override]
    public function run(): void
    {
        $this->master = new Master(
            config: $this->config,
            emitter: $this->emitter,
            logger: $this->roleLogger('master'),
            workerRunner: $this->runWorker(...),
            watcher: $this->watcher,
            driverName: $this->name(),
        );

        try {
            $this->master->run();
        } finally {
            $this->master = null;
        }
    }

    #[\Override]
    public function shutdown(bool $graceful = true): void
    {
        $this->master?->stop($graceful);
    }

    #[\Override]
    public function reloadWorkers(): void
    {
        $this->master?->reload();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function stats(): array
    {
        return ['driver' => $this->name()] + ($this->master?->stats() ?? []);
    }

    /**
     * 在 fork 出来的子进程里执行；返回值即进程退出码。
     *
     * @param Closure(): resource $listenFactory
     */
    private function runWorker(int $slot, Closure $listenFactory): int
    {
        return (new Worker(
            config: $this->config,
            id: $slot,
            dispatcher: $this->dispatcher,
            emitter: $this->emitter,
            logger: $this->roleLogger('worker#' . $slot),
            listenFactory: $listenFactory,
        ))->run();
    }
}
