<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class SiteDomainCollector
{
    public function __construct(private SiteFinder $siteFinder)
    {
    }

    /**
     * @return string[]
     */
    public function collectDomains(): array
    {
        $domains = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $siteHost = $this->extractHost((string)$site->getBase());
            if ($siteHost !== null) {
                $domains[$siteHost] = $siteHost;
            }

            foreach ($site->getAllLanguages() as $language) {
                $languageHost = $this->extractHost((string)$language->getBase()) ?? $siteHost;
                if ($languageHost !== null) {
                    $domains[$languageHost] = $languageHost;
                }
            }
        }

        ksort($domains);
        return array_values($domains);
    }

    private function extractHost(string $base): ?string
    {
        $host = parse_url($base, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower(rtrim($host, '.'));
    }
}
