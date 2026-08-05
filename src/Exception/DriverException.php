<?php

declare(strict_types=1);

namespace Kode\Server\Exception;

/**
 * 驱动不可用或驱动内部错误。
 */
final class DriverException extends ServerException
{
    public static function unavailable(string $driver, string $reason): self
    {
        return new self(sprintf('驱动 "%s" 不可用：%s', $driver, $reason));
    }
}
