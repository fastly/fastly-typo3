<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Fastly\Cdn\Api\FastlyClientFactory;
use GuzzleHttp\Client;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services()->defaults()->autowire()->autoconfigure();
    $services->load('Fastly\\Cdn\\', '../Classes/');
    $services
        ->set('fastly_cdn_fastlyclient', Client::class)
        ->factory([FastlyClientFactory::class, 'getClient']);
};
