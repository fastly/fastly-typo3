<?php

declare(strict_types=1);

use Fastly\Cdn\Cache\Backend\FastlyBackend as FastlyBackend;
use Fastly\Cdn\Cache\Backend\V13\FastlyBackend as FastlyBackendV13;
use Fastly\Cdn\Processor\ImageOptimizerProcessor;
use Fastly\Cdn\Resource\ProcessedFileRepository;
use Fastly\Cdn\Resource\V13\ProcessedFileRepository as ProcessedFileRepositoryV13;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$isTypo3V13 = (new Typo3Version())->getMajorVersion() < 14;

if ($isTypo3V13) {
    $fastlyBackend = FastlyBackendV13::class;
    $processedFileRepository = ProcessedFileRepositoryV13::class;
} else {
    $fastlyBackend = FastlyBackend::class;
    $processedFileRepository = ProcessedFileRepository::class;
}

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_fastly_dummy'] = [
    'frontend' => VariableFrontend::class,
    'backend' => $fastlyBackend,
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
$isCdnEnabled = (bool) $configuration->get('fastly', 'enableCdn');
$isIOEnabled = (bool) $configuration->get('fastly', 'enableImageOptimizer');

if ($isIOEnabled) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Core\Resource\ProcessedFileRepository::class]['className'] = $processedFileRepository;
}

if ($isCdnEnabled) {
    ExtensionManagementUtility::addTypoScript(
        'fastly',
        'setup',
        "@import 'EXT:fastly/Configuration/TypoScript/setup.typoscript'"
    );
}
