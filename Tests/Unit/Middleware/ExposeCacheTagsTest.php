<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Middleware;

use Fastly\Cdn\Middleware\ExposeCacheTags;
use Fastly\Cdn\Service\SurrogateKeyHasher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExposeCacheTagsTest extends UnitTestCase
{
    private function makeMiddleware(): ExposeCacheTags
    {
        return new ExposeCacheTags(new SurrogateKeyHasher());
    }

    private function makeCollector(array $tagNames): object
    {
        $tags = array_map(static fn (string $name): CacheTag => new CacheTag($name), $tagNames);
        return new readonly class($tags) {
            public function __construct(private array $tags) {}

            public function getCacheTags(): array
            {
                return $this->tags;
            }
        };
    }

    private function makeRequest(array $tagNames = []): ServerRequestInterface
    {
        $collector = $this->makeCollector($tagNames);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnMap([
            ['frontend.cache.collector', null, $collector],
        ]);

        return $request;
    }

    private function makeHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    // -------------------------------------------------------------------------
    // process()
    // -------------------------------------------------------------------------

    public function testSurrogateKeyHeaderIsSetUnconditionally(): void
    {
        // No reverse-proxy or x-varnish detection exists — the header is set
        // on every request, regardless of what sits in front of TYPO3.
        $middleware = $this->makeMiddleware();
        $response = $this->createMock(ResponseInterface::class);
        $modifiedResponse = $this->createStub(ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Surrogate-Key', self::isType('string'))
            ->willReturn($modifiedResponse);

        $result = $middleware->process(
            $this->makeRequest(['pages_1']),
            $this->makeHandler($response),
        );

        $this->assertSame($modifiedResponse, $result);
    }

    public function testMultipleTagsPreserveCollectorOrder(): void
    {
        // Tag order is not sorted — the middleware emits tags in whatever
        // order the cache collector returns them.
        $middleware = $this->makeMiddleware();
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        $middleware->process(
            $this->makeRequest(['tt_content_2', 'pages_1']),
            $this->makeHandler($response),
        );

        $this->assertSame('tt_content_2 pages_1', $capturedValue);
    }

    public function testSurrogateKeyValueIsSpaceSeparatedLowercasedTagNames(): void
    {
        $middleware = $this->makeMiddleware();
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        $middleware->process(
            $this->makeRequest(['Pages_1', 'TT_Content_2']),
            $this->makeHandler($response),
        );

        $this->assertSame('pages_1 tt_content_2', $capturedValue);
    }

    public function testEmptyTagArrayResultsInEmptySurrogateKey(): void
    {
        $middleware = $this->makeMiddleware();
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        $middleware->process(
            $this->makeRequest([]),
            $this->makeHandler($response),
        );

        $this->assertSame('', $capturedValue);
    }

    // -------------------------------------------------------------------------
    // header-size guard
    // -------------------------------------------------------------------------

    public function testHeaderStaysPlaintextWhenJoinedTagsAreUnderMaxLength(): void
    {
        $middleware = $this->makeMiddleware();
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        $tagNames = array_map(static fn (int $i): string => sprintf('tt_content_%d', $i), range(1, 5));
        $middleware->process(
            $this->makeRequest($tagNames),
            $this->makeHandler($response),
        );

        $this->assertSame(implode(' ', $tagNames), $capturedValue);
    }

    public function testHeaderIsHashedWhenJoinedTagsExceedMaxLength(): void
    {
        $hasher = new SurrogateKeyHasher();
        $middleware = new ExposeCacheTags($hasher);
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        // 900 synthetic tags push the joined plaintext header well past the
        // 12,000 char threshold (the AGENTS.md-mandated header-size guard).
        $tagNames = array_map(static fn (int $i): string => sprintf('tt_content_%d', $i), range(1, 900));
        $middleware->process(
            $this->makeRequest($tagNames),
            $this->makeHandler($response),
        );

        $this->assertLessThanOrEqual(12_000, strlen($capturedValue));
        $hashedTags = explode(' ', $capturedValue);
        $this->assertCount(900, $hashedTags);
        foreach ($hashedTags as $index => $hashedTag) {
            $this->assertSame($hasher->hash($tagNames[$index]), $hashedTag);
        }
    }
}
