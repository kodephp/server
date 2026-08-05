<?php

declare(strict_types=1);

namespace Kode\Server\Driver;

use Kode\Server\Config\ServerConfig;
use Kode\Server\Contract\DriverInterface;
use Kode\Server\Contract\WatcherInterface;
use Kode\Server\Event\Emitter;
use Kode\Server\Http\Dispatcher;
use Kode\Server\Support\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 驱动公共部分：持有同一套依赖，并统一"给日志打进程角色标签"的方式。
 */
abstract class AbstractDriver implements DriverInterface
{
    public function __construct(
        protected readonly ServerConfig $config,
        protected readonly Dispatcher $dispatcher,
        protected readonly Emitter $emitter,
        protected readonly LoggerInterface $logger = new NullLogger(),
        protected readonly ?WatcherInterface $watcher = null,
    ) {
    }

    /**
     * 给日志加上 master / worker#N 之类的角色标签。
     *
     * 只有自带的 Logger 支持；注入第三方 PSR-3 实现时原样返回，不做侵入。
     */
    protected function roleLogger(string $role): LoggerInterface
    {
        return $this->logger instanceof Logger ? $this->logger->withRole($role) : $this->logger;
    }
}
