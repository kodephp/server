<?php

declare(strict_types=1);

namespace Kode\Server\Tests\Unit;

use Kode\Server\Config\Limits;
use Kode\Server\Exception\ProtocolException;
use Kode\Server\Http\ParsedRequest;
use Kode\Server\Http\Parser;
use PHPUnit\Framework\TestCase;

/**
 * HTTP/1.1 增量解析器。
 *
 * 这里的用例大多来自"真实世界里会打过来的畸形报文"——
 * 解析器是唯一直面不可信输入的组件，宁可测得啰嗦。
 */
final class ParserTest extends TestCase
{
    public function testSimpleGet(): void
    {
        $parser = new Parser(new Limits());
        $parser->append("GET /hello?a=1 HTTP/1.1\r\nHost: example.com\r\n\r\n");

        $request = $parser->next();

        self::assertInstanceOf(ParsedRequest::class, $request);
        self::assertSame('GET', $request->method);
        self::assertSame('/hello?a=1', $request->target);
        self::assertSame('1.1', $request->protocolVersion);
        self::assertSame('example.com', $request->header('host'));
        self::assertTrue($request->keepAlive);
        self::assertSame(0, $request->bodyLength);

        self::assertNull($parser->next(), '缓冲区已空');
        $request->close();
    }

    public function testRequestArrivingOneByteAtATime(): void
    {
        $raw = "POST /submit HTTP/1.1\r\nHost: a\r\nContent-Length: 11\r\n\r\nhello world";
        $parser = new Parser(new Limits());

        // 逐字节喂入：真实网络里报文被 TCP 切碎是常态，解析器必须是增量的
        foreach (str_split($raw) as $i => $byte) {
            $parser->append($byte);
            if ($i < \strlen($raw) - 1) {
                self::assertNull($parser->next(), '报文未完整时不应产出请求');
            }
        }

        $request = $parser->next();
        self::assertInstanceOf(ParsedRequest::class, $request);
        self::assertSame(11, $request->bodyLength);
        self::assertSame('hello world', stream_get_contents($request->body));
        $request->close();
    }

    public function testPipelinedRequests(): void
    {
        $parser = new Parser(new Limits());
        $parser->append(
            "GET /one HTTP/1.1\r\nHost: a\r\n\r\n"
            . "GET /two HTTP/1.1\r\nHost: a\r\n\r\n"
            . "GET /three HTTP/1.1\r\nHost: a\r\n\r\n",
        );

        $targets = [];
        while (($request = $parser->next()) !== null) {
            $targets[] = $request->target;
            $request->close();
        }

        self::assertSame(['/one', '/two', '/three'], $targets);
    }

    public function testChunkedTransferEncoding(): void
    {
        $parser = new Parser(new Limits());
        $parser->append(
            "POST /upload HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nhello\r\n"
            . "1b\r\n, this is a chunked payload\r\n"
            . "0\r\n\r\n",
        );

        $request = $parser->next();

        self::assertInstanceOf(ParsedRequest::class, $request);
        self::assertSame('hello, this is a chunked payload', stream_get_contents($request->body));
        self::assertSame(32, $request->bodyLength);
        $request->close();
    }

    public function testChunkedWithExtensionsAndTrailers(): void
    {
        $parser = new Parser(new Limits());
        $parser->append(
            "POST /x HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "4;name=value\r\nabcd\r\n"
            . "0\r\nX-Checksum: deadbeef\r\n\r\n",
        );

        $request = $parser->next();

        self::assertInstanceOf(ParsedRequest::class, $request);
        self::assertSame('abcd', stream_get_contents($request->body));
        $request->close();
    }

    public function testHttp10DefaultsToClose(): void
    {
        $parser = new Parser(new Limits());
        $parser->append("GET / HTTP/1.0\r\nHost: a\r\n\r\n");

        $request = $parser->next();
        self::assertInstanceOf(ParsedRequest::class, $request);
        self::assertFalse($request->keepAlive);
        $request->close();
    }

    public function testHttp10WithExplicitKeepAlive(): void
    {
        $parser = new Parser(new Limits());
        $parser->append("GET / HTTP/1.0\r\nHost: a\r\nConnection: keep-alive\r\n\r\n");

        $request = $parser->next();
        self::assertInstanceOf(ParsedRequest::class, $request);
        self::assertTrue($request->keepAlive);
        $request->close();
    }

