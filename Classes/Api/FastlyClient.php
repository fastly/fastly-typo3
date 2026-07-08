<?php

declare(strict_types=1);

namespace Fastly\Cdn\Api;

use Fastly\Api\DomainApi;
use Fastly\Api\Http3Api;
use Fastly\Api\PurgeApi;
use Fastly\Api\ProductBotManagementApi;
use Fastly\Api\ProductDdosProtectionApi;
use Fastly\Api\ProductNgwafApi;
use Fastly\Api\ServiceApi;
use Fastly\Api\VersionApi;
use Fastly\Configuration;
use Fastly\Model\DomainResponse;
use Fastly\Model\SchemasVersionResponse;
use Fastly\Model\ServiceResponse;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use GuzzleHttp\ClientInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\SingletonInterface;

#[Autoconfigure(public: true)]
final readonly class FastlyClient implements FastlyClientInterface, SingletonInterface
{
    private ServiceApi $serviceApi;

    private VersionApi $versionApi;

    private DomainApi $domainApi;

    private Http3Api $http3Api;

    private ProductBotManagementApi $botManagementApi;

    private ProductNgwafApi $ngwafApi;

    private ProductDdosProtectionApi $ddosProtectionApi;

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
        $this->versionApi = new VersionApi($this->client, $config);
        $this->domainApi = new DomainApi($this->client, $config);
        $this->http3Api = new Http3Api($this->client, $config);
        $this->botManagementApi = new ProductBotManagementApi($this->client, $config);
        $this->ngwafApi = new ProductNgwafApi($this->client, $config);
        $this->ddosProtectionApi = new ProductDdosProtectionApi($this->client, $config);
        $this->purgeApi = new PurgeApi($this->client, $config);
    }

    public function getConfiguredServiceId(): string
    {
        return $this->serviceId;
    }

    public function createService(string $name, string $comment = ''): ServiceResponse
    {
        $options = ['name' => $name, 'type' => ServiceResponse::TYPE_VCL];
        if ($comment !== '') {
            $options['comment'] = $comment;
        }

        return $this->serviceApi->createService($options);
    }

    public function updateService(string $serviceId, ?string $name = null, ?string $comment = null): ServiceResponse
    {
        $options = ['service_id' => $serviceId];
        if ($name !== null && $name !== '') {
            $options['name'] = $name;
        }
        if ($comment !== null) {
            $options['comment'] = $comment;
        }

        return $this->serviceApi->updateService($options);
    }

    /**
     * @return SchemasVersionResponse[]
     */
    public function listServiceVersions(string $serviceId): array
    {
        return $this->versionApi->listServiceVersions(['service_id' => $serviceId]);
    }

    public function cloneServiceVersion(string $serviceId, int $version): Version
    {
        return $this->versionApi->cloneServiceVersion(['service_id' => $serviceId, 'version_id' => $version]);
    }

    public function activateServiceVersion(string $serviceId, int $version): VersionResponse
    {
        return $this->versionApi->activateServiceVersion(['service_id' => $serviceId, 'version_id' => $version]);
    }

    /**
     * @return DomainResponse[]
     */
    public function listDomains(string $serviceId, int $version): array
    {
        return $this->domainApi->listDomains(['service_id' => $serviceId, 'version_id' => $version]);
    }

    public function createDomain(string $serviceId, int $version, string $domain): DomainResponse
    {
        return $this->domainApi->createDomain([
            'service_id' => $serviceId,
            'version_id' => $version,
            'name' => $domain,
        ]);
    }

    public function enableHttp3(string $serviceId, int $version): void
    {
        $this->http3Api->createHttp3(['service_id' => $serviceId, 'version_id' => $version]);
    }

    public function getHttp3(string $serviceId, int $version): object
    {
        return $this->http3Api->getHttp3(['service_id' => $serviceId, 'version_id' => $version]);
    }

    public function enableBotManagement(string $serviceId): void
    {
        $this->botManagementApi->enableProductBotManagement(['service_id' => $serviceId]);
    }

    public function getBotManagement(string $serviceId): object
    {
        return $this->botManagementApi->getProductBotManagement(['service_id' => $serviceId]);
    }

    public function enableNgwaf(string $serviceId): void
    {
        $this->ngwafApi->enableProductNgwaf(['service_id' => $serviceId]);
    }

    public function getNgwaf(string $serviceId): object
    {
        return $this->ngwafApi->getProductNgwaf(['service_id' => $serviceId]);
    }

    public function enableDdosProtection(string $serviceId): void
    {
        $this->ddosProtectionApi->enableProductDdosProtection(['service_id' => $serviceId]);
    }

    public function getDdosProtection(string $serviceId): object
    {
        return $this->ddosProtectionApi->getProductDdosProtection(['service_id' => $serviceId]);
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
