<?php

declare(strict_types=1);

namespace Fastly\Cdn\Api;

use Fastly\Cdn\Api\Middleware\RetryMiddleware;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FastlyClientFactory
{
    public static function getClient(): ClientInterface
    {
        $defaultConfig = $GLOBALS['TYPO3_CONF_VARS']['HTTP'];
        $config = array_merge($GLOBALS['TYPO3_CONF_VARS']['HTTP'], [
            'base_uri' => 'https://api.fastly.com',
            'timeout' => 8,
            'connect_timeout' => 5,
            'headers' => [
                'User-Agent' => 'TYPO3 Api/1.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'verify' =>
                filter_var($defaultConfig['verify'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ??
                $defaultConfig['verify'],
        ]);

        if (
            isset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']) &&
            is_array($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'])
        ) {
            $stack = HandlerStack::create();
            foreach ($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] ?? [] as $handler) {
                $stack->push($handler);
            }

            $stack->push(RetryMiddleware::retry($config));
            $config['handler'] = $stack;
        }

        return GeneralUtility::makeInstance(HttpClient::class, $config);
    }
}
