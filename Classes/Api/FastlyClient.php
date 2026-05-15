<?php

declare(strict_types=1);

namespace Fastly\Cdn\Api;

use Fastly\Api\PurgeApi;
use Fastly\Api\ServiceApi;
use Fastly\Configuration;
use GuzzleHttp\ClientInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\SingletonInterface;

#[Autoconfigure(public: true)]
final readonly class FastlyClient implements SingletonInterface
{
    private ServiceApi $serviceApi;

    private PurgeApi $purgeApi;

    public function __construct(
        #[Autowire(service: 'fastly_cdn_fastlyclient')]
        private readonly ClientInterface $client,
        #[SensitiveParameter]
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "apiToken")')]
        private readonly string $apiToken,
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "serviceId")')]
        private readonly string $serviceId,
    ) {
        $config = Configuration::getDefaultConfiguration()->setApiToken($this->apiToken);
        $this->serviceApi = new ServiceApi($this->client, $config);
        $this->purgeApi = new PurgeApi($this->client, $config);
    }

    public function purgeByTag(string $tag): void
    {
        $this->purgeApi->purgeTag(
            ['service_id' => $this->serviceId, 'surrogate_key' => $tag, 'fastly_soft_purge' => true]
        );
    }

    public function purgeAll(): void
    {
        $this->purgeApi->purgeAll(['service_id' => $this->serviceId, 'fastly_soft_purge' => true]);
    }
}
