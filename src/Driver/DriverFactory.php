<?php

declare(strict_types=1);

namespace Kode\Server\Driver;

use Kode\Server\Config\ServerConfig;
use Kode\Server\Contract\DriverInterface;
use Kode\Server\Contract\WatcherInterface;
use Kode\Server\Enum\DriverType;
use Kode\Server\Event\Emitter;
use Kode\Server\Exception\DriverException;
use Kode\Server\Http\Dispatcher;
use Kode\Server\Support\Sys;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 驱动解析与实例化。
 *
 * `auto` 的选择原则是**可预测**优先于"最快"：
 *   1. 装了 Swoole 且没被显式关闭 → swoole（吞吐最好）
 *   2. 有 pcntl 的类 UNIX 环境    → stream（默认主力）
 *   3. 其余（Windows / 无 pcntl） → single（保证能跑起来）
 *
 * 显式指定驱动时**绝不静默降级**：用户写了 driver=swoole 却没装扩展，
 * 就该在启动时报错，而不是悄悄换成别的实现，让人上线后才发现性能对不上。
 */
final class DriverFactory
{
    /** @var array<string, class-string<DriverInterface>> */
    private const MAP = [
        'stream' => StreamDriver::class,
        'swoole' => SwooleDriver::class,
        'single' => SingleDriver::class,
    ];

    /** @var array<string, callable(ServerConfig, Dispatcher, Emitter, LoggerInterface, ?WatcherInterface): DriverInterface> */
    private static array $custom = [];

    /**
     * 注册自定义驱动（如 ReactPHP / AMPHP 适配）。
     *
     * @param callable(ServerConfig, Dispatcher, Emitter, LoggerInterface, ?WatcherInterface): DriverInterface $factory
     */
    public static function extend(string $name, callable $factory): void
    {
        self::$custom[strtolower($name)] = $factory;
    }

    public static function forget(string $name): void
    {
        unset(self::$custom[strtolower($name)]);
    }

    public static function create(
        ServerConfig $config,
        Dispatcher $dispatcher,
        Emitter $emitter,
        LoggerInterface $logger = new NullLogger(),
        ?WatcherInterface $watcher = null,
    ): DriverInterface {
        $name = self::resolve($config->driver);

        if (isset(self::$custom[$name])) {
            return (self::$custom[$name])($config, $dispatcher, $emitter, $logger, $watcher);
        }

        $class = self::MAP[$name] ?? throw DriverException::unavailable($name, '未注册该驱动。');

        if (!$class::isSupported()) {
            throw DriverException::unavailable($name, self::missingReason($name));
        }

        return new $class($config, $dispatcher, $emitter, $logger, $watcher);
    }

    /**
     * 把 auto 落实为具体驱动名。
     */
    public static function resolve(DriverType $type): string
    {
        if ($type !== DriverType::Auto) {
            return $type->value;
        }

        if (SwooleDriver::isSupported()) {
            return 'swoole';
        }
        if (StreamDriver::isSupported()) {
            return 'stream';
        }

        return 'single';
    }

    /**
     * 当前环境下每个驱动是否可用，供 CLI 诊断输出。
     *
     * @return array<string, bool>
     */
    public static function availability(): array
    {
        $result = [];
        foreach (self::MAP as $name => $class) {
            $result[$name] = $class::isSupported();
        }
        foreach (array_keys(self::$custom) as $name) {
            $result[$name] = true;
        }

        return $result;
    }

    private static function missingReason(string $name): string
    {
        return match ($name) {
            'swoole' => '需要 ext-swoole。安装：pecl install swoole，或改用 driver=stream。',
            'stream' => Sys::isWindows()
                ? 'Windows 不支持 fork，请使用 driver=single。'
                : '需要 ext-pcntl。安装扩展，或改用 driver=single（单进程模式）。',
            default => '当前环境不满足运行条件。',
        };
    }
}
