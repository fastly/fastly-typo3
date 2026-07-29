<?php

declare(strict_types=1);

namespace Fastly\Cdn\Cache\Backend\V13;

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

    public function flush()
    {
        $this->flushService->flushAll();
    }

    public function flushByTags(array $tags)
    {
        $this->flushService->purgeTags($tags);
    }

    public function flushByTag(string $tag)
    {
        $this->flushService->purgeTag($tag);
    }
}
