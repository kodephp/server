<?php

declare(strict_types=1);

namespace Kode\Server\Exception;

use RuntimeException;

/**
 * 服务端运行期异常基类。
 */
class ServerException extends RuntimeException implements ExceptionInterface
{
}
