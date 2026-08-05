<?php

declare(strict_types=1);

namespace Kode\Server\Enum;

/**
 * 服务生命周期事件。
 *
 * 括号内标注事件的触发进程：M = Master，W = Worker。
 * 回调签名见 README，统一通过 {@see \Kode\Server\Event\Emitter} 派发。
 */
enum Event: string
{
    /** (M) 端口尚未监听、进程尚未 fork 之前，可用于最后的配置校验 */
    case BeforeStart = 'beforeStart';

    /** (M) 监听已建立、Worker 已拉起，服务正式对外提供能力 */
    case Start = 'start';

    /** (W) Worker 进程启动完成，适合在此初始化连接池 / 容器 */
    case WorkerStart = 'workerStart';

    /** (W) Worker 进程即将退出（优雅退出路径上一定会触发） */
    case WorkerStop = 'workerStop';

    /** (M) Master 观察到某个 Worker 已退出，携带退出码与信号 */
    case WorkerExit = 'workerExit';

    /** (W) 新连接建立 */
    case Connect = 'connect';

    /** (W) 收到完整请求，交由处理器分发 */
    case Request = 'request';

    /** (W) 响应已完整写出，适合打点统计 */
    case Response = 'response';

    /** (W) 连接关闭 */
    case Close = 'close';

    /** (M|W) 捕获到未处理异常，参数为 Throwable */
    case Error = 'error';

    /** (M) 收到重载信号，开始滚动重启 Worker */
    case Reload = 'reload';

    /** (M) 所有 Worker 均已替换为新进程 */
    case Reloaded = 'reloaded';

    /** (M) Master 退出前的最后一站，可用于清理外部资源 */
    case Shutdown = 'shutdown';
}
