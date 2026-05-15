<?php

declare(strict_types=1);

use Fastly\Cdn\Cache\Backend\FastlyBackend;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;

call_user_func(function () {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_fastly_dummy'] = [
        'frontend' => NullFrontend::class,
        'backend' => FastlyBackend::class,
        'groups' => ['pages', 'all'],
    ];
});
