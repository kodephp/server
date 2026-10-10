<?php

declare(strict_types=1);

namespace Kode\Server\Contract;

/**
 * 等待组接口
 */
interface WaitGroupInterface
{
    /**
     * 添加计数
     * 
     * @param int $delta 增量
     */
    public function add(int $delta = 1): void;

    /**
     * 完成计数
     */
    public function done(): void;

    /**
     * 等待所有完成
     * 
     * @param float $timeout 超时时间（秒），-1 表示无限等待
     * @return bool 是否成功
     */
    public function wait(float $timeout = -1): bool;

    /**
     * 获取当前计数
     */
    public function getCount(): int;
}
