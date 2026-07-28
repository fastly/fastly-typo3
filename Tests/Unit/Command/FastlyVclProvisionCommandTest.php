<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Command;

use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Command\FastlyVclProvisionCommand;
use Fastly\Cdn\Service\ManagedVersionResolver;
use Fastly\Cdn\Service\VclFileResolver;
use Fastly\Cdn\Service\VclProvisioner;
use Fastly\Model\SchemasVersionResponse;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FastlyVclProvisionCommandTest extends UnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/vclcmd_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/main.vcl', 'MAIN');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/*') ?: []);
        @rmdir($this->root);
        parent::tearDown();
    }

    /**
     * @return FastlyClientInterface&MockObject
     */
    private function client(): FastlyClientInterface
    {
        return $this->createMock(FastlyClientInterface::class);
    }

    private function tester(FastlyClientInterface $client): CommandTester
    {
        $command = new FastlyVclProvisionCommand(
            $client,
            new VclProvisioner($client, new ManagedVersionResolver($client), new VclFileResolver('', $this->root)),
        );

        return new CommandTester($command);
    }

    public function testFailsWhenNoServiceIdConfigured(): void
    {
        $client = $this->client();
        $client->method('getConfiguredServiceId')->willReturn('');
        $client->expects(self::never())->method('listServiceVersions');

        $tester = $this->tester($client);
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('service ID', $tester->getDisplay());
    }

    public function testReportsApiFailureAsCleanFailure(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willThrowException(new ApiException('boom', 500));

        $tester = $this->tester($client);
        $tester->execute(['--service-id' => 'svc']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    public function testProvisionsUploadsAndActivates(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn([
            new SchemasVersionResponse(['number' => 2, 'active' => true, 'locked' => true]),
        ]);
        $client->method('getCustomVclRaw')->willThrowException(new ApiException('not found', 404));
        $client->method('cloneServiceVersion')->willReturn(new Version(['number' => 3]));
        $client->method('updateServiceVersionComment')->willReturn(new VersionResponse(['number' => 3]));
        $client->method('createCustomVcl')->willReturn($this->createStub(\Fastly\Model\VclResponse::class));
        $client->method('setCustomVclMain')->willReturn($this->createStub(\Fastly\Model\VclResponse::class));
        $client->expects(self::once())->method('activateServiceVersion')->with('svc', 3)
            ->willReturn(new VersionResponse(['number' => 3]));

        $tester = $this->tester($client);
        $tester->execute(['--service-id' => 'svc']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('main', $tester->getDisplay());
    }

    public function testDryRunPerformsNoWrites(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn([
            new SchemasVersionResponse(['number' => 2, 'active' => true, 'locked' => true]),
        ]);
        $client->method('getCustomVclRaw')->willThrowException(new ApiException('not found', 404));
        $client->expects(self::never())->method('cloneServiceVersion');
        $client->expects(self::never())->method('createCustomVcl');
        $client->expects(self::never())->method('activateServiceVersion');

        $tester = $this->tester($client);
        $tester->execute(['--service-id' => 'svc', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
