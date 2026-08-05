<?php

declare(strict_types=1);

namespace Kode\Server\Driver;

use Kode\Server\Enum\Event;
use Kode\Server\Process\Daemon;
use Kode\Server\Process\Listener;
use Kode\Server\Process\PidFile;
use Kode\Server\Process\Status;
use Kode\Server\Process\Worker;
use Kode\Server\Support\Sys;
use Throwable;

/**
 * 单进程驱动：不 fork，一个进程既监听又处理。
 *
 * 存在的意义有三个，都很实在：
 *  1. **没有 ext-pcntl 也能跑**（Windows、精简容器镜像、部分虚拟主机）；
 *  2. **调试友好**——断点不会因为 fork 而失效，`var_dump` 直接打在终端；
 *  3. **测试友好**——单测里起一个服务不需要处理子进程回收。
 *
 * 代价同样明确：吞吐受限于单核，业务代码里一个致命错误会带走整个服务，
 * 没有 Master 兜底重启。生产环境请用 {@see StreamDriver}。
 */
final class SingleDriver extends AbstractDriver
{
    private ?Worker $worker = null;

    private ?PidFile $pidFile = null;

    private ?Status $status = null;

    public function name(): string
    {
        return 'single';
    }

    public static function isSupported(): bool
    {
        return true; // 只用到 streams，任何环境都能跑
    }

    public function run(): void
    {
        $startedAt = microtime(true);

        $running = (new PidFile($this->config->pidFilePath()))->readAlive();
        if ($running !== null) {
            throw \Kode\Server\Exception\StateException::alreadyRunning($running);
        }

        $logger = $this->roleLogger('single');

        if ($this->watcher !== null) {
            $logger->notice('单进程模式不支持热重载（需要 fork 才能替换进程），watch 配置已忽略');
        }

        $this->emitter->emit(Event::BeforeStart, $this->config);

        if ($this->config->daemon) {
            Daemon::daemonize($this->config->logFile);
            $startedAt = microtime(true);
        }

        $this->pidFile = new PidFile($this->config->pidFilePath());
        $this->pidFile->acquire(Sys::pid());

        $this->status = new Status($this->config->statusFilePath());

        try {
            $this->worker = new Worker(
                config: $this->config,
                id: 0,
                dispatcher: $this->dispatcher,
                emitter: $this->emitter,
                logger: $logger,
                listenFactory: fn () => Listener::create($this->config),
            );

            $this->writeStatus($startedAt);
            $this->worker->loop()->addPeriodicTimer(2.0, fn () => $this->writeStatus($startedAt));

            $logger->info('{name} 以单进程模式启动，监听 {address}', [
                'name' => $this->config->name,
                'address' => $this->config->address(),
            ]);
            $this->emitter->emit(Event::Start, $this);

            $this->worker->run();
        } finally {
            $this->cleanup();
        }
    }

    public function shutdown(bool $graceful = true): void
    {
        $this->worker?->stop($graceful);
    }

    public function reloadWorkers(): void
    {
        // 单进程没有可替换的对象，重载语义上等价于"优雅退出，由外部拉起"
        $this->roleLogger('single')->notice('单进程模式下 reload 等价于停止服务，请由 supervisor 等外部工具负责重启');
        $this->shutdown(true);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return ['driver' => $this->name()] + ($this->worker?->stats() ?? []);
    }

    private function writeStatus(float $startedAt): void
    {
        $this->status?->write(Status::snapshot(
            $this->config->name,
            $this->config->address(),
            $this->name(),
            $startedAt,
            [$this->worker?->stats() ?? []],
        ));
    }

    private function cleanup(): void
    {
        $this->worker = null;

        try {
            $this->emitter->emit(Event::Shutdown, $this);
        } catch (Throwable $e) {
            $this->roleLogger('single')->error('Shutdown 回调出错', ['exception' => $e]);
        }

        $this->status?->remove();
        $this->pidFile?->release();
        $this->status = null;
        $this->pidFile = null;
    }
}
