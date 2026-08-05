<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Server\Config\ServerConfig;
use Kode\Server\Contract\WatcherInterface;
use Kode\Server\Enum\Event;
use Kode\Server\Enum\Signal;
use Kode\Server\Event\Emitter;
use Kode\Server\Exception\ProcessException;
use Kode\Server\Support\Sys;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Master 进程：只做四件事——拉起 Worker、盯着它们、转达信号、收尸。
 *
 * 它绝不处理业务请求。这是 prefork 模型能扛住业务代码崩溃的根本原因：
 * Master 的代码路径足够短，短到几乎不可能出错。
 *
 * 三个容易被忽略但很关键的设计：
 *  1. **滚动重载**：一次只替换一个 Worker，重载期间服务容量不会掉到 0；
 *  2. **崩溃退避**：Worker 起来就死时指数延迟重启，避免 fork 风暴把机器打死；
 *  3. **两段式停机**：先 SIGTERM 等优雅收敛，超时才 SIGKILL。
 */
final class Master
{
    /** 主循环节拍 */
    private const TICK = 0.05;

    /** 存活时间短于该值即视为"启动即崩溃" */
    private const CRASH_WINDOW = 3.0;

    private const BACKOFF_STEPS = [0.0, 0.2, 0.5, 1.0, 2.0, 5.0];

    /** @var array<int, array{id: int, pid: int, generation: int, startedAt: float}> pid => 信息 */
    private array $workers = [];

    /** @var array<int, int> slot => 连续崩溃次数 */
    private array $crashes = [];

    /** @var array<int, float> slot => 允许重启的时刻 */
    private array $respawnAt = [];

    /** @var list<int> 待滚动替换的旧 Worker pid */
    private array $reloadQueue = [];

    private bool $reloading = false;

    private bool $stopping = false;

    private int $generation = 1;

    private float $stopDeadline = 0.0;

    private float $startedAt = 0.0;

    private float $lastWatchAt = 0.0;

    private float $lastStatusAt = 0.0;

    /** @var resource|null */
    private $listen = null;

    private readonly PidFile $pidFile;

    private readonly Status $status;

    /**
     * @param \Closure(int, \Closure): int $workerRunner fn(slot, listenFactory): 退出码，在子进程中执行
     * @param string                       $driverName   已解析的驱动名；状态文件里要写实际值而不是 "auto"
     */
    public function __construct(
        private readonly ServerConfig $config,
        private readonly Emitter $emitter,
        private readonly LoggerInterface $logger,
        private readonly \Closure $workerRunner,
        private readonly ?WatcherInterface $watcher = null,
        private readonly string $driverName = 'stream',
    ) {
        if (!Sys::hasPcntl()) {
            throw new ProcessException(
                '多进程模式需要 ext-pcntl。可改用 driver=single 单进程模式，或安装扩展后重试。',
            );
        }

        $this->pidFile = new PidFile($config->pidFilePath());
        $this->status = new Status($config->statusFilePath());
    }

