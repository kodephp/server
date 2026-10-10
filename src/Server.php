<?php

declare(strict_types=1);

namespace Kode\Server;

use Kode\Server\Config\ServerConfig;
use Kode\Server\Contract\DriverInterface;
use Kode\Server\Contract\WatcherInterface;
use Kode\Server\Driver\DriverFactory;
use Kode\Server\Enum\Event;
use Kode\Server\Enum\State;
use Kode\Server\Event\Emitter;
use Kode\Server\Exception\ConfigException;
use Kode\Server\Exception\StateException;
use Kode\Server\Http\CallableHandler;
use Kode\Server\Http\Dispatcher;
use Kode\Server\Http\Factories;
use Kode\Server\Http\RequestBuilder;
use Kode\Server\Process\Controller;
use Kode\Server\Reload\FileWatcher;
use Kode\Server\Contract\CoroutineDriverInterface;
use Kode\Server\Coroutine\FiberCoroutineDriver;
use Kode\Server\Parallel\Parallel;
use Kode\Server\Support\Logger;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP 服务门面——本包唯一需要直接打交道的类。
 *
 * ```php
 * (new Server(['host' => '0.0.0.0', 'port' => 9501, 'workers' => 4]))
 *     ->onRequest(fn (ServerRequestInterface $r) => 'hello')
 *     ->start();
 * ```
 *
 * 职责边界很清楚：**它只管生命周期，不管路由、不管中间件、不管业务**。
 * 路由交给 kode/router，中间件交给 kode/middleware，
 * 它们最终都以一个 PSR-15 处理器的形式挂进来。
 *
 * 作为组合根，它把 kode 生态的三块能力收口并对外暴露，供 handler 在请求内使用：
 *  - 进程基座：kode/process（Master-Worker、守护、信号、滚动重载）
 *  - 协程运行时：kode/fibers（coroutine() 跑并发子任务）
 *  - 并行卸载：kode/parallel（parallel() 把 CPU 密集任务 offload 到独立运行时）
 *
 * 一个实例的两种角色，注意区分：
 *  - **服务进程内**（start() 正在阻塞）：stop()/reload() 直接操作驱动；
 *  - **服务进程外**（另一个 CLI 进程）：stop()/reload() 通过 PID 文件发信号。
 * 两种场景用同一套方法名，由内部状态自动路由，调用方不需要关心。
 */
final class Server
{
    private readonly ServerConfig $serverConfig;

    private readonly Emitter $emitter;

    private LoggerInterface $logger;

    private ?RequestHandlerInterface $handler = null;

    private ?Factories $factories = null;

    private ?DriverInterface $driver = null;

    private ?WatcherInterface $watcher = null;

    private State $state = State::Stopped;

    private bool $debug = false;

    private ?CoroutineDriverInterface $coroutineDriver = null;

    private ?Parallel $parallel = null;

    /**
     * @param array<string, mixed>|ServerConfig $config
     */
    public function __construct(array|ServerConfig $config = [])
    {
        $this->serverConfig = \is_array($config) ? ServerConfig::fromArray($config) : $config;
        $this->emitter = new Emitter();
        $this->logger = $this->defaultLogger();
        $this->emitter->setLogger($this->logger);
    }

    /**
     * @param array<string, mixed>|ServerConfig $config
     */
    public static function create(array|ServerConfig $config = []): self
    {
        return new self($config);
    }

    // ------------------------------------------------------------------
    // 装配
    // ------------------------------------------------------------------

    /**
     * 注册请求处理器。
     *
     * 接受三种形态，覆盖从"一行 demo"到"完整中间件管道"的所有场景：
     *  - PSR-15 RequestHandlerInterface（推荐，kode/router、kode/middleware 都实现了它）
     *  - callable(ServerRequestInterface): ResponseInterface|string|array|null
     *  - 返回字符串/数组时自动包装成 200 响应（数组按 JSON 输出）
     */
    public function onRequest(callable|RequestHandlerInterface $handler): self
    {
        $this->assertConfigurable('onRequest');

        $this->handler = $handler instanceof RequestHandlerInterface
            ? $handler
            : new CallableHandler($handler);

        return $this;
    }

