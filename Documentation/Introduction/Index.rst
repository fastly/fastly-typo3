..  _introduction:

============
Introduction
============

..  _what-it-does:

What does it do?
=================

The extension has three independent subsystems that share configuration but
stay decoupled in code:

Image Optimization
    A custom FAL processor rewrites image URLs so they are served through the
    `Fastly Image Optimization (IO) <https://www.fastly.com/documentation/reference/io/>`__
    endpoint. The processor only constructs URLs and appends IO query
    parameters - the actual transformation happens on the Fastly edge. See
    :ref:`image-optimization`.

Surrogate Key exposure
    A PSR-15 middleware reads the cache tags that TYPO3's built-in `CacheTag
    middleware
    <https://docs.typo3.org/permanent/typo3/reference-coreapi/main/en-us/ApiOverview/CachingFramework/Index.html>`__
    has already collected and emits them on the response as the
    ``Surrogate-Key`` header, so Fastly can purge by page, FAL file, site or
    language. See :ref:`surrogate-keys`.

VCL and service provisioning
    Symfony Console commands provision the custom VCL the extension relies on
    and manage the Fastly service configuration (domains, optional product
    features) directly from TYPO3's own site configuration. See
    :ref:`vcl-customization` and :ref:`commands`.

..  _requirements:

Requirements
============

TYPO3
    ``^13.4 || ^14.3``

PHP
    ``^8.3 || ^8.4 || ^8.5``

Fastly account
    A `Fastly <https://www.fastly.com/signup/?utm_source=github&utm_medium=readme&utm_campaign=typo3-extension>`__ account and service - the free
    developer trial is enough to set everything up. See
    :ref:`getting-started`.

..  _single-global-service:

A single global Fastly service
===============================

All configuration lives in the extension's **Extension Configuration** - there
is **no** per-site (YAML) override. One Fastly service backs the whole TYPO3
installation, whether it hosts one site or several; see :ref:`configuration`.
