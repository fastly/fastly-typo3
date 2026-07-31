<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Cache\Backend;

use Fastly\Cdn\Api\FastlyClient;
use Fastly\Cdn\Cache\Backend\FastlyBackend;
use Fastly\Cdn\Service\FlushService;
use Fastly\Cdn\Service\SurrogateKeyHasher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * FastlyBackend delegates to FlushService via GeneralUtility::makeInstance().
 * Both FastlyBackend and FlushService are final readonly, so we register a real
 * FlushService (backed by a GuzzleHttp MockHandler) via setSingletonInstance() and
 * verify behaviour by inspecting the captured HTTP request history stored in a
 * class property (avoids PHP reference issues when returning from helper methods).
 */
final class FastlyBackendTest extends UnitTestCase
{
    // UnitTestCase integrity check requires this when tests register singletons.
    protected bool $resetSingletonInstances = true;

    private array $requestHistory = [];

    private function createBackend(int $responseCount = 10): FastlyBackend
    {
        $this->requestHistory = [];
        $responses = array_fill(0, $responseCount, new Response(200, [], '{"status":"ok"}'));
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->requestHistory));

        $fastlyClient = new FastlyClient(
            new Client(['handler' => $stack]),
            $this->createStub(FrontendInterface::class),
            'API_TOKEN_PLACEHOLDER',
            'SVC_ID_PLACEHOLDER',
            new SurrogateKeyHasher(),
        );
        $flushService = new FlushService(
            $fastlyClient,
            $this->createStub(LoggerInterface::class),
            true,
        );
        // FlushService implements SingletonInterface; setSingletonInstance() registers it
        // so that FastlyBackend::__construct() receives this instance via makeInstance().
        GeneralUtility::setSingletonInstance(FlushService::class, $flushService);

        return new FastlyBackend();
    }

    public function testFlushDelegatesFlushAll(): void
    {
        $this->skipTestIfOldVersion();
        $backend = $this->createBackend();
        $backend->flush();

        $this->assertCount(1, $this->requestHistory);
        $this->assertStringContainsString('purge_all', (string) $this->requestHistory[0]['request']->getUri());
    }

    public function testFlushByTagDelegatesBanTag(): void
    {
        $this->skipTestIfOldVersion();
        $backend = $this->createBackend();
        $backend->flushByTag('my-tag');

        $this->assertCount(1, $this->requestHistory);
        $this->assertStringContainsString(
            'my-tag',
            (string) $this->requestHistory[0]['request']->getHeaderLine('surrogate-key'),
        );
    }

    public function testFlushByTagsCallsBanTagForEachTag(): void
    {
        $this->skipTestIfOldVersion();
        $backend = $this->createBackend();
        $backend->flushByTags(['alpha', 'beta', 'gamma']);

        $this->assertCount(3, $this->requestHistory);
        $surrogateKeys = array_map(
            static fn (array $e): string => $e['request']->getHeaderLine('surrogate-key'),
            $this->requestHistory,
        );
        $this->assertStringContainsString('alpha', implode(' ', $surrogateKeys));
        $this->assertStringContainsString('beta', implode(' ', $surrogateKeys));
        $this->assertStringContainsString('gamma', implode(' ', $surrogateKeys));
    }

    public function testFlushByTagsWithEmptyArrayMakesNoRequests(): void
    {
        $this->skipTestIfOldVersion();
        $backend = $this->createBackend(0);
        $backend->flushByTags([]);

        $this->assertCount(0, $this->requestHistory);
    }

    private function skipTestIfOldVersion()
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped();
        }
    }
}
