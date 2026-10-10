<?php

declare(strict_types=1);

namespace Kode\Server\Contract;

/**
 * 通道接口
 */
interface ChannelInterface
{
    /**
     * 推送数据
     * 
     * @param mixed $data 数据
     * @param float $timeout 超时时间（秒）
     * @return bool 是否成功
     */
    public function push(mixed $data, float $timeout = -1): bool;

    /**
     * 弹出数据
     * 
     * @param float $timeout 超时时间（秒）
     * @return mixed 数据或 null
     */
    public function pop(float $timeout = -1): mixed;

    /**
     * 关闭通道
     */
    public function close(): void;

    /**
     * 获取容量
     */
    public function getCapacity(): int;

    /**
     * 获取当前长度
     */
    public function getLength(): int;

    /**
     * 是否已关闭
     */
    public function isClosed(): bool;
}
