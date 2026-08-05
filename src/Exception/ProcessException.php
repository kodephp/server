<?php

declare(strict_types=1);

namespace Kode\Server\Exception;

/**
 * 进程相关错误：fork 失败、信号安装失败、PID 文件不可写等。
 */
final class ProcessException extends ServerException
{
}
