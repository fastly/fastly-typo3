<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Command;

use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Command\FastlyServiceUpdateCommand;
use Fastly\Cdn\Service\FastlyServiceProvisioner;
use Fastly\Cdn\Service\ManagedVersionResolver;
use Fastly\Cdn\Service\SiteDomainCollector;
use Fastly\Model\DomainResponse;
use Fastly\Model\SchemasVersionResponse;
use Fastly\Model\ServiceResponse;
use Fastly\Model\Version;
use Fastly\Model\VersionResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FastlyServiceUpdateCommandTest extends UnitTestCase
{
    /**
     * @param string[] $hosts
     */
    private function collectorWithHosts(array $hosts): SiteDomainCollector
    {
        $sites = [];
        foreach ($hosts as $host) {
            $uri = $this->createMock(UriInterface::class);
            $uri->method('__toString')->willReturn('https://' . $host . '/');
            $site = $this->createMock(Site::class);
            $site->method('getBase')->willReturn($uri);
            $site->method('getAllLanguages')->willReturn([]);
            $sites[] = $site;
        }

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        return new SiteDomainCollector($siteFinder);
    }

    private function tester(FastlyClientInterface $client, array $hosts = ['example.com']): CommandTester
    {
        $command = new FastlyServiceUpdateCommand(
            $this->collectorWithHosts($hosts),
            new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)),
            $client,
        );

        return new CommandTester($command);
    }

    /**
     * @return FastlyClientInterface&MockObject
     */
    private function client(): FastlyClientInterface
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('getConfiguredServiceId')->willReturn('svc');

        return $client;
    }

    private function notFound(): ApiException
    {
        return new ApiException('not found', 404);
    }

    /**
     * Configure the mock so the service has one locked active version (2) and
     * no products enabled — the plain read state every scenario starts from.
     */
    private function stubActiveVersionTwoWithoutProducts(FastlyClientInterface&MockObject $client): void
    {
        $client->method('listServiceVersions')->willReturn([
            new SchemasVersionResponse(['number' => 2, 'active' => true, 'locked' => true]),
        ]);
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());
    }

    public function testAddsMissingDomainOnClonedVersionAndActivates(): void
    {
        $client = $this->client();
        $this->stubActiveVersionTwoWithoutProducts($client);
        $client->method('listDomains')->willReturn([]);
        $client->expects($this->once())->method('cloneServiceVersion')
            ->with('svc', 2)
            ->willReturn(new Version(['number' => 3]));
        $client->method('updateServiceVersionComment')->willReturn(new VersionResponse(['number' => 3]));
        $client->expects($this->once())->method('createDomain')
            ->with('svc', 3, 'example.com')
            ->willReturn(new DomainResponse(['name' => 'example.com']));
        $client->expects($this->once())->method('activateServiceVersion')
            ->with('svc', 3)
            ->willReturn(new VersionResponse(['number' => 3]));

        $tester = $this->tester($client);
        $exitCode = $tester->execute(['--service-id' => 'svc']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('svc', $display);
        $this->assertStringContainsString('example.com', $display);
        $this->assertStringContainsString('added', $display);
        $this->assertStringContainsString('yes', $display, 'the activated flag must render as yes');
    }

    public function testDryRunMakesNoWriteCallsAndReportsPlannedChanges(): void
    {
        $client = $this->client();
        $this->stubActiveVersionTwoWithoutProducts($client);
        $client->method('listDomains')->willReturn([]);
        $client->expects($this->never())->method('updateService');
        $client->expects($this->never())->method('cloneServiceVersion');
        $client->expects($this->never())->method('createDomain');
        $client->expects($this->never())->method('enableHttp3');
        $client->expects($this->never())->method('activateServiceVersion');

        $tester = $this->tester($client);
        $exitCode = $tester->execute(['--service-id' => 'svc', '--dry-run' => true, '--http3' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('example.com', $display);
        $this->assertStringContainsString('HTTP/3', $display);
        $this->assertStringContainsString('would enable', $display);
    }

    public function testForwardsNameAndCommentToServiceUpdate(): void
    {
        $client = $this->client();
        $this->stubActiveVersionTwoWithoutProducts($client);
        // Domain already present: nothing else to write, no activation needed.
        $client->method('listDomains')->willReturn([new DomainResponse(['name' => 'example.com'])]);
        $client->expects($this->once())->method('updateService')
            ->with('svc', 'New name', 'New comment')
            ->willReturn(new ServiceResponse(['id' => 'svc']));
        $client->expects($this->never())->method('activateServiceVersion');

        $tester = $this->tester($client);
        $exitCode = $tester->execute([
            '--service-id' => 'svc',
            '--name' => ' New name ',
            '--comment' => 'New comment',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('already configured', $tester->getDisplay());
    }

    public function testNoActivateLeavesChangedVersionInactive(): void
    {
        $client = $this->client();
        $this->stubActiveVersionTwoWithoutProducts($client);
        $client->method('listDomains')->willReturn([]);
        $client->method('cloneServiceVersion')->willReturn(new Version(['number' => 3]));
        $client->method('updateServiceVersionComment')->willReturn(new VersionResponse(['number' => 3]));
        $client->method('createDomain')->willReturn(new DomainResponse(['name' => 'example.com']));
        $client->expects($this->never())->method('activateServiceVersion');

        $tester = $this->tester($client);
        $exitCode = $tester->execute(['--service-id' => 'svc', '--no-activate' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('no', $tester->getDisplay(), 'the activated flag must render as no');
    }

    public function testFallsBackToConfiguredServiceIdFromExtensionConfiguration(): void
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('getConfiguredServiceId')->willReturn('CONFIGURED_SVC');
        $client->method('listServiceVersions')->willReturn([
            new SchemasVersionResponse(['number' => 2, 'active' => true, 'locked' => true]),
        ]);
        $client->method('getHttp3')->willThrowException($this->notFound());
        $client->method('getBotManagement')->willThrowException($this->notFound());
        $client->method('getNgwaf')->willThrowException($this->notFound());
        $client->method('getDdosProtection')->willThrowException($this->notFound());
        $client->method('listDomains')->willReturn([new DomainResponse(['name' => 'example.com'])]);

        $tester = $this->tester($client);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('CONFIGURED_SVC', $tester->getDisplay());
    }

    public function testFailsCleanlyWithoutServiceId(): void
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('getConfiguredServiceId')->willReturn('');
        $client->expects($this->never())->method('listServiceVersions');

        $tester = $this->tester($client);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No Fastly service ID configured', $tester->getDisplay());
    }

    public function testFailsCleanlyWithoutSiteDomains(): void
    {
        $client = $this->client();
        $client->expects($this->never())->method('listServiceVersions');

        $tester = $this->tester($client, []);
        $exitCode = $tester->execute(['--service-id' => 'svc']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No absolute TYPO3 site domains found', $tester->getDisplay());
    }

    public function testReportsApiExceptionAsCleanFailure(): void
    {
        $client = $this->client();
        $client->method('listServiceVersions')->willThrowException(new ApiException('unauthorized', 401));

        $tester = $this->tester($client);
        $exitCode = $tester->execute(['--service-id' => 'svc']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Fastly API request failed: unauthorized', $tester->getDisplay());
    }
}
