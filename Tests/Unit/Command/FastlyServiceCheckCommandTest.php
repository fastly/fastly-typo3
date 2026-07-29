<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Command;

use stdClass;
use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Command\FastlyServiceCheckCommand;
use Fastly\Cdn\Service\FastlyServiceProvisioner;
use Fastly\Cdn\Service\SiteDomainCollector;
use Fastly\Model\DomainResponse;
use Fastly\Model\VersionResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;

final class FastlyServiceCheckCommandTest extends AbstractServiceCommandTestCase
{
    protected function createCommand(
        SiteDomainCollector $collector,
        FastlyServiceProvisioner $provisioner,
        FastlyClientInterface $client,
    ): FastlyServiceCheckCommand {
        return new FastlyServiceCheckCommand($collector, $provisioner, $client);
    }

    /**
     * @param string[] $serviceDomains
     * @return FastlyClientInterface&MockObject
     */
    private function clientWithServiceDomains(array $serviceDomains): FastlyClientInterface
    {
        $notFound = $this->notFound();
        $client = $this->client();
        $client->method('listServiceVersions')->willReturn([
            new VersionResponse(['number' => 2, 'active' => true, 'locked' => true]),
        ]);
        $client->method('listDomains')->willReturn(array_map(
            static fn (string $name): DomainResponse => new DomainResponse(['name' => $name]),
            $serviceDomains,
        ));
        $client->method('getHttp3')->willThrowException($notFound);
        $client->method('getBotManagement')->willThrowException($notFound);
        $client->method('getNgwaf')->willThrowException($notFound);
        $client->method('getDdosProtection')->willThrowException($notFound);

        return $client;
    }

    public function testSucceedsWhenAllSiteDomainsAreConfigured(): void
    {
        $tester = $this->tester($this->clientWithServiceDomains(['example.com']));
        $exitCode = $tester->execute(['--service-id' => 'svc']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('svc', $display);
        $this->assertStringContainsString('2', $display, 'the active version must be rendered');
        $this->assertStringContainsString('configured', $display);
    }

    public function testFailsWhenSiteDomainsAreMissingInFastly(): void
    {
        $tester = $this->tester($this->clientWithServiceDomains([]));
        $exitCode = $tester->execute(['--service-id' => 'svc']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('missing in Fastly', $tester->getDisplay());
    }

    public function testReportsServiceDomainsUnknownToTypo3WithoutFailing(): void
    {
        $tester = $this->tester($this->clientWithServiceDomains(['example.com', 'legacy.example.com']));
        $exitCode = $tester->execute(['--service-id' => 'svc']);

        $this->assertSame(Command::SUCCESS, $exitCode, 'extra Fastly domains are informational, not an error');
        $this->assertStringContainsString('not in TYPO3 site config', $tester->getDisplay());
    }

    public function testRendersFeatureStatusForEnabledAndDisabledProducts(): void
    {
        $notFound = new ApiException('not found', 404);
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('listServiceVersions')->willReturn([
            new VersionResponse(['number' => 2, 'active' => true, 'locked' => true]),
        ]);
        $client->method('listDomains')->willReturn([new DomainResponse(['name' => 'example.com'])]);
        $client->method('getHttp3')->willReturn(new stdClass());
        $client->method('getBotManagement')->willThrowException($notFound);
        $client->method('getNgwaf')->willThrowException($notFound);
        $client->method('getDdosProtection')->willThrowException($notFound);

        $tester = $this->tester($client);
        $exitCode = $tester->execute(['--service-id' => 'svc']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('HTTP/3', $display);
        $this->assertStringContainsString('Bot Management', $display);
        $this->assertStringContainsString('Next-Gen WAF', $display);
        $this->assertStringContainsString('DDoS Protection', $display);
        $this->assertStringContainsString('yes', $display);
        $this->assertStringContainsString('no', $display);
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
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('getConfiguredServiceId')->willReturn('svc');
        $client->expects($this->never())->method('listServiceVersions');

        $tester = $this->tester($client, []);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No absolute TYPO3 site domains found', $tester->getDisplay());
    }

    public function testReportsApiExceptionAsCleanFailure(): void
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('getConfiguredServiceId')->willReturn('svc');
        $client->method('listServiceVersions')->willThrowException(new ApiException('unauthorized', 401));

        $tester = $this->tester($client);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Fastly API request failed: unauthorized', $tester->getDisplay());
    }
}
