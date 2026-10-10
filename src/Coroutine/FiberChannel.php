<?php

declare(strict_types=1);

namespace Kode\Server\Coroutine;

use Kode\Server\Contract\ChannelInterface;

/**
 * 进程内有界队列式通道，适配 kode/process 的 ChannelInterface。
 *
 * 为什么不复用 kode/fibers 的 Channel：后者在 push/pop 时通过 Fiber::suspend()
 * 挂起，必须由常驻事件循环负责 resume，而 kode/server 的请求处理是“每请求一次驱动”
 * 的模型、没有这样的调度器，直接复用会死锁。这里用确定性的有界队列实现同样的生产/
 * 消费语义，且在请求内（或任意上下文）都可安全使用。
 *
 * 容量语义：capacity > 0 为缓冲上限；capacity = 0 表示无界队列（push 永远成功）。
 * push/pop 在容量不足 / 暂无数据且 timeout > 0 时以极短睡眠轮询，timeout <= 0 时立即返回。
 */
final class FiberChannel implements ChannelInterface
{
    /** @var array<int, mixed> */
    private array $messages = [];

    private bool $closed = false;

    public function __construct(private readonly int $capacity = 0)
    {
    }

    public function push(mixed $data, float $timeout = -1): bool
    {
        if ($this->closed) {
            return false;
        }

        $deadline = $timeout < 0 ? null : microtime(true) + $timeout;

        while (!$this->hasSpace()) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }
            usleep(1000);
        }

        $this->messages[] = $data;

        return true;
    }

    public function pop(float $timeout = -1): mixed
    {
        if ($this->isClosed() && $this->messages === []) {
            return null;
        }

        $deadline = $timeout < 0 ? null : microtime(true) + $timeout;

        while ($this->messages === []) {
            if ($this->isClosed()) {
                return null;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }
            usleep(1000);
        }

        return array_shift($this->messages);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->messages = [];
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getLength(): int
    {
        return \count($this->messages);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function hasSpace(): bool
    {
        return $this->capacity <= 0 || \count($this->messages) < $this->capacity;
    }
}
