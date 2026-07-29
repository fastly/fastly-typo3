<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Command;

use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClientInterface;
use Fastly\Cdn\Service\FastlyServiceProvisioner;
use Fastly\Cdn\Service\ManagedVersionResolver;
use Fastly\Cdn\Service\SiteDomainCollector;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Shared fixtures for the fastly:service:* command tests — all three commands
 * take the same collaborators (SiteDomainCollector, FastlyServiceProvisioner,
 * FastlyClientInterface), only the command class differs.
 */
abstract class AbstractServiceCommandTestCase extends UnitTestCase
{
    abstract protected function createCommand(
        SiteDomainCollector $collector,
        FastlyServiceProvisioner $provisioner,
        FastlyClientInterface $client,
    ): Command;

    /**
     * @param string[] $hosts
     */
    protected function collectorWithHosts(array $hosts): SiteDomainCollector
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

    /**
     * @param string[] $hosts
     */
    protected function tester(FastlyClientInterface $client, array $hosts = ['example.com']): CommandTester
    {
        return new CommandTester($this->createCommand(
            $this->collectorWithHosts($hosts),
            new FastlyServiceProvisioner($client, new ManagedVersionResolver($client)),
            $client,
        ));
    }

    /**
     * @return FastlyClientInterface&MockObject
     */
    protected function client(): FastlyClientInterface
    {
        $client = $this->createMock(FastlyClientInterface::class);
        $client->method('getConfiguredServiceId')->willReturn('svc');

        return $client;
    }

    protected function notFound(): ApiException
    {
        return new ApiException('not found', 404);
    }
}
