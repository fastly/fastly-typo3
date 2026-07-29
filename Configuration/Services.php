<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Fastly\Cdn\Api\FastlyClient;
use Fastly\Cdn\Api\FastlyClientFactory;
use Fastly\Cdn\Api\FastlyClientInterface;
use GuzzleHttp\Client;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services()->defaults()->autowire()->autoconfigure();
    $services->load('Fastly\\Cdn\\', '../Classes/')
        ->exclude([
            '../Classes/Command/AbstractFastlyServiceCommand.php',
            '../Classes/Cache/Backend/FastlyBackend.php',
            '../Classes/Cache/Backend/V13/FastlyBackend.php',
        ]);
    $services->alias(FastlyClientInterface::class, FastlyClient::class);
    $services
        ->set('fastly_cdn_fastlyclient', Client::class)
        ->factory([FastlyClientFactory::class, 'getClient']);
};
