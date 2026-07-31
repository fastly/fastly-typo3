<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Api;

use Fastly\Cdn\Api\FastlyClient;
use Fastly\Cdn\Service\SurrogateKeyHasher;
use Fastly\Model\DomainResponse;
use Fastly\Model\ServiceResponse;
use Fastly\Model\VclResponse;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FastlyClientTest extends UnitTestCase
{
    public function testPurgeByTagSendsBothPlaintextAndHashedSurrogateKey(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeByTag('my-cache-tag');

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame(
            'my-cache-tag ' . (new SurrogateKeyHasher())->hash('my-cache-tag'),
            $request->getHeaderLine('surrogate-key'),
        );
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

        return new FastlyClient(
            $guzzle,
            $this->createStub(FrontendInterface::class),
            'API_TOKEN_PLACEHOLDER',
            'SVC_ID_PLACEHOLDER',
            new SurrogateKeyHasher(),
        );
    }

    public function testCreateCustomVclPostsNameContentAndMainFlag(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"name":"main"}')]), $history);

        $client->createCustomVcl('svc', 3, 'main', 'sub vcl_recv {}', true);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/service/svc/version/3/vcl', $request->getUri()->getPath());
        $body = urldecode((string) $request->getBody());
        $this->assertStringContainsString('name=main', $body);
        $this->assertStringContainsString('main=true', $body);
        $this->assertStringContainsString('sub vcl_recv', $body);
    }

    public function testUpdateCustomVclPutsToNamedVclWithContent(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"name":"caching"}')]), $history);

        $client->updateCustomVcl('svc', 3, 'caching', 'sub fastly_caching_fetch {}');

        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/service/svc/version/3/vcl/caching', $request->getUri()->getPath());
        $this->assertStringContainsString('fastly_caching_fetch', urldecode((string) $request->getBody()));
    }

    public function testSetCustomVclMainPutsToMainEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"name":"main"}')]), $history);

        $client->setCustomVclMain('svc', 3, 'main');

        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/service/svc/version/3/vcl/main/main', $request->getUri()->getPath());
    }

    public function testGetCustomVclRawReturnsRawBody(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"content":"sub vcl_recv {}"}')]), $history);

        $raw = $client->getCustomVclRaw('svc', 3, 'main');

        $this->assertSame('sub vcl_recv {}', $raw);
        $this->assertSame('/service/svc/version/3/vcl/main', $history[0]['request']->getUri()->getPath());
    }

    public function testLintVclPostsContentToServiceLintEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"status":"ok"}')]), $history);

        $client->lintVcl('svc', 'sub vcl_recv {}');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/service/svc/lint', $request->getUri()->getPath());
        $this->assertStringContainsString('vcl_recv', urldecode((string) $request->getBody()));
    }

    public function testPurgeByTagDoesNotSendSoftPurgeHeader(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeByTag('any-tag');

        $request = $history[0]['request'];
        $this->assertSame('', $request->getHeaderLine('Fastly-Soft-Purge'));
    }

    public function testPurgeByTagIncludesServiceId(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeByTag('some-tag');

        $request = $history[0]['request'];
        $this->assertStringContainsString('SVC_ID_PLACEHOLDER', (string) $request->getUri());
    }

    public function testPurgeAllSendsExactlyOneRequest(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeAll();

        $this->assertCount(1, $history);
        // The Fastly SDK's purgeAll() does not support Fastly-Soft-Purge header;
        // the fastly_soft_purge option is silently ignored for this operation.
        $this->assertStringContainsString('purge_all', (string) $history[0]['request']->getUri());
    }

    public function testPurgeAllIncludesServiceId(): void
    {
        $history = [];
        $client = $this->buildClientWithHistory($history);
        $client->purgeAll();

        $request = $history[0]['request'];
        $this->assertStringContainsString('SVC_ID_PLACEHOLDER', (string) $request->getUri());
    }

    public function testGetConfiguredServiceIdReturnsConstructorValueWithoutHttp(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([]), $history);

        $this->assertSame('SVC_ID_PLACEHOLDER', $client->getConfiguredServiceId());
        $this->assertCount(0, $history);
    }

    public function testPurgeByTagsSendsPlaintextAndHashedKeysForEachTag(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"status":"ok"}')]), $history);
        $hasher = new SurrogateKeyHasher();

        $client->purgeByTags(['Tag-One', 'tag-two']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/service/SVC_ID_PLACEHOLDER/purge', $request->getUri()->getPath());
        $this->assertSame(
            sprintf('tag-one tag-two %s %s', $hasher->hash('Tag-One'), $hasher->hash('tag-two')),
            $request->getHeaderLine('surrogate-key'),
        );
    }

    public function testCreateServicePostsNameCommentAndVclType(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"id":"new-svc"}')]), $history);

        $service = $client->createService('TYPO3 Fastly Service', 'managed by TYPO3');

        $this->assertInstanceOf(ServiceResponse::class, $service);
        $this->assertSame('new-svc', $service->getId());
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/service', $request->getUri()->getPath());
        $body = urldecode((string) $request->getBody());
        $this->assertStringContainsString('name=TYPO3 Fastly Service', $body);
        $this->assertStringContainsString('type=vcl', $body);
        $this->assertStringContainsString('comment=managed by TYPO3', $body);
    }

    public function testCreateServiceOmitsEmptyComment(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"id":"new-svc"}')]), $history);

        $client->createService('TYPO3 Fastly Service');

        $this->assertStringNotContainsString('comment=', urldecode((string) $history[0]['request']->getBody()));
    }

    public function testUpdateServicePutsNameAndComment(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"id":"svc"}')]), $history);

        $client->updateService('svc', 'New name', 'New comment');

        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/service/svc', $request->getUri()->getPath());
        $body = urldecode((string) $request->getBody());
        $this->assertStringContainsString('name=New name', $body);
        $this->assertStringContainsString('comment=New comment', $body);
    }

    public function testUpdateServiceOmitsNullNameAndComment(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"id":"svc"}')]), $history);

        $client->updateService('svc');

        $body = urldecode((string) $history[0]['request']->getBody());
        $this->assertStringNotContainsString('name=', $body);
        $this->assertStringNotContainsString('comment=', $body);
    }

    public function testListServiceVersionsFetchesOnceAndServesRepeatsFromRuntimeCache(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '[{"number":1,"active":true,"locked":true}]')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $store = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willReturnCallback(static function (string $id) use (&$store): bool {
            return array_key_exists($id, $store);
        });
        $cache->method('set')->willReturnCallback(static function (string $id, $value) use (&$store): void {
            $store[$id] = $value;
        });
        $cache->method('get')->willReturnCallback(static function (string $id) use (&$store): mixed {
            return $store[$id];
        });
        $client = new FastlyClient(new Client(['handler' => $stack]), $cache, 'API_TOKEN_PLACEHOLDER', 'SVC_ID_PLACEHOLDER', new SurrogateKeyHasher());

        $first = $client->listServiceVersions('svc');
        $second = $client->listServiceVersions('svc');

        $this->assertCount(1, $history, 'the second call must be served from the runtime cache');
        $this->assertSame('/service/svc/version', $history[0]['request']->getUri()->getPath());
        $this->assertCount(1, $first);
        $this->assertInstanceOf(VersionResponse::class, $first[0]);
        $this->assertSame(1, (int) $first[0]->getNumber());
        $this->assertSame($first, $second);
    }

    public function testCloneServiceVersionPutsToCloneEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"number":4}')]), $history);

        $version = $client->cloneServiceVersion('svc', 2);

        $this->assertInstanceOf(Version::class, $version);
        $this->assertSame(4, (int) $version->getNumber());
        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/service/svc/version/2/clone', $request->getUri()->getPath());
    }

    public function testActivateServiceVersionPutsToActivateEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"number":3}')]), $history);

        $version = $client->activateServiceVersion('svc', 3);

        $this->assertInstanceOf(VersionResponse::class, $version);
        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/service/svc/version/3/activate', $request->getUri()->getPath());
    }

    public function testUpdateServiceVersionCommentPutsCommentToVersionEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"number":3}')]), $history);

        $client->updateServiceVersionComment('svc', 3, 'Draft managed by the TYPO3 Fastly extension.');

        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/service/svc/version/3', $request->getUri()->getPath());
        $this->assertStringContainsString(
            'comment=Draft managed by the TYPO3 Fastly extension.',
            urldecode((string) $request->getBody()),
        );
    }

    public function testListDomainsGetsVersionDomainEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(
            new MockHandler([new Response(200, [], '[{"name":"example.com"}]')]),
            $history,
        );

        $domains = $client->listDomains('svc', 2);

        $this->assertCount(1, $domains);
        $this->assertInstanceOf(DomainResponse::class, $domains[0]);
        $this->assertSame('example.com', $domains[0]->getName());
        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/service/svc/version/2/domain', $request->getUri()->getPath());
    }

    public function testCreateDomainPostsDomainName(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"name":"example.com"}')]), $history);

        $domain = $client->createDomain('svc', 2, 'example.com');

        $this->assertInstanceOf(DomainResponse::class, $domain);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/service/svc/version/2/domain', $request->getUri()->getPath());
        $this->assertStringContainsString('name=example.com', urldecode((string) $request->getBody()));
    }

    public function testListCustomVclGetsVersionVclEndpoint(): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '[{"name":"main"}]')]), $history);

        $vcls = $client->listCustomVcl('svc', 2);

        $this->assertCount(1, $vcls);
        $this->assertInstanceOf(VclResponse::class, $vcls[0]);
        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/service/svc/version/2/vcl', $request->getUri()->getPath());
    }

    /**
     * @param callable(FastlyClient): mixed $call
     */
    #[DataProvider('featureEndpoints')]
    public function testFeatureCallsUseExpectedMethodAndPath(callable $call, string $method, string $path): void
    {
        $history = [];
        $client = $this->clientFromMock(new MockHandler([new Response(200, [], '{"product":{"id":"x"}}')]), $history);

        $call($client);

        $request = $history[0]['request'];
        $this->assertSame($method, $request->getMethod());
        $this->assertSame($path, $request->getUri()->getPath());
    }

    /**
     * @return iterable<string, array{callable(FastlyClient): mixed, string, string}>
     */
    public static function featureEndpoints(): iterable
    {
        yield 'enableHttp3' => [
            static fn (FastlyClient $c) => $c->enableHttp3('svc', 2),
            'POST',
            '/service/svc/version/2/http3',
        ];
        yield 'getHttp3' => [
            static fn (FastlyClient $c): object => $c->getHttp3('svc', 2),
            'GET',
            '/service/svc/version/2/http3',
        ];
        yield 'enableBotManagement' => [
            static fn (FastlyClient $c) => $c->enableBotManagement('svc'),
            'PUT',
            '/enabled-products/v1/bot_management/services/svc',
        ];
        yield 'getBotManagement' => [
            static fn (FastlyClient $c): object => $c->getBotManagement('svc'),
            'GET',
            '/enabled-products/v1/bot_management/services/svc',
        ];
        yield 'enableNgwaf' => [
            static fn (FastlyClient $c) => $c->enableNgwaf('svc'),
            'PUT',
            '/enabled-products/v1/ngwaf/services/svc',
        ];
        yield 'getNgwaf' => [
            static fn (FastlyClient $c): object => $c->getNgwaf('svc'),
            'GET',
            '/enabled-products/v1/ngwaf/services/svc',
        ];
        yield 'enableDdosProtection' => [
            static fn (FastlyClient $c) => $c->enableDdosProtection('svc'),
            'PUT',
            '/enabled-products/v1/ddos_protection/services/svc',
        ];
        yield 'getDdosProtection' => [
            static fn (FastlyClient $c): object => $c->getDdosProtection('svc'),
            'GET',
            '/enabled-products/v1/ddos_protection/services/svc',
        ];
    }
}
