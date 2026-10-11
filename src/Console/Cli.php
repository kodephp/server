<?php

declare(strict_types=1);

namespace Kode\Server\Console;

use Kode\Server\Config\ServerConfig;
use Kode\Server\Driver\DriverFactory;
use Kode\Server\Exception\ExceptionInterface;
use Kode\Server\Server;
use Kode\Server\Support\Bytes;
use Kode\Server\Support\Sys;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * `kode-server` 命令行入口的实现体。
 *
 * 之所以不依赖 kode/console：服务的启停脚本必须在依赖尽可能少的前提下可用——
 * 生产环境排障时，最不希望看到的就是"控制台组件加载失败导致服务停不掉"。
 *
 * 引导文件（默认按顺序探测 server.php / bootstrap/server.php / config/server.php）
 * 需要 return 下列任意一种：
 *  - Server 实例（完全自定义，CLI 只负责调用生命周期方法）
 *  - RequestHandlerInterface / callable（CLI 用命令行参数组装配置）
 *  - array{handler: callable|RequestHandlerInterface, config?: array}
 */
final class Cli
{
    private const BOOTSTRAP_CANDIDATES = [
        'server.php',
        'bootstrap/server.php',
        'config/server.php',
        'kode-server.php',
    ];

    /** @var array<string, string|bool> */
    private array $options = [];

    /** @var list<string> */
    private array $arguments = [];

