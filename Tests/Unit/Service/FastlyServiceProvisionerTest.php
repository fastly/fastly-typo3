<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Service\FastlyServiceProvisioner;
use Fastly\Model\DomainResponse;
use Fastly\Model\SchemasVersionResponse;
use Fastly\Model\ServiceResponse;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FastlyServiceProvisionerTest extends UnitTestCase
{
    /**
     * @param array<int, array{number: int, active: bool, locked: bool}> $versions
     * @return SchemasVersionResponse[]
     */
    private function versions(array $versions): array
    {
        return array_map(
            static fn (array $v): SchemasVersionResponse => new SchemasVersionResponse($v),
            $versions,
        );
    }

    /**
     * @param string[] $names
     * @return DomainResponse[]
     */
    private function domains(array $names): array
    {
        return array_map(static fn (string $n): DomainResponse => new DomainResponse(['name' => $n]), $names);
    }

    private function notFound(): ApiException
    {
        return new ApiException('not found', 404);
    }

    /**
     * @return FastlyClientInterface&MockObject
     */
    private function client(): FastlyClientInterface
    {
        return $this->createMock(FastlyClientInterface::class);
    }

    /**
     * C2: an update that needs a new version must clone the active version rather
     * than reuse an arbitrary pre-existing inactive/unlocked draft (which could
     * carry unrelated staged config that would then be published on activation).
     */
    public function testUpdateClonesActiveVersionAndNeverReusesStrayDraft(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => false, 'locked' => true],
            ['number' => 2, 'active' => true, 'locked' => true],
            ['number' => 3, 'active' => false, 'locked' => false], // stray draft — must be ignored
        ]));
        $client->method('listDomains')->willReturn($this->domains([]));
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        // The active version (2) must be cloned; version 3 must never be used.
        $client->expects(self::once())->method('cloneServiceVersion')
            ->with('svc', 2)
            ->willReturn(new Version(['number' => 4]));

        $createdOnVersion = null;
        $client->expects(self::once())->method('createDomain')
            ->willReturnCallback(function (string $s, int $v, string $d) use (&$createdOnVersion): DomainResponse {
                $createdOnVersion = $v;
                return new DomainResponse(['name' => $d]);
            });

        $activatedVersion = null;
        $client->expects(self::once())->method('activateServiceVersion')
            ->willReturnCallback(function (string $s, int $v) use (&$activatedVersion): VersionResponse {
                $activatedVersion = $v;
                return new VersionResponse(['number' => $v]);
            });

        $result = (new FastlyServiceProvisioner($client))->updateService('svc', ['example.com'], [], true);

        self::assertSame(4, $result['version']);
        self::assertTrue($result['cloned']);
        self::assertSame(4, $createdOnVersion, 'domain must be created on the cloned version');
        self::assertSame(4, $activatedVersion, 'the cloned version must be the one activated');
    }

    public function testAddServiceDryRunReportsPlannedChangesWithoutApiWrites(): void
    {
        $client = $this->client();
        $client->expects(self::never())->method('createService');
        $client->expects(self::never())->method('createDomain');
        $client->expects(self::never())->method('activateServiceVersion');

        $result = (new FastlyServiceProvisioner($client))->addService(
            'name',
            '',
            ['example.com', 'example.org'],
            [FastlyServiceProvisioner::FEATURE_HTTP3 => true],
            true,
            true,
        );

        self::assertSame('', $result['serviceId']);
        self::assertFalse($result['created']);
        self::assertFalse($result['activated']);
        self::assertSame(['example.com', 'example.org'], $result['addedDomains']);
        self::assertSame(['http3' => 'would enable'], $result['features']);
    }

    public function testAddServiceCreatesActivatesAndSkipsExistingDomains(): void
    {
        $client = $this->client();
        $client->method('createService')->willReturn(new ServiceResponse(['id' => 'new-svc']));
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => false, 'locked' => false],
        ]));
        // example.com already present; only example.org should be created.
        $client->method('listDomains')->willReturn($this->domains(['example.com']));

        $created = [];
        $client->method('createDomain')->willReturnCallback(
            function (string $s, int $v, string $d) use (&$created): DomainResponse {
                $created[] = $d;
                return new DomainResponse(['name' => $d]);
            },
        );
        $client->expects(self::once())->method('activateServiceVersion')
            ->with('new-svc', 1)
            ->willReturn(new VersionResponse(['number' => 1]));

        $result = (new FastlyServiceProvisioner($client))->addService(
            'name',
            '',
            ['example.com', 'example.org'],
            [],
            true,
        );

        self::assertSame('new-svc', $result['serviceId']);
        self::assertTrue($result['created']);
        self::assertTrue($result['activated']);
        self::assertSame(['example.org'], $created, 'existing domains must be skipped');
        self::assertSame(['example.org'], $result['addedDomains']);
    }

    public function testCheckServiceClassifiesDomainsAndReportsDisabledProductsOn404(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains(['example.com', 'legacy.example.com']));
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        $status = (new FastlyServiceProvisioner($client))->checkService('svc', ['example.com', 'new.example.com']);

        self::assertSame(2, $status['activeVersion']);
        self::assertSame(['example.com'], $status['matchingDomains']);
        self::assertSame(['new.example.com'], $status['missingDomains']);
        self::assertSame(['legacy.example.com'], $status['unknownDomains']);
        self::assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_HTTP3]);
        self::assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT]);
    }

    public function testCheckServiceReportsEnabledProductWhenGetterSucceeds(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains([]));
        $client->method('getHttp3')->willReturn(new \stdClass());
        $client->method('getBotManagement')->willReturn(new \stdClass());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        $status = (new FastlyServiceProvisioner($client))->checkService('svc', []);

        self::assertTrue($status['features'][FastlyServiceProvisioner::FEATURE_HTTP3]);
        self::assertTrue($status['features'][FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT]);
        self::assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_NGWAF]);
    }

    public function testUpdateReportsAlreadyActiveFeatureWithoutReEnabling(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains(['example.com']));
        $client->method('getHttp3')->willReturn(new \stdClass()); // already enabled
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        // Nothing to change: domain present, http3 already active → no clone, no enable, no activate.
        $client->expects(self::never())->method('cloneServiceVersion');
        $client->expects(self::never())->method('enableHttp3');
        $client->expects(self::never())->method('activateServiceVersion');

        $result = (new FastlyServiceProvisioner($client))->updateService(
            'svc',
            ['example.com'],
            [FastlyServiceProvisioner::FEATURE_HTTP3 => true],
            true,
        );

        self::assertSame('already active', $result['features'][FastlyServiceProvisioner::FEATURE_HTTP3]);
        self::assertFalse($result['activated']);
        self::assertSame([], $result['addedDomains']);
    }
}
