<?php

declare(strict_types=1);

namespace Fastly\Cdn\Api\Middleware;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class RetryMiddleware
{
    public static function retry(array $config): callable
    {
        return Middleware::retry(
            function (
                $retries,
                RequestInterface $request,
                ?Response $response = null,
                ?TransferException $exception = null,
            ) use ($config): bool {
                $config = array_key_exists('max_attempt', $config) ? $config : ['max_attempt' => 3];

                // Limit the number of retries to 3
                if ($retries >= $config['max_attempt']) {
                    return false;
                }

                // Retry connection exceptions
                if ($exception instanceof ConnectException) {
                    return true;
                }

                // Retry on server errors
                return $response instanceof Response && $response->getStatusCode() >= 500;
            },
            function (
                $numberOfRetries,
                RequestInterface $request,
                ?Response $response = null,
            ) use ($config): int|float {
                if ($response instanceof Response && $response->hasHeader('Retry-After')) {
                    $retryAfter = $response->getHeaderLine('Retry-After');
                    if (is_numeric($retryAfter)) {
                        return ((float) $retryAfter) * 1000;
                    }

                    $retryAfterTimestamp = strtotime($retryAfter);
                    if ($retryAfterTimestamp !== false) {
                        return max(0, $retryAfterTimestamp - time()) * 1000;
                    }
                }

                $config = array_key_exists('sec_before_attempt', $config)
                    ? $config
                    : ['sec_before_attempt' => 0.5];

                return $config['sec_before_attempt'] * 1000 * $numberOfRetries;
            },
        );
    }
}
