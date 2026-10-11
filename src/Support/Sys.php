<?php

declare(strict_types=1);

namespace Kode\Server\Support;

use Kode\Server\Exception\ProcessException;

/**
 * 操作系统能力探测与薄封装。
 *
 * 所有对 pcntl / posix 的依赖都收敛在这里：扩展缺失时降级而不是致命错误，
 * 使得同一份代码在开发机（可能没装 pcntl）与生产机上都能跑。
 */
final class Sys
{
    private static ?int $cpuCount = null;

    public static function hasPcntl(): bool
    {
        return \extension_loaded('pcntl') && \function_exists('pcntl_fork');
    }

    public static function hasPosix(): bool
    {
        return \extension_loaded('posix') && \function_exists('posix_kill');
    }

    public static function hasSwoole(): bool
    {
        return \extension_loaded('swoole') && class_exists('\Swoole\Http\Server');
    }

    public static function isWindows(): bool
    {
        return \DIRECTORY_SEPARATOR === '\\';
    }

    public static function pid(): int
    {
        return \function_exists('posix_getpid') ? posix_getpid() : (getmypid() ?: 0);
    }

    /**
     * 探测可用 CPU 核心数，用于 workers=0 时的自动推导。
     */
    public static function cpuCount(): int
    {
        if (self::$cpuCount !== null) {
            return self::$cpuCount;
        }

        $count = 0;
        if (\function_exists('swoole_cpu_num')) {
            $count = (int) \swoole_cpu_num();
        }

        if ($count < 1 && is_readable('/proc/cpuinfo')) {
            $content = (string) @file_get_contents('/proc/cpuinfo');
            $count = substr_count($content, "\nprocessor") + (str_starts_with($content, 'processor') ? 1 : 0);
        }

        if ($count < 1 && \function_exists('shell_exec') && !self::isWindows()) {
            $out = @shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null') ?: @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            $count = (int) trim((string) $out);
        }

        return self::$cpuCount = max(1, $count);
    }

    /**
     * 判断进程是否存活。posix 缺失时退化为 /proc 检查，再退化为 true（保守）。
     */
    public static function processAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            if (posix_kill($pid, 0)) {
                return true;
            }

            // errno == EPERM(1) 表示进程存在但不属于当前用户，同样视为存活
            return \function_exists('posix_get_last_error') && posix_get_last_error() === 1;
        }

        return is_dir('/proc/' . $pid);
    }

    public static function kill(int $pid, int $signal): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return posix_kill($pid, $signal);
        }
        if (\function_exists('pcntl_signal_dispatch') && \function_exists('exec')) {
            @exec(sprintf('kill -%d %d 2>/dev/null', $signal, $pid), $_, $code);

            return $code === 0;
        }

        return false;
    }

    /**
     * 设置进程标题，便于 ps / top 中辨认 master 与 worker。
     */
    public static function setProcessTitle(string $title): void
    {
        if (self::isWindows()) {
            return;
        }
        if (\function_exists('cli_set_process_title')) {
            // macOS 上无权限时会抛 Notice，这里静默处理
            @cli_set_process_title($title);
        }
    }

    /**
     * 进程降权：以 root 启动、以低权限用户运行 Worker。
     */
    public static function switchUser(?string $user, ?string $group): void
    {
        if (($user === null && $group === null) || !self::hasPosix()) {
            return;
        }
        if (posix_getuid() !== 0) {
            return; // 非 root 无需也无法降权
        }

        if ($group !== null) {
            $info = posix_getgrnam($group);
            if ($info === false) {
                throw new ProcessException(sprintf('用户组 "%s" 不存在，无法降权。', $group));
            }
            if (!posix_setgid((int) $info['gid'])) {
                throw new ProcessException(sprintf('切换用户组到 "%s" 失败。', $group));
            }
        }

        if ($user !== null) {
            $info = posix_getpwnam($user);
            if ($info === false) {
                throw new ProcessException(sprintf('用户 "%s" 不存在，无法降权。', $user));
            }
            if (\function_exists('posix_initgroups')) {
                $_ = @posix_initgroups($user, (int) $info['gid']);
            }
            if (!posix_setuid((int) $info['uid'])) {
                throw new ProcessException(sprintf('切换用户到 "%s" 失败。', $user));
            }
        }
    }

    /**
     * 当前进程已使用内存（字节）。
     */
    public static function memoryUsage(): int
    {
        return memory_get_usage(true);
    }

    public static function tempDir(?string $preferred = null): string
    {
        $dir = $preferred ?? sys_get_temp_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new ProcessException(sprintf('临时目录 "%s" 不存在且无法创建。', $dir));
        }
        if (!is_writable($dir)) {
            throw new ProcessException(sprintf('临时目录 "%s" 不可写。', $dir));
        }

        return rtrim($dir, \DIRECTORY_SEPARATOR);
    }

    /**
     * 确保目录存在且可写，返回规范化后的绝对路径。
     */
    public static function ensureDirFor(string $file): void
    {
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new ProcessException(sprintf('目录 "%s" 不存在且无法创建。', $dir));
        }
        if (!is_writable($dir)) {
            throw new ProcessException(sprintf('目录 "%s" 不可写。', $dir));
        }
    }
}
