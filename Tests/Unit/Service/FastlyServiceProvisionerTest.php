<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use stdClass;
use Iterator;
use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Service\FastlyServiceProvisioner;
use Fastly\Cdn\Service\ManagedVersionResolver;
use Fastly\Model\DomainResponse;
use Fastly\Model\SchemasVersionResponse;
use Fastly\Model\ServiceResponse;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $client->expects($this->once())->method('cloneServiceVersion')
            ->with('svc', 2)
            ->willReturn(new Version(['number' => 4]));

        // The clone must be tagged so a later run can recognise and reuse it.
        $client->expects($this->once())->method('updateServiceVersionComment')
            ->with('svc', 4, ManagedVersionResolver::MANAGED_VERSION_COMMENT)
            ->willReturn(new VersionResponse(['number' => 4]));

        $createdOnVersion = null;
        $client->expects($this->once())->method('createDomain')
            ->willReturnCallback(function (string $s, int $v, string $d) use (&$createdOnVersion): DomainResponse {
                $createdOnVersion = $v;
                return new DomainResponse(['name' => $d]);
            });

        $activatedVersion = null;
        $client->expects($this->once())->method('activateServiceVersion')
            ->willReturnCallback(function (string $s, int $v) use (&$activatedVersion): VersionResponse {
                $activatedVersion = $v;
                return new VersionResponse(['number' => $v]);
            });

        $result = ((new FastlyServiceProvisioner($client, new ManagedVersionResolver($client))))->updateService('svc', ['example.com'], [], true);

        $this->assertSame(4, $result['version']);
        $this->assertTrue($result['cloned']);
        $this->assertSame(4, $createdOnVersion, 'domain must be created on the cloned version');
        $this->assertSame(4, $activatedVersion, 'the cloned version must be the one activated');
    }

    /**
     * I1: a draft this extension previously cloned (identified by its comment
     * marker) is reused instead of cloning again, so repeated updates — notably
     * under --no-activate — do not pile up new draft versions.
     */
    public function testUpdateReusesManagedDraftInsteadOfCloning(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
            ['number' => 3, 'active' => false, 'locked' => false, 'comment' => ManagedVersionResolver::MANAGED_VERSION_COMMENT],
        ]));
        $client->method('listDomains')->willReturn($this->domains([]));
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        $client->expects($this->never())->method('cloneServiceVersion');
        $createdOnVersion = null;
        $client->expects($this->once())->method('createDomain')
            ->willReturnCallback(function (string $s, int $v, string $d) use (&$createdOnVersion): DomainResponse {
                $createdOnVersion = $v;
                return new DomainResponse(['name' => $d]);
            });

        $result = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->updateService('svc', ['example.com'], [], true);

        $this->assertSame(3, $result['version']);
        $this->assertFalse($result['cloned']);
        $this->assertSame(3, $createdOnVersion, 'the missing domain must be added to the reused managed draft');
    }

    /**
     * I1: when the managed draft already carries the desired domains, a repeated
     * update makes no write calls at all — no new clone, no domain creation, no
     * activation.
     */
    public function testUpdateMakesNoWritesWhenManagedDraftAlreadyMatches(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
            ['number' => 3, 'active' => false, 'locked' => false, 'comment' => ManagedVersionResolver::MANAGED_VERSION_COMMENT],
        ]));
        // Active version (2) still lacks the domain; the managed draft (3) already has it.
        $client->method('listDomains')->willReturnCallback(
            fn (string $s, int $v): array => $v === 3 ? $this->domains(['example.com']) : $this->domains([]),
        );
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        $client->expects($this->never())->method('cloneServiceVersion');
        $client->expects($this->never())->method('createDomain');
        $client->expects($this->never())->method('activateServiceVersion');

        $result = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->updateService('svc', ['example.com'], [], true);

        $this->assertSame(3, $result['version']);
        $this->assertFalse($result['cloned']);
        $this->assertSame([], $result['addedDomains']);
        $this->assertFalse($result['activated']);
    }

    public function testAddServiceDryRunReportsPlannedChangesWithoutApiWrites(): void
    {
        $client = $this->client();
        $client->expects($this->never())->method('createService');
        $client->expects($this->never())->method('createDomain');
        $client->expects($this->never())->method('activateServiceVersion');

        $result = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->addService(
            'name',
            '',
            ['example.com', 'example.org'],
            [FastlyServiceProvisioner::FEATURE_HTTP3 => true],
            true,
            true,
        );

        $this->assertSame('', $result['serviceId']);
        $this->assertFalse($result['created']);
        $this->assertFalse($result['activated']);
        $this->assertSame(['example.com', 'example.org'], $result['addedDomains']);
        $this->assertSame(['http3' => 'would enable'], $result['features']);
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
        $client->expects($this->once())->method('activateServiceVersion')
            ->with('new-svc', 1)
            ->willReturn(new VersionResponse(['number' => 1]));

        $result = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->addService(
            'name',
            '',
            ['example.com', 'example.org'],
            [],
            true,
        );

        $this->assertSame('new-svc', $result['serviceId']);
        $this->assertTrue($result['created']);
        $this->assertTrue($result['activated']);
        $this->assertSame(['example.org'], $created, 'existing domains must be skipped');
        $this->assertSame(['example.org'], $result['addedDomains']);
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

        $status = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->checkService('svc', ['example.com', 'new.example.com']);

        $this->assertSame(2, $status['activeVersion']);
        $this->assertSame(['example.com'], $status['matchingDomains']);
        $this->assertSame(['new.example.com'], $status['missingDomains']);
        $this->assertSame(['legacy.example.com'], $status['unknownDomains']);
        $this->assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_HTTP3]);
        $this->assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT]);
    }

    public function testCheckServiceReportsEnabledProductWhenGetterSucceeds(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains([]));
        $client->method('getHttp3')->willReturn(new stdClass());
        $client->method('getBotManagement')->willReturn(new stdClass());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        $status = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->checkService('svc', []);

        $this->assertTrue($status['features'][FastlyServiceProvisioner::FEATURE_HTTP3]);
        $this->assertTrue($status['features'][FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT]);
        $this->assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_NGWAF]);
    }

    /**
     * The Fastly enablement contract for a not-enabled product is not pinned to a
     * single status code, so any client-side "not enabled" signal must be read as
     * disabled rather than propagated as a check failure.
     */
    #[DataProvider('productDisabledStatusCodes')]
    public function testCheckServiceTreatsProductClientErrorsAsDisabled(int $statusCode): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains([]));
        $client->method('getHttp3')->willThrowException(new ApiException('not enabled', $statusCode));
        $client->method('getBotManagement')->willThrowException(new ApiException('not enabled', $statusCode));
        $client->method('getNgwaf')->willThrowException(new ApiException('not enabled', $statusCode));
        $client->method('getDdosProtection')->willThrowException(new ApiException('not enabled', $statusCode));

        $status = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->checkService('svc', []);

        $this->assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_HTTP3]);
        $this->assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT]);
        $this->assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_NGWAF]);
        $this->assertFalse($status['features'][FastlyServiceProvisioner::FEATURE_DDOS_PROTECTION]);
    }

    /**
     * @return Iterator<string, array{int}>
     */
    public static function productDisabledStatusCodes(): Iterator
    {
        yield '400 Bad Request' => [400];
        yield '403 Forbidden' => [403];
        yield '404 Not Found' => [404];
    }

    public function testCheckServicePropagatesUnexpectedApiErrors(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains([]));
        $client->method('getHttp3')->willThrowException(new ApiException('server error', 500));

        $this->expectException(ApiException::class);
        (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->checkService('svc', []);
    }

    public function testUpdateReportsAlreadyActiveFeatureWithoutReEnabling(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains(['example.com']));
        $client->method('getHttp3')->willReturn(new stdClass()); // already enabled
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        // Nothing to change: domain present, http3 already active → no clone, no enable, no activate.
        $client->expects($this->never())->method('cloneServiceVersion');
        $client->expects($this->never())->method('enableHttp3');
        $client->expects($this->never())->method('activateServiceVersion');

        $result = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->updateService(
            'svc',
            ['example.com'],
            [FastlyServiceProvisioner::FEATURE_HTTP3 => true],
            true,
        );

        $this->assertSame('already active', $result['features'][FastlyServiceProvisioner::FEATURE_HTTP3]);
        $this->assertFalse($result['activated']);
        $this->assertSame([], $result['addedDomains']);
    }

    public function testUpdateEnablesDisabledProducts(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains(['example.com']));
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        $client->expects($this->once())->method('enableBotManagement')->with('svc');
        $client->expects($this->once())->method('enableNgwaf')->with('svc');
        $client->expects($this->once())->method('enableDdosProtection')->with('svc');

        $result = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->updateService(
            'svc',
            ['example.com'],
            [
                FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT => true,
                FastlyServiceProvisioner::FEATURE_NGWAF => true,
                FastlyServiceProvisioner::FEATURE_DDOS_PROTECTION => true,
            ],
            true,
        );

        $this->assertSame('enabled', $result['features'][FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT]);
        $this->assertSame('enabled', $result['features'][FastlyServiceProvisioner::FEATURE_NGWAF]);
        $this->assertSame('enabled', $result['features'][FastlyServiceProvisioner::FEATURE_DDOS_PROTECTION]);
    }

    public function testUpdateReportsActiveProductsWithoutReEnabling(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains(['example.com']));
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willReturn(new stdClass());
        $client->method('getNgwaf')->willReturn(new stdClass());
        $client->method('getDdosProtection')->willReturn(new stdClass());

        $client->expects($this->never())->method('enableBotManagement');
        $client->expects($this->never())->method('enableNgwaf');
        $client->expects($this->never())->method('enableDdosProtection');

        $result = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->updateService(
            'svc',
            ['example.com'],
            [
                FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT => true,
                FastlyServiceProvisioner::FEATURE_NGWAF => true,
                FastlyServiceProvisioner::FEATURE_DDOS_PROTECTION => true,
            ],
            true,
        );

        $this->assertSame('already active', $result['features'][FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT]);
        $this->assertSame('already active', $result['features'][FastlyServiceProvisioner::FEATURE_NGWAF]);
        $this->assertSame('already active', $result['features'][FastlyServiceProvisioner::FEATURE_DDOS_PROTECTION]);
    }

    public function testUpdateServiceDryRunReportsPlannedChangesWithoutApiWrites(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $client->method('listDomains')->willReturn($this->domains([]));
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willReturn(new stdClass()); // already enabled
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());

        $client->expects($this->never())->method('updateService');
        $client->expects($this->never())->method('cloneServiceVersion');
        $client->expects($this->never())->method('createDomain');
        $client->expects($this->never())->method('enableHttp3');
        $client->expects($this->never())->method('enableBotManagement');
        $client->expects($this->never())->method('activateServiceVersion');

        $result = (new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)))->updateService(
            'svc',
            ['example.com'],
            [
                FastlyServiceProvisioner::FEATURE_HTTP3 => true,
                FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT => true,
            ],
            true,
            true,
            'New name',
            'New comment',
        );

        $this->assertSame(2, $result['version']);
        $this->assertTrue($result['cloned'], 'the plan must announce that a new version would be cloned');
        $this->assertFalse($result['activated']);
        $this->assertSame(['example.com'], $result['addedDomains']);
        $this->assertSame('would enable', $result['features'][FastlyServiceProvisioner::FEATURE_HTTP3]);
        $this->assertSame('already active', $result['features'][FastlyServiceProvisioner::FEATURE_BOT_MANAGEMENT]);
    }
}
