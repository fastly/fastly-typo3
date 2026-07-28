<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Resolves the set of custom VCL files to provision, applying Fluid-style
 * overriding across an ordered list of root paths.
 *
 * The extension's own {@see EXT:fastly/Resources/Private/VCL/} is the lowest
 * priority. Each path in the "vclRootPaths" extension configuration is layered on
 * top in order (later entries win), so a site package can replace any shipped
 * file or add new ones, exactly like Fluid template root paths.
 */
final readonly class VclFileResolver implements SingletonInterface
{
    /**
     * Name (without extension) of the file that must be flagged as the Fastly
     * "main" VCL. Every other file is pulled in via `include` from it.
     */
    public const string MAIN_NAME = 'main';

    /**
     * @var string[] Absolute root directories, lowest priority first.
     */
    private array $roots;

    public function __construct(
        #[Autowire(expression: 'service("extension-configuration").get("fastly", "vclRootPaths")')]
        string $vclRootPaths = '',
        ?string $defaultRoot = null,
    ) {
        $default = $defaultRoot ?? dirname(__DIR__, 2) . '/Resources/Private/VCL';
        $this->roots = array_merge([$default], $this->resolveConfiguredRoots($vclRootPaths));
    }

    /**
     * @return array<string, string> VCL name => content, sorted by name.
     */
    public function resolveFiles(): array
    {
        $files = [];
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*.vcl') ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }

                $files[basename($path, '.vcl')] = (string)file_get_contents($path);
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return string[] Absolute directories for the configured (override) roots.
     */
    private function resolveConfiguredRoots(string $vclRootPaths): array
    {
        $roots = [];
        foreach (preg_split('/[,\n]/', $vclRootPaths) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $absolute = $this->toAbsolute($entry);
            if ($absolute !== '' && is_dir($absolute)) {
                $roots[] = rtrim($absolute, '/');
            }
        }

        return $roots;
    }

    private function toAbsolute(string $entry): string
    {
        $absolute = GeneralUtility::getFileAbsFileName($entry);
        // getFileAbsFileName rejects paths outside the project root by returning
        // ''; still honour an explicit absolute path an administrator configured.
        if ($absolute === '' && PathUtility::isAbsolutePath($entry)) {
            return $entry;
        }

        return $absolute;
    }
}
