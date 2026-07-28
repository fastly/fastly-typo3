<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Guards the shipped custom VCL against the Fastly concatenation model.
 *
 * Feature files define the built-in subroutines (vcl_recv, vcl_fetch, ...)
 * directly; Fastly concatenates same-named built-in subroutines in the order the
 * compiler encounters them. main.vcl therefore only lists includes (at the top,
 * so feature fragments are reachable before its own terminating returns) plus the
 * #FASTLY boilerplate — it must not orchestrate features with `call`.
 *
 * See https://www.fastly.com/documentation/reference/vcl/subroutines
 */
final class VclResourcesTest extends UnitTestCase
{
    private const EXPECTED_FEATURES = [
        'image_optimizer',
        'caching',
        'surrogate_keys',
        'grace',
        'esi',
    ];

    /**
     * The Fastly state subroutines a feature file is allowed to define. Custom
     * subroutines are forbidden: Fastly does not concatenate them, so they would
     * only run if main.vcl called them — the model we are moving away from.
     */
    private const BUILTIN_SUBROUTINES = [
        'vcl_recv',
        'vcl_hash',
        'vcl_hit',
        'vcl_miss',
        'vcl_pass',
        'vcl_fetch',
        'vcl_error',
        'vcl_deliver',
        'vcl_log',
    ];

    private function vclDir(): string
    {
        return dirname(__DIR__, 2) . '/Resources/Private/VCL';
    }

    private function main(): string
    {
        return (string)file_get_contents($this->vclDir() . '/main.vcl');
    }

    /**
     * @return string[]
     */
    private function subsIn(string $content): array
    {
        preg_match_all('/\bsub\s+([a-zA-Z0-9_]+)\s*\{/', $content, $matches);
        return $matches[1];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function featureFiles(): array
    {
        return array_combine(
            self::EXPECTED_FEATURES,
            array_map(static fn (string $name): array => [$name], self::EXPECTED_FEATURES),
        );
    }

    #[DataProvider('featureFiles')]
    public function testFeatureFileExists(string $name): void
    {
        self::assertFileExists($this->vclDir() . '/' . $name . '.vcl');
    }

    #[DataProvider('featureFiles')]
    public function testMainIncludesFeatureFile(string $name): void
    {
        self::assertMatchesRegularExpression(
            '/include\s+"' . preg_quote($name, '/') . '"\s*;/',
            $this->main(),
            sprintf('main.vcl must include "%s"', $name),
        );
    }

    #[DataProvider('featureFiles')]
    public function testFeatureFileDefinesOnlyBuiltinSubroutines(string $name): void
    {
        $content = (string)file_get_contents($this->vclDir() . '/' . $name . '.vcl');
        $subs = $this->subsIn($content);

        self::assertNotEmpty($subs, sprintf('%s.vcl must define at least one built-in subroutine', $name));
        foreach ($subs as $sub) {
            self::assertContains(
                $sub,
                self::BUILTIN_SUBROUTINES,
                sprintf('%s.vcl defines "%s"; feature files may only define built-in subroutines so Fastly concatenates them', $name, $sub),
            );
        }
    }

    /**
     * main.vcl must not orchestrate features with `call`; features are wired purely
     * by include + built-in subroutine concatenation.
     */
    public function testMainContainsNoCallStatements(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\bcall\s+[a-zA-Z0-9_]+\s*;/',
            $this->main(),
            'main.vcl must not use `call`; feature subroutines are concatenated, not called',
        );
    }

    /**
     * The include block must precede main.vcl's own subroutine definitions: those
     * carry the terminating returns, and anything a feature defines after a
     * terminating return of the same built-in subroutine is unreachable.
     */
    public function testIncludesPrecedeMainSubroutineDefinitions(): void
    {
        $main = $this->main();
        $lastInclude = 0;
        if (preg_match_all('/^\s*include\s+"[^"]+"\s*;/m', $main, $m, PREG_OFFSET_CAPTURE)) {
            $lastInclude = $m[0][array_key_last($m[0])][1];
        }
        self::assertGreaterThan(0, $lastInclude, 'main.vcl must contain include statements');

        self::assertSame(1, preg_match('/^\s*sub\s+vcl_[a-z]+\s*\{/m', $main, $s, PREG_OFFSET_CAPTURE));
        $firstSub = $s[0][1];
        self::assertLessThan($firstSub, $lastInclude, 'includes must appear before main.vcl subroutine definitions');
    }

    /**
     * Every non-main .vcl file that ships must be wired into main.vcl.
     */
    public function testEveryShippedFileIsIncludedInMain(): void
    {
        $files = glob($this->vclDir() . '/*.vcl') ?: [];
        foreach ($files as $file) {
            $name = basename($file, '.vcl');
            if ($name === 'main') {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/include\s+"' . preg_quote($name, '/') . '"\s*;/',
                $this->main(),
                sprintf('main.vcl must include shipped file "%s"', $name),
            );
        }
    }
}
