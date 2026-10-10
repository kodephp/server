# kode/server

> HTTP Server 生命周期管理层 —— 对标 Swoole 的 `Http\Server`，但纯 PHP 8.4+ 即可运行。

`kode/server` 只做一件事：把"如何启动一个 HTTP 服务、如何接收并派发请求、如何让它优雅地重启与停止"这套生命周期管清楚。它是 [kode](https://github.com/kodephp) 框架的进程启动服务层，但本身零框架依赖，可以独立使用。

- **进程模型**：Master-Worker 预派生（prefork），业务代码崩溃不会拖垮 Master。
- **优雅重启**：滚动重载（一次只换一个 Worker）、崩溃指数退避、两段式停机（SIGTERM → 超时 → SIGKILL）。
- **热重载**：监视源码目录，变更即平滑替换 Worker，开发零停机。
- **多驱动**：`stream`（默认、纯 PHP）、`swoole`（可选加速）、`single`（无 pcntl 的调试模式），`auto` 自动选择。
- **协议层**：自研增量式 HTTP/1.1 解析器，内建防慢连接、防超大头、防请求走私。
- **标准兼容**：仅依赖 PSR-7 / PSR-15 / PSR-17，工厂实现自动发现。

---

## 作为 kode 生态的组合根

`kode/server` 不重复造轮子——它是把 kode 生态里三块能力收口、对外统一暴露的**组合根（composition root）**：

| 能力 | 基座包 | 在 kode/server 里的位置 |
| --- | --- | --- |
| 进程 / 守护 / 信号 / 滚动重载 | [`kode/process`](https://github.com/kodephp/process) | `Master`-`Worker` 预派生由它提供；信号常量取自 `Kode\Process\Signal` |
| 协程运行时 | [`kode/fibers`](https://github.com/kodephp/fibers) | `Server::coroutine()` 暴露，handler 内跑并发子任务 |
| 并行卸载 | [`kode/parallel`](https://github.com/kodephp/parallel) | `Server::parallel()` 暴露，把 CPU 密集任务 offload 到独立运行时 |

因此 `kode/server` 自身只保留**真正属于 HTTP 服务层**的部分：增量式 HTTP/1.1 解析、PSR 桥接、`Server` 门面、驱动（`stream`/`swoole`/`single`）与 CLI。进程管理、信号处理、热重载、协程调度全部委托给上层基座包——它们的版本演进会自动惠及本包。

> 设计取舍：kode/process 是「任务 / Worker 池」模型（IPC 派发 `assignTask`），没有 HTTP `accept` 循环，所以 `kode/server` **保留了自己的 accept 循环**，只把信号值 / 命名 / 进程生命周期交给 kode/process。

---

## 在 handler 内使用协程与并行

`kode/server` 不绑死执行模型：handler 里想并发就并发，想卸载就卸载。闭包里用 `use ($server)` 拿到门面即可。

```php
<?php
use Kode\Server\Server;
use Psr\Http\Message\ServerRequestInterface;

$server = new Server(['host' => '0.0.0.0', 'port' => 9501, 'workers' => 4]);

$server->onRequest(function (ServerRequestInterface $req) use ($server) {
    $id = $req->getQueryParams()['id'] ?? 0;

    // 协程：并发抓取多个下游（基于 kode/fibers）
    [$user, $orders] = $server->coroutine()->batch(
        [fn () => fetchUser($id), fn () => fetchOrders($id)],
        concurrency: 2,
    );

    // 并行：把 CPU 密集任务 offload 到独立运行时（基于 kode/parallel）
    $future = $server->parallel()->run(
        fn (array $a) => heavyCompute($a['n']),
        ['n' => 1_000_000],
    );
    $score = $server->parallel()->await($future);

    return ['user' => $user, 'orders' => $orders, 'score' => $score];
});

$server->start();
```

`coroutine()` 返回的对象满足 `Kode\Server\Contract\CoroutineDriverInterface`
（`go` / `batch` / `sleep` / `createChannel` / `createWaitGroup`），以后可整体替换为 Swow 等其它后端：

```php
$server->setCoroutineDriver(new MySwowDriver());
```

---

## 安装

```bash
composer require kode/server
```

可选扩展（`ext-pcntl` / `ext-posix` 强烈建议，用于多进程、信号控制与优雅重启；`ext-swoole` 用于 Swoole 驱动；`ext-sockets` 用于 `SO_REUSEPORT`）。缺少 `ext-pcntl` 时自动降级为单进程模式，代码无需改动。

PSR-17 工厂实现按需安装其一即可（自动发现，无需配置）：

```bash
composer require kode/http      # 推荐
# 或 nyholm/psr7、guzzlehttp/psr7、laminas/diactoros、slim/psr7 ...
```

> **运行期依赖**：`kode/server` 强制依赖 `kode/process`、`kode/fibers`、`kode/parallel` 三个基座包，它们需能从 Packagist 解析（已发布即可）。本地 monorepo 开发时，把它们的 `path` 仓库写在 `composer.local.json`（已 gitignore）里即可联调，无需改动发布的 `composer.json`。

---

## 快速开始

```php
<?php
// server.php
use Kode\Server\Server;

$server = new Server([
    'host'    => '0.0.0.0',
    'port'    => 9501,
    'workers' => 4,
]);

// 任意可调用对象，返回 PSR-7 Response / 字符串 / 数组(自动 JSON) / null(204)
$server->onRequest(function (\Psr\Http\Message\ServerRequestInterface $req) {
    return [
        'method'  => $req->getMethod(),
        'path'    => $req->getUri()->getPath(),
        'worker'  => $req->getServerParams()['KODE_WORKER_ID'] ?? null,
    ];
});

$server->start();
```

回调的返回值会被自动规范化：

| 返回类型 | 行为 |
| --- | --- |
| `Psr\Http\Message\ResponseInterface` | 原样发出 |
| `string` / `Stringable` | `200 text/html` |
| `array` / `JsonSerializable` / `stdClass` | `200 application/json` |
| `null` | `204 No Content` |

也可以直接传入 PSR-15 `RequestHandlerInterface` 或中间件管道：

```php
$server->onRequest(new App\Handler());
```

---

## 生命周期管理

### 编程式

```php
$server->start();                 // 阻塞运行，直到收到停止信号
$server->isRunning();             // 当前进程是否正在服务
$server->reload();                // 滚动重载 Worker
$server->stop();                  // 优雅停止
$server->reopenLogs();            // 通知重新打开日志（配合 logrotate）

$server->status();                // 状态快照（仅服务进程内有效）
$server->pid();                   // Master PID
$server->driverName();            // 实际生效的驱动
```

### 带外控制（另一个进程）

从 CLI 操作正在运行的服务，靠的是 **PID 文件 + 信号**，不额外开端口：

```bash
php bin/kode-server start   --bootstrap=server.php --port=9501 --workers=4 -d
php bin/kode-server status  --port=9501
php bin/kode-server reload  --port=9501
php bin/kode-server stop    --port=9501
```

> 控制命令需要通过 `--port`（或一致的 `--name` / `--bootstrap`）定位到正确的实例，因为 PID 文件路径由 `name + 端口` 唯一决定。

---

## 热重载（文件监视）

```bash
php bin/kode-server start --bootstrap=server.php --watch=src --workers=2
```

修改 `src/` 下任意文件，Master 会逐个替换 Worker（服务容量不会掉到 0）。也可在代码里配置：

```php
$server->setWatcher(new \Kode\Server\Reload\FileWatcher(['src', 'app']));
// 或
new Server(['watch' => ['src', 'app']]);
```

---

## 驱动

| 驱动 | 说明 | 依赖 |
| --- | --- | --- |
| `stream` | 默认。纯 PHP `stream_socket_server` + `stream_select`，预派生多进程 | `ext-pcntl` / `ext-posix` |
| `swoole` | 协程、更高吞吐 | `ext-swoole` |
| `single` | 单进程，热重载不支持，适合调试 / 测试 | 无 |
| `auto` | 按 `swoole → stream → single` 顺序自动选择 | — |

显式指定某驱动不会"静默降级"：若所选驱动不可用，会直接报错。

```php
new Server(['driver' => \Kode\Server\Enum\DriverType::Swoole]);
```

---

## 配置速查

`ServerConfig::fromArray()` 同时接受 `snake_case` 与 `camelCase` 键名，非法配置在 `start()` 之前就会抛出。

```php
new Server([
    'host'          => '0.0.0.0',
    'port'          => 9501,
    'driver'        => 'auto',
    'workers'       => 4,                 // 0 = 按 CPU 核心数推导
    'daemon'        => true,
    'max_body_size' => '16M',             // 支持 8K / 2M / 1G 这类写法
    'max_requests'  => 1000,              // Worker 处理 N 个请求后自愈重启
    'max_requests_jitter' => 0.1,         // 抖动，避免所有 Worker 同时退出
    'graceful_timeout' => 30.0,
    'watch'         => ['src'],
    // 'limits' => [...], 'swoole' => [...]
]);
```

协议层限额（防滥用第一道防线）通过 `limits` 子数组或扁平键传入：

```php
'limits' => [
    'max_header_size' => 16384,
    'max_body_size'   => 8 * 1024 * 1024,
    'max_uri_length'  => 8192,
    'keep_alive'      => true,
],
```

---

## 架构

```
                ┌─────────────── 控制面（另一个进程）──────────────┐
                │  kode-server stop/reload  →  PID 文件 + 信号      │
                └───────────────────────┬──────────────────────────┘
                                         │ SIGTERM / SIGUSR1 / SIGUSR2
   ┌─────────────────────────────────────▼─────────────────────────────────┐
   │  Master（绝不处理业务，只管拉起/盯着/回收 Worker）                       │
   │   · 滚动重载：一次只换一个 Worker                                       │
   │   · 崩溃退避：起来就死的 Worker 指数延迟重启                            │
   │   · 两段式停机：SIGTERM 等优雅收敛 → 超时 SIGKILL                      │
   │       ├─ Worker #0  ── 解析请求 → 派发 → 响应                          │
   │       ├─ Worker #1  ── 解析请求 → 派发 → 响应                          │
   │       └─ Worker #N  ── 解析请求 → 派发 → 响应                          │
   └──────────────────────────────────────────────────────────────────────┘
```

请求进入 Worker 后：`Parser`（增量解析，生成 `ParsedRequest`）→ `RequestBuilder`（组装 PSR-7 `ServerRequest`）→ `Dispatcher`（`onRequest` 回调 / PSR-15 处理器）→ `Emitter`（生命周期事件，异常隔离）→ 写回。

---

## CLI 命令

| 命令 | 说明 |
| --- | --- |
| `start` | 启动。`-d` 后台守护；`--watch=dir` 开热重载；`--bootstrap=file` 指定启动脚本 |
| `stop` | 优雅停止（`--force` 强制 SIGKILL） |
| `restart` | 停止后重新启动 |
| `reload` | 滚动重载 Worker |
| `status` | 读取状态快照 |
| `info` | 打印解析后的配置 |
| `version` | 版本号 |

启动脚本（`--bootstrap`）需返回一个 `callable(ServerRequestInterface): mixed` 或 `RequestHandlerInterface`：

```php
<?php
// 返回闭包即可
return function (\Psr\Http\Message\ServerRequestInterface $req) {
    return ['hello' => 'world'];
};
```

---

## 测试与质量

```bash
composer test-unit     # 单元测试（解析器 / 配置 / 驱动）
composer analyse       # PHPStan 静态分析（level 6）
composer check         # analyse + test
```

端到端冒烟（跨进程跑通 启动 → 请求 → 滚动重载 → 停止，并在真实请求内验证协程）：

```bash
php scripts/e2e.php    # 全绿即通过；退出码非 0 为失败
```

---

## 许可证

Apache-2.0
