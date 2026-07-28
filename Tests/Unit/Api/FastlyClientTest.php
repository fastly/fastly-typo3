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

        return $this->clientFromMock($mock, $container);
    }

    private function clientFromMock(MockHandler $mock, array &$container): FastlyClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($container));
        $guzzle = new Client(['handler' => $stack]);

        return new FastlyClient($guzzle, 'API_TOKEN_PLACEHOLDER', 'SVC_ID_PLACEHOLDER');
    }

    public function testCreateCustomVclPostsNameContentAndMainFlag(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"name":"main"}')]), $history);

        $client->createCustomVcl('svc', 3, 'main', 'sub vcl_recv {}', true);

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/service/svc/version/3/vcl', $request->getUri()->getPath());
        $body = urldecode((string) $request->getBody());
        self::assertStringContainsString('name=main', $body);
        self::assertStringContainsString('main=true', $body);
        self::assertStringContainsString('sub vcl_recv', $body);
    }

    public function testUpdateCustomVclPutsToNamedVclWithContent(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"name":"caching"}')]), $history);

        $client->updateCustomVcl('svc', 3, 'caching', 'sub fastly_caching_fetch {}');

        $request = $history[0]['request'];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/service/svc/version/3/vcl/caching', $request->getUri()->getPath());
        self::assertStringContainsString('fastly_caching_fetch', urldecode((string) $request->getBody()));
    }

    public function testSetCustomVclMainPutsToMainEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"name":"main"}')]), $history);

        $client->setCustomVclMain('svc', 3, 'main');

        $request = $history[0]['request'];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/service/svc/version/3/vcl/main/main', $request->getUri()->getPath());
    }

    public function testGetCustomVclRawReturnsRawBody(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], 'sub vcl_recv {}')]), $history);

        $raw = $client->getCustomVclRaw('svc', 3, 'main');

        self::assertSame('sub vcl_recv {}', $raw);
        self::assertSame('/service/svc/version/3/vcl/main/download', $history[0]['request']->getUri()->getPath());
    }

    public function testLintVclPostsContentToServiceLintEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"status":"ok"}')]), $history);

        $client->lintVcl('svc', 'sub vcl_recv {}');

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/service/svc/lint', $request->getUri()->getPath());
        self::assertStringContainsString('vcl_recv', urldecode((string) $request->getBody()));
    }

    public function testPurgeByTagDoesNotSendSoftPurgeHeader(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeByTag('any-tag');

        $request = $history[0]['request'];
        self::assertSame('', $request->getHeaderLine('Fastly-Soft-Purge'));
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
