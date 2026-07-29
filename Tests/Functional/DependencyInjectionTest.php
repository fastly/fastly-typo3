<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Functional;

use Fastly\Cdn\Api\FastlyClient;
use Fastly\Cdn\Processor\ImageOptimizerProcessor;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Smoke test for the extension's DI wiring: the services this extension
 * exposes must be resolvable from a booted TYPO3 container with the
 * extension configuration autowired into their constructors.
 */
final class DependencyInjectionTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['fastly'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'fastly' => [
                'serviceId' => 'SERVICE_ID_PLACEHOLDER',
                'apiToken' => 'API_TOKEN_PLACEHOLDER',
                'assetUrl' => '_images/',
                // Boolean toggles must be real booleans: the autowire expressions
                // bind them to strictly typed bool constructor parameters.
                'enableImageOptimizer' => true,
                'enableCdn' => false,
                'quality' => '85,75',
                'allowedExtensions' => 'jpg,jpeg,webp,avif,png,tiff',
                'ignoreAssets' => false,
                'vclRootPaths' => '',
            ],
        ],
    ];

    public function testFastlyClientIsWiredWithExtensionConfiguration(): void
    {
        $client = $this->get(FastlyClient::class);

        $this->assertInstanceOf(FastlyClient::class, $client);
        $this->assertSame('SERVICE_ID_PLACEHOLDER', $client->getConfiguredServiceId());
    }

    public function testImageOptimizerProcessorIsPubliclyAvailable(): void
    {
        $this->assertInstanceOf(ImageOptimizerProcessor::class, $this->get(ImageOptimizerProcessor::class));
    }

    public function testAllConsoleCommandsAreRegistered(): void
    {
        $registry = $this->get(CommandRegistry::class);

        foreach ([
            'fastly:service:add',
            'fastly:service:update',
            'fastly:service:check',
            'fastly:vcl:provision',
            'fastly:vcl:diff',
        ] as $commandName) {
            $this->assertTrue($registry->has($commandName), sprintf('command "%s" must be registered', $commandName));
        }
    }
}
