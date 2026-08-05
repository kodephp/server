<?php

declare(strict_types=1);

namespace Kode\Server\Support;

use Kode\Server\Exception\ConfigException;

/**
 * 字节数解析与格式化：允许配置里写 "8M"、"512K"、"1G"。
 */
final class Bytes
{
    private const UNITS = ['B' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];

    /**
     * @param int|string $value 形如 1024、"8M"、"1g"、"512 K"
     */
    public static function parse(int|string $value, string $key = 'size'): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw ConfigException::of($key, $value, '非负整数');
            }

            return $value;
        }

        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            throw ConfigException::of($key, $value, '非空的字节数表达式');
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([BKMG]?)B?$/', $normalized, $m)) {
            throw ConfigException::of($key, $value, '形如 1024 / 8M / 1G 的字节数表达式');
        }

        $unit = $m[2] !== '' ? $m[2] : 'B';

        return (int) round(((float) $m[1]) * self::UNITS[$unit]);
    }

    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }
        foreach (['K' => 1024, 'M' => 1048576, 'G' => 1073741824] as $unit => $factor) {
            if ($bytes < $factor * 1024 || $unit === 'G') {
                return rtrim(rtrim(number_format($bytes / $factor, 2, '.', ''), '0'), '.') . $unit;
            }
        }

        return $bytes . 'B';
    }
}
