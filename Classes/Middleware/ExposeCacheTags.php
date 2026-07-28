<?php

declare(strict_types=1);

namespace Fastly\Cdn\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheTag;

final readonly class ExposeCacheTags implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $cacheDataCollector = $request->getAttribute('frontend.cache.collector');
        $cacheTags = array_map(
            fn (CacheTag $cacheTag): string => $cacheTag->name,
            $cacheDataCollector->getCacheTags(),
        );
        return $response->withHeader('Surrogate-Key', strtolower(implode(' ', $cacheTags) ?? ''));
    }
}
