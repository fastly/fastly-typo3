<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use Fastly\Cdn\Service\SiteDomainCollector;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteDomainCollectorTest extends UnitTestCase
{
    private function uri(string $value): UriInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn($value);
        return $uri;
    }

    /**
     * @param string[] $languageBases
     */
    private function site(string $base, array $languageBases): Site
    {
        $languages = array_map(function (string $languageBase): SiteLanguage {
            $language = $this->createMock(SiteLanguage::class);
            $language->method('getBase')->willReturn($this->uri($languageBase));
            return $language;
        }, $languageBases);

        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn($this->uri($base));
        $site->method('getAllLanguages')->willReturn($languages);
        return $site;
    }

    /**
     * @param Site[] $sites
     */
    private function collector(array $sites): SiteDomainCollector
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);
        return new SiteDomainCollector($siteFinder);
    }

    public function testCollectsAndDedupesSiteAndLanguageHosts(): void
    {
        $collector = $this->collector([
            $this->site('https://www.example.com/', [
                'https://www.example.com/en/',
                'https://fr.example.com/',
            ]),
        ]);

        $this->assertSame(['fr.example.com', 'www.example.com'], $collector->collectDomains());
    }

    public function testLanguageWithoutHostFallsBackToSiteHost(): void
    {
        $collector = $this->collector([
            $this->site('https://www.example.com/', ['/en/']),
        ]);

        $this->assertSame(['www.example.com'], $collector->collectDomains());
    }

    public function testNormalizesHostCaseAndTrailingDot(): void
    {
        $collector = $this->collector([
            $this->site('https://WWW.Example.COM./', []),
        ]);

        $this->assertSame(['www.example.com'], $collector->collectDomains());
    }

    public function testReturnsEmptyWhenNoHostsConfigured(): void
    {
        $collector = $this->collector([
            $this->site('/', ['/en/']),
        ]);

        $this->assertSame([], $collector->collectDomains());
    }

    public function testMergesAndSortsHostsAcrossSites(): void
    {
        $collector = $this->collector([
            $this->site('https://zeta.example.com/', []),
            $this->site('https://alpha.example.com/', ['https://beta.example.com/']),
        ]);

        $this->assertSame(['alpha.example.com', 'beta.example.com', 'zeta.example.com'], $collector->collectDomains());
    }
}
