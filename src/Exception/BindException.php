<?php

declare(strict_types=1);

namespace Kode\Server\Exception;

/**
 * 监听地址无法绑定（端口占用、权限不足、地址非法等）。
 */
final class BindException extends ServerException
{
    public static function forAddress(string $address, string $reason, int $code = 0): self
    {
        return new self(sprintf('无法监听 %s：%s', $address, $reason), $code);
    }
}
