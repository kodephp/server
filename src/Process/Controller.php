<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Process\Signal;
use Kode\Server\Config\ServerConfig;
use Kode\Server\Enum\State;
use Kode\Server\Support\Sys;

/**
 * 带外控制面：从**另一个进程**操作正在运行的服务。
 *
 * `kode-server stop` 和 `$server->stop()`（非服务进程内调用）都走这里。
 * 唯一的通信手段是 PID 文件 + 信号——没有额外端口、没有 socket 文件，
 * 少一个组件就少一类故障。
 */
final class Controller
{
    private readonly PidFile $pidFile;

    private readonly Status $statusFile;

    public function __construct(private readonly ServerConfig $config)
    {
        $this->pidFile = new PidFile($config->pidFilePath());
        $this->statusFile = new Status($config->statusFilePath());
    }

    /**
     * 正在运行的 Master PID；没有运行返回 null（顺带清理陈旧 PID 文件）。
     */
    public function pid(): ?int
    {
        return $this->pidFile->readAlive();
    }

    public function isRunning(): bool
    {
        return $this->pid() !== null;
    }

    public function state(): State
    {
        return $this->isRunning() ? State::Running : State::Stopped;
    }

    /**
     * 停止服务并等待进程真正消失。
     *
     * 两段式：先 SIGTERM 等优雅收敛，超时后 SIGKILL 收尾。
     * "发完信号就返回"是不负责任的——调用方紧接着重启会撞上端口占用。
     *
     * @param float|null $timeout 等待时限（秒），null 表示按配置推导
     *
     * @return bool true=已停止；false=服务本来就没在跑
     */
    public function stop(bool $graceful = true, ?float $timeout = null): bool
    {
        $pid = $this->pid();
        if ($pid === null) {
            return false;
        }

        if (!$graceful) {
            Sys::kill($pid, Signal::KILL);
            $this->waitGone($pid, 3.0);
            $this->cleanup();

            return true;
        }

        $timeout ??= $this->config->gracefulTimeout + $this->config->stopTimeout + 2.0;

        Sys::kill($pid, Signal::TERM);

        if (!$this->waitGone($pid, $timeout)) {
            Sys::kill($pid, Signal::KILL);
            $this->waitGone($pid, 3.0);
        }

        $this->cleanup();

        return true;
    }

    /**
     * 触发滚动重载（SIGUSR1）。
     *
     * @return bool false 表示服务未运行
     */
    public function reload(): bool
    {
        $pid = $this->pid();
        if ($pid === null) {
            return false;
        }

        return Sys::kill($pid, Signal::USR1);
    }

    /**
     * 通知服务重新打开日志文件（SIGUSR2），配合 logrotate 使用。
     */
    public function reopenLogs(): bool
    {
        $pid = $this->pid();

        return $pid !== null && Sys::kill($pid, Signal::USR2);
    }

    /**
     * 读取状态快照文件。
     *
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        if (!$this->isRunning()) {
            return null;
        }

        return $this->statusFile->read();
    }

    /**
     * 停止后重新启动需要调用方自己拉起新进程——这里只保证"旧的已经彻底没了"。
     */
    private function waitGone(int $pid, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (!Sys::processAlive($pid)) {
                return true;
            }
            usleep(50_000);
        }

        return !Sys::processAlive($pid);
    }

    private function cleanup(): void
    {
        // 被 SIGKILL 打死的进程来不及自清理，这里补一刀，避免下次启动误报"已在运行"
        $this->pidFile->release();
        $this->statusFile->remove();
    }
}
