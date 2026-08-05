<?php

declare(strict_types=1);

namespace Kode\Server\Http;

use Kode\Server\Enum\Event;
use Kode\Server\Event\Emitter;
use Kode\Server\Exception\ProtocolException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 请求派发：连接层与业务处理器之间的唯一通道。
 *
 * 所有驱动（stream / swoole / single）都复用它，
 * 保证"异常怎么变成 500""事件什么时候触发"在各运行时下表现一致。
 *
 * **任何业务异常都不会逃出这里**——Worker 不能因为一个控制器写错就退出。
 */
final class Dispatcher
{
    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private readonly RequestBuilder $builder,
        private readonly Factories $factories,
        private readonly Emitter $emitter,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $debug = false,
    ) {
    }

    /**
     * @param array<string, mixed> $serverParams
     */
    public function dispatch(ParsedRequest $raw, array $serverParams = []): ResponseInterface
    {
        $request = null;

        try {
            $request = $this->builder->build($raw, $serverParams);

            return $this->handle($request);
        } catch (ProtocolException $e) {
            $this->logger->notice('请求报文非法：{message}', ['message' => $e->getMessage()]);

            return $this->status($e->status, $e->getMessage());
        } catch (Throwable $e) {
            return $this->renderThrowable($e, $request);
        }
    }

    /**
     * 已经拿到 PSR-7 请求时的入口（Swoole 驱动走这条）。
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // 监听器可以短路整个请求，语义上等价于最外层中间件
            $short = $this->emitter->emitFirst(Event::Request, $request);
            if ($short instanceof ResponseInterface) {
                return $short;
            }

            return $this->handler->handle($request);
        } catch (Throwable $e) {
            return $this->renderThrowable($e, $request);
        }
    }

    public function renderThrowable(Throwable $e, ?ServerRequestInterface $request): ResponseInterface
    {
        $this->logger->error('请求处理异常：{message}', [
            'message' => $e->getMessage(),
            'uri' => $request?->getUri()->__toString(),
            'exception' => $e,
        ]);

        // 业务可以注册 Error 监听器返回自定义错误页
        try {
            $custom = $this->emitter->emitFirst(Event::Error, $e, $request);
            if ($custom instanceof ResponseInterface) {
                return $custom;
            }
        } catch (Throwable $inner) {
            $this->logger->critical('错误处理器自身抛出异常', ['exception' => $inner]);
        }

        if ($e instanceof ProtocolException) {
            return $this->status($e->status, $e->getMessage());
        }

        $detail = $this->debug
            ? sprintf("%s: %s\n%s:%d\n%s", $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString())
            : '';

        return $this->status(500, $detail);
    }

    public function status(int $status, string $detail = ''): ResponseInterface
    {
        $reason = Responder::reason($status);
        $response = $this->factories->response->createResponse($status);

        $body = $detail === ''
            ? sprintf("<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>%d %s</title></head><body><h1>%d %s</h1></body></html>", $status, $reason, $status, $reason)
            : sprintf("<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>%d %s</title></head><body><h1>%d %s</h1><pre>%s</pre></body></html>", $status, $reason, $status, $reason, htmlspecialchars($detail, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->factories->stream->createStream($body));
    }

    public function factories(): Factories
    {
        return $this->factories;
    }
}
