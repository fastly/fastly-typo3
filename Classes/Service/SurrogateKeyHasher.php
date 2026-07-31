<?php

declare(strict_types=1);

namespace Fastly\Cdn\Service;

final readonly class SurrogateKeyHasher
{
    private const int HASH_LENGTH = 8;

    public function hash(string $tag): string
    {
        return substr(sha1($tag), 0, self::HASH_LENGTH);
    }
}
