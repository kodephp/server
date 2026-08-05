<?php

declare(strict_types=1);

namespace Kode\Server\Contract;

/**
 * 运行驱动：把"生命周期指令"翻译成具体运行时的动作。
 *
 * 驱动只关心怎么跑，不关心业务；请求最终都会走到同一个 PSR-15 处理器。
 */
interface DriverInterface
{
    /**
     * 驱动标识，如 stream / swoole / single。
     */
    public function name(): string;

    /**
     * 当前环境是否具备运行该驱动的条件。
     */
    public static function isSupported(): bool;

    /**
     * 启动服务，阻塞直到服务停止。
     */
    public function run(): void;

    /**
     * 在服务进程内部触发停止（信号处理器或业务代码调用）。
     */
    public function shutdown(bool $graceful = true): void;

    /**
     * 在服务进程内部触发 Worker 重载。
     */
    public function reloadWorkers(): void;

    /**
     * @return array<string, mixed> 运行期指标快照
     */
    public function stats(): array;
}
