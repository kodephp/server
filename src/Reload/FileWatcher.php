<?php

declare(strict_types=1);

namespace Kode\Server\Reload;

use FilesystemIterator;
use Kode\Server\Config\WatchOptions;
use Kode\Server\Contract\WatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * 轮询式文件变更监视器。
 *
 * 为什么用轮询而不是 inotify：
 *  - inotify 需要 ext-inotify，装机率低，且 macOS 上没有等价物；
 *  - 热重载只用于开发环境，1 秒的检测延迟完全可以接受；
 *  - 轮询实现不依赖任何扩展，行为在所有平台上一致。
 *
 * 三个必须做对的细节：
 *  1. **只在 Master 里跑**。Worker 数量是可变的，让它们各自扫盘是纯粹的浪费。
 *  2. **文件数熔断**。误配成扫描整个项目根目录（含 vendor）时，每秒 stat 十万个文件
 *     会把 Master 拖死——这比不做热重载严重得多，所以超限直接停掉监视并告警。
 *  3. **首次轮询只建基线**。启动瞬间不能因为"第一次看到这些文件"就触发一次重载。
 */
final class FileWatcher implements WatcherInterface
{
    /** 单次上报的变更文件数上限，避免日志被刷屏 */
    private const MAX_REPORT = 20;

    /** @var array<string, int> 文件路径 => mtime ^ size 指纹 */
    private array $baseline = [];

    private bool $primed = false;

    private bool $disabled = false;

    /** @var list<string> */
    private readonly array $dirs;

    /** @var list<string> 归一化后的小写扩展名 */
    private readonly array $extensions;

    public function __construct(
        private readonly WatchOptions $options,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $dirs = [];
        foreach ($this->options->dirs as $dir) {
            $real = realpath($dir);
            if ($real === false || !is_dir($real)) {
                $this->logger->warning('监视目录不存在，已跳过：{dir}', ['dir' => $dir]);

                continue;
            }
            $dirs[] = $real;
        }

        // 去掉被其它监视目录包含的子目录，避免同一文件被扫两遍
        $this->dirs = self::dedupe($dirs);

        $this->extensions = array_values(array_unique(array_map(
            static fn (string $ext): string => strtolower(ltrim($ext, '.')),
            $this->options->extensions,
        )));

        if ($this->dirs === []) {
            $this->disabled = true;
            $this->logger->warning('没有有效的监视目录，热重载未生效');
        }
    }

    #[\Override]
    public function interval(): float
    {
        return $this->options->interval;
    }

    /**
     * @return list<string> 发生变更的文件（相对可读路径），无变更返回空数组
     */
    #[\Override]
    public function poll(): array
    {
        if ($this->disabled) {
            return [];
        }

        $current = $this->scan();
        if ($current === null) {
            return []; // 已熔断
        }

        if (!$this->primed) {
            $this->baseline = $current;
            $this->primed = true;
            $this->logger->info('热重载已开启，监视 {n} 个文件：{dirs}', [
                'n' => \count($current),
                'dirs' => implode(', ', $this->dirs),
            ]);

            return [];
        }

        $changed = [];

        foreach ($current as $path => $fingerprint) {
            if (!isset($this->baseline[$path])) {
                $changed[] = $path;          // 新增
            } elseif ($this->baseline[$path] !== $fingerprint) {
                $changed[] = $path;          // 修改
            }
            if (\count($changed) >= self::MAX_REPORT) {
                break;
            }
        }

        if (\count($changed) < self::MAX_REPORT) {
            foreach ($this->baseline as $path => $_) {
                if (!isset($current[$path])) {
                    $changed[] = $path;      // 删除
                    if (\count($changed) >= self::MAX_REPORT) {
                        break;
                    }
                }
            }
        }

        $this->baseline = $current;

        return array_values(array_map($this->shorten(...), $changed));
    }

    /**
     * 重载完成后重建基线（重载过程中文件可能还在变）。
     */
    public function reset(): void
    {
        $this->primed = false;
        $this->baseline = [];
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->dirs;
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, int>|null null 表示已熔断
     */
    private function scan(): ?array
    {
        $result = [];
        $count = 0;

        foreach ($this->dirs as $dir) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator(
                            $dir,
                            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
                        ),
                        $this->accept(...),
                    ),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD,
                );

                /** @var SplFileInfo $file */
                foreach ($iterator as $file) {
                    if (!$file->isFile()) {
                        continue;
                    }

                    if (++$count > $this->options->maxFiles) {
                        $this->disabled = true;
                        $this->logger->error(
                            '监视文件数超过上限 {max}，已停止热重载。请把 watch_dirs 收窄到源码目录，或调大 watch_max_files。',
                            ['max' => $this->options->maxFiles],
                        );

                        return null;
                    }

                    // mtime 秒级精度在"1 秒内连改两次"时会漏判，叠加 size 能补上大部分场景
                    $result[$file->getPathname()] = ((int) $file->getMTime()) ^ ((int) $file->getSize() << 20);
                }
            } catch (Throwable $e) {
                // 扫描过程中目录被删是常见情况，记一笔继续，不要影响服务
                $this->logger->debug('扫描目录 {dir} 出错：{message}', [
                    'dir' => $dir,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function accept(SplFileInfo $file): bool
    {
        $name = $file->getFilename();

        if ($name !== '' && $name[0] === '.') {
            return false; // 忽略隐藏文件与 .git 之类的目录
        }

        if ($file->isDir()) {
            return !\in_array($name, $this->options->exclude, true);
        }

        if ($this->extensions === []) {
            return true;
        }

        return \in_array(strtolower($file->getExtension()), $this->extensions, true);
    }

    /**
     * 日志里只展示相对监视根的路径，长路径刷屏不利于阅读。
     */
    private function shorten(string $path): string
    {
        foreach ($this->dirs as $dir) {
            if (str_starts_with($path, $dir . \DIRECTORY_SEPARATOR)) {
                return substr($path, \strlen($dir) + 1);
            }
        }

        return $path;
    }

    /**
     * @param list<string> $dirs
     *
     * @return list<string>
     */
    private static function dedupe(array $dirs): array
    {
        sort($dirs);
        $kept = [];

        foreach ($dirs as $dir) {
            foreach ($kept as $parent) {
                if (str_starts_with($dir . \DIRECTORY_SEPARATOR, $parent . \DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }
            $kept[] = $dir;
        }

        return $kept;
    }
}
