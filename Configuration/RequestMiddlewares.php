<?php

declare(strict_types=1);

use Fastly\Cdn\Middleware\ExposeCacheTags;

return [
    'frontend' => [
        'fastly/cdn/expose-cache-tags' => [
            'target' => ExposeCacheTags::class,
            'after' => ['typo3/cms-core/cache-tags-attribute'],
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
