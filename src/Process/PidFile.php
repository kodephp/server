<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Server\Exception\ProcessException;
use Kode\Server\Exception\StateException;
use Kode\Server\Support\Sys;

/**
 * PID 文件 + 排他锁。
 *
 * 只比对 PID 是不够的：进程刚退出、PID 被系统复用的窗口里会误判。
 * 这里用 flock 持有独占锁，锁随进程消亡自动释放，
 * "拿不到锁"才是"确实有实例在跑"的可靠证据。
 */
final class PidFile
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * 抢占 PID 文件；已有实例在运行时抛出 StateException。
     */
    public function acquire(int $pid): void
    {
        Sys::ensureDirFor($this->path);

        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new ProcessException(sprintf('无法打开 PID 文件 "%s"。', $this->path));
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            $existing = (int) trim((string) fread($handle, 32));
            fclose($handle);

            throw StateException::alreadyRunning($existing);
        }

        // 极端情况下锁可用但文件里残留着仍存活的 PID（例如手工 kill -9 了锁持有者）
        rewind($handle);
        $existing = (int) trim((string) fread($handle, 32));
        if ($existing > 0 && $existing !== $pid && Sys::processAlive($existing)) {
            flock($handle, \LOCK_UN);
            fclose($handle);

            throw StateException::alreadyRunning($existing);
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) $pid);
        fflush($handle);

        $this->handle = $handle;
    }

    /**
     * 守护化后 PID 变了，需要就地改写。
     */
    public function update(int $pid): void
    {
        if (!\is_resource($this->handle)) {
            $this->acquire($pid);

            return;
        }

        ftruncate($this->handle, 0);
        rewind($this->handle);
        fwrite($this->handle, (string) $pid);
        fflush($this->handle);
    }

    public function read(): ?int
    {
        if (!is_file($this->path)) {
            return null;
        }
        $content = @file_get_contents($this->path);
        if ($content === false) {
            return null;
        }
        $pid = (int) trim($content);

        return $pid > 0 ? $pid : null;
    }

    /**
     * 读取仍然存活的 master pid。
     */
    public function readAlive(): ?int
    {
        $pid = $this->read();

        return $pid !== null && Sys::processAlive($pid) ? $pid : null;
    }

    public function release(): void
    {
        if (\is_resource($this->handle)) {
            flock($this->handle, \LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * fork 出来的子进程不应该持有父进程的锁句柄。
     */
    public function detach(): void
    {
        if (\is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
