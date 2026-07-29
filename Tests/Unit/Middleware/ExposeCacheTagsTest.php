<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Middleware;

use Fastly\Cdn\Middleware\ExposeCacheTags;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExposeCacheTagsTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
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

    private function makeRequest(bool $behindProxy, bool $hasVarnishHeader, array $tagNames = []): ServerRequestInterface
    {
        $normalizedParams = $this->createMock(NormalizedParams::class);
        $normalizedParams->method('isBehindReverseProxy')->willReturn($behindProxy);

        $collector = $this->makeCollector($tagNames);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnMap([
            ['normalizedParams', null, $normalizedParams],
            ['frontend.cache.collector', null, $collector],
        ]);
        $request->method('hasHeader')->with('x-varnish')->willReturn($hasVarnishHeader);

        return $request;
    }

    private function makeHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    // -------------------------------------------------------------------------
    // process() — proxy detection
    // -------------------------------------------------------------------------

    public function testBehindReverseProxyAddsSurrogateKeyHeader(): void
    {
        $middleware = new ExposeCacheTags();
        $response = $this->createMock(ResponseInterface::class);
        $modifiedResponse = $this->createStub(ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Surrogate-Key', self::isType('string'))
            ->willReturn($modifiedResponse);

        $result = $middleware->process(
            $this->makeRequest(true, false, ['pages_1']),
            $this->makeHandler($response),
        );

        $this->assertSame($modifiedResponse, $result);
    }

    public function testXVarnishHeaderAddsSurrogateKeyHeader(): void
    {
        $middleware = new ExposeCacheTags();
        $response = $this->createMock(ResponseInterface::class);
        $modifiedResponse = $this->createStub(ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Surrogate-Key', self::isType('string'))
            ->willReturn($modifiedResponse);

        $result = $middleware->process(
            $this->makeRequest(false, true, ['tt_content_5']),
            $this->makeHandler($response),
        );

        $this->assertSame($modifiedResponse, $result);
    }

    public function testSurrogateKeyValueIsSpaceSeparatedTagNames(): void
    {
        $middleware = new ExposeCacheTags();
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        $middleware->process(
            $this->makeRequest(true, false, ['pages_1', 'tt_content_2']),
            $this->makeHandler($response),
        );

        $this->assertSame('pages_1 tt_content_2', $capturedValue);
    }

    // -------------------------------------------------------------------------
    // simplifyCacheTags() — tested via process()
    // -------------------------------------------------------------------------

    public function testNoTcaTableTagsReturnsAllTagsUnchanged(): void
    {
        $GLOBALS['TCA'] = [];
        $middleware = new ExposeCacheTags();
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        $middleware->process(
            $this->makeRequest(true, false, ['pages_1', 'pages_2']),
            $this->makeHandler($response),
        );

        $this->assertSame('pages_1 pages_2', $capturedValue);
    }

    public function testRecordTagForTableNotInTcaIsKept(): void
    {
        $GLOBALS['TCA'] = ['pages' => []];
        $middleware = new ExposeCacheTags();
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        $middleware->process(
            $this->makeRequest(true, false, ['pages', 'unknown_123']),
            $this->makeHandler($response),
        );

        // 'unknown_123' is kept because 'unknown' is not in TCA
        $this->assertStringContainsString('unknown_123', (string) $capturedValue);
    }

    public function testEmptyTagArrayResultsInEmptySurrogateKey(): void
    {
        $GLOBALS['TCA'] = [];
        $middleware = new ExposeCacheTags();
        $capturedValue = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedValue, $response): ResponseInterface {
                $capturedValue = $value;
                return $response;
            }
        );

        $middleware->process(
            $this->makeRequest(true, false, []),
            $this->makeHandler($response),
        );

        $this->assertSame('', $capturedValue);
    }
}
