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
        return new class($tags) {
            public function __construct(private readonly array $tags) {}

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

    private function makeResponse(): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            static function (string $name, string $value) use ($response): ResponseInterface {
                $modified = clone $response;
                return $modified;
            }
        );
        return $response;
    }

    // -------------------------------------------------------------------------
    // process() — proxy detection
    // -------------------------------------------------------------------------

    public function testNotBehindProxyAndNoVarnishHeaderReturnsOriginalResponse(): void
    {
        $middleware = new ExposeCacheTags();
        $originalResponse = $this->createMock(ResponseInterface::class);
        $originalResponse->expects(self::never())->method('withHeader');

        $result = $middleware->process(
            $this->makeRequest(false, false),
            $this->makeHandler($originalResponse),
        );

        self::assertSame($originalResponse, $result);
    }

    public function testBehindReverseProxyAddsSurrogateKeyHeader(): void
    {
        $middleware = new ExposeCacheTags();
        $response = $this->createMock(ResponseInterface::class);
        $modifiedResponse = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('withHeader')
            ->with('Surrogate-Key', self::isType('string'))
            ->willReturn($modifiedResponse);

        $result = $middleware->process(
            $this->makeRequest(true, false, ['pages_1']),
            $this->makeHandler($response),
        );

        self::assertSame($modifiedResponse, $result);
    }

    public function testXVarnishHeaderAddsSurrogateKeyHeader(): void
    {
        $middleware = new ExposeCacheTags();
        $response = $this->createMock(ResponseInterface::class);
        $modifiedResponse = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('withHeader')
            ->with('Surrogate-Key', self::isType('string'))
            ->willReturn($modifiedResponse);

        $result = $middleware->process(
            $this->makeRequest(false, true, ['tt_content_5']),
            $this->makeHandler($response),
        );

        self::assertSame($modifiedResponse, $result);
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

        self::assertSame('pages_1 tt_content_2', $capturedValue);
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

        self::assertSame('pages_1 pages_2', $capturedValue);
    }

    public function testRecordSpecificTagRemovedWhenTableTagAlsoPresentInTca(): void
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
            $this->makeRequest(true, false, ['pages', 'pages_1', 'pages_99']),
            $this->makeHandler($response),
        );

        // pages_1 and pages_99 should be removed; only 'pages' remains
        self::assertSame('pages', $capturedValue);
    }

    public function testMultipleTableTagsRemoveTheirRespectiveRecordTags(): void
    {
        $GLOBALS['TCA'] = ['pages' => [], 'tt_content' => []];
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
            $this->makeRequest(true, false, ['pages', 'pages_1', 'tt_content', 'tt_content_456']),
            $this->makeHandler($response),
        );

        self::assertSame('pages tt_content', $capturedValue);
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
        self::assertStringContainsString('unknown_123', $capturedValue);
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

        self::assertSame('', $capturedValue);
    }
}
