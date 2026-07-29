..  _start:

======
Fastly
======

:Extension key:
    fastly

:Package name:
    fastly/typo3

:Language:
    en

:License:
    This document is published under the
    `MIT <https://opensource.org/licenses/MIT>`__ license.

----

Fastly's edge CDN and `Fastly Image Optimization (IO)
<https://www.fastly.com/documentation/reference/io/>`__ integration for
TYPO3 CMS.

`Fastly <https://www.fastly.com/>`__ is a global edge cloud platform: a
network of caching servers (a CDN) close to your visitors that serves your
pages and images so most requests never reach your TYPO3 server. This
extension connects TYPO3 to Fastly:

*   It generates image URLs that Fastly Image Optimization transforms on the
    edge - the extension never optimizes images itself.
*   It exposes TYPO3's cache tags as a ``Surrogate-Key`` header, so Fastly can
    purge exactly the pages that changed when an editor saves content.
*   It provisions and maintains the Fastly service itself via CLI commands -
    the Fastly control panel is never required for day-to-day operation.

A single global Fastly service backs the whole TYPO3 installation; there is
no per-site configuration.

----

**Table of contents**

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    GettingStarted/Index
    Configuration/Index
    ImageOptimization/Index
    SurrogateKeys/Index
    Esi/Index
    VclCustomization/Index
    Commands/Index
