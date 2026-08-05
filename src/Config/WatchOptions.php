<?php

declare(strict_types=1);

namespace Kode\Server\Config;

use Kode\Server\Exception\ConfigException;

/**
 * 热重载（文件变更监视）配置。
 *
 * 只在开发环境开启：Master 轮询目录指纹，一旦变化就滚动重启 Worker，
 * 生产环境请用 reload() / SIGUSR1 主动触发。
 */
final readonly class WatchOptions
{
    /**
     * @param list<string> $dirs       监视目录（绝对路径）
     * @param list<string> $extensions 关心的扩展名
     * @param list<string> $exclude    目录名黑名单（按路径片段匹配）
     * @param float        $interval   轮询间隔（秒）
     * @param int          $maxFiles   扫描文件数上限，超过则告警并停止监视，避免拖垮 Master
     */
    public function __construct(
        public bool $enabled = false,
        public array $dirs = [],
        public array $extensions = ['php'],
        public array $exclude = ['vendor', 'node_modules', '.git', '.idea', 'runtime', 'storage', 'cache'],
        public float $interval = 1.0,
        public int $maxFiles = 20000,
    ) {
        if ($this->interval <= 0) {
            throw ConfigException::of('watch.interval', $this->interval, '大于 0 的秒数');
        }
        if ($this->maxFiles <= 0) {
            throw ConfigException::of('watch.max_files', $this->maxFiles, '正整数');
        }
        foreach ($this->dirs as $dir) {
            if (!\is_string($dir) || $dir === '') {
                throw ConfigException::of('watch.dirs', $dir, '非空目录路径字符串');
            }
        }
        if ($this->enabled && $this->dirs === []) {
            throw new ConfigException('开启热重载时必须指定 watch_dirs。');
        }
    }

    /**
     * 支持三种写法：
     *   'watch' => true                       // 需另行给出 watch_dirs
     *   'watch' => ['src', 'config']          // 目录列表，隐式开启
     *   'watch' => ['enabled' => true, ...]   // 完整配置
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        /** @var array<string, mixed> $options */
        return new self(...$options);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(self::class, '__construct'))->getParameters(),
        );
    }
}
