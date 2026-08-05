<?php

declare(strict_types=1);

namespace Kode\Server\Process;

use Kode\Server\Config\ServerConfig;
use Kode\Server\Exception\BindException;
use Kode\Server\Support\Sys;

/**
 * 监听套接字的创建与回收。
 *
 * 两种模型：
 *  - 默认：Master 建好套接字再 fork，Worker 共享同一个 fd，由内核分发连接；
 *  - reusePort：每个 Worker 自己 bind 同一端口（SO_REUSEPORT），
 *    内核按四元组哈希分流，避免惊群，代价是重载瞬间可能丢少量连接。
 */
final class Listener
{
    /**
     * @return resource
     */
    public static function create(ServerConfig $config)
    {
        $context = stream_context_create([
            'socket' => array_filter([
                'backlog' => $config->backlog,
                'so_reuseaddr' => true,
                'so_reuseport' => $config->reusePort ?: null,
                'tcp_nodelay' => $config->tcpNoDelay ?: null,
                'ipv6_v6only' => false,
            ], static fn (mixed $v): bool => $v !== null),
        ]);

        if ($config->isUnixSocket()) {
            self::cleanStaleSocket($config->socketPath());
        }

        $errno = 0;
        $error = '';
        $socket = @stream_socket_server(
            $config->address(),
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            $context,
        );

        if ($socket === false) {
            throw BindException::forAddress(
                $config->address(),
                $error !== '' ? $error : '未知错误',
                $errno,
            );
        }

        stream_set_blocking($socket, false);

        if ($config->isUnixSocket()) {
            @chmod($config->socketPath(), 0o666);
        }

        return $socket;
    }

    /**
     * @param resource|null $socket
     */
    public static function close($socket, ServerConfig $config): void
    {
        if (\is_resource($socket)) {
            @fclose($socket);
        }
        if ($config->isUnixSocket() && file_exists($config->socketPath())) {
            @unlink($config->socketPath());
        }
    }

    /**
     * UNIX 套接字文件不会随进程退出而消失，重启前需要判断它是否还有人在听。
     */
    private static function cleanStaleSocket(string $path): void
    {
        if (!file_exists($path)) {
            Sys::ensureDirFor($path);

            return;
        }

        $probe = @stream_socket_client('unix://' . $path, $errno, $error, 0.2);
        if (\is_resource($probe)) {
            fclose($probe);

            throw BindException::forAddress('unix://' . $path, '套接字已被占用');
        }

        @unlink($path);
    }
}
