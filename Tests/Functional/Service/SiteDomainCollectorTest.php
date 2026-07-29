<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Functional\Service;

use Fastly\Cdn\Service\SiteDomainCollector;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Runs the collector against real site configuration files resolved by the
 * real SiteFinder instead of mocked Site entities.
 */
final class SiteDomainCollectorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['fastly'];

    /**
     * @param array<string, mixed> $configuration
     */
    private function writeSiteConfiguration(string $identifier, array $configuration): void
    {
        $directory = $this->instancePath . '/typo3conf/sites/' . $identifier;
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/config.yaml', Yaml::dump($configuration, 99, 2));
    }

    public function testCollectsDeduplicatedHostsFromSitesAndLanguageBases(): void
    {
        $this->writeSiteConfiguration('main', [
            'rootPageId' => 1,
            'base' => 'https://www.example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    // Relative base: resolves against the site base, same host.
                    'base' => '/',
                ],
                [
                    'languageId' => 1,
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'base' => 'https://DE.example.com/',
                ],
            ],
        ]);
        $this->writeSiteConfiguration('second', [
            'rootPageId' => 2,
            'base' => 'https://www.example.org/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => '/',
                ],
            ],
        ]);

        $collector = new SiteDomainCollector($this->get(SiteFinder::class));

        $this->assertSame(['de.example.com', 'www.example.com', 'www.example.org'], $collector->collectDomains(), 'hosts must be lowercased, deduplicated and sorted');
    }

    public function testIgnoresSitesWithoutAbsoluteBase(): void
    {
        $this->writeSiteConfiguration('relative', [
            'rootPageId' => 1,
            'base' => '/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => '/',
                ],
            ],
        ]);

        $collector = new SiteDomainCollector($this->get(SiteFinder::class));

        $this->assertSame([], $collector->collectDomains());
    }
}
