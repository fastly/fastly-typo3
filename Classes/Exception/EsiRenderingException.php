<?php

declare(strict_types=1);

namespace Fastly\Cdn\Exception;

use RuntimeException;

/**
 * Thrown when the ESI ViewHelper cannot resolve a valid `src`, e.g. an
 * unsupported backend context or a rendering context missing a request object.
 */
final class EsiRenderingException extends RuntimeException
{
}
