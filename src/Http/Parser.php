<?php

declare(strict_types=1);

namespace Kode\Server\Http;

use Kode\Server\Config\Limits;
use Kode\Server\Exception\ProtocolException;

/**
 * 增量式 HTTP/1.x 请求解析器（每条连接一个实例）。
 *
 * 之所以自己写而不是攒字符串再 explode：
 *  1. 必须支持"数据分片到达"——TCP 不保证一个请求一个包；
 *  2. 必须支持长连接与管线化——一个缓冲区里可能躺着好几个请求；
 *  3. 必须在**读的过程中**就掐掉超限请求，而不是读完再判断。
 *
 * 安全上着重防了三件事：超大头部（431）、超大 body（413）、
 * 以及 Content-Length 与 Transfer-Encoding 同时出现的请求走私（400）。
 */
final class Parser
{
    private const STATE_HEAD = 0;
    private const STATE_BODY = 1;
    private const STATE_CHUNK_SIZE = 2;
    private const STATE_CHUNK_DATA = 3;
    private const STATE_CHUNK_CRLF = 4;
    private const STATE_TRAILER = 5;
    private const STATE_DONE = 6;

    private const MAX_CHUNK_LINE = 1024;
    private const MAX_TRAILER_SIZE = 8192;

    /** token 允许的字符（RFC 7230 3.2.6） */
    private const TOKEN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    private string $buffer = '';

    private int $state = self::STATE_HEAD;

    private bool $needsContinue = false;

    /** 解析中的请求头信息 */
    private string $method = '';
    private string $target = '';
    private string $version = '1.1';

    /** @var array<string, list<string>> */
    private array $headers = [];

    private bool $keepAlive = true;

    /** @var resource|null */
    private $body = null;

    private int $bodyWritten = 0;

    private int $remaining = 0;

    private int $trailerSize = 0;

    public function __construct(private readonly Limits $limits)
    {
    }

    /**
     * 追加从套接字读到的数据。
     */
    public function append(string $data): void
    {
        if ($data === '') {
            return;
        }
        $this->buffer .= $data;

        if ($this->state !== self::STATE_HEAD) {
            return;
        }

        // 头部区内已超限（且尚未出现终止符），立即拒绝，避免缓冲区被撑爆。
        // 已出现终止符时交给 parseHead() 处理（它会对"完整的超大头部"同样报错）。
        if (
            \strlen($this->buffer) > $this->limits->maxHeaderSize
            && !str_contains($this->buffer, "\r\n\r\n")
            && !str_contains($this->buffer, "\n\n")
        ) {
            throw ProtocolException::headerTooLarge($this->limits->maxHeaderSize);
        }

        // 头部已经完整收齐：提前解析，让上层尽早拿到 Expect: 100-continue 等信号。
        // 连接层需要在读取 body 之前先回 100 Continue，而此时 body 往往还没到达。
        if (str_contains($this->buffer, "\r\n\r\n") || str_contains($this->buffer, "\n\n")) {
            $this->parseHead();
        }
    }

    /**
     * 取出下一个完整请求；数据不足时返回 null。
     *
     * @throws ProtocolException 报文非法或超限
     */
    public function next(): ?ParsedRequest
    {
        while (true) {
            switch ($this->state) {
                case self::STATE_HEAD:
                    if (!$this->parseHead()) {
                        return null;
                    }
                    break;

                case self::STATE_BODY:
                    if (!$this->readFixedBody()) {
                        return null;
                    }
                    break;

                case self::STATE_CHUNK_SIZE:
                case self::STATE_CHUNK_DATA:
                case self::STATE_CHUNK_CRLF:
                case self::STATE_TRAILER:
                    if (!$this->readChunked()) {
                        return null;
                    }
                    break;

                case self::STATE_DONE:
                    return $this->finish();
            }
        }
    }

