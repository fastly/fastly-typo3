<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use Fastly\Cdn\Service\VclFileResolver;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class VclFileResolverTest extends UnitTestCase
{
    /** @var string[] */
    private array $dirs = [];

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir() . '/vclres_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;
        return $dir;
    }

    private function writeVcl(string $dir, string $name, string $content): void
    {
        file_put_contents($dir . '/' . $name, $content);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testReturnsAllVclFilesFromDefaultRoot(): void
    {
        $default = $this->makeDir();
        $this->writeVcl($default, 'main.vcl', 'MAIN');
        $this->writeVcl($default, 'caching.vcl', 'CACHE');

        $files = (new VclFileResolver('', $default))->resolveFiles();

        self::assertSame(['caching' => 'CACHE', 'main' => 'MAIN'], $files);
    }

    public function testOverrideRootReplacesFileContent(): void
    {
        $default = $this->makeDir();
        $this->writeVcl($default, 'main.vcl', 'MAIN');
        $this->writeVcl($default, 'caching.vcl', 'DEFAULT');
        $override = $this->makeDir();
        $this->writeVcl($override, 'caching.vcl', 'OVERRIDDEN');

        $files = (new VclFileResolver($override, $default))->resolveFiles();

        self::assertSame('OVERRIDDEN', $files['caching']);
        self::assertSame('MAIN', $files['main'], 'unoverridden files keep the default content');
    }

    public function testOverrideRootCanAddNewFile(): void
    {
        $default = $this->makeDir();
        $this->writeVcl($default, 'main.vcl', 'MAIN');
        $override = $this->makeDir();
        $this->writeVcl($override, 'custom.vcl', 'CUSTOM');

        $files = (new VclFileResolver($override, $default))->resolveFiles();

        self::assertSame('CUSTOM', $files['custom']);
        self::assertArrayHasKey('main', $files);
    }

    public function testLaterConfiguredRootWinsOverEarlierAndDefault(): void
    {
        $default = $this->makeDir();
        $this->writeVcl($default, 'caching.vcl', 'DEFAULT');
        $low = $this->makeDir();
        $this->writeVcl($low, 'caching.vcl', 'LOW');
        $high = $this->makeDir();
        $this->writeVcl($high, 'caching.vcl', 'HIGH');

        $files = (new VclFileResolver($low . ',' . $high, $default))->resolveFiles();

        self::assertSame('HIGH', $files['caching']);
    }

    public function testIgnoresNonVclFilesAndBlankConfigEntries(): void
    {
        $default = $this->makeDir();
        $this->writeVcl($default, 'main.vcl', 'MAIN');
        $this->writeVcl($default, 'readme.txt', 'nope');

        $files = (new VclFileResolver(' , ,' . "\n", $default))->resolveFiles();

        self::assertSame(['main' => 'MAIN'], $files);
    }

    public function testNonExistentConfiguredRootIsSkipped(): void
    {
        $default = $this->makeDir();
        $this->writeVcl($default, 'main.vcl', 'MAIN');

        $files = (new VclFileResolver('/does/not/exist/anywhere', $default))->resolveFiles();

        self::assertSame(['main' => 'MAIN'], $files);
    }
}
