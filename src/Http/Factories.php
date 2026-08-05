<?php

declare(strict_types=1);

namespace Kode\Server\Http;

use Kode\Server\Exception\ConfigException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * PSR-17 工厂集合 + 自动发现。
 *
 * 本包不绑定任何 PSR-7 实现：装了 kode/http 就用它，
 * 没装则依次尝试 nyholm / guzzle / laminas / httpsoft，
 * 一个都没有时给出可执行的安装提示，而不是抛一个看不懂的 class not found。
 */
final class Factories
{
    /** 同时实现全部 5 个工厂接口的"全家桶"类 */
    private const BUNDLES = [
        \Kode\Http\Psr7\Factory\Psr17Factory::class,
        \Nyholm\Psr7\Factory\Psr17Factory::class,
        \GuzzleHttp\Psr7\HttpFactory::class,
    ];

    /** 分散实现时的候选表：接口 => 候选类 */
    private const CANDIDATES = [
        ServerRequestFactoryInterface::class => [
            \Kode\Http\Psr7\Factory\ServerRequestFactory::class,
            \Nyholm\Psr7\Factory\Psr17Factory::class,
            \GuzzleHttp\Psr7\HttpFactory::class,
            \Laminas\Diactoros\ServerRequestFactory::class,
            \HttpSoft\Message\ServerRequestFactory::class,
            \Slim\Psr7\Factory\ServerRequestFactory::class,
        ],
        StreamFactoryInterface::class => [
            \Kode\Http\Psr7\Factory\StreamFactory::class,
            \Nyholm\Psr7\Factory\Psr17Factory::class,
            \GuzzleHttp\Psr7\HttpFactory::class,
            \Laminas\Diactoros\StreamFactory::class,
            \HttpSoft\Message\StreamFactory::class,
            \Slim\Psr7\Factory\StreamFactory::class,
        ],
        UploadedFileFactoryInterface::class => [
            \Kode\Http\Psr7\Factory\UploadedFileFactory::class,
            \Nyholm\Psr7\Factory\Psr17Factory::class,
            \GuzzleHttp\Psr7\HttpFactory::class,
            \Laminas\Diactoros\UploadedFileFactory::class,
            \HttpSoft\Message\UploadedFileFactory::class,
            \Slim\Psr7\Factory\UploadedFileFactory::class,
        ],
        UriFactoryInterface::class => [
            \Kode\Http\Psr7\Factory\UriFactory::class,
            \Nyholm\Psr7\Factory\Psr17Factory::class,
            \GuzzleHttp\Psr7\HttpFactory::class,
            \Laminas\Diactoros\UriFactory::class,
            \HttpSoft\Message\UriFactory::class,
            \Slim\Psr7\Factory\UriFactory::class,
        ],
        ResponseFactoryInterface::class => [
            \Kode\Http\Psr7\Factory\ResponseFactory::class,
            \Nyholm\Psr7\Factory\Psr17Factory::class,
            \GuzzleHttp\Psr7\HttpFactory::class,
            \Laminas\Diactoros\ResponseFactory::class,
            \HttpSoft\Message\ResponseFactory::class,
            \Slim\Psr7\Factory\ResponseFactory::class,
        ],
    ];

    private static ?self $default = null;

    public function __construct(
        public readonly ServerRequestFactoryInterface $serverRequest,
        public readonly StreamFactoryInterface $stream,
        public readonly UploadedFileFactoryInterface $uploadedFile,
        public readonly UriFactoryInterface $uri,
        public readonly ResponseFactoryInterface $response,
    ) {
    }

    /**
     * 用同一个"全家桶"对象填满 5 个槽位。
     */
    public static function fromBundle(object $factory): self
    {
        foreach (
            [
                ServerRequestFactoryInterface::class,
                StreamFactoryInterface::class,
                UploadedFileFactoryInterface::class,
                UriFactoryInterface::class,
                ResponseFactoryInterface::class,
            ] as $interface
        ) {
            if (!$factory instanceof $interface) {
                throw new ConfigException(sprintf(
                    '%s 未实现 %s，无法作为 PSR-17 全家桶使用。',
                    $factory::class,
                    $interface,
                ));
            }
        }

        /** @var ServerRequestFactoryInterface&StreamFactoryInterface&UploadedFileFactoryInterface&UriFactoryInterface&ResponseFactoryInterface $factory */
        return new self($factory, $factory, $factory, $factory, $factory);
    }

    public static function discover(): self
    {
        if (self::$default !== null) {
            return self::$default;
        }

        foreach (self::BUNDLES as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $instance = new $class();
            if (
                $instance instanceof ServerRequestFactoryInterface
                && $instance instanceof StreamFactoryInterface
                && $instance instanceof UploadedFileFactoryInterface
                && $instance instanceof UriFactoryInterface
                && $instance instanceof ResponseFactoryInterface
            ) {
                return self::$default = new self($instance, $instance, $instance, $instance, $instance);
            }
        }

        $resolved = [];
        foreach (self::CANDIDATES as $interface => $classes) {
            foreach ($classes as $class) {
                if (!class_exists($class)) {
                    continue;
                }
                $instance = new $class();
                if ($instance instanceof $interface) {
                    $resolved[$interface] = $instance;
                    break;
                }
            }
        }

        if (\count($resolved) !== \count(self::CANDIDATES)) {
            throw new ConfigException(
                '未找到可用的 PSR-17 工厂实现。请安装 kode/http（推荐）或 nyholm/psr7：' . \PHP_EOL
                . '    composer require kode/http' . \PHP_EOL
                . '也可以调用 Server::setFactories() 手动注入。',
            );
        }

        return self::$default = new self(
            $resolved[ServerRequestFactoryInterface::class],
            $resolved[StreamFactoryInterface::class],
            $resolved[UploadedFileFactoryInterface::class],
            $resolved[UriFactoryInterface::class],
            $resolved[ResponseFactoryInterface::class],
        );
    }

    public static function setDefault(?self $factories): void
    {
        self::$default = $factories;
    }
}
