<?php

declare(strict_types=1);

namespace Kode\Server\Tests\Unit;

use Kode\Server\Config\Limits;
use Kode\Server\Config\ServerConfig;
use Kode\Server\Enum\DriverType;
use Kode\Server\Exception\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new ServerConfig();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9501, $config->port);
        self::assertSame('tcp://127.0.0.1:9501', $config->address());
        self::assertFalse($config->isUnixSocket());
        self::assertGreaterThan(0, $config->workerCount());
    }

    public function testFromArrayAcceptsSnakeCaseAndRoutesNestedKeys(): void
    {
        $config = ServerConfig::fromArray([
            'host' => '0.0.0.0',
            'port' => 8080,
            'workers' => 4,
            'max_body_size' => '16M',      // → Limits
            'keep_alive_timeout' => 5.0,   // → Limits
            'watch_dirs' => [__DIR__],     // → WatchOptions
            'watch_interval' => 2.0,
        ]);

        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(4, $config->workerCount());
        self::assertSame(16 * 1024 * 1024, $config->limits->maxBodySize);
        self::assertSame(5.0, $config->limits->keepAliveTimeout);
        self::assertSame([__DIR__], $config->watch->dirs);
        self::assertSame(2.0, $config->watch->interval);
    }

    public function testWatchShorthandForms(): void
    {
        $list = ServerConfig::fromArray(['watch' => [__DIR__]]);
        self::assertTrue($list->watch->enabled);
        self::assertSame([__DIR__], $list->watch->dirs);

        $string = ServerConfig::fromArray(['watch' => __DIR__]);
        self::assertTrue($string->watch->enabled);
        self::assertSame([__DIR__], $string->watch->dirs);
    }

    public function testUnixSocketAddress(): void
    {
        $config = new ServerConfig(host: 'unix:/tmp/kode-test.sock');

        self::assertTrue($config->isUnixSocket());
        self::assertSame('/tmp/kode-test.sock', $config->socketPath());
        self::assertSame('unix:///tmp/kode-test.sock', $config->address());
    }

    public function testIpv6AddressIsBracketed(): void
    {
        self::assertSame('tcp://[::1]:9501', (new ServerConfig(host: '::1'))->address());
    }

    public function testWithProducesModifiedCopy(): void
    {
        $original = new ServerConfig(port: 9501);
        $copy = $original->with(['port' => 9502]);

        self::assertSame(9501, $original->port, '原实例不可变');
        self::assertSame(9502, $copy->port);
    }

    public function testUnknownKeyIsRejectedWithSuggestions(): void
    {
        $this->expectException(ConfigException::class);
        ServerConfig::fromArray(['prot' => 80]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejectedUpFront(array $options): void
    {
        $this->expectException(ConfigException::class);
        ServerConfig::fromArray($options);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield '端口越界' => [['port' => 70000]];
        yield '端口为零' => [['port' => 0]];
        yield '负数 worker' => [['workers' => -1]];
        yield '空 host' => [['host' => '']];
        yield '非法日志级别' => [['log_level' => 'loud']];
        yield '抖动比例越界' => [['max_requests_jitter' => 1.5]];
        yield '优雅超时为零' => [['graceful_timeout' => 0]];
        yield '未知驱动' => [['driver' => 'nginx']];
        yield 'UNIX 套接字不能开 reuse_port' => [['host' => 'unix:/tmp/a.sock', 'reuse_port' => true]];
        yield '开启 watch 却没给目录' => [['watch' => ['enabled' => true]]];
    }

    public function testSingleDriverAlwaysUsesOneWorker(): void
    {
        $config = new ServerConfig(driver: DriverType::Single, workers: 8);

        self::assertSame(1, $config->workerCount());
    }

    public function testMaxRequestsJitterStaysWithinBounds(): void
    {
        $config = new ServerConfig(maxRequests: 1000, maxRequestsJitter: 0.1);

        $seen = [];
        for ($i = 0; $i < 16; ++$i) {
            $value = $config->maxRequestsFor($i);
            self::assertGreaterThanOrEqual(900, $value);
            self::assertLessThanOrEqual(1100, $value);
            $seen[$value] = true;
        }

        self::assertGreaterThan(1, \count($seen), '抖动应让各 Worker 的阈值分散开，避免同时重启');
    }

    public function testMaxRequestsDisabledReturnsZero(): void
    {
        self::assertSame(0, (new ServerConfig())->maxRequestsFor(3));
    }

    public function testPidFilePathDerivedFromNameAndPort(): void
    {
        $config = new ServerConfig(port: 1234, name: 'my app');

        self::assertStringContainsString('my_app-1234.pid', $config->pidFilePath());
        self::assertStringEndsWith('.status', $config->statusFilePath());
    }

    public function testLimitsParseHumanReadableSizes(): void
    {
        $limits = Limits::fromArray(['maxBodySize' => '2G', 'bodyMemoryLimit' => '512K']);

        self::assertSame(2 * 1024 ** 3, $limits->maxBodySize);
        self::assertSame(512 * 1024, $limits->bodyMemoryLimit);
    }
}
