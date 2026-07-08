<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use Fastly\Cdn\Api\FastlyClient;
use Fastly\Cdn\Service\FlushService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
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
        return new FastlyClient(new Client(['handler' => $stack]), 'API_TOKEN_PLACEHOLDER', 'SERVICE_ID_PLACEHOLDER');
    }

    private function createLogger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    public function testBanTagCallsPurgeByTagWhenCdnEnabled(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), true);
        $service->banTag('some-tag');

        self::assertSame(0, $mock->count(), 'MockHandler should have been consumed once');
    }

    public function testBanTagDoesNothingWhenCdnDisabled(): void
    {
        $mock = new MockHandler([]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), false);
        $service->banTag('some-tag');

        self::assertSame(0, $mock->count(), 'No HTTP request should be made when CDN is disabled');
    }

    public function testBanTagLogsErrorOnApiException(): void
    {
        // Fastly SDK throws ApiException for non-2xx responses
        $mock = new MockHandler([new Response(403, [], '{"detail":"Not authorized"}')]);
        $logger = $this->createLogger();
        $logger->expects(self::once())->method('error')->with(
            'failed purging Fastly cache by tag',
            self::callback(static fn (array $ctx): bool => isset($ctx['tag']) && $ctx['tag'] === 'my-tag'),
        );

        $service = new FlushService($this->createFastlyClient($mock), $logger, true);
        $service->banTag('my-tag');
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
        $service->banTag('some-tag');

        self::assertSame(0, $mock->count(), 'No HTTP request should be made when CDN is disabled via string config');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disabledStringValues(): array
    {
        return [
            'string zero' => ['0'],
            'string false' => ['false'],
            'empty string' => [''],
        ];
    }

    public function testBanTagTreatsStringOneAsEnabled(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), '1');
        $service->banTag('some-tag');

        self::assertSame(0, $mock->count(), 'A request should be made when CDN is enabled via string config');
    }

    public function testFlushAllCallsPurgeAllWhenCdnEnabled(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), true);
        $service->flushAll();

        self::assertSame(0, $mock->count());
    }

    public function testFlushAllDoesNothingWhenCdnDisabled(): void
    {
        $mock = new MockHandler([]);
        $service = new FlushService($this->createFastlyClient($mock), $this->createLogger(), false);
        $service->flushAll();

        self::assertSame(0, $mock->count());
    }

    public function testFlushAllLogsErrorOnApiException(): void
    {
        $mock = new MockHandler([new Response(500, [], '{"detail":"Internal Server Error"}')]);
        $logger = $this->createLogger();
        $logger->expects(self::once())->method('error')->with(
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
        $logger->expects(self::never())->method('error');

        $service = new FlushService($this->createFastlyClient($mock), $logger, true);
        $service->banTag('tag-1');
        $service->flushAll();
    }
}
