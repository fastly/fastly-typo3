<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Guards the shipped custom VCL against the Fastly concatenation model.
 *
 * main.vcl defines the built-in subroutines (vcl_recv, vcl_fetch, ...) directly.
 * Each one must carry its matching `#FASTLY <name>` macro so Fastly can splice in
 * its own boilerplate when the file is uploaded as custom VCL, and each built-in
 * subroutine must be defined exactly once — a duplicate definition would mean two
 * competing terminating returns.
 *
 * See https://www.fastly.com/documentation/reference/vcl/subroutines
 */
final class VclResourcesTest extends UnitTestCase
{
    private const array BUILTIN_SUBROUTINES = [
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
        preg_match_all('/\bsub\s+(\w+)\s*\{/', $content, $matches);
        return $matches[1];
    }

    public function testMainVclExists(): void
    {
        $this->assertFileExists($this->vclDir() . '/main.vcl');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function builtinSubroutines(): array
    {
        return array_combine(
            self::BUILTIN_SUBROUTINES,
            array_map(static fn (string $name): array => [$name], self::BUILTIN_SUBROUTINES),
        );
    }

    #[DataProvider('builtinSubroutines')]
    public function testMainDefinesEachBuiltinSubroutineExactlyOnce(string $name): void
    {
        $occurrences = preg_match_all('/\bsub\s+' . preg_quote($name, '/') . '\s*\{/', $this->main());
        $this->assertSame(1, $occurrences, sprintf('main.vcl must define "%s" exactly once', $name));
    }

    #[DataProvider('builtinSubroutines')]
    public function testMainSubroutineContainsMatchingFastlyMacro(string $name): void
    {
        $macro = strtoupper(substr($name, strlen('vcl_')));
        $this->assertMatchesRegularExpression(
            '/#FASTLY\s+' . preg_quote(strtolower($macro), '/') . '/',
            $this->main(),
            sprintf('main.vcl must contain the "#FASTLY %s" macro inside %s', strtolower($macro), $name),
        );
    }

    public function testMainDefinesOnlyBuiltinSubroutines(): void
    {
        $subs = $this->subsIn($this->main());
        foreach ($subs as $sub) {
            $this->assertContains($sub, self::BUILTIN_SUBROUTINES, sprintf('main.vcl defines "%s"; only built-in subroutines are allowed', $sub));
        }
    }

    /**
     * main.vcl must not orchestrate logic with `call`; any shared behaviour lives
     * directly inside the built-in subroutines.
     */
    public function testMainContainsNoCallStatements(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\bcall\s+\w+\s*;/', $this->main(), 'main.vcl must not use `call`');
    }
}
