<?php

declare(strict_types=1);

namespace Fastly\Cdn\Middleware;

use Fastly\Cdn\Service\SurrogateKeyHasher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheTag;

final readonly class ExposeCacheTags implements MiddlewareInterface
{
    /**
     * Fastly's response header budget is ~16 KB; this leaves headroom for
     * other response headers (Set-Cookie, Cache-Control, ...) on the same
     * response.
     */
    private const int MAX_HEADER_LENGTH = 12_000;

    public function __construct(
        private SurrogateKeyHasher $hasher,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $cacheDataCollector = $request->getAttribute('frontend.cache.collector');
        $cacheTags = array_map(
            fn (CacheTag $cacheTag): string => $cacheTag->name,
            $cacheDataCollector->getCacheTags(),
        );

        $joined = strtolower(implode(' ', $cacheTags));
        if (strlen($joined) > self::MAX_HEADER_LENGTH) {
            $joined = strtolower(implode(' ', array_map(
                $this->hasher->hash(...),
                $cacheTags,
            )));
        }

        return $response->withHeader('Surrogate-Key', $joined);
    }
}
