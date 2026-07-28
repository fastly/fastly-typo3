<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Command;

use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Command\FastlyVclDiffCommand;
use Fastly\Cdn\Service\ManagedVersionResolver;
use Fastly\Cdn\Service\VclFileResolver;
use Fastly\Cdn\Service\VclProvisioner;
use Fastly\Model\SchemasVersionResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FastlyVclDiffCommandTest extends UnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/vcldiff_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/main.vcl', 'MAIN');
        file_put_contents($this->root . '/caching.vcl', 'NEW');
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
        $command = new FastlyVclDiffCommand(
            $client,
            new VclProvisioner($client, new ManagedVersionResolver($client), new VclFileResolver('', $this->root)),
        );

        return new CommandTester($command);
    }

    public function testRendersPerFileStatusAndMakesNoWrites(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn([
            new SchemasVersionResponse(['number' => 2, 'active' => true, 'locked' => true]),
        ]);
        // main exists and matches; caching exists but differs; (none missing here)
        $client->method('getCustomVclRaw')->willReturnCallback(
            function (string $s, int $v, string $n): string {
                return $n === 'main' ? 'MAIN' : 'OLD';
            },
        );
        $client->expects(self::never())->method('cloneServiceVersion');
        $client->expects(self::never())->method('createCustomVcl');
        $client->expects(self::never())->method('updateCustomVcl');
        $client->expects(self::never())->method('activateServiceVersion');

        $tester = $this->tester($client);
        $tester->execute(['--service-id' => 'svc']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('caching', $display);
        self::assertStringContainsString('main', $display);
    }

    public function testReportsMissingFilesAsAdded(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn([
            new SchemasVersionResponse(['number' => 2, 'active' => true, 'locked' => true]),
        ]);
        $client->method('getCustomVclRaw')->willThrowException(new ApiException('not found', 404));

        $tester = $this->tester($client);
        $tester->execute(['--service-id' => 'svc']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('added', $tester->getDisplay());
    }
}
