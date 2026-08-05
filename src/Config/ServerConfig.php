<?php

declare(strict_types=1);

namespace Kode\Server\Config;

use Kode\Server\Enum\DriverType;
use Kode\Server\Exception\ConfigException;
use Kode\Server\Support\Bytes;
use Kode\Server\Support\Sys;

/**
 * 服务配置（不可变值对象）。
 *
 * 设计原则：**一切非法配置在 start() 之前抛出**。
 * 进程一旦 fork，配置错误的排查成本会成倍上升，所以宁可在这里啰嗦一点。
 *
 * 支持扁平数组构造，键名 snake_case / camelCase 皆可：
 *
 *   ServerConfig::fromArray([
 *       'host' => '0.0.0.0',
 *       'port' => 9501,
 *       'workers' => 4,
 *       'max_body_size' => '16M',   // 自动归入 limits
 *       'watch_dirs' => [__DIR__],  // 自动归入 watch
 *   ]);
 */
final readonly class ServerConfig
{
    /**
     * @param string      $host             监听地址；也可写 "unix:/path/to.sock" 走 UNIX 域套接字
     * @param int         $port             监听端口；UNIX 套接字时忽略
     * @param DriverType  $driver           运行驱动
     * @param int         $workers          Worker 进程数，0 表示按 CPU 核心数自动推导
     * @param int         $backlog          listen backlog
     * @param bool        $reusePort        每个 Worker 独立监听（SO_REUSEPORT），由内核做负载均衡
     * @param bool        $tcpNoDelay       关闭 Nagle 算法，降低小包延迟
     * @param string      $name             进程名前缀，同时用于默认 PID 文件名
     * @param int         $maxRequests      Worker 处理多少请求后自愈重启，0 表示不限
     * @param float       $maxRequestsJitter 重启阈值抖动比例，避免所有 Worker 同时退出
     * @param int         $memoryLimit      Worker 内存超过该值后自愈重启，0 表示不限
     * @param float       $gracefulTimeout  优雅退出时等待在途请求的时限（秒）
     * @param float       $stopTimeout      优雅期结束后到 SIGKILL 的宽限时间（秒）
     * @param array<string, mixed> $swoole  透传给 Swoole\Server::set() 的额外配置
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 9501,
        public DriverType $driver = DriverType::Auto,
        public int $workers = 0,
        public int $backlog = 1024,
        public bool $reusePort = false,
        public bool $tcpNoDelay = true,
        public string $name = 'kode-server',
        public ?string $user = null,
        public ?string $group = null,
        public bool $daemon = false,
        public ?string $pidFile = null,
        public ?string $statusFile = null,
        public ?string $logFile = null,
        public string $logLevel = 'info',
        public int $maxRequests = 0,
        public float $maxRequestsJitter = 0.1,
        public int $memoryLimit = 0,
        public float $gracefulTimeout = 30.0,
        public float $stopTimeout = 5.0,
        public Limits $limits = new Limits(),
        public WatchOptions $watch = new WatchOptions(),
        public array $swoole = [],
    ) {
        $this->validate();
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $own = [];
        $limits = [];
        $watch = [];

        $ownKeys = self::keys();
        $limitKeys = Limits::keys();
        $watchKeys = WatchOptions::keys();

        foreach ($options as $rawKey => $value) {
            $key = self::camel((string) $rawKey);

            // 显式分组写法
            if ($key === 'limits') {
                if (!\is_array($value)) {
                    throw ConfigException::of('limits', $value, '数组');
                }
                foreach ($value as $k => $v) {
                    $limits[self::camel((string) $k)] = $v;
                }
                continue;
            }
            if ($key === 'watch') {
                $watch = array_merge($watch, self::normalizeWatch($value));
                continue;
            }
            if ($key === 'swoole') {
                if (!\is_array($value)) {
                    throw ConfigException::of('swoole', $value, '数组');
                }
                $own['swoole'] = $value;
                continue;
            }

            // watch_* 前缀 → WatchOptions
            if (str_starts_with($key, 'watch') && \strlen($key) > 5) {
                $watchKey = lcfirst(substr($key, 5));
                if (\in_array($watchKey, $watchKeys, true)) {
                    $watch[$watchKey] = $value;
                    continue;
                }
            }

            if (\in_array($key, $ownKeys, true)) {
                $own[$key] = $value;
                continue;
            }
            if (\in_array($key, $limitKeys, true)) {
                $limits[$key] = $value;
                continue;
            }

            throw ConfigException::unknown(
                (string) $rawKey,
                [...$ownKeys, ...$limitKeys, ...array_map(static fn (string $k): string => 'watch' . ucfirst($k), $watchKeys)],
            );
        }

        if ($limits !== []) {
            $own['limits'] = Limits::fromArray($limits);
        }
        if ($watch !== []) {
            $own['watch'] = WatchOptions::fromArray($watch);
        }
        if (isset($own['driver']) && !$own['driver'] instanceof DriverType) {
            $value = $own['driver'];
            $own['driver'] = DriverType::tryFrom(strtolower((string) $value))
                ?? throw ConfigException::of('driver', $value, 'auto|stream|swoole|single');
        }
        if (isset($own['memoryLimit'])) {
            /** @var int|string $raw */
            $raw = $own['memoryLimit'];
            $own['memoryLimit'] = Bytes::parse($raw, 'memory_limit');
        }

        /** @var array<string, mixed> $own */
        return new self(...$own);
    }

    /**
     * 派生一个只改动少量字段的副本。
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $base = [];
        foreach (self::keys() as $key) {
            $base[$key] = $this->{$key};
        }

        foreach ($changes as $rawKey => $value) {
            $key = self::camel((string) $rawKey);
            if (!\array_key_exists($key, $base)) {
                throw ConfigException::unknown((string) $rawKey, self::keys());
            }
            $base[$key] = $value;
        }

        /** @var array<string, mixed> $base */
        return new self(...$base);
    }

    /**
     * 供 stream_socket_server 使用的监听地址。
     */
    public function address(): string
    {
        if ($this->isUnixSocket()) {
            return 'unix://' . $this->socketPath();
        }

        $host = str_contains($this->host, ':') && !str_starts_with($this->host, '[')
            ? '[' . $this->host . ']'   // IPv6
            : $this->host;

        return sprintf('tcp://%s:%d', $host, $this->port);
    }

    public function isUnixSocket(): bool
    {
        return str_starts_with($this->host, 'unix:');
    }

    public function socketPath(): string
    {
        $path = ltrim(substr($this->host, 5), '/');
        if ($path === '') {
            throw new ConfigException('UNIX 套接字路径为空，正确写法如 "unix:/var/run/app.sock"。');
        }

        return '/' . $path;
    }

    /**
     * 实际生效的 Worker 数量。
     */
    public function workerCount(): int
    {
        if ($this->driver === DriverType::Single) {
            return 1;
        }
        if ($this->workers > 0) {
            return $this->workers;
        }

        return Sys::cpuCount();
    }

    public function pidFilePath(): string
    {
        return $this->pidFile ?? sprintf(
            '%s/%s-%s.pid',
            sys_get_temp_dir(),
            preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->name) ?? 'kode-server',
            $this->isUnixSocket() ? substr(md5($this->host), 0, 8) : (string) $this->port,
        );
    }

    public function statusFilePath(): string
    {
        return $this->statusFile ?? preg_replace('/\.pid$/', '.status', $this->pidFilePath()) ?? $this->pidFilePath() . '.status';
    }

    /**
     * 单个 Worker 的实际请求数上限（叠加抖动）。
     */
    public function maxRequestsFor(int $workerId): int
    {
        if ($this->maxRequests <= 0) {
            return 0;
        }
        if ($this->maxRequestsJitter <= 0.0) {
            return $this->maxRequests;
        }

        $delta = (int) round($this->maxRequests * $this->maxRequestsJitter);

        return max(1, $this->maxRequests - $delta + ($delta > 0 ? ($workerId * 977) % (2 * $delta + 1) : 0));
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(self::class, '__construct'))->getParameters(),
        );
    }

    private function validate(): void
    {
        if ($this->host === '') {
            throw ConfigException::of('host', $this->host, '非空监听地址');
        }
        if (!$this->isUnixSocket() && ($this->port < 1 || $this->port > 65535)) {
            throw ConfigException::of('port', $this->port, '1-65535 之间的端口号');
        }
        if ($this->workers < 0) {
            throw ConfigException::of('workers', $this->workers, '非负整数（0 表示按 CPU 核心数）');
        }
        if ($this->backlog < 1) {
            throw ConfigException::of('backlog', $this->backlog, '正整数');
        }
        if ($this->name === '' || !preg_match('/^[\w.\- ]+$/u', $this->name)) {
            throw ConfigException::of('name', $this->name, '非空且不含特殊字符的进程名');
        }
        if ($this->maxRequests < 0) {
            throw ConfigException::of('max_requests', $this->maxRequests, '非负整数');
        }
        if ($this->maxRequestsJitter < 0 || $this->maxRequestsJitter >= 1) {
            throw ConfigException::of('max_requests_jitter', $this->maxRequestsJitter, '[0, 1) 之间的小数');
        }
        if ($this->memoryLimit < 0) {
            throw ConfigException::of('memory_limit', $this->memoryLimit, '非负字节数');
        }
        if ($this->gracefulTimeout <= 0) {
            throw ConfigException::of('graceful_timeout', $this->gracefulTimeout, '大于 0 的秒数');
        }
        if ($this->stopTimeout < 0) {
            throw ConfigException::of('stop_timeout', $this->stopTimeout, '非负秒数');
        }
        if (!\in_array(strtolower($this->logLevel), ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            throw ConfigException::of('log_level', $this->logLevel, 'PSR-3 日志级别');
        }
        if ($this->daemon && !Sys::hasPcntl()) {
            throw new ConfigException('daemon=true 需要 ext-pcntl 支持。');
        }
        if ($this->reusePort && $this->isUnixSocket()) {
            throw new ConfigException('UNIX 套接字不支持 SO_REUSEPORT。');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeWatch(mixed $value): array
    {
        if (\is_bool($value)) {
            return ['enabled' => $value];
        }
        if (\is_string($value)) {
            return ['enabled' => true, 'dirs' => [$value]];
        }
        if (!\is_array($value)) {
            throw ConfigException::of('watch', $value, 'bool | 目录列表 | 配置数组');
        }
        // 纯列表 → 视作目录清单
        if ($value !== [] && array_is_list($value)) {
            return ['enabled' => true, 'dirs' => $value];
        }

        $out = [];
        foreach ($value as $k => $v) {
            $out[self::camel((string) $k)] = $v;
        }
        if (!isset($out['enabled']) && isset($out['dirs'])) {
            $out['enabled'] = true;
        }

        return $out;
    }

    private static function camel(string $key): string
    {
        if (!str_contains($key, '_') && !str_contains($key, '-') && !str_contains($key, '.')) {
            return $key;
        }

        return lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-', '.'], ' ', $key))));
    }
}