    /**
     * @return int 退出码
     */
    public function run(): int
    {
        $this->startedAt = microtime(true);

        // 先做一次廉价探测，好让"端口已被自己占用"这类错误在前台就报出来
        $running = $this->pidFile->readAlive();
        if ($running !== null) {
            throw \Kode\Server\Exception\StateException::alreadyRunning($running);
        }

        $this->emitter->emit(Event::BeforeStart, $this->config);

        if ($this->config->daemon) {
            Daemon::daemonize($this->config->logFile);
            $this->startedAt = microtime(true);
        }

        $this->pidFile->acquire(Sys::pid());
        Sys::setProcessTitle(sprintf('%s: master (%s)', $this->config->name, $this->config->address()));

        try {
            if (!$this->config->reusePort) {
                $this->listen = Listener::create($this->config);
            }

            $this->installSignals();

            for ($slot = 0; $slot < $this->config->workerCount(); ++$slot) {
                $this->spawn($slot);
            }

            $this->logger->info('{name} 已启动，监听 {address}，{n} 个 Worker', [
                'name' => $this->config->name,
                'address' => $this->config->address(),
                'n' => $this->config->workerCount(),
            ]);

            $this->emitter->emit(Event::Start, $this);

            $this->loop();

            return 0;
        } catch (Throwable $e) {
            $this->logger->critical('Master 异常：{message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $this->terminateAll(false);

            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    // ------------------------------------------------------------------
    // 主循环
    // ------------------------------------------------------------------

    private function loop(): void
    {
        while (true) {
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $this->reap();

            if ($this->stopping) {
                if ($this->workers === []) {
                    return;
                }
                if (microtime(true) > $this->stopDeadline) {
                    $this->forceKillRemaining();

                    return;
                }
            } else {
                $this->respawnPending();
                $this->pollWatcher();
            }

            $this->refreshStatus();

            usleep((int) (self::TICK * 1_000_000));
        }
    }

    /**
     * 回收退出的 Worker，并决定是否重新拉起。
     */
    private function reap(): void
    {
        while (true) {
            $status = 0;
            $pid = pcntl_waitpid(-1, $status, \WNOHANG);
            if ($pid <= 0) {
                return;
            }

            $info = $this->workers[$pid] ?? null;
            unset($this->workers[$pid]);

            if ($info === null) {
                continue; // 业务自己 fork 的子进程，不归我们管
            }

            $slot = $info['id'];
            $lifetime = microtime(true) - $info['startedAt'];
            $exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
            $signal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0;

            $this->emitter->emit(Event::WorkerExit, $slot, $pid, $exitCode, $signal);

            $context = ['slot' => $slot, 'pid' => $pid, 'code' => $exitCode, 'signal' => $signal, 'lifetime' => round($lifetime, 2)];
            if ($exitCode === 0 || $signal === Signal::Term->value) {
                $this->logger->info('Worker #{slot}(pid {pid}) 已退出', $context);
            } else {
                $this->logger->error('Worker #{slot}(pid {pid}) 非正常退出 code={code} signal={signal}', $context);
            }

            if ($this->stopping) {
                continue;
            }

            $this->scheduleRespawn($slot, $lifetime, $exitCode, $signal);

            if ($this->reloading) {
                $this->reloadNext();
            }
        }
    }

    /**
     * 崩溃退避：起来就死的 Worker 越重启越慢，给运维留出发现问题的机会。
     */
    private function scheduleRespawn(int $slot, float $lifetime, int $exitCode, int $signal): void
    {
        $crashed = $lifetime < self::CRASH_WINDOW && $exitCode !== 0 && $signal !== Signal::Term->value;

        if (!$crashed) {
            $this->crashes[$slot] = 0;
            $this->respawnAt[$slot] = 0.0;

            return;
        }

        $count = ($this->crashes[$slot] ?? 0) + 1;
        $this->crashes[$slot] = $count;
        $delay = self::BACKOFF_STEPS[min($count, \count(self::BACKOFF_STEPS) - 1)];
        $this->respawnAt[$slot] = microtime(true) + $delay;

        $this->logger->warning('Worker #{slot} 启动后 {lifetime}s 内崩溃（第 {n} 次），{delay}s 后重试', [
            'slot' => $slot,
            'lifetime' => round($lifetime, 2),
            'n' => $count,
            'delay' => $delay,
        ]);
    }

    private function respawnPending(): void
    {
        $now = microtime(true);
        $expected = $this->config->workerCount();

        for ($slot = 0; $slot < $expected; ++$slot) {
            if ($this->slotPid($slot) !== null) {
                continue;
            }
            if (($this->respawnAt[$slot] ?? 0.0) > $now) {
                continue;
            }
            $this->spawn($slot);
        }
    }

    private function pollWatcher(): void
    {
        if ($this->watcher === null || $this->reloading) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastWatchAt < $this->watcher->interval()) {
            return;
        }
        $this->lastWatchAt = $now;

        try {
            $changed = $this->watcher->poll();
        } catch (Throwable $e) {
            $this->logger->error('文件监视出错', ['exception' => $e]);

            return;
        }

        if ($changed === []) {
            return;
        }

        $this->logger->info('检测到 {n} 个文件变更（{sample}），触发热重载', [
            'n' => \count($changed),
            'sample' => implode(', ', \array_slice($changed, 0, 3)),
        ]);
        $this->reload();
    }

    private function refreshStatus(): void
    {
        $now = microtime(true);
        if ($now - $this->lastStatusAt < 2.0) {
            return;
        }
        $this->lastStatusAt = $now;

        $this->status->write(Status::snapshot(
            $this->config->name,
            $this->config->address(),
            $this->driverName,
            $this->startedAt,
            array_values(array_map(
                static fn (array $w): array => [
                    'id' => $w['id'],
                    'pid' => $w['pid'],
                    'generation' => $w['generation'],
                    'uptime' => round(microtime(true) - $w['startedAt'], 1),
                ],
                $this->workers,
            )),
        ));
    }

    // ------------------------------------------------------------------
    // 进程操作
    // ------------------------------------------------------------------

    private function spawn(int $slot): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new ProcessException(sprintf('fork Worker #%d 失败。', $slot));
        }

        if ($pid === 0) {
            $code = 255;
            try {
                // 子进程不再持有 Master 的这些资源与职责
                $this->pidFile->detach();
                $this->workers = [];
                $this->reloadQueue = [];

                $listen = $this->listen;
                $listenFactory = $listen !== null
                    ? static fn () => $listen
                    : fn () => Listener::create($this->config);

                $code = ($this->workerRunner)($slot, $listenFactory);
            } catch (Throwable $e) {
                // 子进程里没什么能补救的，尽量把原因留下来
                $this->logger->critical('Worker #{slot} 启动失败：{message}', [
                    'slot' => $slot,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            } finally {
                exit($code);
            }
        }

        $this->workers[$pid] = [
            'id' => $slot,
            'pid' => $pid,
            'generation' => $this->generation,
            'startedAt' => microtime(true),
        ];
    }

    private function slotPid(int $slot): ?int
    {
        foreach ($this->workers as $pid => $info) {
            if ($info['id'] === $slot) {
                return $pid;
            }
        }

        return null;
    }

    /**
     * 滚动重载：逐个替换 Worker，任何时刻都至少有 N-1 个进程在服务。
     */
    public function reload(): void
    {
        if ($this->stopping) {
            return;
        }

        if ($this->reloading) {
            $this->logger->notice('上一次重载尚未完成，忽略本次请求');

            return;
        }

        ++$this->generation;
        $this->reloading = true;
        $this->reloadQueue = array_keys($this->workers);
        $this->crashes = [];
        $this->respawnAt = [];

        $this->logger->info('开始滚动重载 {n} 个 Worker', ['n' => \count($this->reloadQueue)]);
        $this->emitter->emit(Event::Reload, $this->generation);

        $this->reloadNext();
    }

    private function reloadNext(): void
    {
        while ($this->reloadQueue !== []) {
            $pid = array_shift($this->reloadQueue);
            if (!isset($this->workers[$pid])) {
                continue; // 已经自己退出了
            }
            Sys::kill($pid, Signal::Term->value);

            return;
        }

        $this->reloading = false;
        $this->logger->info('重载完成，当前代次 {gen}', ['gen' => $this->generation]);
        $this->emitter->emit(Event::Reloaded, $this->generation);
    }

    /**
     * 请求停止；graceful=false 时直接 SIGKILL。
     */
    public function stop(bool $graceful = true): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->reloading = false;
        $this->reloadQueue = [];

        $this->logger->info('收到停止指令（{mode}），共 {n} 个 Worker', [
            'mode' => $graceful ? '优雅' : '强制',
            'n' => \count($this->workers),
        ]);

        $this->stopDeadline = microtime(true) + ($graceful ? $this->config->gracefulTimeout + $this->config->stopTimeout : 0.0);
        $this->terminateAll($graceful);
    }

    private function terminateAll(bool $graceful): void
    {
        $signal = $graceful ? Signal::Term->value : Signal::Kill->value;
        foreach (array_keys($this->workers) as $pid) {
            Sys::kill($pid, $signal);
        }
    }

    private function forceKillRemaining(): void
    {
        foreach (array_keys($this->workers) as $pid) {
            $this->logger->warning('Worker pid {pid} 未在期限内退出，发送 SIGKILL', ['pid' => $pid]);
            Sys::kill($pid, Signal::Kill->value);
        }

        // 给内核一点时间把子进程收掉，避免留下僵尸
        $deadline = microtime(true) + 1.0;
        while ($this->workers !== [] && microtime(true) < $deadline) {
            $this->reap();
            usleep(20_000);
        }
    }

    private function installSignals(): void
    {
        Signals::ignore(\defined('SIGPIPE') ? (int) \constant('SIGPIPE') : 13);

        $handlers = [];
        foreach (Signals::terminationSignals() as $signal) {
            $handlers[$signal] = function (): void {
                $this->stop(true);
            };
        }
        $handlers[Signal::Hup->value] = function (): void {
            $this->reload();
        };
        $handlers[Signal::usr1()] = function (): void {
            $this->reload();
        };
        $handlers[Signal::usr2()] = function (): void {
            if (method_exists($this->logger, 'reopen')) {
                $this->logger->reopen();
            }
            $this->logger->info('日志文件已重新打开');
        };
        // 子进程退出只需唤醒主循环，真正的回收在 reap() 里做
        $handlers[Signal::chld()] = static function (): void {
        };

        Signals::install($handlers);
    }

    private function cleanup(): void
    {
        Listener::close($this->listen, $this->config);
        $this->listen = null;

        try {
            $this->emitter->emit(Event::Shutdown, $this);
        } catch (Throwable $e) {
            $this->logger->error('Shutdown 回调出错', ['exception' => $e]);
        }

        $this->status->remove();
        $this->pidFile->release();

        $this->logger->info('{name} 已停止', ['name' => $this->config->name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return [
            'master_pid' => Sys::pid(),
            'generation' => $this->generation,
            'workers' => array_values($this->workers),
            'reloading' => $this->reloading,
            'stopping' => $this->stopping,
            'uptime' => round(microtime(true) - $this->startedAt, 1),
        ];
    }
}
