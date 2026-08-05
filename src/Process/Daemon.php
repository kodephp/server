<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Server\Exception\ProcessException;
use Kode\Server\Support\Sys;

/**
 * 守护化：脱离终端，成为独立会话的首进程。
 *
 * 两次 fork 的意义：第一次让父进程退出以便 shell 立刻返回；
 * setsid 之后再 fork 一次，保证新进程不是会话首进程，
 * 从而永远无法重新获得控制终端。
 */
final class Daemon
{
    /**
     * @return int 守护进程自身的 PID（父进程不会从这里返回，会直接退出）
     */
    public static function daemonize(?string $logFile = null): int
    {
        if (!Sys::hasPcntl() || !\function_exists('posix_setsid')) {
            throw new ProcessException('守护化需要 ext-pcntl 与 ext-posix 支持。');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new ProcessException('fork 失败，无法守护化。');
        }
        if ($pid > 0) {
            exit(0); // 父进程立刻退出，shell 拿回控制权
        }

        if (posix_setsid() === -1) {
            throw new ProcessException('posix_setsid 失败，无法脱离控制终端。');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new ProcessException('二次 fork 失败，无法守护化。');
        }
        if ($pid > 0) {
            exit(0);
        }

        umask(0o022);
        self::redirectStdio($logFile);

        return Sys::pid();
    }

    /**
     * 重定向标准流：没有日志文件就丢进 /dev/null，
     * 否则任何 echo / 未捕获的警告都会写进一个已经关闭的终端。
     */
    private static function redirectStdio(?string $logFile): void
    {
        $target = $logFile ?? '/dev/null';
        if ($logFile !== null) {
            Sys::ensureDirFor($logFile);
        }

        // 必须先关再开：新打开的流会占用刚刚空出来的最小 fd，
        // 也就是 0/1/2，这样连 C 层直接写 stdout 的扩展也会被一并重定向。
        foreach (['STDIN', 'STDOUT', 'STDERR'] as $constant) {
            if (\defined($constant) && \is_resource(\constant($constant))) {
                @fclose(\constant($constant));
            }
        }

        $in = @fopen('/dev/null', 'rb');
        $out = @fopen($target, 'ab');
        $err = @fopen($target, 'ab');

        if ($in === false || $out === false || $err === false) {
            throw new ProcessException('重定向标准输入输出失败。');
        }

        // 保持引用，避免被 GC 关闭
        $GLOBALS['__kode_server_stdin'] = $in;
        $GLOBALS['__kode_server_stdout'] = $out;
        $GLOBALS['__kode_server_stderr'] = $err;

        ini_set('display_errors', 'stderr');
        ini_set('error_log', $logFile ?? '');
    }
}
