<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Model\DomainResponse;
use Fastly\Model\SchemasVersionResponse;
use TYPO3\CMS\Core\SingletonInterface;

final readonly class FastlyServiceProvisioner implements SingletonInterface
{
    public const FEATURE_HTTP3 = 'http3';
    public const FEATURE_BOT_MANAGEMENT = 'botManagement';
    public const FEATURE_NGWAF = 'ngwaf';
    public const FEATURE_DDOS_PROTECTION = 'ddosProtection';

    public function __construct(private FastlyClientInterface $client)
    {
    }

    /**
     * @param string[] $domains
     * @param array<string, bool> $features
     * @return array<string, mixed>
     */
    public function addService(
        string $name,
        string $comment,
        array $domains,
        array $features,
        bool $activate,
        bool $dryRun = false,
    ): array {
        if ($dryRun) {
            return [
                'serviceId' => '',
                'version' => 1,
                'created' => false,
                'activated' => false,
                'addedDomains' => $domains,
                'existingDomains' => [],
                'features' => $this->plannedFeatures($features),
            ];
        }

        $service = $this->client->createService($name, $comment);
        $serviceId = (string)$service->getId();
        $version = $this->findEditableVersion($serviceId);

        $addedDomains = $this->addMissingDomains($serviceId, $version, $domains);
        $featureChanges = $this->enableFeatures($serviceId, $version, $features);

        if ($activate) {
            $this->client->activateServiceVersion($serviceId, $version);
        }

        return [
            'serviceId' => $serviceId,
            'version' => $version,
            'created' => true,
            'activated' => $activate,
            'addedDomains' => $addedDomains,
            'existingDomains' => [],
            'features' => $featureChanges,
        ];
    }

    /**
     * @param string[] $domains
     * @param array<string, bool> $features
     * @return array<string, mixed>
     */
    public function updateService(
        string $serviceId,
        array $domains,
        array $features,
        bool $activate,
        bool $dryRun = false,
        ?string $name = null,
        ?string $comment = null,
    ): array {
        $status = $this->checkService($serviceId, $domains);
        $featureStatus = $status['features'];
        $missingDomains = $status['missingDomains'];
        $needsHttp3 = ($features[self::FEATURE_HTTP3] ?? false) && ($featureStatus[self::FEATURE_HTTP3] ?? false) === false;
        $needsVersion = $missingDomains !== [] || $needsHttp3;
        $targetVersion = (int)$status['activeVersion'];
        $cloned = false;

        if ($dryRun) {
            return [
                'serviceId' => $serviceId,
                'version' => $targetVersion,
                'cloned' => $needsVersion,
                'activated' => false,
                'addedDomains' => $missingDomains,
                'existingDomains' => $status['matchingDomains'],
                'features' => $this->plannedFeatures($features, $featureStatus),
            ];
        }

        if (($name !== null && $name !== '') || $comment !== null) {
            $this->client->updateService($serviceId, $name, $comment);
        }

        if ($needsVersion) {
            $targetVersion = $this->editableVersionForUpdate($serviceId, (int)$status['activeVersion']);
            $cloned = $targetVersion !== (int)$status['activeVersion'];
        }

        $addedDomains = $needsVersion ? $this->addMissingDomains($serviceId, $targetVersion, $domains) : [];
        $featureChanges = $this->enableFeatures($serviceId, $targetVersion, $features, $featureStatus);

        if ($activate && ($addedDomains !== [] || $needsHttp3)) {
            $this->client->activateServiceVersion($serviceId, $targetVersion);
        }

        return [
            'serviceId' => $serviceId,
            'version' => $targetVersion,
            'cloned' => $cloned,
            'activated' => $activate && ($addedDomains !== [] || $needsHttp3),
            'addedDomains' => $addedDomains,
            'existingDomains' => $status['matchingDomains'],
            'features' => $featureChanges,
        ];
    }

    /**
     * @param string[] $configuredDomains
     * @return array<string, mixed>
     */
    public function checkService(string $serviceId, array $configuredDomains): array
    {
        $activeVersion = $this->findActiveVersion($serviceId);
        $serviceDomains = $this->domainNames($this->client->listDomains($serviceId, $activeVersion));
        $configuredLookup = array_fill_keys($configuredDomains, true);
        $serviceLookup = array_fill_keys($serviceDomains, true);

        return [
            'serviceId' => $serviceId,
            'activeVersion' => $activeVersion,
            'configuredDomains' => $configuredDomains,
            'serviceDomains' => $serviceDomains,
            'matchingDomains' => array_values(array_intersect($configuredDomains, $serviceDomains)),
            'missingDomains' => array_values(array_diff($configuredDomains, array_keys($serviceLookup))),
            'unknownDomains' => array_values(array_diff($serviceDomains, array_keys($configuredLookup))),
            'features' => [
                self::FEATURE_HTTP3 => $this->isHttp3Enabled($serviceId, $activeVersion),
                self::FEATURE_BOT_MANAGEMENT => $this->isProductEnabled(fn () => $this->client->getBotManagement($serviceId)),
                self::FEATURE_NGWAF => $this->isProductEnabled(fn () => $this->client->getNgwaf($serviceId)),
                self::FEATURE_DDOS_PROTECTION => $this->isProductEnabled(fn () => $this->client->getDdosProtection($serviceId)),
            ],
        ];
    }

    /**
     * @param string[] $domains
     * @return string[]
     */
    private function addMissingDomains(string $serviceId, int $version, array $domains): array
    {
        $existing = array_fill_keys($this->domainNames($this->client->listDomains($serviceId, $version)), true);
        $added = [];
        foreach ($domains as $domain) {
            if (isset($existing[$domain])) {
                continue;
            }
            $this->client->createDomain($serviceId, $version, $domain);
            $added[] = $domain;
        }

        return $added;
    }

    /**
     * @param array<string, bool> $features
     * @param array<string, bool> $currentStatus
     * @return array<string, string>
     */
    private function enableFeatures(string $serviceId, int $version, array $features, array $currentStatus = []): array
    {
        $changes = [];
        if ($features[self::FEATURE_HTTP3] ?? false) {
            $active = $currentStatus[self::FEATURE_HTTP3] ?? $this->isHttp3Enabled($serviceId, $version);
            if ($active) {
                $changes[self::FEATURE_HTTP3] = 'already active';
            } else {
                $this->client->enableHttp3($serviceId, $version);
                $changes[self::FEATURE_HTTP3] = 'enabled';
            }
        }
        if ($features[self::FEATURE_BOT_MANAGEMENT] ?? false) {
            $changes[self::FEATURE_BOT_MANAGEMENT] = $this->enableProduct(
                $currentStatus[self::FEATURE_BOT_MANAGEMENT] ?? null,
                fn () => $this->client->enableBotManagement($serviceId),
            );
        }
        if ($features[self::FEATURE_NGWAF] ?? false) {
            $changes[self::FEATURE_NGWAF] = $this->enableProduct(
                $currentStatus[self::FEATURE_NGWAF] ?? null,
                fn () => $this->client->enableNgwaf($serviceId),
            );
        }
        if ($features[self::FEATURE_DDOS_PROTECTION] ?? false) {
            $changes[self::FEATURE_DDOS_PROTECTION] = $this->enableProduct(
                $currentStatus[self::FEATURE_DDOS_PROTECTION] ?? null,
                fn () => $this->client->enableDdosProtection($serviceId),
            );
        }

        return $changes;
    }

    private function enableProduct(?bool $currentStatus, \Closure $enable): string
    {
        if ($currentStatus === true) {
            return 'already active';
        }

        $enable();
        return 'enabled';
    }

    private function isHttp3Enabled(string $serviceId, int $version): bool
    {
        try {
            $this->client->getHttp3($serviceId, $version);
            return true;
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    private function isProductEnabled(\Closure $getter): bool
    {
        try {
            $getter();
            return true;
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    private function findEditableVersion(string $serviceId): int
    {
        $versions = $this->client->listServiceVersions($serviceId);
        foreach ($versions as $version) {
            if ((bool)$version->getLocked() === false) {
                return (int)$version->getNumber();
            }
        }

        $latest = $this->latestVersion($versions);
        if ($latest !== null) {
            return (int)$latest->getNumber();
        }

        return 1;
    }

    /**
     * Always clone the active version to obtain a known-clean draft. Reusing an
     * arbitrary pre-existing inactive/unlocked version risks publishing unrelated
     * staged configuration when the version is activated.
     */
    private function editableVersionForUpdate(string $serviceId, int $activeVersion): int
    {
        return (int)$this->client->cloneServiceVersion($serviceId, $activeVersion)->getNumber();
    }

    /**
     * @param SchemasVersionResponse[]|null $versions
     */
    private function findActiveVersion(string $serviceId, ?array $versions = null): int
    {
        $versions ??= $this->client->listServiceVersions($serviceId);
        foreach ($versions as $version) {
            if ((bool)$version->getActive()) {
                return (int)$version->getNumber();
            }
        }

        $latest = $this->latestVersion($versions);
        return $latest === null ? 1 : (int)$latest->getNumber();
    }

    /**
     * @param SchemasVersionResponse[] $versions
     */
    private function latestVersion(array $versions): ?SchemasVersionResponse
    {
        $latest = null;
        foreach ($versions as $version) {
            if ($latest === null || (int)$version->getNumber() > (int)$latest->getNumber()) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /**
     * @param DomainResponse[] $domains
     * @return string[]
     */
    private function domainNames(array $domains): array
    {
        $names = [];
        foreach ($domains as $domain) {
            $name = strtolower(rtrim((string)$domain->getName(), '.'));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
        ksort($names);
        return array_values($names);
    }

    /**
     * @param array<string, bool> $features
     * @param array<string, bool> $currentStatus
     * @return array<string, string>
     */
    private function plannedFeatures(array $features, array $currentStatus = []): array
    {
        $planned = [];
        foreach ($features as $feature => $enabled) {
            if (!$enabled) {
                continue;
            }
            $planned[$feature] = ($currentStatus[$feature] ?? false) ? 'already active' : 'would enable';
        }

        return $planned;
    }
}
