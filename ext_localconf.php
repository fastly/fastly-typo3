<?php

declare(strict_types=1);

use Fastly\Cdn\Cache\Backend\FastlyBackend;
use Fastly\Cdn\Processor\ImageOptimizerProcessor;
use Fastly\Cdn\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_fastly_dummy'] = [
    'frontend' => VariableFrontend::class,
    'backend' => FastlyBackend::class,
    'groups' => ['pages', 'all'],
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['processors']['FastlyProcessor'] = [
    'className' => ImageOptimizerProcessor::class,
    'before' => [
        // On top of all
        'SvgImageProcessor'
    ],
];

$configuration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
$isCdnEnabled = (bool)$configuration->get('fastly', 'enableCdn');
$isIOEnabled = (bool)$configuration->get('fastly', 'enableImageOptimizer');

if ($isIOEnabled) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Core\Resource\ProcessedFileRepository::class]['className'] = ProcessedFileRepository::class;
}

if ($isCdnEnabled) {
    ExtensionManagementUtility::addTypoScript(
        'fastly',
        'setup',
        "@import 'EXT:fastly/Configuration/TypoScript/setup.typoscript'"
    );
}