    /**
     * 客户端发了 Expect: 100-continue，连接层需要先回一行再继续读 body。
     * 该标记只会被取走一次。
     */
    public function takeContinueFlag(): bool
    {
        if (!$this->needsContinue) {
            return false;
        }
        $this->needsContinue = false;

        return true;
    }

    /**
     * 连接空闲（没有半截请求挂着）时才可以安全地关闭或复用。
     */
    public function isIdle(): bool
    {
        return $this->state === self::STATE_HEAD && trim($this->buffer) === '';
    }

    public function bufferSize(): int
    {
        return \strlen($this->buffer);
    }

    // ------------------------------------------------------------------
    // 头部
    // ------------------------------------------------------------------

    private function parseHead(): bool
    {
        // 宽容处理请求前的空行（RFC 允许忽略）
        $this->buffer = ltrim($this->buffer, "\r\n");

        $end = strpos($this->buffer, "\r\n\r\n");
        $sepLength = 4;
        $lfEnd = strpos($this->buffer, "\n\n");
        if ($lfEnd !== false && ($end === false || $lfEnd < $end)) {
            $end = $lfEnd;
            $sepLength = 2;
        }

        if ($end === false) {
            if (\strlen($this->buffer) > $this->limits->maxHeaderSize) {
                throw ProtocolException::headerTooLarge($this->limits->maxHeaderSize);
            }

            return false;
        }

        if ($end + $sepLength > $this->limits->maxHeaderSize) {
            throw ProtocolException::headerTooLarge($this->limits->maxHeaderSize);
        }

        $head = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + $sepLength);

        $lines = preg_split('/\r?\n/', $head) ?: [];
        $requestLine = array_shift($lines);
        if (!\is_string($requestLine) || $requestLine === '') {
            throw ProtocolException::badRequest('缺少请求行');
        }

        $this->parseRequestLine($requestLine);
        $this->parseHeaderLines($lines);
        $this->resolveFraming();

