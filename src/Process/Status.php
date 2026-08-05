<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Server\Support\Sys;

/**
 * 运行状态快照文件。
 *
 * Master 定期把自己的视图写成 JSON，`kode-server status` 直接读文件，
 * 不需要额外开管理端口，也不会打扰正在服务的进程。
 */
final class Status
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function write(array $data): void
    {
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $temp = $this->path . '.tmp';
        if (@file_put_contents($temp, $json, \LOCK_EX) !== false) {
            @rename($temp, $this->path); // 原子替换，避免读到写了一半的文件
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $content = @file_get_contents($this->path);
        if ($content === false || !json_validate($content)) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true);

        return \is_array($data) ? $data : null;
    }

    public function remove(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        if (is_file($this->path . '.tmp')) {
            @unlink($this->path . '.tmp');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $workers
     *
     * @return array<string, mixed>
     */
    public static function snapshot(
        string $name,
        string $address,
        string $driver,
        float $startedAt,
        array $workers,
    ): array {
        return [
            'name' => $name,
            'address' => $address,
            'driver' => $driver,
            'master_pid' => Sys::pid(),
            'started_at' => date('c', (int) $startedAt),
            'uptime' => round(microtime(true) - $startedAt, 1),
            'worker_count' => \count($workers),
            'workers' => array_values($workers),
            'memory' => Sys::memoryUsage(),
            'updated_at' => date('c'),
        ];
    }
}
