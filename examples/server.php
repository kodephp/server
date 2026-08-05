<?php

declare(strict_types=1);

/*
 * 最小可运行示例。
 *
 * 前台启动：  php bin/kode-server start --bootstrap=examples/server.php --port=9501
 * 开发模式：  php bin/kode-server start --bootstrap=examples/server.php --watch=src
 * 平滑重载：  php bin/kode-server reload
 * 查看状态：  php bin/kode-server status
 */

use Psr\Http\Message\ServerRequestInterface;

require \dirname(__DIR__) . '/vendor/autoload.php';

return static function (ServerRequestInterface $request): array {
    return [
        'method' => $request->getMethod(),
        'path' => $request->getUri()->getPath(),
        'query' => $request->getQueryParams(),
        'worker' => $request->getServerParams()['KODE_WORKER_ID'] ?? null,
        'pid' => getmypid(),
        'time' => date('c'),
    ];
};
