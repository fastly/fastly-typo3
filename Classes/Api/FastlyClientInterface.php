<?php

declare(strict_types=1);

namespace Fastly\Cdn\Api;

use Fastly\Model\DomainResponse;
use Fastly\Model\SchemasVersionResponse;
use Fastly\Model\ServiceResponse;
use Fastly\Model\ValidatorResult;
use Fastly\Model\VclResponse;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;

/**
 * Contract for the Fastly API client.
 *
 * Exists so collaborators (FlushService, FastlyServiceProvisioner, the CLI
 * commands) can depend on a mockable seam instead of the final readonly
 * FastlyClient implementation.
 */
interface FastlyClientInterface
{
    public function getConfiguredServiceId(): string;

    public function createService(string $name, string $comment = ''): ServiceResponse;

    public function updateService(string $serviceId, ?string $name = null, ?string $comment = null): ServiceResponse;

    /**
     * @return SchemasVersionResponse[]
     */
    public function listServiceVersions(string $serviceId): array;

    public function cloneServiceVersion(string $serviceId, int $version): Version;

    public function activateServiceVersion(string $serviceId, int $version): VersionResponse;

    public function updateServiceVersionComment(string $serviceId, int $version, string $comment): VersionResponse;

    /**
     * @return DomainResponse[]
     */
    public function listDomains(string $serviceId, int $version): array;

    public function createDomain(string $serviceId, int $version, string $domain): DomainResponse;

    /**
     * @return VclResponse[]
     */
    public function listCustomVcl(string $serviceId, int $version): array;

    public function getCustomVclRaw(string $serviceId, int $version, string $name): string;

    public function createCustomVcl(string $serviceId, int $version, string $name, string $content, bool $main = false): VclResponse;

    public function updateCustomVcl(string $serviceId, int $version, string $name, string $content): VclResponse;

    public function setCustomVclMain(string $serviceId, int $version, string $name): VclResponse;

    public function lintVcl(string $serviceId, string $content): ValidatorResult;

    public function enableHttp3(string $serviceId, int $version): void;

    public function getHttp3(string $serviceId, int $version): object;

    public function enableBotManagement(string $serviceId): void;

    public function getBotManagement(string $serviceId): object;

    public function enableNgwaf(string $serviceId): void;

    public function getNgwaf(string $serviceId): object;

    public function enableDdosProtection(string $serviceId): void;

    public function getDdosProtection(string $serviceId): object;

    public function purgeByTag(string $tag): void;

    public function purgeByTags(array $tags): void;

    public function purgeAll(): void;
}
