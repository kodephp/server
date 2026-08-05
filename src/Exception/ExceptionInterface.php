<?php

declare(strict_types=1);

namespace Kode\Server\Exception;

use Throwable;

/**
 * 本包抛出的所有异常都实现该接口，便于调用方统一捕获。
 */
interface ExceptionInterface extends Throwable
{
}
