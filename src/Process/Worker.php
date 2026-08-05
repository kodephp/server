<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Process\Signal;
use Kode\Server\Config\ServerConfig;
use Kode\Server\Contract\LoopInterface;
use Kode\Server\Enum\Event;
use Kode\Server\Event\Emitter;
use Kode\Server\Http\Connection;
use Kode\Server\Http\Dispatcher;
use Kode\Server\Http\Parser;
use Kode\Server\Http\Responder;
use Kode\Server\Loop\StreamSelectLoop;
use Kode\Server\Support\Bytes;
use Kode\Server\Support\Sys;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Worker 进程：接受连接、驱动事件循环、把请求交给 Dispatcher。
 *
 * 自愈机制（三选一触发即优雅重启，由 Master 负责拉起新进程）：
 *  - 处理请求数达到 maxRequests —— 对冲第三方扩展的内存泄漏；
 *  - 常驻内存超过 memoryLimit；
 *  - 收到 Master 的 SIGTERM。
 *
 * "优雅"的含义在这里非常具体：停止 accept → 已建立的连接把当前响应写完 →
 * 连接归零或超时 → 退出。任何一个请求都不该因为重启而被拦腰截断。
 */
final class Worker
{
    /** 单次可读事件里最多 accept 多少个连接，避免饿死其它 fd */
    private const ACCEPT_BURST = 64;

    private LoopInterface $loop;

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var resource|null */
    private $listen = null;

    private bool $stopping = false;

    private bool $accepting = false;

    private int $handled = 0;

    private readonly int $maxRequests;

    private float $startedAt = 0.0;

