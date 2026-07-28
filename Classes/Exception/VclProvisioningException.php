<?php

declare(strict_types=1);

namespace Fastly\Cdn\Exception;

use RuntimeException;

/**
 * Thrown when the custom VCL cannot be provisioned, e.g. the resolved file set is
 * missing the required main file.
 */
final class VclProvisioningException extends RuntimeException
{
}
