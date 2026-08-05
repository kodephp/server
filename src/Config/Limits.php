<?php

declare(strict_types=1);

namespace Kode\Server\Config;

use Kode\Server\Exception\ConfigException;
use Kode\Server\Support\Bytes;

/**
 * HTTP 协议层限额。
 *
 * 这些数字是服务端的第一道防线：慢连接、超大头、无限 body、上传炸弹
 * 都在这里被挡住，而不是等到业务代码里才发现内存被打爆。
 */
final readonly class Limits
{
    /**
     * @param int   $maxHeaderSize        请求行 + 头部区总字节上限
     * @param int   $maxHeaderCount       头字段条数上限
     * @param int   $maxUriLength         请求目标（含 query）字节上限
     * @param int   $maxBodySize          请求体字节上限，0 表示不限
     * @param int   $bodyMemoryLimit      请求体超过该值后落盘到临时文件
     * @param bool  $keepAlive            是否允许长连接
     * @param float $keepAliveTimeout     长连接空闲多久后关闭（秒）
     * @param int   $maxKeepAliveRequests 单条连接最多复用多少次
     * @param float $headerTimeout        从连接建立到收完请求头的时限（防 slowloris）
     * @param float $bodyTimeout          收完请求体的时限
     * @param float $writeTimeout         单次响应写出的时限
     * @param int   $maxConnections       单个 Worker 的并发连接上限，0 表示不限
     * @param int   $maxFormFields        表单字段数上限
     * @param int   $maxUploadFiles       单请求上传文件数上限
     */
    public function __construct(
        public int $maxHeaderSize = 16384,
        public int $maxHeaderCount = 100,
        public int $maxUriLength = 8192,
        public int $maxBodySize = 8388608,
        public int $bodyMemoryLimit = 1048576,
        public bool $keepAlive = true,
        public float $keepAliveTimeout = 15.0,
        public int $maxKeepAliveRequests = 1000,
        public float $headerTimeout = 10.0,
        public float $bodyTimeout = 30.0,
        public float $writeTimeout = 30.0,
        public int $maxConnections = 10000,
        public bool $parseMultipart = true,
        public int $maxFormFields = 1000,
        public int $maxUploadFiles = 64,
        public ?string $tempDir = null,
        public bool $sendServerHeader = true,
        public bool $sendDateHeader = true,
        public string $serverSignature = 'kode-server',
    ) {
        self::positive('max_header_size', $this->maxHeaderSize);
        self::positive('max_header_count', $this->maxHeaderCount);
        self::positive('max_uri_length', $this->maxUriLength);
        self::nonNegative('max_body_size', $this->maxBodySize);
        self::positive('body_memory_limit', $this->bodyMemoryLimit);
        self::positiveFloat('keep_alive_timeout', $this->keepAliveTimeout);
        self::positive('max_keep_alive_requests', $this->maxKeepAliveRequests);
        self::positiveFloat('header_timeout', $this->headerTimeout);
        self::positiveFloat('body_timeout', $this->bodyTimeout);
        self::positiveFloat('write_timeout', $this->writeTimeout);
        self::nonNegative('max_connections', $this->maxConnections);
        self::positive('max_form_fields', $this->maxFormFields);
        self::positive('max_upload_files', $this->maxUploadFiles);

        if ($this->maxBodySize > 0 && $this->bodyMemoryLimit > $this->maxBodySize) {
            throw new ConfigException('body_memory_limit 不应大于 max_body_size。');
        }
    }

    /**
     * @param array<string, mixed> $options 已归一化为 camelCase 的键值对
     */
    public static function fromArray(array $options): self
    {
        $sizeKeys = ['maxHeaderSize', 'maxUriLength', 'maxBodySize', 'bodyMemoryLimit'];
        foreach ($sizeKeys as $key) {
            if (isset($options[$key]) && (\is_string($options[$key]) || \is_int($options[$key]))) {
                $options[$key] = Bytes::parse($options[$key], $key);
            }
        }

        /** @var array<string, mixed> $options */
        return new self(...$options);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(self::class, '__construct'))->getParameters(),
        );
    }

    private static function positive(string $key, int $value): void
    {
        if ($value <= 0) {
            throw ConfigException::of($key, $value, '正整数');
        }
    }

    private static function nonNegative(string $key, int $value): void
    {
        if ($value < 0) {
            throw ConfigException::of($key, $value, '非负整数');
        }
    }

    private static function positiveFloat(string $key, float $value): void
    {
        if ($value <= 0) {
            throw ConfigException::of($key, $value, '大于 0 的秒数');
        }
    }
}
