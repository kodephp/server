<?php

declare(strict_types=1);

namespace Kode\Server\Tests\Unit\Parallel;

use Kode\Server\Parallel\Parallel;
use PHPUnit\Framework\TestCase;

/**
 * 验证 kode/server 的并行卸载门面（组合 kode/parallel）。
 *
 * 本环境下 ext-parallel 大概率不可用，引擎会降级到 process / sync，
 * 但 run()/await()/map() 都应正常返回结果。
 */
final class ParallelTest extends TestCase
{
    private Parallel $parallel;

    protected function setUp(): void
    {
        $this->parallel = new Parallel();
    }

    public function testEngineIsReported(): void
    {
        self::assertContains($this->parallel->engine(), ['parallel', 'process', 'sync']);
        self::assertGreaterThan(0, $this->parallel->cpus());
    }

    public function testRunAwait(): void
    {
        $future = $this->parallel->run(
            static fn (array $args): int => $args['a'] + $args['b'],
            ['a' => 2, 'b' => 3],
        );

        self::assertSame(5, $this->parallel->await($future));
    }

    public function testMap(): void
    {
        $result = $this->parallel->map([1, 2, 3], static fn (int $x): int => $x * 10, 2);

        self::assertSame([10, 20, 30], $result);
    }
}
