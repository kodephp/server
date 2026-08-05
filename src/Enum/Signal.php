<?php

declare(strict_types=1);

namespace Kode\Server\Enum;

/**
 * 本包使用到的信号语义（与 nginx / php-fpm 的习惯保持一致）。
 *
 * 之所以不直接散落 SIGxxx 常量：pcntl 未安装时这些常量不存在，
 * 集中在此处便于降级与单元测试。
 */
enum Signal: int
{
    case Hup = 1;    // 平滑重载 Worker
    case Int = 2;    // 优雅停止（Ctrl+C）
    case Quit = 3;   // 优雅停止
    case Kill = 9;   // 强制杀死
    case Usr1 = 10;  // 平滑重载 Worker（Linux 值，见 self::usr1()）
    case Usr2 = 12;  // 重新打开日志文件
    case Term = 15;  // 优雅停止
    case Chld = 17;  // 子进程状态变更（Linux 值，见 self::chld()）

    /**
     * USR1 / USR2 / CHLD 在 macOS 与 Linux 上取值不同，运行期以 pcntl 常量为准。
     */
    public static function usr1(): int
    {
        return defined('SIGUSR1') ? (int) constant('SIGUSR1') : self::Usr1->value;
    }

    public static function usr2(): int
    {
        return defined('SIGUSR2') ? (int) constant('SIGUSR2') : self::Usr2->value;
    }

    public static function chld(): int
    {
        return defined('SIGCHLD') ? (int) constant('SIGCHLD') : self::Chld->value;
    }

    public static function name(int $signal): string
    {
        return match ($signal) {
            self::Hup->value => 'SIGHUP',
            self::Int->value => 'SIGINT',
            self::Quit->value => 'SIGQUIT',
            self::Kill->value => 'SIGKILL',
            self::Term->value => 'SIGTERM',
            default => match (true) {
                $signal === self::usr1() => 'SIGUSR1',
                $signal === self::usr2() => 'SIGUSR2',
                $signal === self::chld() => 'SIGCHLD',
                default => 'SIG' . $signal,
            },
        };
    }
}
