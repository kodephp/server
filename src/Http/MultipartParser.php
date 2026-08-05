<?php

declare(strict_types=1);

namespace Kode\Server\Http;

use Kode\Server\Config\Limits;
use Kode\Server\Exception\ProtocolException;

/**
 * 流式 multipart/form-data 解析。
 *
 * 全程按块读取，文件内容写进 php://temp（超阈值自动落盘、fclose 即回收），
 * 因此上传 1GB 文件也不会把 Worker 的内存打满，也不会留下需要手工清理的临时文件。
 */
final class MultipartParser
{
    private const READ_CHUNK = 65536;

    /** 单个 part 头部的字节上限 */
    private const MAX_PART_HEADER = 8192;

    /** @var array<string, mixed> */
    private array $fields = [];

    /** @var array<string, mixed> */
    private array $files = [];

    private int $fieldCount = 0;

    private int $fileCount = 0;

    private string $buffer = '';

    /** @var resource */
    private $stream;

    private bool $eof = false;

    public function __construct(
        private readonly Limits $limits,
        private readonly Factories $factories,
    ) {
    }

    /**
     * @param resource $body
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [字段, 上传文件]
     */
    public function parse($body, string $boundary): array
    {
        if ($boundary === '') {
            throw ProtocolException::badRequest('multipart 请求缺少 boundary');
        }

        $this->stream = $body;
        $this->buffer = '';
        $this->eof = false;
        $this->fields = [];
        $this->files = [];
        $this->fieldCount = 0;
        $this->fileCount = 0;

        rewind($this->stream);

        $delimiter = '--' . $boundary;

        // 跳到第一个分隔符
        if (!$this->seekTo($delimiter)) {
            throw ProtocolException::badRequest('multipart 报文缺少起始分隔符');
        }

        while (true) {
            $marker = $this->readExactly(2);
            if ($marker === '--' || $marker === '') {
                break; // 结束分隔符或数据读尽
            }
            // 分隔符后允许有多余空白，最终必须以换行收尾
            $this->consumeLineEnd($marker);

            $headers = $this->readPartHeaders();
            [$name, $filename] = $this->parseDisposition($headers['content-disposition'] ?? '');

            if ($name === null) {
                $this->skipPart($delimiter);
                continue;
            }

            if ($filename === null) {
                $this->readField($name, $delimiter);
            } else {
                $this->readFile($name, $filename, $headers['content-type'] ?? null, $delimiter);
            }

            if ($this->eof && $this->buffer === '') {
                break;
            }
        }

        return [$this->fields, $this->files];
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function readPartHeaders(): array
    {
        $headers = [];
        $consumed = 0;

        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                throw ProtocolException::badRequest('multipart part 头部不完整');
            }
            $consumed += \strlen($line) + 2;
            if ($consumed > self::MAX_PART_HEADER) {
                throw ProtocolException::badRequest('multipart part 头部超限');
            }
            if ($line === '') {
                return $headers;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                throw ProtocolException::badRequest('multipart part 头部格式非法');
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }

    /**
     * @return array{0: ?string, 1: ?string} [字段名, 文件名]
     */
    private function parseDisposition(string $disposition): array
    {
        if ($disposition === '' || stripos($disposition, 'form-data') === false) {
            return [null, null];
        }

        $name = $this->extractParam($disposition, 'name');
        $filename = $this->extractParam($disposition, 'filename');

        // RFC 5987 的 filename* 优先
        if (preg_match('/filename\*\s*=\s*([^;]+)/i', $disposition, $m)) {
            $raw = trim($m[1]);
            if (preg_match("/^([\w-]+)'[^']*'(.+)$/", $raw, $parts)) {
                $decoded = rawurldecode($parts[2]);
                if (strcasecmp($parts[1], 'utf-8') === 0 || mb_check_encoding($decoded, 'UTF-8')) {
                    $filename = $decoded;
                }
            }
        }

        return [$name, $filename === null ? null : basename(str_replace('\\', '/', $filename))];
    }

    private function extractParam(string $subject, string $key): ?string
    {
        if (preg_match('/(?:^|;)\s*' . preg_quote($key, '/') . '\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $subject, $m)) {
            return stripslashes($m[1]);
        }
        if (preg_match('/(?:^|;)\s*' . preg_quote($key, '/') . '\s*=\s*([^;]+)/i', $subject, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function readField(string $name, string $delimiter): void
    {
        if (++$this->fieldCount > $this->limits->maxFormFields) {
            throw ProtocolException::badRequest(sprintf('表单字段数量超过 %d 限制', $this->limits->maxFormFields));
        }

        $value = '';
        $this->pump($delimiter, function (string $chunk) use (&$value): void {
            $value .= $chunk;
            // 单个字段值也不允许无限大
            if (\strlen($value) > $this->limits->bodyMemoryLimit) {
                throw ProtocolException::payloadTooLarge($this->limits->bodyMemoryLimit);
            }
        });

        $this->assign($this->fields, $name, $value);
    }

    private function readFile(string $name, string $filename, ?string $contentType, string $delimiter): void
    {
        if (++$this->fileCount > $this->limits->maxUploadFiles) {
            throw ProtocolException::badRequest(sprintf('上传文件数量超过 %d 限制', $this->limits->maxUploadFiles));
        }

        $temp = fopen('php://temp/maxmemory:' . $this->limits->bodyMemoryLimit, 'w+b');
        if ($temp === false) {
            throw new ProtocolException('无法为上传文件创建缓冲', 500);
        }

        $size = 0;
        $this->pump($delimiter, static function (string $chunk) use ($temp, &$size): void {
            $written = fwrite($temp, $chunk);
            if ($written === false) {
                throw new ProtocolException('写入上传缓冲失败', 500);
            }
            $size += $written;
        });

        rewind($temp);

        $uploaded = $this->factories->uploadedFile->createUploadedFile(
            $this->factories->stream->createStreamFromResource($temp),
            $size,
            \UPLOAD_ERR_OK,
            $filename,
            $contentType,
        );

        $this->assign($this->files, $name, $uploaded);
    }

    private function skipPart(string $delimiter): void
    {
        $this->pump($delimiter, static function (string $chunk): void {
        });
    }

    /**
     * 把当前 part 的内容一块块喂给回调，直到遇见分隔符。
     */
    private function pump(string $delimiter, callable $sink): void
    {
        $needle = "\r\n" . $delimiter;
        $keep = \strlen($needle);

        while (true) {
            $pos = strpos($this->buffer, $needle);
            if ($pos !== false) {
                $sink(substr($this->buffer, 0, $pos));
                $this->buffer = substr($this->buffer, $pos + $keep);

                return;
            }

            if ($this->eof) {
                // 容忍最后一个 part 没有 CRLF 前缀的畸形报文
                $pos = strpos($this->buffer, $delimiter);
                if ($pos !== false) {
                    $sink(substr($this->buffer, 0, $pos));
                    $this->buffer = substr($this->buffer, $pos + \strlen($delimiter));

                    return;
                }
                $sink($this->buffer);
                $this->buffer = '';

                return;
            }

            // 保留可能横跨两块的分隔符前缀
            if (\strlen($this->buffer) > $keep) {
                $flushLength = \strlen($this->buffer) - $keep;
                $sink(substr($this->buffer, 0, $flushLength));
                $this->buffer = substr($this->buffer, $flushLength);
            }

            $this->fill();
        }
    }

    private function seekTo(string $delimiter): bool
    {
        while (true) {
            $pos = strpos($this->buffer, $delimiter);
            if ($pos !== false) {
                $this->buffer = substr($this->buffer, $pos + \strlen($delimiter));

                return true;
            }
            if ($this->eof) {
                return false;
            }
            if (\strlen($this->buffer) > \strlen($delimiter)) {
                $this->buffer = substr($this->buffer, -\strlen($delimiter));
            }
            $this->fill();
        }
    }

    private function readLine(): ?string
    {
        while (true) {
            $pos = strpos($this->buffer, "\n");
            if ($pos !== false) {
                $line = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);

                return rtrim($line, "\r");
            }
            if ($this->eof) {
                return null;
            }
            $this->fill();
        }
    }

    private function readExactly(int $length): string
    {
        while (\strlen($this->buffer) < $length && !$this->eof) {
            $this->fill();
        }

        $take = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, \strlen($take));

        return $take;
    }

    private function consumeLineEnd(string $consumed): void
    {
        // 已经读走 2 个字节，可能是 "\r\n" 也可能是内容的一部分
        if ($consumed === "\r\n") {
            return;
        }
        if (str_starts_with($consumed, "\n")) {
            $this->buffer = substr($consumed, 1) . $this->buffer;

            return;
        }
        $this->buffer = $consumed . $this->buffer;
        $line = $this->readLine();
        if ($line === null) {
            $this->eof = true;
        }
    }

    private function fill(): void
    {
        if ($this->eof) {
            return;
        }
        $chunk = fread($this->stream, self::READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            $this->eof = true;

            return;
        }
        $this->buffer .= $chunk;
        if (feof($this->stream)) {
            $this->eof = true;
        }
    }

    /**
     * 支持 a、a[]、a[b][c] 形式的嵌套字段名。
     *
     * @param array<string, mixed> $target
     */
    private function assign(array &$target, string $name, mixed $value): void
    {
        if (!str_contains($name, '[')) {
            $target[$name] = $value;

            return;
        }

        if (!preg_match('/^([^\[]+)((?:\[[^\]]*\])+)$/', $name, $m)) {
            $target[$name] = $value;

            return;
        }

        preg_match_all('/\[([^\]]*)\]/', $m[2], $segments);
        $keys = array_merge([$m[1]], $segments[1]);

        $cursor = &$target;
        $last = array_key_last($keys);
        foreach ($keys as $index => $key) {
            $isLast = $index === $last;
            if ($key === '') {
                if ($isLast) {
                    $cursor[] = $value;

                    return;
                }
                $cursor[] = [];
                $key = array_key_last($cursor);
            } elseif ($isLast) {
                $cursor[$key] = $value;

                return;
            } elseif (!isset($cursor[$key]) || !\is_array($cursor[$key])) {
                $cursor[$key] = [];
            }

            /** @var array<string, mixed> $next */
            $next = &$cursor[$key];
            unset($cursor);
            $cursor = &$next;
            unset($next);
        }
    }
}