    /**
     * @param list<string> $argv
     *
     * @return int 退出码
     */
    public function run(array $argv): int
    {
        $script = array_shift($argv) ?? 'kode-server';
        $this->parse($argv);

        $command = $this->arguments[0] ?? ($this->flag('version') ? 'version' : 'help');

        try {
            return match ($command) {
                'start' => $this->start(),
                'stop' => $this->stop(),
                'restart' => $this->restart(),
                'reload' => $this->reload(),
                'status' => $this->status(),
                'info' => $this->info(),
                'version', '--version' => $this->version(),
                'help', '--help' => $this->help(basename($script)),
                default => $this->unknown($command, basename($script)),
            };
        } catch (ExceptionInterface $e) {
            // 包内异常都是"可预期的用户错误"，只打信息，不用栈把终端刷满
            $this->error($e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            if ($this->flag('verbose') || $this->flag('v')) {
                $this->line($e->getTraceAsString());
            } else {
                $this->line('  （加 --verbose 查看完整调用栈）');
            }

            return 1;
        }
    }

    // ------------------------------------------------------------------
    // 命令
    // ------------------------------------------------------------------

    private function start(): int
    {
        $server = $this->resolveServer();

        if ($server->isRunning()) {
            $this->error(sprintf('服务已在运行中（master pid=%d）。', (int) $server->pid()));

            return 1;
        }

        $config = $server->config();
        $this->line(sprintf(
            "\033[32m%s\033[0m %s  驱动=%s  workers=%d%s",
            $config->name,
            $config->address(),
            $server->driverName(),
            $config->workerCount(),
            $config->daemon ? '  [daemon]' : '',
        ));

        if (!$config->daemon) {
            $this->line('  按 Ctrl+C 停止服务');
        }

        return $server->start();
    }

    private function stop(): int
    {
        $server = $this->resolveServer(requireHandler: false);
        $graceful = !$this->flag('force');

        if (!$server->stop($graceful)) {
            $this->line('服务未在运行。');

            return 0;
        }

        $this->line(sprintf("\033[32m✓\033[0m 服务已停止（%s）。", $graceful ? '优雅' : '强制'));

        return 0;
    }

    private function restart(): int
    {
        $server = $this->resolveServer();

        if ($server->stop(!$this->flag('force'))) {
            $this->line('旧实例已停止，正在启动新实例…');
        }

        return $this->start();
    }

    private function reload(): int
    {
        $server = $this->resolveServer(requireHandler: false);

        if (!$server->reload()) {
            $this->error('服务未在运行，无法重载。');

            return 1;
        }

        $this->line("\033[32m✓\033[0m 已发送重载信号，Worker 将被逐个替换。");

        return 0;
    }

    private function status(): int
    {
        $server = $this->resolveServer(requireHandler: false);
        $status = $server->status();

        if ($status === null) {
            $this->line("\033[33m●\033[0m 服务未运行");

            return 1;
        }

        if ($this->flag('json')) {
            $this->line((string) json_encode($status, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->line("\033[32m●\033[0m 运行中");
        $this->kv('名称', (string) ($status['name'] ?? '-'));
        $this->kv('监听', (string) ($status['address'] ?? '-'));
        $this->kv('驱动', (string) ($status['driver'] ?? '-'));
        $this->kv('Master', (string) ($status['master_pid'] ?? '-'));
        $this->kv('启动于', (string) ($status['started_at'] ?? '-'));
        $this->kv('运行时长', $this->duration((float) ($status['uptime'] ?? 0)));
        $this->kv('内存', Bytes::format((int) ($status['memory'] ?? 0)));

        /** @var list<array<string, mixed>> $workers */
        $workers = \is_array($status['workers'] ?? null) ? $status['workers'] : [];
        $this->kv('Worker', (string) \count($workers));

        foreach ($workers as $worker) {
            $this->line(sprintf(
                '    #%-3s pid=%-7s 代次=%-3s 运行=%s',
                (string) ($worker['id'] ?? '?'),
                (string) ($worker['pid'] ?? '?'),
                (string) ($worker['generation'] ?? '-'),
                $this->duration((float) ($worker['uptime'] ?? 0)),
            ));
        }

        return 0;
    }

    private function info(): int
    {
        $this->line("\033[1m运行环境\033[0m");
        $this->kv('PHP', \PHP_VERSION);
        $this->kv('SAPI', \PHP_SAPI);
        $this->kv('CPU 核心', (string) Sys::cpuCount());
        $this->kv('pcntl', Sys::hasPcntl() ? '✓' : '✗');
        $this->kv('posix', Sys::hasPosix() ? '✓' : '✗');
        $this->kv('swoole', Sys::hasSwoole() ? '✓' : '✗');
        $this->kv('sockets', \extension_loaded('sockets') ? '✓' : '✗');

        $this->line('');
        $this->line("\033[1m可用驱动\033[0m");
        foreach (DriverFactory::availability() as $name => $ok) {
            $this->kv($name, $ok ? '✓ 可用' : '✗ 不可用');
        }
        $this->kv('auto 将选择', DriverFactory::resolve(\Kode\Server\Enum\DriverType::Auto));

        $bootstrap = $this->findBootstrap();
        $this->line('');
        $this->line("\033[1m引导文件\033[0m");
        $this->kv('路径', $bootstrap ?? '未找到（可用 --bootstrap= 指定）');

        return 0;
    }

    private function version(): int
    {
        $this->line('kode/server ' . $this->packageVersion());

        return 0;
    }

    private function help(string $script): int
    {
        $this->line(<<<TXT
            \033[1mkode/server\033[0m — HTTP 服务生命周期管理

            \033[1m用法\033[0m
              {$script} <命令> [选项]

            \033[1m命令\033[0m
              start            启动服务（默认前台运行）
              stop             停止服务，等待进程真正退出
              restart          停止后重新启动
              reload           滚动重载 Worker，服务不中断（发布新代码用这个）
              status           查看运行状态
              info             诊断当前环境与可用驱动
              version          显示版本
              help             显示本帮助

            \033[1m选项\033[0m
              --bootstrap=FILE 引导文件路径，需 return Server 实例或请求处理器
                               默认依次探测 server.php / bootstrap/server.php / config/server.php
              --host=HOST      监听地址，也可写 unix:/path/to.sock
              --port=PORT      监听端口
              --workers=N      Worker 进程数，0 表示按 CPU 核心数
              --driver=NAME    auto | stream | swoole | single
              --watch[=DIRS]   开启热重载，多个目录用逗号分隔（开发环境用）
              --pid-file=FILE  PID 文件路径
              --log-file=FILE  日志文件路径
              --log-level=LVL  debug|info|notice|warning|error|critical
              -d, --daemon     以守护进程方式运行
              --force          stop/restart 时跳过优雅期，直接强杀
              --json           status 以 JSON 输出
              --debug          错误响应中包含异常栈（切勿用于生产）
              -v, --verbose    出错时打印完整调用栈

            \033[1m示例\033[0m
              {$script} start --port=8080 --workers=4
              {$script} start --watch=src,config          # 开发模式，改代码自动重载
              {$script} start -d --log-file=/var/log/app.log
              {$script} reload                            # 平滑发布
            TXT);

        return 0;
    }

    private function unknown(string $command, string $script): int
    {
        $this->error(sprintf('未知命令 "%s"。', $command));
        $this->line(sprintf('运行 `%s help` 查看可用命令。', $script));

        return 1;
    }

    // ------------------------------------------------------------------
    // 引导
    // ------------------------------------------------------------------

    private function resolveServer(bool $requireHandler = true): Server
    {
        $file = $this->findBootstrap();

        if ($file === null) {
            if (!$requireHandler) {
                // stop/status 只需要配置就能定位 PID 文件
                return new Server($this->cliConfig());
            }

            throw new \RuntimeException(
                '未找到引导文件。请创建 server.php，例如：' . \PHP_EOL . \PHP_EOL
                . "    <?php" . \PHP_EOL
                . "    require __DIR__ . '/vendor/autoload.php';" . \PHP_EOL
                . "    return fn (\$request) => 'hello';" . \PHP_EOL . \PHP_EOL
                . '或用 --bootstrap=/path/to/file.php 指定。',
            );
        }

        /** @var mixed $loaded */
        $loaded = require $file;

        if ($loaded instanceof Server) {
            return $loaded;
        }

        $overrides = $this->cliConfig();

        if (\is_array($loaded)) {
            /** @var array<string, mixed> $loaded */
            $handler = $loaded['handler'] ?? null;
            /** @var array<string, mixed> $base */
            $base = \is_array($loaded['config'] ?? null) ? $loaded['config'] : [];
            $overrides = array_merge($base, $overrides);
        } else {
            $handler = $loaded;
        }

        if (!\is_callable($handler) && !$handler instanceof RequestHandlerInterface) {
            throw new \RuntimeException(sprintf(
                '引导文件 "%s" 必须 return Server 实例、PSR-15 处理器、callable，'
                . '或 ["handler" => ..., "config" => [...]]，实际得到 %s。',
                $file,
                get_debug_type($loaded),
            ));
        }

        $server = new Server(ServerConfig::fromArray($overrides));
        $server->onRequest($handler);

        if ($this->flag('debug')) {
            $server->debug();
        }

        return $server;
    }

    /**
     * 把命令行选项翻译成配置数组。
     *
     * @return array<string, mixed>
     */
    private function cliConfig(): array
    {
        $config = [];

        foreach (['host', 'port', 'workers', 'driver', 'name', 'user', 'group'] as $key) {
            $value = $this->option($key);
            if ($value !== null) {
                $config[$key] = \in_array($key, ['port', 'workers'], true) ? (int) $value : $value;
            }
        }

        foreach (['pid-file' => 'pid_file', 'status-file' => 'status_file', 'log-file' => 'log_file', 'log-level' => 'log_level'] as $opt => $key) {
            $value = $this->option($opt);
            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        if ($this->flag('daemon') || $this->flag('d')) {
            $config['daemon'] = true;
        }

        $watch = $this->options['watch'] ?? null;
        if ($watch !== null) {
            $dirs = \is_string($watch) && $watch !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $watch))))
                : [getcwd() !== false ? getcwd() : '.'];
            $config['watch'] = ['enabled' => true, 'dirs' => $dirs];
        }

        return $config;
    }

    private function findBootstrap(): ?string
    {
        $explicit = $this->option('bootstrap');
        if ($explicit !== null) {
            $path = realpath($explicit);
            if ($path === false || !is_file($path)) {
                throw new \RuntimeException(sprintf('引导文件不存在："%s"。', $explicit));
            }

            return $path;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }

        foreach (self::BOOTSTRAP_CANDIDATES as $candidate) {
            $path = $cwd . \DIRECTORY_SEPARATOR . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // 参数解析与输出
    // ------------------------------------------------------------------

    /**
     * @param list<string> $argv
     */
    private function parse(array $argv): void
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);
                if ($body === '') {
                    continue;
                }
                $pos = strpos($body, '=');
                if ($pos === false) {
                    $this->options[$body] = true;
                } else {
                    $this->options[substr($body, 0, $pos)] = substr($body, $pos + 1);
                }

                continue;
            }

            if (str_starts_with($arg, '-') && \strlen($arg) > 1) {
                foreach (str_split(substr($arg, 1)) as $short) {
                    $this->options[$short] = true;
                }

                continue;
            }

            $this->arguments[] = $arg;
        }
    }

    private function option(string $key): ?string
    {
        $value = $this->options[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function flag(string $key): bool
    {
        $value = $this->options[$key] ?? false;

        return $value === true || $value === '1' || $value === 'true';
    }

    private function packageVersion(): string
    {
        $file = \dirname(__DIR__, 2) . '/composer.json';
        if (!is_file($file)) {
            return 'dev';
        }
        /** @var array<string, mixed>|null $json */
        $json = json_decode((string) file_get_contents($file), true);

        return \is_array($json) && \is_string($json['version'] ?? null) ? $json['version'] : 'dev';
    }

    private function duration(float $seconds): string
    {
        $seconds = (int) $seconds;
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return sprintf('%dm%ds', intdiv($seconds, 60), $seconds % 60);
        }
        if ($seconds < 86400) {
            return sprintf('%dh%dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return sprintf('%dd%dh', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }

    private function kv(string $key, string $value): void
    {
        // 中文是双宽字符，用字节数或字符数补齐都会错位，必须按显示宽度算
        $width = \function_exists('mb_strwidth') ? mb_strwidth($key, 'UTF-8') : \strlen($key);
        $padding = str_repeat(' ', max(1, 13 - $width));

        $this->line(sprintf("  \033[2m%s\033[0m%s%s", $key, $padding, $value));
    }

    private function line(string $message): void
    {
        fwrite(\STDOUT, $this->paint($message) . \PHP_EOL);
    }

    private function error(string $message): void
    {
        fwrite(\STDERR, $this->paint("\033[31m✗\033[0m " . $message) . \PHP_EOL);
    }

    /**
     * 输出被重定向到文件时剥掉颜色转义。
     */
    private function paint(string $message): string
    {
        static $supported = null;

        if ($supported === null) {
            $supported = \defined('STDOUT')
                && \stream_isatty(\STDOUT)
                && getenv('NO_COLOR') === false;
        }

        return $supported ? $message : (preg_replace('/\033\[[0-9;]*m/', '', $message) ?? $message);
    }
}