    private ?string $deadlineTimer = null;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly int $id,
        private readonly Dispatcher $dispatcher,
        private readonly Emitter $emitter,
        private readonly LoggerInterface $logger,
        private readonly \Closure $listenFactory,
        ?LoopInterface $loop = null,
    ) {
        $this->loop = $loop ?? new StreamSelectLoop();
        $this->maxRequests = $config->maxRequestsFor($id);
    }

    /**
     * @return int 退出码
     */
    public function run(): int
    {
        $this->startedAt = microtime(true);
        Sys::setProcessTitle(sprintf('%s: worker #%d', $this->config->name, $this->id));

        $this->installSignals();

        try {
            Sys::switchUser($this->config->user, $this->config->group);

            $this->listen = ($this->listenFactory)();
            $this->startAccepting();

            // 以"完成的响应数"而不是"连接数"作为自愈计数，长连接场景才准确
            $this->emitter->on(Event::Response, function (): void {
                ++$this->handled;
            }, \PHP_INT_MAX);

            $this->loop->addPeriodicTimer(1.0, $this->sweep(...));

            $this->emitter->emit(Event::WorkerStart, $this->id, Sys::pid());
            $this->logger->info('Worker #{id} 就绪（maxRequests={max}）', [
                'id' => $this->id,
                'max' => $this->maxRequests > 0 ? $this->maxRequests : '∞',
            ]);

            $this->loop->run();

            return 0;
        } catch (Throwable $e) {
            $this->logger->critical('Worker #{id} 异常退出：{message}', [
                'id' => $this->id,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return 255;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 供单进程模式从外部触发停止。
     */
    public function stop(bool $graceful = true): void
    {
        $this->beginShutdown($graceful);
    }

    public function loop(): LoopInterface
    {
        return $this->loop;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return [
            'id' => $this->id,
            'pid' => Sys::pid(),
            'connections' => \count($this->connections),
            'handled' => $this->handled,
            'memory' => Sys::memoryUsage(),
            'uptime' => round(microtime(true) - $this->startedAt, 1),
        ];
    }

    // ------------------------------------------------------------------

    private function installSignals(): void
    {
        // SIGPIPE 必须忽略：往已被对端关闭的连接里写数据是常态，
        // 默认处理会直接杀死进程。
        Signals::ignore(Signal::PIPE);

        $handlers = [];
        foreach (Signals::terminationSignals() as $signal) {
            $handlers[$signal] = function (): void {
                $this->beginShutdown(true);
            };
        }
        $handlers[Signal::USR1] = function (): void {
            $this->beginShutdown(true);
        };
        $handlers[Signal::USR2] = function (): void {
            if (method_exists($this->logger, 'reopen')) {
                $this->logger->reopen();
            }
        };
        // Worker 不处理 SIGCHLD，交还默认行为，避免误吞业务自己 fork 的子进程
        Signals::reset(Signal::CHLD);
        Signals::install($handlers, $this->logger);
    }

    private function startAccepting(): void
    {
        if ($this->accepting || $this->listen === null || $this->stopping) {
            return;
        }
        $this->accepting = true;
        $this->loop->onReadable($this->listen, $this->accept(...));
    }

    private function stopAccepting(): void
    {
        if (!$this->accepting || $this->listen === null) {
            return;
        }
        $this->accepting = false;
        $this->loop->offReadable($this->listen);
    }

    private function accept(): void
    {
        if ($this->listen === null) {
            return;
        }

        for ($i = 0; $i < self::ACCEPT_BURST; ++$i) {
            $peer = '';
            $socket = @stream_socket_accept($this->listen, 0, $peer);
            if (!\is_resource($socket)) {
                return; // 连接被别的 Worker 抢走了，或队列已空
            }

            if ($this->stopping) {
                @fclose($socket);
                continue;
            }

            $this->openConnection($socket, $peer);

            if ($this->atCapacity()) {
                $this->logger->warning('Worker #{id} 达到连接数上限 {max}，暂停 accept', [
                    'id' => $this->id,
                    'max' => $this->config->limits->maxConnections,
                ]);
                $this->stopAccepting();

                return;
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function openConnection($socket, string $peer): void
    {
        $connection = new Connection(
            socket: $socket,
            loop: $this->loop,
            limits: $this->config->limits,
            parser: new Parser($this->config->limits),
            responder: new Responder($this->config->limits),
            dispatcher: $this->dispatcher,
            serverParams: $this->serverParams($socket, $peer),
            emitter: $this->emitter,
            onClose: $this->releaseConnection(...),
            logger: $this->logger,
        );

        $this->connections[spl_object_id($connection)] = $connection;
        $connection->open();
    }

    private function releaseConnection(Connection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);

        if (!$this->stopping && !$this->accepting && !$this->atCapacity()) {
            $this->startAccepting();
        }

        if ($this->stopping && $this->connections === []) {
            $this->loop->stop();
        }
    }

    private function atCapacity(): bool
    {
        $max = $this->config->limits->maxConnections;

        return $max > 0 && \count($this->connections) >= $max;
    }

    /**
     * @param resource $socket
     *
     * @return array<string, mixed>
     */
    private function serverParams($socket, string $peer): array
    {
        $local = (string) @stream_socket_get_name($socket, false);

        [$remoteHost, $remotePort] = $this->splitAddress($peer);
        [$localHost, $localPort] = $this->splitAddress($local);

        return array_filter([
            'REMOTE_ADDR' => $remoteHost,
            'REMOTE_PORT' => $remotePort,
            'SERVER_ADDR' => $localHost,
            'SERVER_PORT' => $localPort !== null ? $localPort : (string) $this->config->port,
            'SERVER_SOFTWARE' => $this->config->limits->serverSignature,
            'KODE_WORKER_ID' => (string) $this->id,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitAddress(string $address): array
    {
        if ($address === '') {
            return [null, null];
        }
        // IPv6: [::1]:12345
        if (preg_match('/^\[(.+)\]:(\d+)$/', $address, $m)) {
            return [$m[1], $m[2]];
        }
        $pos = strrpos($address, ':');
        if ($pos === false) {
            return [$address, null];
        }

        return [substr($address, 0, $pos), substr($address, $pos + 1)];
    }

    /**
     * 每秒一次的巡检：超时回收 + 自愈判定。
     */
    private function sweep(): void
    {
        $now = microtime(true);

        foreach ($this->connections as $connection) {
            try {
                $connection->checkTimeout($now);
            } catch (Throwable $e) {
                $this->logger->error('清理连接时出错', ['exception' => $e]);
                $connection->close(true);
            }
        }

        if ($this->stopping) {
            if ($this->connections === []) {
                $this->loop->stop();
            }

            return;
        }

        if ($this->maxRequests > 0 && $this->handled >= $this->maxRequests) {
            $this->logger->info('Worker #{id} 已处理 {n} 个请求，主动重启', [
                'id' => $this->id,
                'n' => $this->handled,
            ]);
            $this->beginShutdown(true);

            return;
        }

        $limit = $this->config->memoryLimit;
        if ($limit > 0 && Sys::memoryUsage() > $limit) {
            $this->logger->warning('Worker #{id} 内存达到 {used}（上限 {limit}），主动重启', [
                'id' => $this->id,
                'used' => Bytes::format(Sys::memoryUsage()),
                'limit' => Bytes::format($limit),
            ]);
            $this->beginShutdown(true);
        }
    }

    private function beginShutdown(bool $graceful): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;

        $this->stopAccepting();

        if (!$graceful) {
            foreach ($this->connections as $connection) {
                $connection->close(true);
            }
            $this->loop->stop();

            return;
        }

        foreach ($this->connections as $connection) {
            $connection->shutdown();
        }

        if ($this->connections === []) {
            $this->loop->stop();

            return;
        }

        $this->logger->info('Worker #{id} 开始优雅退出，等待 {n} 个连接收敛', [
            'id' => $this->id,
            'n' => \count($this->connections),
        ]);

        // 兜底：给足时间后强制收场，绝不无限期挂着
        $this->deadlineTimer = $this->loop->addTimer($this->config->gracefulTimeout, function (): void {
            if ($this->connections !== []) {
                $this->logger->warning('Worker #{id} 优雅期结束，强制关闭 {n} 个连接', [
                    'id' => $this->id,
                    'n' => \count($this->connections),
                ]);
            }
            foreach ($this->connections as $connection) {
                $connection->close(true);
            }
            $this->loop->stop();
        });
    }

    private function cleanup(): void
    {
        if ($this->deadlineTimer !== null) {
            $this->loop->cancelTimer($this->deadlineTimer);
            $this->deadlineTimer = null;
        }

        foreach ($this->connections as $connection) {
            $connection->close(true);
        }
        $this->connections = [];

        if (\is_resource($this->listen)) {
            $this->loop->offReadable($this->listen);
            @fclose($this->listen);
        }
        $this->listen = null;

        try {
            $this->emitter->emit(Event::WorkerStop, $this->id, Sys::pid());
        } catch (Throwable $e) {
            $this->logger->error('WorkerStop 回调出错', ['exception' => $e]);
        }
    }
}
