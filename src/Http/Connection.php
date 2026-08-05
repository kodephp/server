<?php

declare(strict_types=1);

namespace Kode\Server\Http;

use Kode\Server\Config\Limits;
use Kode\Server\Contract\LoopInterface;
use Kode\Server\Enum\Event;
use Kode\Server\Event\Emitter;
use Kode\Server\Exception\ProtocolException;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 单条 TCP 连接的状态机。
 *
 * 职责边界很清楚：**只管字节进出与连接寿命**，
 * 业务逻辑一律交给 Dispatcher。
 *
 * 处理了几个真实环境里躲不掉的问题：
 *  - 部分写：一次 fwrite 写不完就挂写监听，绝不阻塞整个 Worker；
 *  - 大响应：body 按块从流里泵出来，不整包驻留内存；
 *  - 管线化：一次读到多个请求时按序处理、按序回；
 *  - 慢连接：由 Worker 定期扫描超时并回收。
 */
final class Connection
{
    private const READ_CHUNK = 65536;
    private const BODY_CHUNK = 65536;

    /** @var resource|null */
    private $socket;

    private string $outBuffer = '';

    private ?StreamInterface $bodyStream = null;

    private bool $chunked = false;

    private bool $writing = false;

    private bool $waitingWritable = false;

    /** 响应写完后是否关闭连接 */
    private bool $closeAfterWrite = false;

    private bool $closed = false;

    public int $requestCount = 0;

    public float $lastActivity;

    /** Worker 优雅退出时置 false，让在途连接自然收敛 */
    public bool $allowKeepAlive = true;

    private ?ParsedRequest $current = null;

    /**
     * @param resource             $socket
     * @param array<string, mixed> $serverParams
     * @param \Closure(self): void $onClose
     */
    public function __construct(
        $socket,
        private readonly LoopInterface $loop,
        private readonly Limits $limits,
        private readonly Parser $parser,
        private readonly Responder $responder,
        private readonly Dispatcher $dispatcher,
        private readonly array $serverParams,
        private readonly Emitter $emitter,
        private readonly \Closure $onClose,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->socket = $socket;
        $this->lastActivity = microtime(true);
    }

