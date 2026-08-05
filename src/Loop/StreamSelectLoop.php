<?php

declare(strict_types=1);

namespace Kode\Server\Loop;

use Kode\Server\Contract\LoopInterface;

/**
 * 基于 stream_select 的单线程事件循环。
 *
 * 关键细节：
 *  - 用 hrtime() 计时，不受系统时钟回拨影响；
 *  - stream_select 被信号打断（EINTR）时返回 false，必须当作"正常唤醒"处理，
 *    否则收到 SIGTERM 的瞬间循环就崩了；
 *  - 每轮主动 dispatch 一次信号，保证即便宿主没开 async signals 也能及时响应。
 */
final class StreamSelectLoop implements LoopInterface
{
    /** @var array<int, resource> */
    private array $readStreams = [];

    /** @var array<int, callable> */
    private array $readListeners = [];

    /** @var array<int, resource> */
    private array $writeStreams = [];

    /** @var array<int, callable> */
    private array $writeListeners = [];

    /** @var array<string, array{float, ?float, callable}> id => [到期时刻, 周期(null 表示一次性), 回调] */
    private array $timers = [];

    /** @var list<callable> */
    private array $deferred = [];

    private bool $running = false;

    private int $timerSequence = 0;

    private readonly bool $hasPcntl;

    /** 无事可做时的最大阻塞时长（秒），保证信号与定时器不会被无限期挂起 */
    private const MAX_BLOCK = 0.2;

    public function __construct()
    {
        $this->hasPcntl = \function_exists('pcntl_signal_dispatch');
    }

    public function onReadable($stream, callable $listener): void
    {
        $key = (int) $stream;
        $this->readStreams[$key] = $stream;
        $this->readListeners[$key] = $listener;
    }

    public function offReadable($stream): void
    {
        $key = (int) $stream;
        unset($this->readStreams[$key], $this->readListeners[$key]);
    }

    public function onWritable($stream, callable $listener): void
    {
        $key = (int) $stream;
        $this->writeStreams[$key] = $stream;
        $this->writeListeners[$key] = $listener;
    }

    public function offWritable($stream): void
    {
        $key = (int) $stream;
        unset($this->writeStreams[$key], $this->writeListeners[$key]);
    }

    public function addTimer(float $delay, callable $callback): string
    {
        $id = 't' . (++$this->timerSequence);
        $this->timers[$id] = [$this->now() + max(0.0, $delay), null, $callback];

        return $id;
    }

    public function addPeriodicTimer(float $interval, callable $callback): string
    {
        $interval = max(0.001, $interval);
        $id = 'p' . (++$this->timerSequence);
        $this->timers[$id] = [$this->now() + $interval, $interval, $callback];

        return $id;
    }

    public function cancelTimer(string $timerId): void
    {
        unset($this->timers[$timerId]);
    }

    public function defer(callable $callback): void
    {
        $this->deferred[] = $callback;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            if ($this->hasPcntl) {
                pcntl_signal_dispatch();
            }

            $this->runDeferred();
            if (!$this->running) {
                break;
            }

            $timeout = $this->runTimers();
            if (!$this->running) {
                break;
            }

            $this->select($timeout);
        }

        $this->running = false;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * 单步执行，供测试与 Master 主循环复用。
     */
    public function tick(float $timeout = 0.0): void
    {
        if ($this->hasPcntl) {
            pcntl_signal_dispatch();
        }
        $this->runDeferred();
        $next = $this->runTimers();
        $this->select(min($timeout, $next));
    }

    private function runDeferred(): void
    {
        if ($this->deferred === []) {
            return;
        }
        $queue = $this->deferred;
        $this->deferred = [];
        foreach ($queue as $callback) {
            $callback();
        }
    }

    /**
     * 执行到期定时器，返回到下一个定时器的等待时长。
     */
    /**
     * @phpstan-impure
     */
    private function runTimers(): float
    {
        if ($this->timers === []) {
            return self::MAX_BLOCK;
        }

        $now = $this->now();
        $next = self::MAX_BLOCK;

        foreach ($this->timers as $id => [$deadline, $interval, $callback]) {
            if (!isset($this->timers[$id])) {
                continue; // 回调里被取消了
            }
            if ($deadline > $now) {
                $next = min($next, $deadline - $now);
                continue;
            }

            if ($interval === null) {
                unset($this->timers[$id]);
            } else {
                // 防止长时间阻塞后疯狂补偿，直接从当前时间重新计时
                $this->timers[$id] = [$now + $interval, $interval, $callback];
                $next = min($next, $interval);
            }

            $callback();

            if (!$this->running) {
                return 0.0;
            }
        }

        return max(0.0, $next);
    }

    private function select(float $timeout): void
    {
        $read = $this->readStreams;
        $write = $this->writeStreams;
        $except = [];

        if ($read === [] && $write === []) {
            if ($timeout > 0) {
                usleep((int) ($timeout * 1_000_000));
            }

            return;
        }

        $timeout = max(0.0, min($timeout, self::MAX_BLOCK));
        $sec = (int) $timeout;
        $usec = (int) (($timeout - $sec) * 1_000_000);

        // 信号打断时 stream_select 返回 false 并抛出 warning，这里主动压制
        $result = @stream_select($read, $write, $except, $sec, $usec);
        if ($result === false || $result === 0) {
            return;
        }

        foreach ($read as $stream) {
            $listener = $this->readListeners[(int) $stream] ?? null;
            if ($listener !== null) {
                $listener($stream);
            }
        }

        foreach ($write as $stream) {
            $listener = $this->writeListeners[(int) $stream] ?? null;
            if ($listener !== null) {
                $listener($stream);
            }
        }
    }

    private function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
