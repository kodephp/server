<?php

declare(strict_types=1);

namespace Kode\Server\Contract;

/**
 * 协程驱动接口
 * 
 * 支持多种协程后端：kode/fibers、Swow
 */
interface CoroutineDriverInterface
{
    /**
     * 获取驱动名称
     */
    public function getName(): string;

    /**
     * 创建并运行协程
     * 
     * @param callable $callback 协程回调
     * @return mixed 协程返回值或协程ID
     */
    public function go(callable $callback): mixed;

    /**
     * 批量并发执行
     * 
     * @param array<array-key, mixed> $items 数据项
     * @param callable(mixed): mixed $callback 处理回调
     * @param int $concurrency 并发数
     * @return array<array-key, mixed> 结果数组
     */
    public function batch(array $items, callable $callback, int $concurrency = 10): array;

    /**
     * 协程睡眠
     * 
     * @param float $seconds 秒数
     */
    public function sleep(float $seconds): void;

    /**
     * 获取当前协程ID
     */
    public function getCurrentId(): int;

    /**
     * 检查是否在协程中
     */
    public function inCoroutine(): bool;

    /**
     * 创建通道
     * 
     * @param int $capacity 容量
     */
    public function createChannel(int $capacity = 0): ChannelInterface;

    /**
     * 创建等待组
     */
    public function createWaitGroup(): WaitGroupInterface;
}
