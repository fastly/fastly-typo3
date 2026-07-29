<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Command;

use InvalidArgumentException;
use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Command\FastlyServiceAddCommand;
use Fastly\Cdn\Service\FastlyServiceProvisioner;
use Fastly\Cdn\Service\ManagedVersionResolver;
use Fastly\Cdn\Service\SiteDomainCollector;
use Fastly\Model\DomainResponse;
use Fastly\Model\SchemasVersionResponse;
use Fastly\Model\ServiceResponse;
use Fastly\Model\VersionResponse;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FastlyServiceAddCommandTest extends UnitTestCase
{
    private function collectorWithHost(string $host): SiteDomainCollector
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('https://' . $host . '/');
        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn($uri);
        $site->method('getAllLanguages')->willReturn([]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);

        return new SiteDomainCollector($siteFinder);
    }

    private function tester(FastlyClientInterface $client): CommandTester
    {
        $command = new FastlyServiceAddCommand(
            $this->collectorWithHost('example.com'),
            new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)),
            $client,
        );

        return new CommandTester($command);
    }

    /**
     * I3: a non-ApiException thrown while talking to the SDK (e.g. a missing
     * required parameter) must be caught at the command boundary and rendered as
     * a clean error instead of surfacing as an uncaught fatal.
     */
    public function testReportsNonApiExceptionAsCleanFailure(): void
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('createService')->willThrowException(new InvalidArgumentException('missing parameter'));

        $tester = $this->tester($client);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('missing parameter', $tester->getDisplay());
    }

    public function testReportsApiExceptionAsCleanFailure(): void
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('createService')->willThrowException(new ApiException('unauthorized', 401));

        $tester = $this->tester($client);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('unauthorized', $tester->getDisplay());
    }

    public function testCreatesServiceAddsDomainAndPrintsServiceIdNote(): void
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->expects($this->once())->method('createService')
            ->with('My Service', 'managed by TYPO3')
            ->willReturn(new ServiceResponse(['id' => 'new-svc']));
        $client->method('listServiceVersions')->willReturn([
            new SchemasVersionResponse(['number' => 1, 'active' => false, 'locked' => false]),
        ]);
        $client->method('listDomains')->willReturn([]);
        $client->expects($this->once())->method('createDomain')
            ->with('new-svc', 1, 'example.com')
            ->willReturn(new DomainResponse(['name' => 'example.com']));
        $client->expects($this->once())->method('activateServiceVersion')
            ->with('new-svc', 1)
            ->willReturn(new VersionResponse(['number' => 1]));

        $tester = $this->tester($client);
        $exitCode = $tester->execute(['--name' => 'My Service', '--comment' => 'managed by TYPO3']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Set this value as the extension serviceId: new-svc', $display);
        $this->assertStringContainsString('example.com', $display);
        $this->assertStringContainsString('added', $display);
    }

    public function testFailsCleanlyWithoutSiteDomains(): void
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->expects($this->never())->method('createService');

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $command = new FastlyServiceAddCommand(
            new SiteDomainCollector($siteFinder),
            new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)),
            $client,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No absolute TYPO3 site domains found', $tester->getDisplay());
    }
}
