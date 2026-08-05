<?php

declare(strict_types=1);

namespace Kode\Server\Driver;

use Kode\Server\Enum\Event;
use Kode\Server\Exception\DriverException;
use Kode\Server\Support\Sys;
use Throwable;

/**
 * Swoole 驱动：把生命周期指令翻译成 Swoole\Http\Server 的调用。
 *
 * 这里刻意**不**引入任何 Swoole 类型声明——扩展没装时这些类根本不存在，
 * 一旦写进类型签名，`composer dump-autoload` 阶段的静态检查就会炸。
 * 全部走 class_string + 动态调用，代价是失去 IDE 补全，换来的是无扩展环境下
 * 整个包依然能被正常加载和分析。
 *
 * 语义对齐（这是驱动层存在的全部意义）：
 *  - start()  → $server->start()
 *  - stop()   → $server->shutdown()
 *  - reload() → $server->reload()   ← Swoole 原生就是滚动重载
 *  - onRequest 收到的仍是 PSR-7 请求，业务代码在两种驱动下完全一致
 */
final class SwooleDriver extends AbstractDriver
{
    private const SERVER_CLASS = 'Swoole\\Http\\Server';

    private const TIMER_CLASS = 'Swoole\\Timer';

    /** SWOOLE_PROCESS */
    private const MODE_PROCESS = 2;

    /** SWOOLE_SOCK_TCP */
    private const SOCK_TCP = 1;

    /** SWOOLE_SOCK_UNIX_STREAM */
    private const SOCK_UNIX = 5;

    private ?object $server = null;

    public function name(): string
    {
        return 'swoole';
    }

    public static function isSupported(): bool
    {
        return Sys::hasSwoole() && class_exists(self::SERVER_CLASS);
    }

    public function run(): void
    {
        if (!self::isSupported()) {
            throw DriverException::unavailable('swoole', '未安装 ext-swoole，或版本过低不含 Swoole\\Http\\Server。');
        }

        $this->emitter->emit(Event::BeforeStart, $this->config);

        $server = $this->createServer();
        $this->server = $server;

        $this->bindEvents($server);

        try {
            /** @var callable $start */
            $start = [$server, 'start'];
            $start();
        } finally {
            $this->server = null;
        }
    }

    public function shutdown(bool $graceful = true): void
    {
        $server = $this->server;
        if ($server === null) {
            return;
        }

        // Swoole 的 shutdown() 本身已经是优雅停机（等在途请求收敛）；
        // 强制退出没有对应 API，只能由外部发 SIGKILL，这里保持一致行为。
        /** @var callable $call */
        $call = [$server, 'shutdown'];
        $call();
    }

    public function reloadWorkers(): void
    {
        $server = $this->server;
        if ($server === null) {
            return;
        }
        /** @var callable $reload */
        $reload = [$server, 'reload'];
        $reload();
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $server = $this->server;
        if ($server === null) {
            return ['driver' => $this->name()];
        }

        /** @var callable $stats */
        $stats = [$server, 'stats'];

        /** @var array<string, mixed> $raw */
        $raw = $stats();

        return ['driver' => $this->name()] + $raw;
    }

    // ------------------------------------------------------------------

