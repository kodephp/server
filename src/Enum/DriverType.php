<?php

declare(strict_types=1);

namespace Kode\Server\Enum;

/**
 * 底层运行驱动。
 */
enum DriverType: string
{
    /** 按可用扩展自动挑选：swoole > stream */
    case Auto = 'auto';

    /** 纯 PHP：stream_socket_server + stream_select + pcntl 多进程，零扩展依赖 */
    case Stream = 'stream';

    /** ext-swoole：协程 HTTP 服务器 */
    case Swoole = 'swoole';

    /** 单进程前台运行，不 fork，便于调试与集成测试 */
    case Single = 'single';
}