        return true;
    }

    private function parseRequestLine(string $line): void
    {
        $parts = explode(' ', $line);
        if (\count($parts) !== 3) {
            throw ProtocolException::badRequest('请求行格式非法');
        }
        [$method, $target, $protocol] = $parts;

        if (!preg_match(self::TOKEN, $method)) {
            throw ProtocolException::badRequest('请求方法非法');
        }
        if ($target === '') {
            throw ProtocolException::badRequest('请求目标为空');
        }
        if (\strlen($target) > $this->limits->maxUriLength) {
            throw ProtocolException::uriTooLong($this->limits->maxUriLength);
        }
        if (!preg_match('#^HTTP/(\d)\.(\d)$#', $protocol, $m)) {
            throw ProtocolException::badRequest('协议版本格式非法');
        }
        if ($m[1] !== '1') {
            throw ProtocolException::versionNotSupported($protocol);
        }

        $this->method = strtoupper($method);
        $this->target = $target;
        $this->version = $m[1] . '.' . $m[2];
        $this->headers = [];
    }

    /**
     * @param list<string> $lines
     */
    private function parseHeaderLines(array $lines): void
    {
        $count = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                // obs-fold 已被 RFC 7230 废弃，且是请求走私的常见载体
                throw ProtocolException::badRequest('不接受折行的请求头');
            }
            if (++$count > $this->limits->maxHeaderCount) {
                throw ProtocolException::badRequest(sprintf('请求头数量超过 %d 条限制', $this->limits->maxHeaderCount));
            }

            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                throw ProtocolException::badRequest('请求头格式非法');
            }

            $name = substr($line, 0, $colon);
            if (!preg_match(self::TOKEN, $name)) {
                throw ProtocolException::badRequest('请求头名称非法');
            }

            $value = trim(substr($line, $colon + 1), " \t");
            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)) {
                throw ProtocolException::badRequest('请求头取值包含控制字符');
            }

            $existing = $this->findHeaderKey($name);
            if ($existing !== null) {
                $this->headers[$existing][] = $value;
            } else {
                $this->headers[$name] = [$value];
            }
        }
    }

    private function findHeaderKey(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $_) {
            if (strtolower($key) === $lower) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function headerValues(string $name): array
    {
        $key = $this->findHeaderKey($name);

        return $key === null ? [] : $this->headers[$key];
    }

    private function headerLine(string $name): ?string
    {
        $values = $this->headerValues($name);

        return $values === [] ? null : implode(', ', $values);
    }

    /**
     * 判定 body 的分帧方式，并顺带确定长连接语义。
     */
    private function resolveFraming(): void
    {
        $transferEncoding = $this->headerLine('Transfer-Encoding');
        $contentLengths = $this->headerValues('Content-Length');

        if ($this->version === '1.1' && $this->headerLine('Host') === null) {
            throw ProtocolException::badRequest('HTTP/1.1 请求缺少 Host 头');
        }

        // 请求走私防护：两种分帧同时出现时一律拒绝
        if ($transferEncoding !== null && $contentLengths !== []) {
            throw ProtocolException::badRequest('Content-Length 与 Transfer-Encoding 不能同时出现');
        }

        $this->keepAlive = $this->resolveKeepAlive();
        $this->needsContinue = stripos($this->headerLine('Expect') ?? '', '100-continue') !== false;
        $this->bodyWritten = 0;
        $this->trailerSize = 0;
        $this->body = null;

        if ($transferEncoding !== null) {
            $encodings = array_map(
                static fn (string $v): string => strtolower(trim($v)),
                explode(',', $transferEncoding),
            );
            if (end($encodings) !== 'chunked') {
                throw new ProtocolException('仅支持 chunked 传输编码', 501);
            }
            $this->body = $this->createBodyStream();
            $this->state = self::STATE_CHUNK_SIZE;

            return;
        }

        if ($contentLengths !== []) {
            $unique = array_unique($contentLengths);
            if (\count($unique) > 1) {
                throw ProtocolException::badRequest('存在互相矛盾的 Content-Length');
            }
            $raw = trim($contentLengths[0]);
            if (!preg_match('/^\d+$/', $raw)) {
                throw ProtocolException::badRequest('Content-Length 非法');
            }
            $length = (int) $raw;
            $this->guardBodySize($length);

            if ($length > 0) {
                $this->body = $this->createBodyStream();
                $this->remaining = $length;
                $this->state = self::STATE_BODY;

                return;
            }
        }

        $this->state = self::STATE_DONE;
    }

    private function resolveKeepAlive(): bool
    {
        if (!$this->limits->keepAlive) {
            return false;
        }

        $connection = strtolower($this->headerLine('Connection') ?? '');
        if (str_contains($connection, 'close')) {
            return false;
        }
        if ($this->version === '1.0') {
            return str_contains($connection, 'keep-alive');
        }

        return true;
    }

    // ------------------------------------------------------------------
    // 请求体
    // ------------------------------------------------------------------

    private function readFixedBody(): bool
    {
        if ($this->remaining > 0) {
            if ($this->buffer === '') {
                return false;
            }
            $take = min($this->remaining, \strlen($this->buffer));
            $this->writeBody(substr($this->buffer, 0, $take));
            $this->buffer = substr($this->buffer, $take);
            $this->remaining -= $take;
        }

        if ($this->remaining > 0) {
            return false;
        }

        $this->state = self::STATE_DONE;

        return true;
    }

    private function readChunked(): bool
    {
        while (true) {
            switch ($this->state) {
                case self::STATE_CHUNK_SIZE:
                    $line = $this->takeLine(self::MAX_CHUNK_LINE);
                    if ($line === null) {
                        return false;
                    }
                    $size = trim(explode(';', $line, 2)[0]);
                    if ($size === '' || !preg_match('/^[0-9a-fA-F]+$/', $size)) {
                        throw ProtocolException::badRequest('chunk 长度非法');
                    }
                    $this->remaining = (int) hexdec($size);
                    $this->guardBodySize($this->bodyWritten + $this->remaining);
                    $this->state = $this->remaining === 0 ? self::STATE_TRAILER : self::STATE_CHUNK_DATA;
                    break;

                case self::STATE_CHUNK_DATA:
                    if ($this->buffer === '') {
                        return false;
                    }
                    $take = min($this->remaining, \strlen($this->buffer));
                    $this->writeBody(substr($this->buffer, 0, $take));
                    $this->buffer = substr($this->buffer, $take);
                    $this->remaining -= $take;
                    if ($this->remaining > 0) {
                        return false;
                    }
                    $this->state = self::STATE_CHUNK_CRLF;
                    break;

                case self::STATE_CHUNK_CRLF:
                    $line = $this->takeLine(2);
                    if ($line === null) {
                        return false;
                    }
                    if ($line !== '') {
                        throw ProtocolException::badRequest('chunk 结尾缺少 CRLF');
                    }
                    $this->state = self::STATE_CHUNK_SIZE;
                    break;

                case self::STATE_TRAILER:
                    $line = $this->takeLine(self::MAX_CHUNK_LINE);
                    if ($line === null) {
                        return false;
                    }
                    if ($line === '') {
                        $this->state = self::STATE_DONE;

                        return true;
                    }
                    // trailer 字段按 RFC 允许忽略，但要防止无限灌入
                    $this->trailerSize += \strlen($line);
                    if ($this->trailerSize > self::MAX_TRAILER_SIZE) {
                        throw ProtocolException::badRequest('trailer 区超限');
                    }
                    break;

                default:
                    return true;
            }
        }
    }

    /**
     * 从缓冲区取一行（不含换行符）；不足一行时返回 null。
     */
    private function takeLine(int $maxLength): ?string
    {
        $pos = strpos($this->buffer, "\n");
        if ($pos === false) {
            if (\strlen($this->buffer) > $maxLength) {
                throw ProtocolException::badRequest('分块行超长');
            }

            return null;
        }
        if ($pos > $maxLength) {
            throw ProtocolException::badRequest('分块行超长');
        }

        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);

        return rtrim($line, "\r");
    }

    private function guardBodySize(int $size): void
    {
        if ($this->limits->maxBodySize > 0 && $size > $this->limits->maxBodySize) {
            throw ProtocolException::payloadTooLarge($this->limits->maxBodySize);
        }
    }

    private function writeBody(string $data): void
    {
        if ($data === '') {
            return;
        }
        if (!\is_resource($this->body)) {
            $this->body = $this->createBodyStream();
        }
        $written = @fwrite($this->body, $data);
        if ($written === false) {
            throw new ProtocolException('写入请求体缓冲失败', 500);
        }
        $this->bodyWritten += $written;
        $this->guardBodySize($this->bodyWritten);
    }

    /**
     * @return resource
     */
    private function createBodyStream()
    {
        $target = 'php://temp/maxmemory:' . $this->limits->bodyMemoryLimit;
        $stream = @fopen($target, 'w+b');
        if ($stream === false) {
            throw new ProtocolException('无法创建请求体缓冲', 500);
        }

        return $stream;
    }

    private function finish(): ParsedRequest
    {
        if (\is_resource($this->body)) {
            rewind($this->body);
        }

        $request = new ParsedRequest(
            method: $this->method,
            target: $this->target,
            protocolVersion: $this->version,
            headers: $this->headers,
            body: $this->body ?? $this->createBodyStream(),
            bodyLength: $this->bodyWritten,
            keepAlive: $this->keepAlive,
        );

        $this->state = self::STATE_HEAD;
        $this->body = null;
        $this->bodyWritten = 0;
        $this->remaining = 0;
        $this->headers = [];

        return $request;
    }
}
