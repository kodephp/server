<?php

declare(strict_types=1);

namespace Kode\Server\Tests\Unit\Coroutine;

use Fiber;
use Kode\Server\Coroutine\FiberCoroutineDriver;
use Kode\Server\Coroutine\FiberChannel;
use Kode\Server\Coroutine\FiberWaitGroup;
use PHPUnit\Framework\TestCase;

/**
 * 验证 kode/server 的协程驱动（组合 kode/fibers）及其 Channel / WaitGroup 原语。
 */
final class FiberCoroutineDriverTest extends TestCase
{
    private FiberCoroutineDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new FiberCoroutineDriver();
    }

    public function testGetName(): void
    {
        self::assertSame('kode/fibers', $this->driver->getName());
    }

    public function testGoReturnsValue(): void
    {
        $result = $this->driver->go(fn (): int => 2 + 3);

        self::assertSame(5, $result);
    }

    public function testBatchRunsItemsConcurrently(): void
    {
        $result = $this->driver->batch([1, 2, 3], fn (int $x): int => $x * 2, 2);

        self::assertSame([2, 4, 6], $result);
    }

    public function testInCoroutineIsFalseOutsideFiber(): void
    {
        self::assertFalse($this->driver->inCoroutine());
        self::assertSame(0, $this->driver->getCurrentId());
    }

    public function testInCoroutineIsTrueInsideFiber(): void
    {
        $observed = $this->driver->go(function (): array {
            return [
                'in' => $this->driver->inCoroutine(),
                'id' => $this->driver->getCurrentId(),
            ];
        });

        self::assertTrue($observed['in']);
        self::assertIsInt($observed['id']);
        self::assertGreaterThan(0, $observed['id']);
    }

    public function testSleepDoesNotThrow(): void
    {
        $start = microtime(true);
        $this->driver->sleep(0.01);

        self::assertGreaterThanOrEqual(0.01, microtime(true) - $start);
    }

    public function testChannelPushPop(): void
    {
        $channel = $this->driver->createChannel(4);

        self::assertInstanceOf(FiberChannel::class, $channel);
        self::assertSame(4, $channel->getCapacity());
        self::assertFalse($channel->isClosed());

        self::assertTrue($channel->push('hello'));
        self::assertSame(1, $channel->getLength());
        self::assertSame('hello', $channel->pop());
        self::assertSame(0, $channel->getLength());
    }

    public function testChannelClose(): void
    {
        $channel = $this->driver->createChannel(2);
        $channel->push('x');
        $channel->close();

        self::assertTrue($channel->isClosed());
        self::assertFalse($channel->push('y'));
        self::assertNull($channel->pop());
    }

    public function testWaitGroup(): void
    {
        $wg = $this->driver->createWaitGroup();

        self::assertInstanceOf(FiberWaitGroup::class, $wg);

        $wg->add(2);
        self::assertSame(2, $wg->getCount());

        $wg->done();
        $wg->done();
        self::assertSame(0, $wg->getCount());

        self::assertTrue($wg->wait());
    }

    public function testWaitGroupTimeout(): void
    {
        $wg = $this->driver->createWaitGroup();
        $wg->add(1);

        // 没有 done()，应在超时后返回 false
        self::assertFalse($wg->wait(0.05));
    }

    public function testWaitGroupRejectsNegativeDelta(): void
    {
        $wg = $this->driver->createWaitGroup();

        $this->expectException(\InvalidArgumentException::class);
        $wg->add(-1);
    }
}