    /**
     * 注册生命周期钩子。
     *
     * @param callable(mixed...): mixed $listener
     */
    public function on(Event $event, callable $listener, int $priority = 0): self
    {
        $this->emitter->on($event, $listener, $priority);

        return $this;
    }

    /**
     * @param callable(mixed...): mixed $listener
     */
    public function once(Event $event, callable $listener, int $priority = 0): self
    {
        $this->emitter->once($event, $listener, $priority);

        return $this;
    }

    public function off(Event $event, ?callable $listener = null): self
    {
        $this->emitter->off($event, $listener);

        return $this;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->emitter->setLogger($logger);

        return $this;
    }

    /**
     * 手动指定 PSR-17 工厂；不指定时自动探测（kode/http → nyholm → guzzle → ...）。
     */
    public function setFactories(Factories $factories): self
    {
        $this->factories = $factories;

        return $this;
    }

    /**
     * 调试模式：500 响应里带上异常栈。**生产环境务必关闭**。
     */
    public function debug(bool $debug = true): self
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * 替换文件监视器（默认按 watch 配置构造 FileWatcher）。
     */
    public function setWatcher(?WatcherInterface $watcher): self
    {
        $this->watcher = $watcher;

        return $this;
    }

    /**
     * 注入协程驱动（组合根：默认基于 kode/fibers 的实现）。
     *
     * handler 内可用它跑并发子任务；满足 kode/server 的 Contract\CoroutineDriverInterface，
     * 以后可整体替换成 Swow 等其它后端。
     */
    public function setCoroutineDriver(CoroutineDriverInterface $driver): self
    {
        $this->coroutineDriver = $driver;

        return $this;
    }

    /**
     * 协程驱动（惰性单例）。handler 内用它以 kode/fibers 并发执行子任务。
     *
     *   $server->coroutine()->go(fn () => slowIo());
     *   $rows = $server->coroutine()->batch($ids, fn ($id) => fetch($id), 8);
     */
    public function coroutine(): CoroutineDriverInterface
    {
        if ($this->coroutineDriver === null) {
            $this->coroutineDriver = new FiberCoroutineDriver();
        }

        return $this->coroutineDriver;
    }

    /**
     * 并行卸载门面（惰性单例）。handler 内把 CPU 密集任务 offload 到独立运行时。
     *
     *   $future = $server->parallel()->run(fn ($a) => heavy($a[0]), [$n]);
     *   $result = $server->parallel()->await($future);
     */
    public function parallel(): Parallel
    {
        if ($this->parallel === null) {
            $this->parallel = new Parallel();
        }

        return $this->parallel;
    }

    // ------------------------------------------------------------------
    // 生命周期
    // ------------------------------------------------------------------

    /**
     * 启动服务，**阻塞**直到服务停止。
     *
     * @return int 退出码，可直接 `exit($server->start())`
     */
    public function start(): int
    {
        if ($this->state !== State::Stopped) {
            throw StateException::illegalTransition($this->state, 'start');
        }
        if ($this->handler === null) {
            throw new ConfigException(
                '尚未注册请求处理器。请先调用 onRequest()，例如：' . \PHP_EOL
                . '    $server->onRequest(fn ($request) => "hello");',
            );
        }

        $this->state = State::Starting;

        try {
            $this->driver = DriverFactory::create(
                config: $this->serverConfig,
                dispatcher: $this->buildDispatcher(),
                emitter: $this->emitter,
                logger: $this->logger,
                watcher: $this->resolveWatcher(),
            );

            $this->state = State::Running;
            $this->driver->run();

            return 0;
        } catch (Throwable $e) {
            $this->emitter->emit(Event::Error, $e, Event::Start);

            throw $e;
        } finally {
            $this->driver = null;
            $this->state = State::Stopped;
        }
    }

