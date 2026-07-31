<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Service;

use Fastly\Cdn\Service\SurrogateKeyHasher;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SurrogateKeyHasherTest extends UnitTestCase
{
    public function testHashIsDeterministicForTheSameTag(): void
    {
        $hasher = new SurrogateKeyHasher();

        $this->assertSame($hasher->hash('pages_1'), $hasher->hash('pages_1'));
    }

    public function testHashDiffersForDifferentTags(): void
    {
        $hasher = new SurrogateKeyHasher();

        $this->assertNotSame($hasher->hash('pages_1'), $hasher->hash('pages_2'));
    }

    public function testHashIsShortRegardlessOfInputLength(): void
    {
        $hasher = new SurrogateKeyHasher();

        $this->assertSame(8, strlen($hasher->hash('a_very_long_cache_tag_name_that_would_otherwise_bloat_the_header')));
    }

    public function testHashIsConsistentAcrossSeparateInstances(): void
    {
        // Middleware and FastlyClient each get their own injected instance;
        // the hash must not depend on any per-instance state.
        $this->assertSame((new SurrogateKeyHasher())->hash('tt_content_42'), (new SurrogateKeyHasher())->hash('tt_content_42'));
    }
}
