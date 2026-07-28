<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;
use TYPO3\CMS\Core\Utility\GeneralUtility;


$configuration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
$isIOEnabled = (bool)$configuration->get('fastly', 'enableImageOptimizer');
$assetUrl = new Uri($configuration->get('fastly', 'assetUrl'));

if (!$isIOEnabled || $assetUrl->getHost() === '') {
    return Map::fromEntries([]);
}

return Map::fromEntries([
    // Provide declarations for the backend only
    Scope::backend(),
    new MutationCollection(
        new Mutation(
            MutationMode::Extend,
            Directive::ImgSrc,
            UriValue::fromUri($assetUrl),
        ),
    ),
]);
