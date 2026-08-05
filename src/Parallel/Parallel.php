<?php

declare(strict_types=1);

namespace Kode\Server\Parallel;

use Kode\Parallel\Future\FutureInterface;
use function Kode\Parallel\await;
use function Kode\Parallel\cpus;
use function Kode\Parallel\engine;
use function Kode\Parallel\map;
use function Kode\Parallel\run;

/**
 * kode/parallel 的薄门面：让 onRequest handler 把 CPU 密集 / 阻塞型任务
 * 卸载到独立运行时（ext-parallel 线程 / pcntl 子进程 / 同步回退），
 * 不让它们卡住处理请求的 Worker。
 *
 * 引擎自动降级：ext-parallel → pcntl 进程 → 同步回退（此时无并行收益，但不会报错）。
 *
 *   $future = $server->parallel()->run(
 *       fn (array $args) => heavy_compute($args['n']),
 *       ['n' => 1_000_000],
 *   );
 *   $result = $server->parallel()->await($future);   // 阻塞取结果
 */
final class Parallel
{
    /**
     * 提交一个并行任务。
     *
     * @param callable $task 签名 fn(array $args): mixed
     * @param array<array-key, mixed> $args 任务参数
     */
    public function run(callable $task, array $args = []): FutureInterface
    {
        return run($task, $args);
    }

    /**
     * 阻塞等待单个 Future 并返回结果。
     *
     * @param int $timeoutMs 超时毫秒数，<=0 表示无限等待
     *
     * @throws \Kode\Parallel\Exception\ParallelException 超时或任务失败
     */
    public function await(FutureInterface $future, int $timeoutMs = 0): mixed
    {
        return await($future, $timeoutMs);
    }

    /**
     * 并行映射：对集合每个元素执行 worker，按输入键序返回结果。
     *
     * @param iterable<array-key, mixed> $items
     * @param callable $worker 签名 fn(mixed $item, array-key $key): mixed
     *
     * @return array<array-key, mixed>
     */
    public function map(iterable $items, callable $worker, int $concurrency = 0): array
    {
        return map($items, $worker, $concurrency);
    }

    /**
     * 当前生效的引擎名（parallel / process / sync）。
     */
    public function engine(): string
    {
        return engine();
    }

    /**
     * CPU 逻辑核心数。
     */
    public function cpus(): int
    {
        return cpus();
    }
}
