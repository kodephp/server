<?php

declare(strict_types=1);

namespace Kode\Server\Contract;

/**
 * 文件变更监视器：热重载的触发源。
 */
interface WatcherInterface
{
    /**
     * 检查是否发生变更；返回变更的文件列表（可能被截断），无变更时返回空数组。
     *
     * @return list<string>
     */
    public function poll(): array;

    /**
     * 轮询间隔（秒）。
     */
    public function interval(): float;
}
