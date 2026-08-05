<?php

declare(strict_types=1);

/**
 * 端到端冒烟：跨进程验证 启动→请求→滚动重载→停止 的完整生命周期，
 * 并确认协程能力能在真实请求内使用。
 *
 * 跑法：php scripts/e2e.php
 * 退出码 0 = 通过，非 0 = 失败。
 */

use Kode\Server\Config\ServerConfig;
use Kode\Server\Server;
use Psr\Http\Message\ServerRequestInterface;

require __DIR__ . '/../vendor/autoload.php';

$port = 9511;
$pidFile = sys_get_temp_dir() . '/kode-e2e.pid';
@unlink($pidFile);

$config = ServerConfig::fromArray([
    'host' => '127.0.0.1',
    'port' => $port,
    'driver' => 'stream',
    'workers' => 1,
    'name' => 'kode-e2e',
    'pid_file' => $pidFile,
    'log_level' => 'warning',
]);

$failures = [];

function check(bool $cond, string $label): void
{
    global $failures;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . PHP_EOL;
    if (!$cond) {
        $failures[] = $label;
    }
}

function curlGet(string $url, int $timeout = 3): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($code >= 200 && $code < 300) ? $body : null;
}

$pid = pcntl_fork();
if ($pid === -1) {
    fwrite(STDERR, "fork 失败\n");
    exit(1);
}

if ($pid === 0) {
    // ===== 子进程：作为服务进程运行 =====
    $server = new Server($config);
    $server->onRequest(function (ServerRequestInterface $req) use ($server): array {
        // 真实请求内使用协程并发子任务
        $doubled = $server->coroutine()->batch([1, 2, 3], static fn (int $x): int => $x * 2, 2);

        return [
            'path' => $req->getUri()->getPath(),
            'method' => $req->getMethod(),
            'coroutine' => $doubled,
        ];
    });
    $server->start();
    exit(0);
}

// ===== 父进程：编排测试 =====
sleep(1); // 等服务起来

// 1) 启动后可响应，且协程在请求内生效
$body = curlGet("http://127.0.0.1:{$port}/hello");
check($body !== null, '服务启动后 HTTP 请求可达');
if ($body !== null) {
    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    check(($data['path'] ?? null) === '/hello', '请求路径正确 (/hello)');
    check(($data['method'] ?? null) === 'GET', '请求方法正确 (GET)');
    check(($data['coroutine'] ?? null) === [2, 4, 6], '协程 batch 在请求内生效 ([2,4,6])');
}

// 2) 滚动重载不中断服务
$controller = new Server($config);
check($controller->reload(), 'reload() 返回 true');
sleep(1);
$body2 = curlGet("http://127.0.0.1:{$port}/world");
check($body2 !== null, '重载后服务仍可用');
if ($body2 !== null) {
    $data2 = json_decode($body2, true, 512, JSON_THROW_ON_ERROR);
    check(($data2['path'] ?? null) === '/world', '重载后新请求路径正确 (/world)');
}

// 3) 停止后不再可达
check($controller->stop(), 'stop() 返回 true');
sleep(1);
$down = curlGet("http://127.0.0.1:{$port}/bye");
check($down === null, '停止后服务不再可达');

@unlink($pidFile);

if ($failures === []) {
    echo PHP_EOL . 'ALL GREEN' . PHP_EOL;
    exit(0);
}

echo PHP_EOL . 'FAILURES: ' . implode(', ', $failures) . PHP_EOL;
exit(1);
