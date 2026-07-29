<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\Stub;
use Fastly\Model\VclResponse;
use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Exception\VclProvisioningException;
use Fastly\Cdn\Service\ManagedVersionResolver;
use Fastly\Cdn\Service\VclFileResolver;
use Fastly\Cdn\Service\VclProvisioner;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class VclProvisionerTest extends UnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/vclprov_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->root . '/*') ?: []);
        @rmdir($this->root);
        parent::tearDown();
    }

    private function local(string $name, string $content): void
    {
        file_put_contents($this->root . '/' . $name . '.vcl', $content);
    }

    /**
     * @param array<int, array<string, mixed>> $versions
     * @return VersionResponse[]
     */
    private function versions(array $versions): array
    {
        return array_map(static fn (array $v): VersionResponse => new VersionResponse($v), $versions);
    }

    /**
     * @return FastlyClientInterface&MockObject
     */
    private function client(): FastlyClientInterface
    {
        return $this->createMock(FastlyClientInterface::class);
    }

    private function provisioner(FastlyClientInterface $client): VclProvisioner
    {
        return new VclProvisioner(
            $client,
            new ManagedVersionResolver($client),
            new VclFileResolver('', $this->root),
        );
    }

    /**
     * Remote reads return the given content per (version, name); anything not in
     * the map throws a 404, i.e. that VCL file does not exist on that version.
     *
     * @param array<string, string> $map key "version:name" => content
     */
    private function remote(FastlyClientInterface&MockObject $client, array $map): void
    {
        $client->method('getCustomVclRaw')->willReturnCallback(
            function (string $s, int $v, string $n) use ($map): string {
                $key = $v . ':' . $n;
                if (!array_key_exists($key, $map)) {
                    throw new ApiException('not found', 404);
                }

                return $map[$key];
            },
        );
    }

    public function testFirstRunCreatesAllFilesClonesAndActivates(): void
    {
        $this->local('main', 'MAIN');
        $this->local('caching', 'CACHE');

        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $this->remote($client, []); // nothing on the service yet

        $client->expects($this->once())->method('cloneServiceVersion')->with('svc', 2)
            ->willReturn(new Version(['number' => 3]));
        $client->method('updateServiceVersionComment')->willReturn(new VersionResponse(['number' => 3]));

        $created = [];
        $mainFlag = [];
        $client->method('createCustomVcl')->willReturnCallback(
            function (string $s, int $v, string $n, string $c, bool $main) use (&$created, &$mainFlag): Stub {
                $created[] = $n;
                $mainFlag[$n] = $main;
                return $this->createStub(VclResponse::class);
            },
        );
        $client->expects($this->once())->method('setCustomVclMain')->with('svc', 3, 'main')
            ->willReturn($this->createStub(VclResponse::class));
        $client->expects($this->once())->method('activateServiceVersion')->with('svc', 3)
            ->willReturn(new VersionResponse(['number' => 3]));
        $client->expects($this->never())->method('updateCustomVcl');

        $result = $this->provisioner($client)->provision('svc', true);

        $this->assertSame(3, $result['version']);
        $this->assertTrue($result['cloned']);
        $this->assertTrue($result['activated']);
        $this->assertSame(['caching', 'main'], $result['created']);
        $this->assertSame([], $result['updated']);
        $this->assertTrue($mainFlag['main'], 'main.vcl must be created with the main flag');
        $this->assertFalse($mainFlag['caching']);
    }

    public function testRerunWithNoChangesMakesNoWrites(): void
    {
        $this->local('main', 'MAIN');
        $this->local('caching', 'CACHE');

        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $this->remote($client, ['2:main' => 'MAIN', '2:caching' => 'CACHE']);

        $client->expects($this->never())->method('cloneServiceVersion');
        $client->expects($this->never())->method('createCustomVcl');
        $client->expects($this->never())->method('updateCustomVcl');
        $client->expects($this->never())->method('setCustomVclMain');
        $client->expects($this->never())->method('activateServiceVersion');

        $result = $this->provisioner($client)->provision('svc', true);

        $this->assertSame(2, $result['version']);
        $this->assertFalse($result['cloned']);
        $this->assertFalse($result['activated']);
        $this->assertSame(['caching', 'main'], $result['unchanged']);
        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['updated']);
    }

    public function testOnlyChangedFileIsUpdated(): void
    {
        $this->local('main', 'MAIN');
        $this->local('caching', 'NEW');

        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $this->remote($client, ['2:main' => 'MAIN', '2:caching' => 'OLD']);

        $client->expects($this->once())->method('cloneServiceVersion')->with('svc', 2)
            ->willReturn(new Version(['number' => 3]));
        $client->method('updateServiceVersionComment')->willReturn(new VersionResponse(['number' => 3]));

        $updated = [];
        $client->method('updateCustomVcl')->willReturnCallback(
            function (string $s, int $v, string $n, string $c) use (&$updated): Stub {
                $updated[] = $n;
                return $this->createStub(VclResponse::class);
            },
        );
        $client->expects($this->never())->method('createCustomVcl');
        $client->expects($this->never())->method('setCustomVclMain'); // main unchanged, flag persists on clone
        $client->method('activateServiceVersion')->willReturn(new VersionResponse(['number' => 3]));

        $result = $this->provisioner($client)->provision('svc', true);

        $this->assertSame(['caching'], $updated);
        $this->assertSame(['caching'], $result['updated']);
        $this->assertSame([], $result['created']);
        $this->assertSame(['main'], $result['unchanged']);
        $this->assertTrue($result['activated']);
    }

    public function testDryRunReportsPlanWithoutWrites(): void
    {
        $this->local('main', 'MAIN');
        $this->local('caching', 'CACHE');

        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));
        $this->remote($client, []); // all missing

        $client->expects($this->never())->method('cloneServiceVersion');
        $client->expects($this->never())->method('createCustomVcl');
        $client->expects($this->never())->method('updateCustomVcl');
        $client->expects($this->never())->method('setCustomVclMain');
        $client->expects($this->never())->method('activateServiceVersion');

        $result = $this->provisioner($client)->provision('svc', true, true);

        $this->assertSame(['caching', 'main'], $result['created']);
        $this->assertFalse($result['cloned']);
        $this->assertFalse($result['activated']);
    }

    public function testReusesManagedDraftUnderNoActivateWithoutRewritingWhenInSync(): void
    {
        $this->local('main', 'MAIN');
        $this->local('caching', 'CACHE');

        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
            ['number' => 3, 'active' => false, 'locked' => false, 'comment' => ManagedVersionResolver::MANAGED_VERSION_COMMENT],
        ]));
        // The managed draft (3) already carries the desired content.
        $this->remote($client, ['3:main' => 'MAIN', '3:caching' => 'CACHE']);

        $client->expects($this->never())->method('cloneServiceVersion');
        $client->expects($this->never())->method('createCustomVcl');
        $client->expects($this->never())->method('updateCustomVcl');
        $client->expects($this->never())->method('activateServiceVersion');

        $result = $this->provisioner($client)->provision('svc', false);

        $this->assertSame(3, $result['version']);
        $this->assertFalse($result['cloned']);
        $this->assertFalse($result['activated']);
        $this->assertSame(['caching', 'main'], $result['unchanged']);
    }

    public function testThrowsWhenNoMainFilePresent(): void
    {
        $this->local('caching', 'CACHE'); // no main.vcl

        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));

        $this->expectException(VclProvisioningException::class);
        $this->provisioner($client)->provision('svc', true);
    }
}
