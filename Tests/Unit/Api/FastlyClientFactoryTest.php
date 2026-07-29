<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Api;

use ReflectionClass;
use Fastly\Cdn\Api\FastlyClientFactory;
use GuzzleHttp\ClientInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FastlyClientFactoryTest extends UnitTestCase
{
    private array $originalHttpConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalHttpConfig = $GLOBALS['TYPO3_CONF_VARS']['HTTP'] ?? [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => true];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = $this->originalHttpConfig;
        parent::tearDown();
    }

    private function getClientConfig(ClientInterface $client): array
    {
        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('config');
        return $prop->getValue($client);
    }

    public function testGetClientReturnsClientInterface(): void
    {
        $client = FastlyClientFactory::getClient();
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testBaseUriIsSetToFastlyApi(): void
    {
        $client = FastlyClientFactory::getClient();
        $config = $this->getClientConfig($client);

        $this->assertSame('https://api.fastly.com', (string) ($config['base_uri'] ?? ''));
    }

    public function testTimeoutIsEight(): void
    {
        $client = FastlyClientFactory::getClient();
        $config = $this->getClientConfig($client);

        $this->assertSame(8, $config['timeout'] ?? null);
    }

    public function testConnectTimeoutIsFive(): void
    {
        $client = FastlyClientFactory::getClient();
        $config = $this->getClientConfig($client);

        $this->assertSame(5, $config['connect_timeout'] ?? null);
    }

    public function testUserAgentHeaderIsSet(): void
    {
        $client = FastlyClientFactory::getClient();
        $config = $this->getClientConfig($client);

        $this->assertSame('TYPO3 Api/1.0', $config['headers']['User-Agent'] ?? null);
    }

    public function testAcceptHeaderIsApplicationJson(): void
    {
        $client = FastlyClientFactory::getClient();
        $config = $this->getClientConfig($client);

        $this->assertSame('application/json', $config['headers']['Accept'] ?? null);
    }

    public function testVerifyStringTrueIsCastToBoolean(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify'] = 'true';
        $client = FastlyClientFactory::getClient();
        $config = $this->getClientConfig($client);

        $this->assertTrue($config['verify']);
    }

    public function testVerifyStringFalseIsCastToBoolean(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify'] = 'false';
        $client = FastlyClientFactory::getClient();
        $config = $this->getClientConfig($client);

        $this->assertFalse($config['verify']);
    }

    public function testHandlerStackIsCreatedWhenHandlerArrayIsConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = [
            static fn ($handler) => $handler,
        ];
        $client = FastlyClientFactory::getClient();
        $config = $this->getClientConfig($client);

        $this->assertArrayHasKey('handler', $config);
        $this->assertIsCallable($config['handler']);
    }
}
