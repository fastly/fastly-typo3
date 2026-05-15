<?php

declare(strict_types=1);

namespace Fastly\Cdn\Cache\Backend;

use Fastly\Cdn\Service\FlushService;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FastlyBackend extends NullBackend
{

    private readonly FlushService $flushService;

    public function __construct()
    {
        $this->flushService = GeneralUtility::makeInstance(FlushService::class);
    }

    public function flush(): void
    {
        $this->flushService->flushAll();
    }

    public function flushByTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->flushByTag($tag);
        }
    }

    public function flushByTag(string $tag): void
    {
        $this->flushService->banTag($tag);
    }
}
