<?php

declare(strict_types=1);

namespace Kode\Server\Exception;

use InvalidArgumentException;

/**
 * 配置非法：在服务启动前尽早失败，避免带着错误配置进入进程模型。
 */
final class ConfigException extends InvalidArgumentException implements ExceptionInterface
{
    public static function of(string $key, mixed $value, string $expected): self
    {
        return new self(sprintf(
            '配置项 "%s" 非法：期望 %s，实际收到 %s。',
            $key,
            $expected,
            get_debug_type($value) . (is_scalar($value) ? '(' . var_export($value, true) . ')' : ''),
        ));
    }

    /**
     * @param list<string> $known
     */
    public static function unknown(string $key, array $known): self
    {
        $hint = '';
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($known as $candidate) {
            $distance = levenshtein($key, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }
        if ($best !== null && $bestDistance <= 3) {
            $hint = sprintf(' 是否想写 "%s"？', $best);
        }

        return new self(sprintf('未知配置项 "%s"。%s', $key, $hint));
    }
}
