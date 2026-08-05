<?php

declare(strict_types=1);

namespace Kode\Server\Contract;

/**
 * 极简事件循环：只保留 HTTP 服务真正需要的能力。
 *
 * 之所以自带而不依赖 ReactPHP/Amp：本包要做的是"进程与生命周期"，
 * 引入一整套异步框架会把依赖树和心智负担都拉大。需要更强的循环时，
 * 实现该接口注入即可。
 */
interface LoopInterface
{
    /**
     * @param resource $stream
     */
    public function onReadable($stream, callable $listener): void;

    /**
     * @param resource $stream
     */
    public function offReadable($stream): void;

    /**
     * @param resource $stream
     */
    public function onWritable($stream, callable $listener): void;

    /**
     * @param resource $stream
     */
    public function offWritable($stream): void;

    /**
     * @return string 定时器 ID，可用于取消
     */
    public function addTimer(float $delay, callable $callback): string;

    /**
     * @return string 定时器 ID
     */
    public function addPeriodicTimer(float $interval, callable $callback): string;

    public function cancelTimer(string $timerId): void;

    /**
     * 在下一轮循环开始时执行。
     */
    public function defer(callable $callback): void;

    /**
     * 运行循环直到 stop() 被调用且无待办任务。
     */
    public function run(): void;

    /**
     * 请求退出循环。
     */
    public function stop(): void;

    public function isRunning(): bool;
}
