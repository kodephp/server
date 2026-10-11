<?php

declare(strict_types=1);

namespace Kode\Server\Http;

use JsonSerializable;
use Kode\Server\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Stringable;

/**
 * 把闭包适配成 PSR-15 处理器，让 onRequest(fn) 与 handler($psr15) 走同一条通路。
 *
 * 顺带做返回值归一化，这样最小示例不必为了打印一行字去 new 一个 Response：
 *
 *  | 返回值                       | 结果                                  |
 *  |------------------------------|---------------------------------------|
 *  | ResponseInterface            | 原样返回                              |
 *  | string / Stringable          | 200 text/html                         |
 *  | array / JsonSerializable     | 200 application/json                  |
 *  | StreamInterface              | 200，以该流为响应体                   |
 *  | null                         | 204 No Content                        |
 *
 * 其余类型一律抛错——静默把 `false` 变成空响应只会让 bug 藏得更深。
 */
final class CallableHandler implements RequestHandlerInterface
{
    /** @var callable(ServerRequestInterface): mixed */
    private $callback;

    private ?Factories $factories;

    public function __construct(callable $callback, ?Factories $factories = null)
    {
        $this->callback = $callback;
        $this->factories = $factories;
    }

    /**
     * 由 Server 在装配阶段注入，确保和 Dispatcher 用的是同一套工厂。
     */
    public function setFactories(Factories $factories): void
    {
        $this->factories = $factories;
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->coerce(($this->callback)($request));
    }

    private function coerce(mixed $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        $factories = $this->factories ??= Factories::discover();

        if ($result === null) {
            return $factories->response->createResponse(204);
        }

        if ($result instanceof StreamInterface) {
            return $factories->response->createResponse(200)->withBody($result);
        }

        if (\is_string($result) || $result instanceof Stringable) {
            return $this->text($factories, (string) $result);
        }

        if (\is_array($result) || $result instanceof JsonSerializable || $result instanceof \stdClass) {
            return $this->json($factories, $result);
        }

        throw new ServerException(sprintf(
            'onRequest 回调返回了无法转换的 %s。请返回 %s、字符串、数组或 null。',
            get_debug_type($result),
            ResponseInterface::class,
        ));
    }

    private function text(Factories $factories, string $body): ResponseInterface
    {
        return $factories->response->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($factories->stream->createStream($body));
    }

    private function json(Factories $factories, mixed $data): ResponseInterface
    {
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return $factories->response->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($factories->stream->createStream($json));
    }
}