    /**
     * 停止服务。
     *
     * 在服务进程内调用会立即触发优雅停机；在别的进程里调用则通过 PID 文件发信号，
     * 并**等待进程真正退出**后才返回，方便脚本里紧接着执行重启。
     *
     * @return bool false 表示服务本来就没在运行
     */
    public function stop(bool $graceful = true): bool
    {
        if ($this->driver !== null) {
            $this->state = State::Stopping;
            $this->driver->shutdown($graceful);

            return true;
        }

        return $this->controller()->stop($graceful);
    }

    /**
     * 滚动重载 Worker：逐个替换，服务不中断。
     *
     * 这是发布新代码的推荐方式——比 stop + start 少一段完全不可用的窗口。
     *
     * @return bool false 表示服务未运行
     */
    public function reload(): bool
    {
        if ($this->driver !== null) {
            $this->state = State::Reloading;
            $this->driver->reloadWorkers();
            $this->state = State::Running;

            return true;
        }

        return $this->controller()->reload();
    }

    /**
     * 通知服务重开日志文件（logrotate 场景）。
     */
    public function reopenLogs(): bool
    {
        return $this->controller()->reopenLogs();
    }

    /**
     * 运行状态快照；服务未运行时返回 null。
     *
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        return $this->controller()->status();
    }

    /**
     * 当前进程视角的实时指标。
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->driver?->stats() ?? ['driver' => DriverFactory::resolve($this->serverConfig->driver)];
    }

    public function isRunning(): bool
    {
        return $this->driver !== null || $this->controller()->isRunning();
    }

    public function state(): State
    {
        if ($this->state !== State::Stopped) {
            return $this->state;
        }

        return $this->controller()->state();
    }

    /**
     * 正在运行的 Master 进程 PID。
     */
    public function pid(): ?int
    {
        return $this->controller()->pid();
    }

    public function config(): ServerConfig
    {
        return $this->serverConfig;
    }

    public function emitter(): Emitter
    {
        return $this->emitter;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * 实际会使用的驱动名（把 auto 解析成具体实现）。
     */
    public function driverName(): string
    {
        return $this->driver?->name() ?? DriverFactory::resolve($this->serverConfig->driver);
    }

    // ------------------------------------------------------------------

    private function buildDispatcher(): Dispatcher
    {
        $factories = $this->factories ?? Factories::discover();

        \assert($this->handler !== null);

        // 闭包处理器要和 Dispatcher 共用同一套工厂，否则返回数组时会各自去探测一遍
        if ($this->handler instanceof CallableHandler) {
            $this->handler->setFactories($factories);
        }

        return new Dispatcher(
            handler: $this->handler,
            builder: new RequestBuilder($this->serverConfig->limits, $factories),
            factories: $factories,
            emitter: $this->emitter,
            logger: $this->logger,
            debug: $this->debug,
        );
    }

    private function resolveWatcher(): ?WatcherInterface
    {
        if ($this->watcher !== null) {
            return $this->watcher;
        }
        if (!$this->serverConfig->watch->enabled) {
            return null;
        }

        // 监视器只在 Master 里跑，日志打上对应角色便于排查
        $logger = $this->logger instanceof Logger ? $this->logger->withRole('master') : $this->logger;

        return new FileWatcher($this->serverConfig->watch, $logger);
    }

    private function controller(): Controller
    {
        return new Controller($this->serverConfig);
    }

    private function defaultLogger(): Logger
    {
        // 守护进程和重定向输出时不上色，避免日志文件里全是转义序列
        $colors = !$this->serverConfig->daemon
            && $this->serverConfig->logFile === null
            && \function_exists('posix_isatty')
            && \defined('STDOUT')
            && @posix_isatty(\STDOUT);

        return new Logger($this->serverConfig->logFile, $this->serverConfig->logLevel, $colors);
    }

    private function assertConfigurable(string $action): void
    {
        if ($this->state !== State::Stopped) {
            throw StateException::illegalTransition($this->state, $action);
        }
    }
}
