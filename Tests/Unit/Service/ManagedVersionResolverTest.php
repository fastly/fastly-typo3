<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Service\ManagedVersionResolver;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ManagedVersionResolverTest extends UnitTestCase
{
    /**
     * The real SDK deserializes GET /service/{id}/version to VersionResponse
     * models (see FastlyClientTest) — mocks must hand out the same class.
     *
     * @param array<int, array<string, mixed>> $versions
     * @return VersionResponse[]
     */
    private function versions(array $versions): array
    {
        return array_map(
            static fn (array $v): VersionResponse => new VersionResponse($v),
            $versions,
        );
    }

    /**
     * @return FastlyClientInterface&MockObject
     */
    private function client(): FastlyClientInterface
    {
        return $this->createMock(FastlyClientInterface::class);
    }

    public function testResolveActiveVersionReturnsActiveNumber(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => false, 'locked' => true],
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));

        $this->assertSame(2, (new ManagedVersionResolver($client))->resolveActiveVersion('svc'));
    }

    public function testResolveActiveVersionFallsBackToLatestWhenNoneActive(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => false, 'locked' => true],
            ['number' => 3, 'active' => false, 'locked' => false],
            ['number' => 2, 'active' => false, 'locked' => true],
        ]));

        $this->assertSame(3, (new ManagedVersionResolver($client))->resolveActiveVersion('svc'));
    }

    public function testResolveEditableVersionReturnsFirstUnlocked(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => false, 'locked' => true],
            ['number' => 2, 'active' => false, 'locked' => false],
        ]));

        $this->assertSame(2, (new ManagedVersionResolver($client))->resolveEditableVersion('svc'));
    }

    public function testResolveEditableVersionFallsBackToLatestWhenAllLocked(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 1, 'active' => false, 'locked' => true],
            ['number' => 2, 'active' => true, 'locked' => true],
        ]));

        $this->assertSame(2, (new ManagedVersionResolver($client))->resolveEditableVersion('svc'));
    }

    public function testAcquireEditableDraftReusesHighestManagedDraftWithoutCloning(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
            ['number' => 3, 'active' => false, 'locked' => false, 'comment' => ManagedVersionResolver::MANAGED_VERSION_COMMENT],
            ['number' => 4, 'active' => false, 'locked' => false, 'comment' => ManagedVersionResolver::MANAGED_VERSION_COMMENT],
            ['number' => 5, 'active' => false, 'locked' => false], // stray draft — must be ignored
        ]));
        $client->expects($this->never())->method('cloneServiceVersion');
        $client->expects($this->never())->method('updateServiceVersionComment');

        $draft = (new ManagedVersionResolver($client))->acquireEditableDraft('svc', 2);

        $this->assertSame(['version' => 4, 'cloned' => false], $draft);
    }

    public function testAcquireEditableDraftClonesActiveAndTagsWhenNoManagedDraft(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn($this->versions([
            ['number' => 2, 'active' => true, 'locked' => true],
            ['number' => 3, 'active' => false, 'locked' => false], // stray draft — must never be reused
        ]));
        $client->expects($this->once())->method('cloneServiceVersion')
            ->with('svc', 2)
            ->willReturn(new Version(['number' => 4]));
        $client->expects($this->once())->method('updateServiceVersionComment')
            ->with('svc', 4, ManagedVersionResolver::MANAGED_VERSION_COMMENT)
            ->willReturn(new VersionResponse(['number' => 4]));

        $draft = (new ManagedVersionResolver($client))->acquireEditableDraft('svc', 2);

        $this->assertSame(['version' => 4, 'cloned' => true], $draft);
    }
}
