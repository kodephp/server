<?php

declare(strict_types=1);

namespace Kode\Server\Coroutine;

use Fiber;
use Kode\Fibers\Fibers;
use Kode\Server\Contract\WaitGroupInterface;

/**
 * 计数式等待组，适配 kode/process 的 WaitGroupInterface。
 *
 * 与 Channel 同理，这里不复用需要常驻调度器的原语，而是用确定性计数器 +
 * 极短让出/睡眠轮询实现，适用于请求内的“派发 N 个子任务、等它们全部完成”。
 */
final class FiberWaitGroup implements WaitGroupInterface
{
    private int $count = 0;

    public function add(int $delta = 1): void
    {
        if ($delta < 0) {
            throw new \InvalidArgumentException('delta 必须为非负整数');
        }
        $this->count += $delta;
    }

    public function done(): void
    {
        if ($this->count > 0) {
            --$this->count;
        }
    }

    public function wait(float $timeout = -1): bool
    {
        $deadline = $timeout < 0 ? null : microtime(true) + $timeout;

        while ($this->count > 0) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }
            // 协程内让出，避免忙等；非协程环境退化为微睡眠
            if (Fiber::getCurrent() !== null) {
                Fibers::sleep(0.001);
            } else {
                usleep(1000);
            }
        }

        return true;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