    public function testConnectionCloseIsHonoured(): void
    {
        $parser = new Parser(new Limits());
        $parser->append("GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n");

        $request = $parser->next();
        self::assertInstanceOf(ParsedRequest::class, $request);
        self::assertFalse($request->keepAlive);
        $request->close();
    }

    public function testExpect100Continue(): void
    {
        $parser = new Parser(new Limits());
        $parser->append("POST / HTTP/1.1\r\nHost: a\r\nExpect: 100-continue\r\nContent-Length: 2\r\n\r\nhi");

        self::assertTrue($parser->takeContinueFlag(), '应提示上层先回 100 Continue');
        self::assertFalse($parser->takeContinueFlag(), '标志只应被取走一次');

        $request = $parser->next();
        self::assertInstanceOf(ParsedRequest::class, $request);
        $request->close();
    }

    public function testHeaderTooLargeIsRejected(): void
    {
        $parser = new Parser(new Limits(maxHeaderSize: 256));

        $this->expectException(ProtocolException::class);
        $parser->append("GET / HTTP/1.1\r\nHost: a\r\nX-Big: " . str_repeat('x', 512) . "\r\n\r\n");
    }

    public function testUriTooLongIsRejected(): void
    {
        $parser = new Parser(new Limits(maxUriLength: 64));

        $this->expectException(ProtocolException::class);
        $parser->append('GET /' . str_repeat('a', 128) . " HTTP/1.1\r\nHost: a\r\n\r\n");
        $parser->next();
    }

    public function testBodyTooLargeIsRejected(): void
    {
        $parser = new Parser(new Limits(maxBodySize: 16, bodyMemoryLimit: 8));

        $this->expectException(ProtocolException::class);
        $parser->append("POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 1024\r\n\r\n");
        $parser->next();
    }

    public function testTooManyHeadersIsRejected(): void
    {
        $parser = new Parser(new Limits(maxHeaderSize: 1024 * 1024, maxHeaderCount: 3));

        $headers = '';
        for ($i = 0; $i < 10; ++$i) {
            $headers .= "X-H{$i}: v\r\n";
        }

        $this->expectException(ProtocolException::class);
        $parser->append("GET / HTTP/1.1\r\nHost: a\r\n{$headers}\r\n");
        $parser->next();
    }

    public function testUnsupportedVersionIsRejected(): void
    {
        $parser = new Parser(new Limits());

        $this->expectException(ProtocolException::class);
        $parser->append("GET / HTTP/2.0\r\nHost: a\r\n\r\n");
        $parser->next();
    }

    public function testMalformedRequestLineIsRejected(): void
    {
        $parser = new Parser(new Limits());

        $this->expectException(ProtocolException::class);
        $parser->append("GET_ONLY_ONE_TOKEN\r\n\r\n");
        $parser->next();
    }

    /**
     * 请求走私（HTTP Request Smuggling）的经典入口：
     * 同时给出 Content-Length 和 Transfer-Encoding。规范要求忽略 CL 或直接拒绝。
     */
    public function testConflictingLengthHeadersDoNotSmuggle(): void
    {
        $parser = new Parser(new Limits());

        try {
            $parser->append(
                "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 6\r\nTransfer-Encoding: chunked\r\n\r\n"
                . "0\r\n\r\n",
            );
            $request = $parser->next();
        } catch (ProtocolException) {
            self::assertTrue(true, '直接拒绝也是合规做法');

            return;
        }

        self::assertInstanceOf(ParsedRequest::class, $request);
        self::assertSame(0, $request->bodyLength, 'chunked 优先，不能按 Content-Length 读出多余字节');
        $request->close();
    }

    public function testIsIdleReflectsBufferState(): void
    {
        $parser = new Parser(new Limits());
        self::assertTrue($parser->isIdle());

        $parser->append('GET / HTT');
        self::assertFalse($parser->isIdle(), '半个报文在手时不算空闲');
        self::assertGreaterThan(0, $parser->bufferSize());
    }
}