    public function open(): void
    {
        stream_set_blocking($this->socket, false);
        if (\function_exists('stream_set_read_buffer')) {
            @stream_set_read_buffer($this->socket, 0);
        }
        @stream_set_write_buffer($this->socket, 0);

        $this->loop->onReadable($this->socket, $this->onReadable(...));
        $this->emitter->emit(Event::Connect, $this);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @return array<string, mixed>
     */
    public function serverParams(): array
    {
        return $this->serverParams;
    }

    public function remoteAddress(): string
    {
        return (string) ($this->serverParams['REMOTE_ADDR'] ?? '-');
    }

    // ------------------------------------------------------------------
    // 读
    // ------------------------------------------------------------------

    private function onReadable(): void
    {
        if ($this->closed || !\is_resource($this->socket)) {
            return;
        }

        $data = @fread($this->socket, self::READ_CHUNK);

        if ($data === false || ($data === '' && feof($this->socket))) {
            $this->close();

            return;
        }

        if ($data === '') {
            return;
        }

        $this->lastActivity = microtime(true);

        try {
            $this->parser->append($data);
        } catch (ProtocolException $e) {
            $this->failProtocol($e);

            return;
        }

        if ($this->parser->takeContinueFlag()) {
            $this->enqueue("HTTP/1.1 100 Continue\r\n\r\n");
        }

        $this->drain();
    }

    /**
     * 取出缓冲区里所有能处理的请求。
     */
    private function drain(): void
    {
        while (!$this->closed && !$this->writing && !$this->closeAfterWrite) {
            try {
                $request = $this->parser->next();
            } catch (ProtocolException $e) {
                $this->failProtocol($e);

                return;
            }

            if ($request === null) {
                return;
            }

            $this->serve($request);
        }
    }

    private function serve(ParsedRequest $request): void
    {
        $this->current = $request;
        ++$this->requestCount;

        $keepAlive = $request->keepAlive
            && $this->allowKeepAlive
            && $this->limits->keepAlive
            && $this->requestCount < $this->limits->maxKeepAliveRequests;

        try {
            $response = $this->dispatcher->dispatch($request, $this->serverParams);

            $plan = $this->responder->prepare(
                $response,
                $request->protocolVersion,
                $keepAlive,
                $request->method === 'HEAD',
            );

            $this->chunked = $plan['chunked'];
            $this->closeAfterWrite = !$plan['keepAlive'];
            $this->writing = true;
            $this->outBuffer .= $plan['head'];
            $this->bodyStream = $plan['sendBody'] ? $response->getBody() : null;

            if ($this->bodyStream !== null && $this->bodyStream->isSeekable()) {
                try {
                    $this->bodyStream->rewind();
                } catch (Throwable) {
                    // 不可回绕的流按原位置继续读即可
                }
            }

            $this->pump();
        } catch (Throwable $e) {
            $this->logger->error('生成响应失败', ['exception' => $e]);
            $this->closeAfterWrite = true;
            $this->writing = true;
            $this->bodyStream = null;
            $this->outBuffer .= Responder::rawError(500, '', $request->protocolVersion);
            $this->pump();
        } finally {
            $request->close();
            $this->current = null;
        }
    }

    // ------------------------------------------------------------------
    // 写
    // ------------------------------------------------------------------

    private function enqueue(string $data): void
    {
        $this->outBuffer .= $data;
        $this->pump();
    }

    /**
     * 把缓冲区与 body 流尽可能写出去，写不动就挂写监听等下一轮。
     */
    private function pump(): void
    {
        while (!$this->closed) {
            if ($this->outBuffer === '') {
                if ($this->bodyStream === null) {
                    $this->finishWrite();

                    return;
                }

                $chunk = $this->readBodyChunk();
                if ($chunk === '') {
                    continue; // body 读完，下一轮走 finishWrite
                }
                $this->outBuffer .= $chunk;
            }

            if (!\is_resource($this->socket)) {
                $this->close(true);

                return;
            }

            $written = @fwrite($this->socket, $this->outBuffer);

            if ($written === false) {
                $this->close(true);

                return;
            }

            if ($written === 0) {
                $this->waitWritable();

                return;
            }

            $this->lastActivity = microtime(true);
            $this->outBuffer = substr($this->outBuffer, $written);
        }
    }

    /**
     * @return string 空串表示 body 已读完
     */
    private function readBodyChunk(): string
    {
        $body = $this->bodyStream;
        if ($body === null) {
            return '';
        }

        try {
            $data = $body->eof() ? '' : $body->read(self::BODY_CHUNK);
        } catch (Throwable $e) {
            $this->logger->error('读取响应体失败', ['exception' => $e]);
            $data = '';
        }

        if ($data === '') {
            $this->bodyStream = null;
            try {
                $body->close();
            } catch (Throwable) {
                // 忽略：关闭失败不影响连接
            }

            return $this->chunked ? "0\r\n\r\n" : '';
        }

        return $this->chunked
            ? dechex(\strlen($data)) . "\r\n" . $data . "\r\n"
            : $data;
    }

    private function waitWritable(): void
    {
        if ($this->waitingWritable || !\is_resource($this->socket)) {
            return;
        }
        $this->waitingWritable = true;
        $this->loop->onWritable($this->socket, function (): void {
            $this->waitingWritable = false;
            if (\is_resource($this->socket)) {
                $this->loop->offWritable($this->socket);
            }
            $this->pump();
        });
    }

    private function finishWrite(): void
    {
        $this->writing = false;
        $this->chunked = false;

        if ($this->waitingWritable && \is_resource($this->socket)) {
            $this->loop->offWritable($this->socket);
            $this->waitingWritable = false;
        }

        $this->emitter->emit(Event::Response, $this);

        if ($this->closeAfterWrite) {
            $this->close();

            return;
        }

        $this->lastActivity = microtime(true);
        $this->drain(); // 管线化：接着处理缓冲区里的下一个请求
    }

    // ------------------------------------------------------------------
    // 生命周期
    // ------------------------------------------------------------------

    private function failProtocol(ProtocolException $e): void
    {
        $this->logger->notice('协议错误 {status}：{message}', [
            'status' => $e->status,
            'message' => $e->getMessage(),
            'remote' => $this->remoteAddress(),
        ]);

        $this->bodyStream = null;
        $this->closeAfterWrite = true;
        $this->writing = true;
        $this->outBuffer .= Responder::rawError($e->status, $e->getMessage());
        $this->pump();
    }

    /**
     * 检查各类超时，返回 true 表示连接已被回收。
     */
    public function checkTimeout(float $now): bool
    {
        if ($this->closed) {
            return true;
        }

        $idle = $now - $this->lastActivity;

        if ($this->writing) {
            if ($idle > $this->limits->writeTimeout) {
                $this->logger->info('响应写出超时，关闭连接', ['remote' => $this->remoteAddress()]);
                $this->close(true);

                return true;
            }

            return false;
        }

        if (!$this->parser->isIdle()) {
            // 请求收了一半就不动了：典型的 slowloris
            if ($idle > max($this->limits->headerTimeout, $this->limits->bodyTimeout)) {
                $this->logger->info('请求接收超时，关闭连接', ['remote' => $this->remoteAddress()]);
                $this->outBuffer .= Responder::rawError(408, '请求接收超时');
                $this->closeAfterWrite = true;
                $this->writing = true;
                $this->pump();

                return $this->closed;
            }

            return false;
        }

        if ($idle > $this->limits->keepAliveTimeout) {
            $this->close();

            return true;
        }

        return false;
    }

    /**
     * 优雅关闭：允许把已排队的响应写完。
     */
    public function shutdown(): void
    {
        $this->allowKeepAlive = false;
        $this->closeAfterWrite = true;

        if (!$this->writing && $this->outBuffer === '') {
            $this->close();
        }
    }

    public function close(bool $force = false): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (\is_resource($this->socket)) {
            $this->loop->offReadable($this->socket);
            if ($this->waitingWritable) {
                $this->loop->offWritable($this->socket);
                $this->waitingWritable = false;
            }

            if (!$force) {
                // 让内核把剩余数据发完，避免客户端读到 RST
                @stream_socket_shutdown($this->socket, \STREAM_SHUT_WR);
            }
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->bodyStream = null;
        $this->outBuffer = '';
        $this->current?->close();
        $this->current = null;

        $this->emitter->emit(Event::Close, $this);
        ($this->onClose)($this);
    }
}
