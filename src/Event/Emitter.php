<?php

declare(strict_types=1);

namespace Kode\Server\Event;

use Kode\Server\Enum\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 生命周期事件发射器。
 *
 * 刻意不引入 psr/event-dispatcher：生命周期钩子的调用极其频繁（每连接、每请求），
 * 为它们分配事件对象得不偿失。这里只做三件事——注册、按优先级排序、隔离异常派发。
 *
 * **异常隔离**是关键：某个监听器抛异常绝不能掀翻整个 Worker，
 * 异常会被记录并转发到 Event::Error，服务继续运行。
 */
final class Emitter
{
    /** @var array<string, list<array{callable, int, int}>> 事件 => [回调, 优先级, 序号] */
    private array $listeners = [];

    /** @var array<string, list<callable>> 排序后的缓存 */
    private array $sorted = [];

    private int $sequence = 0;

    private bool $inErrorDispatch = false;

    public function __construct(private LoggerInterface $logger = new NullLogger())
    {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 注册监听器，优先级越大越先执行。
     */
    public function on(Event $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event->value][] = [$listener, $priority, $this->sequence++];
        unset($this->sorted[$event->value]);
    }

    /**
     * 只监听一次。
     */
    public function once(Event $event, callable $listener, int $priority = 0): void
    {
        $wrapper = function (mixed ...$args) use ($event, &$wrapper, $listener): void {
            $this->off($event, $wrapper);
            $listener(...$args);
        };
        $this->on($event, $wrapper, $priority);
    }

    public function off(Event $event, ?callable $listener = null): void
    {
        if ($listener === null) {
            unset($this->listeners[$event->value], $this->sorted[$event->value]);

            return;
        }

        $this->listeners[$event->value] = array_values(array_filter(
            $this->listeners[$event->value] ?? [],
            static fn (array $entry): bool => $entry[0] !== $listener,
        ));
        unset($this->sorted[$event->value]);
    }

    public function has(Event $event): bool
    {
        return ($this->listeners[$event->value] ?? []) !== [];
    }

    /**
     * 派发事件；任何监听器异常都不会向上冒泡。
     */
    public function emit(Event $event, mixed ...$args): void
    {
        foreach ($this->resolve($event) as $listener) {
            try {
                $listener(...$args);
            } catch (Throwable $e) {
                $this->handleListenerFailure($event, $e);
            }
        }
    }

    /**
     * 派发并允许监听器返回值，返回第一个非 null 结果。
     * 用于 Event::Request 这类"需要结果"的钩子。
     */
    public function emitFirst(Event $event, mixed ...$args): mixed
    {
        foreach ($this->resolve($event) as $listener) {
            try {
                $result = $listener(...$args);
                if ($result !== null) {
                    return $result;
                }
            } catch (Throwable $e) {
                if ($event === Event::Request) {
                    throw $e; // 请求处理异常交由 HTTP 层转成 500
                }
                $this->handleListenerFailure($event, $e);
            }
        }

        return null;
    }

    /**
     * fork 之后子进程需要清掉只属于父进程的监听器时使用。
     */
    public function clear(): void
    {
        $this->listeners = [];
        $this->sorted = [];
    }

    /**
     * @return list<callable>
     */
    private function resolve(Event $event): array
    {
        if (isset($this->sorted[$event->value])) {
            return $this->sorted[$event->value];
        }

        $entries = $this->listeners[$event->value] ?? [];
        usort($entries, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);

        return $this->sorted[$event->value] = array_map(static fn (array $e): callable => $e[0], $entries);
    }

    private function handleListenerFailure(Event $event, Throwable $e): void
    {
        $this->logger->error('事件监听器抛出异常：{event}', ['event' => $event->value, 'exception' => $e]);

        if ($event === Event::Error || $this->inErrorDispatch) {
            return; // 防止错误处理器自身出错造成无限递归
        }

        $this->inErrorDispatch = true;
        try {
            $this->emit(Event::Error, $e, $event);
        } finally {
            $this->inErrorDispatch = false;
        }
    }
}
