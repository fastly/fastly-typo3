<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use Iterator;
use Fastly\Cdn\Api\FastlyClient;
use Fastly\Cdn\Service\FlushService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * FastlyClient is final readonly and has no interface, so we use a real FastlyClient
 * backed by a GuzzleHttp MockHandler. Tests verify behaviour through the HTTP layer.
 */
final class FlushServiceTest extends UnitTestCase
{
    private function createFastlyClient(MockHandler $mock): FastlyClient
    {
        $stack = HandlerStack::create($mock);
        return new FastlyClient(
            new Client(['handler' => $stack]),
            $this->createStub(FrontendInterface::class),
            'API_TOKEN_PLACEHOLDER',
            'SERVICE_ID_PLACEHOLDER',
        );
    }

    private function createLogger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    /**
     * @return string[]
     */
    private function tags(int $count): array
    {
        return array_map(static fn (int $i): string => 'tag-' . $i, range(1, $count));
    }

    public function testBanTagCallsPurgeByTagWhenCdnEnabled(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), true);
        $service->purgeTag('some-tag');

        $this->assertCount(0, $mock, 'MockHandler should have been consumed once');
    }

    public function testBanTagDoesNothingWhenCdnDisabled(): void
    {
        $mock = new MockHandler([]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), false);
        $service->purgeTag('some-tag');

        $this->assertCount(0, $mock, 'No HTTP request should be made when CDN is disabled');
    }

    public function testBanTagLogsErrorOnApiException(): void
    {
        // Fastly SDK throws ApiException for non-2xx responses
        $mock = new MockHandler([new Response(403, [], '{"detail":"Not authorized"}')]);
        $logger = $this->createLogger();
        $logger->expects($this->once())->method('error')->with(
            'failed purging Fastly cache by tag',
            self::callback(static fn (array $ctx): bool => isset($ctx['tag']) && $ctx['tag'] === 'my-tag'),
        );

        $service = new FlushService($this->createFastlyClient($mock), $logger, true);
        $service->purgeTag('my-tag');
    }

    /**
     * ExtensionConfiguration returns string values, so a disabled toggle arrives
     * as "0" / "false" rather than the boolean false. The old `=== false` check
     * never matched those and left the CDN enabled — this pins the fix.
     *
     */
    #[DataProvider('disabledStringValues')]
    public function testBanTagTreatsStringConfigAsDisabled(string $value): void
    {
        $mock = new MockHandler([]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), $value);
        $service->purgeTag('some-tag');

        $this->assertCount(0, $mock, 'No HTTP request should be made when CDN is disabled via string config');
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function disabledStringValues(): Iterator
    {
        yield 'string zero' => ['0'];
        yield 'string false' => ['false'];
        yield 'empty string' => [''];
    }

    public function testBanTagTreatsStringOneAsEnabled(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), '1');
        $service->purgeTag('some-tag');

        $this->assertCount(0, $mock, 'A request should be made when CDN is enabled via string config');
    }

    public function testFlushAllCallsPurgeAllWhenCdnEnabled(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), true);
        $service->flushAll();

        $this->assertCount(0, $mock);
    }

    public function testFlushAllDoesNothingWhenCdnDisabled(): void
    {
        $mock = new MockHandler([]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), false);
        $service->flushAll();

        $this->assertCount(0, $mock);
    }

    public function testFlushAllLogsErrorOnApiException(): void
    {
        $mock = new MockHandler([new Response(500, [], '{"detail":"Internal Server Error"}')]);
        $logger = $this->createLogger();
        $logger->expects($this->once())->method('error')->with(
            'failed purging all Fastly caches',
            self::callback(static fn (array $ctx): bool => isset($ctx['exception'])),
        );

        $service = new FlushService($this->createFastlyClient($mock), $logger, true);
        $service->flushAll();
    }

    public function testLoggerIsNotCalledOnSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"status":"ok"}'),
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $logger = $this->createLogger();
        $logger->expects($this->never())->method('error');

        $service = new FlushService($this->createFastlyClient($mock), $logger, true);
        $service->purgeTag('tag-1');
        $service->flushAll();
    }

    public function testPurgeTagsDoesNothingWhenCdnDisabled(): void
    {
        $mock = new MockHandler([]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), false);
        $service->purgeTags($this->tags(12));

        $this->assertCount(0, $mock, 'No HTTP request should be made when CDN is disabled');
    }

    public function testPurgeTagsPurgesFewTagsIndividually(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], '{"status":"ok"}'),
            new Response(200, [], '{"status":"ok"}'),
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new FastlyClient(
            new Client(['handler' => $stack]),
            $this->createStub(FrontendInterface::class),
            'API_TOKEN_PLACEHOLDER',
            'SERVICE_ID_PLACEHOLDER',
        );

        $service = new FlushService($client, $this->createLogger(), true);
        $service->purgeTags(['tag-1', 'tag-2', 'tag-3']);

        $this->assertCount(3, $history, 'below the bulk threshold each tag purges as its own request');
        $this->assertStringContainsString('tag-2', (string) $history[1]['request']->getUri());
    }

    public function testPurgeTagsUsesSingleBulkRequestForTenOrMoreTags(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new FastlyClient(
            new Client(['handler' => $stack]),
            $this->createStub(FrontendInterface::class),
            'API_TOKEN_PLACEHOLDER',
            'SERVICE_ID_PLACEHOLDER',
        );

        $service = new FlushService($client, $this->createLogger(), true);
        $service->purgeTags($this->tags(10));

        $this->assertCount(1, $history, 'ten or more tags must purge as one bulk request');
        $request = $history[0]['request'];
        $this->assertSame('/service/SERVICE_ID_PLACEHOLDER/purge', $request->getUri()->getPath());
        $this->assertSame(implode(' ', $this->tags(10)), $request->getHeaderLine('surrogate-key'));
    }

    public function testPurgeTagsLogsErrorWhenBulkPurgeFails(): void
    {
        $mock = new MockHandler([new Response(403, [], '{"detail":"Not authorized"}')]);
        $logger = $this->createLogger();
        $logger->expects($this->once())->method('error')->with(
            'failed purging Fastly cache by tag',
            self::callback(static fn (array $ctx): bool => isset($ctx['tags']) && count($ctx['tags']) === 10),
        );

        $service = new FlushService($this->createFastlyClient($mock), $logger, true);
        $service->purgeTags($this->tags(10));
    }
}
