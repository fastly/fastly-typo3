..  _surrogate-keys:

=================================
Surrogate keys and cache purging
=================================

..  _surrogate-keys-middleware:

The Surrogate-Key middleware
==============================

A PSR-15 middleware (``Fastly\Cdn\Middleware\ExposeCacheTags``) reads the
cache tags that TYPO3's built-in CacheTag middleware has already collected
for the current request (via the ``frontend.cache.collector`` request
attribute) and emits them, space-separated and lowercased, as the response's
`Surrogate-Key
<https://www.fastly.com/documentation/reference/http/http-headers/Surrogate-Key/>`__
header.

The extension does not generate cache tags itself - it only exposes what
TYPO3 core already computes for pages, FAL files, sites and languages. This
is what lets Fastly purge by tag: when a page, file, site or language
changes, TYPO3 flushes the corresponding cache tags, and the mechanism below
translates that into a targeted Fastly purge.

..  _surrogate-keys-flushing:

Cache flushing and purging
============================

The extension registers a dummy cache (``tx_fastly_dummy``) in TYPO3's
``pages`` and ``all`` cache groups, backed by a ``FastlyBackend`` that
forwards every flush call to the Fastly API:

..  list-table::
    :header-rows: 1
    :widths: 40 60

    *   -   TYPO3 cache operation
        -   Fastly API call
    *   -   Flush by one cache tag
        -   Purge by that single Surrogate Key.
    *   -   Flush by several cache tags
        -   Purge by tags (batched) - or purge one by one below 10 tags.
    *   -   Flush all caches in a group this cache belongs to (see below)
        -   Purge everything on the service.

Because the dummy cache is in the ``pages`` and ``all`` groups, **both
"Flush all caches" and "Flush frontend caches" in the TYPO3 backend trigger a
full Fastly purge** - not just a local one.

..  warning::

    After a full purge every request is a cache miss and hits your TYPO3
    server directly. On a small site that's harmless; on a large or
    high-traffic site the full traffic load lands on your backend at once
    until the cache is warm again, which can take it down.

    *   Prefer letting tag-based purging do its job - day-to-day content
        edits only purge the affected pages and rarely need a full flush.
    *   If you do need a full flush, pick a low-traffic window and watch your
        backend load.
    *   Don't wire "flush all caches" into your deployment routine -
        deployments don't need it; see :ref:`getting-started-deployment`.

All purge calls are gated by the ``enableCdn`` configuration key - see
:ref:`configuration-reference`. Disabling it stops the extension from
calling the Fastly API for purges; it does not stop the ``Surrogate-Key``
header from being emitted.
