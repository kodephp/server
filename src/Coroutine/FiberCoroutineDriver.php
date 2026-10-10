<?php

declare(strict_types=1);

namespace Kode\Server\Coroutine;

use Fiber;
use Kode\Fibers\Fibers;
use Kode\Server\Contract\ChannelInterface;
use Kode\Server\Contract\CoroutineDriverInterface;
use Kode\Server\Contract\WaitGroupInterface;

/**
 * 基于 kode/fibers 的协程驱动，满足 kode/server 的 Contract\CoroutineDriverInterface。
 *
 * 这让 kode/server 的 onRequest handler 在 Worker 进程内直接复用 kode 生态的
 * 协程运行时：并发子任务用 go()/batch()，无需自己引一套调度器。
 *
 *   use Kode\Fibers\Fibers;
 *   $results = Fibers::concurrent([
 *       fn () => fetchUser($id),
 *       fn () => fetchOrders($id),
 *   ]);
 *
 * 关于 Channel / WaitGroup：kode/fibers 自带的 Channel 依赖“Fiber 挂起后由事件循环
 * resume”的常驻调度模型，而本包的请求处理是“每请求一次同步驱动”，没有这样的调度器，
 * 因此这里用确定性的进程内有界队列 / 计数器实现，语义等价、可单测，更适合请求内场景。
 */
final class FiberCoroutineDriver implements CoroutineDriverInterface
{
    public function getName(): string
    {
        return 'kode/fibers';
    }

    public function go(callable $callback): mixed
    {
        return Fibers::go($callback);
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return array<array-key, mixed>
     */
    public function batch(array $items, callable $callback, int $concurrency = 10): array
    {
        return Fibers::batch($items, $callback, $concurrency);
    }

    public function sleep(float $seconds): void
    {
        Fibers::sleep($seconds);
    }

    public function getCurrentId(): int
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? 0 : spl_object_id($fiber);
    }

    public function inCoroutine(): bool
    {
        return Fiber::getCurrent() !== null;
    }

    public function createChannel(int $capacity = 0): ChannelInterface
    {
        return new FiberChannel($capacity);
    }

    public function createWaitGroup(): WaitGroupInterface
    {
        return new FiberWaitGroup();
    }
}
