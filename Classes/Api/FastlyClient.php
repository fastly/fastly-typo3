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
use Fastly\Api\VclApi;
use Fastly\Api\VersionApi;
use Fastly\Cdn\Service\SurrogateKeyHasher;
use Fastly\Configuration;
use Fastly\Model\DomainResponse;
use Fastly\Model\InlineObject;
use Fastly\Model\ServiceResponse;
use Fastly\Model\ValidatorResult;
use Fastly\Model\VclResponse;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use GuzzleHttp\ClientInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

#[Autoconfigure(public: true)]
final readonly class FastlyClient implements FastlyClientInterface, SingletonInterface
{
    private const string CACHE_KEY_PREFIX = 'fastly';

    private ServiceApi $serviceApi;

    private VersionApi $versionApi;

    private DomainApi $domainApi;

    private Http3Api $http3Api;

    private ProductBotManagementApi $botManagementApi;

    private ProductNgwafApi $ngwafApi;

    private ProductDdosProtectionApi $ddosProtectionApi;

    private VclApi $vclApi;

    private PurgeApi $purgeApi;

    public function __construct(
        #[Autowire(service: 'fastly_cdn_fastlyclient')]
        private ClientInterface $client,
        #[Autowire(service: 'cache.runtime')]
        private FrontendInterface $runtimeCache,
        #[SensitiveParameter]
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "apiToken")')]
        private string $apiToken,
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "serviceId")')]
        private string $serviceId,
        private SurrogateKeyHasher $hasher,
    ) {
        $config = Configuration::getDefaultConfiguration()->setApiToken($this->apiToken);
        $this->serviceApi = new ServiceApi($this->client, $config);
        $this->versionApi = new VersionApi($this->client, $config);
        $this->domainApi = new DomainApi($this->client, $config);
        $this->http3Api = new Http3Api($this->client, $config);
        $this->botManagementApi = new ProductBotManagementApi($this->client, $config);
        $this->ngwafApi = new ProductNgwafApi($this->client, $config);
        $this->ddosProtectionApi = new ProductDdosProtectionApi($this->client, $config);
        $this->vclApi = new VclApi($this->client, $config);
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
     * @return VersionResponse[]
     */
    public function listServiceVersions(string $serviceId): array
    {
        $cacheIdentifier = sprintf('%s-%s-%s',self::CACHE_KEY_PREFIX, 'listServiceVersions', $serviceId);
        if (!$this->runtimeCache->has($cacheIdentifier)) {
            $this->runtimeCache->set($cacheIdentifier, $this->versionApi->listServiceVersions(['service_id' => $serviceId]));
        }

        return $this->runtimeCache->get($cacheIdentifier);
    }

    public function cloneServiceVersion(string $serviceId, int $version): Version
    {
        return $this->versionApi->cloneServiceVersion(['service_id' => $serviceId, 'version_id' => $version]);
    }

    public function activateServiceVersion(string $serviceId, int $version): VersionResponse
    {
        return $this->versionApi->activateServiceVersion(['service_id' => $serviceId, 'version_id' => $version]);
    }

    public function updateServiceVersionComment(string $serviceId, int $version, string $comment): VersionResponse
    {
        return $this->versionApi->updateServiceVersion([
            'service_id' => $serviceId,
            'version_id' => $version,
            'comment' => $comment,
        ]);
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

    /**
     * @return VclResponse[]
     */
    public function listCustomVcl(string $serviceId, int $version): array
    {
        return $this->vclApi->listCustomVcl(['service_id' => $serviceId, 'version_id' => $version]);
    }

    public function getCustomVclRaw(string $serviceId, int $version, string $name): string
    {
        $result = $this->vclApi->getCustomVcl([
            'service_id' => $serviceId,
            'version_id' => $version,
            'vcl_name' => $name,
        ]);
        return $result->getContent() ?? '';
    }

    public function createCustomVcl(string $serviceId, int $version, string $name, string $content, bool $main = false): VclResponse
    {
        $options = [
            'service_id' => $serviceId,
            'version_id' => $version,
            'name' => $name,
            'content' => $content,
        ];
        if ($main) {
            $options['main'] = true;
        }

        return $this->vclApi->createCustomVcl($options);
    }

    public function updateCustomVcl(string $serviceId, int $version, string $name, string $content): VclResponse
    {
        return $this->vclApi->updateCustomVcl([
            'service_id' => $serviceId,
            'version_id' => $version,
            'vcl_name' => $name,
            'content' => $content,
        ]);
    }

    public function setCustomVclMain(string $serviceId, int $version, string $name): VclResponse
    {
        return $this->vclApi->setCustomVclMain([
            'service_id' => $serviceId,
            'version_id' => $version,
            'vcl_name' => $name,
        ]);
    }

    public function lintVcl(string $serviceId, string $content): ValidatorResult
    {
        return $this->vclApi->lintVclForService([
            'service_id' => $serviceId,
            'inline_object' => new InlineObject(['vcl' => $content]),
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
        $this->purgeByTags([$tag]);
    }

    /**
     * Purges both the plaintext tag and its short hash in the same request.
     * The middleware emits plaintext Surrogate-Keys by default, but falls
     * back to hashed keys once the header-size guard kicks in — since a
     * purge call has no way of knowing which form a given cached response
     * used, it must send both to reliably hit the object.
     */
    public function purgeByTags(array $tags): void
    {
        $plaintextKeys = array_map(strtolower(...), $tags);
        $hashedKeys = array_map($this->hasher->hash(...), $tags);

        $this->purgeApi->bulkPurgeTag([
            'service_id' => $this->serviceId,
            'surrogate_key' => implode(' ', [...$plaintextKeys, ...$hashedKeys]),
        ]);
    }

    public function purgeAll(): void
    {
        $this->purgeApi->purgeAll(['service_id' => $this->serviceId]);
    }
}