    private function createServer(): object
    {
        $class = self::SERVER_CLASS;

        if ($this->config->isUnixSocket()) {
            $server = new $class($this->config->socketPath(), 0, self::MODE_PROCESS, self::SOCK_UNIX);
        } else {
            $server = new $class($this->config->host, $this->config->port, self::MODE_PROCESS, self::SOCK_TCP);
        }

        /** @var callable $set */
        $set = [$server, 'set'];
        $set($this->buildOptions());

        return $server;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOptions(): array
    {
        $limits = $this->config->limits;

        $options = [
            'worker_num' => $this->config->workerCount(),
            'daemonize' => $this->config->daemon,
            'backlog' => $this->config->backlog,
            'open_tcp_nodelay' => $this->config->tcpNoDelay,
            'max_request' => $this->config->maxRequests,
            'package_max_length' => max($limits->maxBodySize, $limits->maxHeaderSize) + 8192,
            'http_parse_post' => true,
            'http_parse_cookie' => true,
            'upload_tmp_dir' => $limits->tempDir ?? sys_get_temp_dir(),
            'max_conn' => $limits->maxConnections > 0 ? $limits->maxConnections : 10000,
            // 与 gracefulTimeout 对齐：重载/停机时给在途请求同样的宽限
            'max_wait_time' => (int) ceil($this->config->gracefulTimeout),
            'reload_async' => true,
            'pid_file' => $this->config->pidFilePath(),
            'enable_coroutine' => true,
        ];

        if ($limits->parseMultipart === false) {
            $options['http_parse_files'] = false;
        }
        if ($this->config->logFile !== null) {
            $options['log_file'] = $this->config->logFile;
        }
        if ($this->config->user !== null) {
            $options['user'] = $this->config->user;
        }
        if ($this->config->group !== null) {
            $options['group'] = $this->config->group;
        }
        if ($this->config->reusePort) {
            $options['enable_reuse_port'] = true;
        }
        if ($limits->keepAlive === false) {
            $options['open_http_protocol'] = true;
            $options['http_compression'] = false;
        }

        // 用户显式给出的 swoole 配置优先级最高
        return array_merge($options, $this->config->swoole);
    }

    private function bindEvents(object $server): void
    {
        /** @var callable $on */
        $on = [$server, 'on'];
        $logger = $this->roleLogger('swoole');

        $on('Start', function () use ($logger, $server): void {
            Sys::setProcessTitle(sprintf('%s: master (%s)', $this->config->name, $this->config->address()));
            $logger->info('{name} 已启动（Swoole 驱动），监听 {address}，{n} 个 Worker', [
                'name' => $this->config->name,
                'address' => $this->config->address(),
                'n' => $this->config->workerCount(),
            ]);
            $this->emitter->emit(Event::Start, $this);
            $this->startWatching($server, $logger);
        });

        $on('WorkerStart', function (object $srv, int $workerId) use ($logger): void {
            $isTask = (bool) ($srv->taskworker ?? false);
            Sys::setProcessTitle(sprintf('%s: %s #%d', $this->config->name, $isTask ? 'task' : 'worker', $workerId));

            if (!$isTask) {
                $this->emitter->emit(Event::WorkerStart, $workerId, Sys::pid());
                $logger->info('Worker #{id} 就绪', ['id' => $workerId]);
            }
        });

        $on('WorkerStop', function (object $srv, int $workerId): void {
            $this->emitter->emit(Event::WorkerStop, $workerId, Sys::pid());
        });

        $on('Request', $this->handleRequest(...));

        $on('Shutdown', function () use ($logger): void {
            $this->emitter->emit(Event::Shutdown, $this);
            $logger->info('{name} 已停止', ['name' => $this->config->name]);
        });
    }

    /**
     * @param object $request  Swoole\Http\Request
     * @param object $response Swoole\Http\Response
     */
    private function handleRequest(object $request, object $response): void
    {
        try {
            $psrRequest = SwooleBridge::toPsr(
                $request,
                $this->dispatcher->factories(),
                ['SERVER_SOFTWARE' => $this->config->limits->serverSignature],
            );

            $this->emitter->emit(Event::Connect, $psrRequest);

            $psrResponse = $this->dispatcher->handle($psrRequest);
        } catch (Throwable $e) {
            $psrResponse = $this->dispatcher->renderThrowable($e, null);
        }

        try {
            SwooleBridge::send($psrResponse, $response, $this->config->limits);
            $this->emitter->emit(Event::Response, $psrResponse);
        } catch (Throwable $e) {
            // 走到这里通常是连接已断开，记一笔即可，不能让异常冒到 Swoole 里
            $this->roleLogger('swoole')->warning('写出响应失败：{message}', [
                'message' => $e->getMessage(),
            ]);
        } finally {
            $this->emitter->emit(Event::Close, null);
        }
    }

    /**
     * Swoole 自身不监视文件，这里在 Master 进程挂一个定时器补上。
     */
    private function startWatching(object $server, \Psr\Log\LoggerInterface $logger): void
    {
        $watcher = $this->watcher;
        if ($watcher === null || !class_exists(self::TIMER_CLASS)) {
            return;
        }

        $timer = self::TIMER_CLASS;
        /** @var callable $tick */
        $tick = [$timer, 'tick'];

        $tick((int) max(200, $watcher->interval() * 1000), function () use ($watcher, $server, $logger): void {
            try {
                $changed = $watcher->poll();
            } catch (Throwable $e) {
                $logger->error('文件监视出错', ['exception' => $e]);

                return;
            }

            if ($changed === []) {
                return;
            }

            $logger->info('检测到 {n} 个文件变更（{sample}），触发热重载', [
                'n' => \count($changed),
                'sample' => implode(', ', \array_slice($changed, 0, 3)),
            ]);

            $this->emitter->emit(Event::Reload, 0);
            /** @var callable $reload */
            $reload = [$server, 'reload'];
            $reload();
        });
    }
}
