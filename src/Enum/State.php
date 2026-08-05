<?php

declare(strict_types=1);

namespace Kode\Server\Enum;

/**
 * 服务生命周期状态机。
 *
 *   Stopped ──start()──> Starting ──> Running ──stop()──> Stopping ──> Stopped
 *                                        │                    ▲
 *                                        └──reload()──> Reloading
 */
enum State: string
{
    case Stopped = 'stopped';
    case Starting = 'starting';
    case Running = 'running';
    case Reloading = 'reloading';
    case Stopping = 'stopping';

    public function isActive(): bool
    {
        return $this === self::Running || $this === self::Reloading;
    }

    public function canStart(): bool
    {
        return $this === self::Stopped;
    }

    public function canStop(): bool
    {
        return $this !== self::Stopped && $this !== self::Stopping;
    }
}
