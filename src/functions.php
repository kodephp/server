<?php

declare(strict_types=1);

namespace Kode\Server;

use Kode\Server\Config\ServerConfig;
use Psr\Http\Server\RequestHandlerInterface;

if (!\function_exists(__NAMESPACE__ . '\\server')) {
    /**
     * 创建一个服务实例（不启动）。
     *
     * ```php
     * use function Kode\Server\server;
     *
     * server(['port' => 9501])->onRequest($router)->start();
     * ```
     *
     * @param array<string, mixed>|ServerConfig $config
     */
    function server(array|ServerConfig $config = []): Server
    {
        return new Server($config);
    }
}

if (!\function_exists(__NAMESPACE__ . '\\serve')) {
    /**
     * 一行启动：创建 + 注册处理器 + 阻塞运行。
     *
     * ```php
     * use function Kode\Server\serve;
     *
     * exit(serve(fn ($request) => 'hello', ['port' => 9501]));
     * ```
     *
     * @param array<string, mixed>|ServerConfig $config
     *
     * @return int 退出码
     */
    function serve(callable|RequestHandlerInterface $handler, array|ServerConfig $config = []): int
    {
        return (new Server($config))->onRequest($handler)->start();
    }
}
