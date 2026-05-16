<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Api;

use Fastly\Cdn\Api\FastlyClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FastlyClientTest extends UnitTestCase
{
    public function testPurgeByTagSendsRequestWithCorrectSurrogateKey(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeByTag('my-cache-tag');

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertStringContainsString('my-cache-tag', (string) $request->getUri());
    }

    private function buildClientWithHistory(array &$container): FastlyClient
    {
        $mock = new MockHandler([
            new Response(200, [], '{"status":"ok"}'),
            new Response(200, [], '{"status":"ok"}'),
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($container));
        $guzzle = new Client(['handler' => $stack]);

        return new FastlyClient($guzzle, 'API_TOKEN_PLACEHOLDER', 'SVC_ID_PLACEHOLDER');
    }

    public function testPurgeByTagSendsSoftPurgeHeader(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeByTag('any-tag');

        // The Fastly PHP SDK serialises `fastly_soft_purge => true` as header value "true"
        $request = $history[0]['request'];
        self::assertSame('true', $request->getHeaderLine('Fastly-Soft-Purge'));
    }

    public function testPurgeByTagIncludesServiceId(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeByTag('some-tag');

        $request = $history[0]['request'];
        self::assertStringContainsString('SVC_ID_PLACEHOLDER', (string) $request->getUri());
    }

    public function testPurgeAllSendsExactlyOneRequest(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeAll();

        self::assertCount(1, $history);
        // The Fastly SDK's purgeAll() does not support Fastly-Soft-Purge header;
        // the fastly_soft_purge option is silently ignored for this operation.
        self::assertStringContainsString('purge_all', (string) $history[0]['request']->getUri());
    }

    public function testPurgeAllIncludesServiceId(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeAll();

        $request = $history[0]['request'];
        self::assertStringContainsString('SVC_ID_PLACEHOLDER', (string) $request->getUri());
    }
}
