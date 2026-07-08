<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

use Fastly\ApiException;
use Fastly\Cdn\Api\FastlyClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\SingletonInterface;

final readonly class FlushService implements SingletonInterface
{
    public function __construct(
        protected FastlyClient $client,
        protected LoggerInterface $logger,
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "enableCdn")')]
        private bool|string $enableCdn = true,
    ) {
    }

    public function banTag(string $tag): void
    {
        if (!$this->isCdnEnabled()) {
            return;
        }

        try {
            $this->client->purgeByTag($tag);
        } catch (ApiException $e) {
            $this->logger->error('failed purging Fastly cache by tag', ['exception' => $e, 'tag' => $tag]);
        }
    }

    public function flushAll(): void
    {
        if (!$this->isCdnEnabled()) {
            return;
        }

        try {
            $this->client->purgeAll();
        } catch (ApiException $e) {
            $this->logger->error('failed purging all Fastly caches', ['exception' => $e]);
        }
    }

    private function isCdnEnabled(): bool
    {
        return filter_var($this->enableCdn, FILTER_VALIDATE_BOOL);
    }
}
