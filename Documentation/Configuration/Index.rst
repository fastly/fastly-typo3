..  _configuration:

=============
Configuration
=============

All configuration lives in the extension's **Extension Configuration**,
editable under *Admin Tools > Settings > Extension Configuration > fastly*.
There is **no** per-site (YAML) override - one global Fastly service backs the
whole TYPO3 installation.

..  _configuration-reference:

Configuration reference
========================

..  list-table::
    :header-rows: 1
    :widths: 20 10 20 50

    *   -   Key
        -   Type
        -   Default
        -   Description
    *   -   ``serviceId``
        -   string
        -   *(empty)*
        -   Alphanumeric string identifying your Fastly service.
    *   -   ``apiToken``
        -   string
        -   *(empty)*
        -   Fastly API token used for cache purge and provisioning
            operations. **Secret** - see :ref:`configuration-secrets`.
    *   -   ``assetUrl``
        -   string
        -   ``_images/``
        -   Path or host prepended to relative source file URLs. Also
            functions as the path prefix the shipped VCL matches to route a
            request to Fastly Image Optimization - see
            :ref:`image-optimization`.
    *   -   ``enableImageOptimizer``
        -   boolean
        -   ``true``
        -   Enables Fastly Image Optimization URL generation on FAL image
            processing.
    *   -   ``enableCdn``
        -   boolean
        -   ``true``
        -   Enables Fastly cache purging. When disabled, TYPO3 cache flushes
            and tag invalidations no longer call the Fastly API - the
            ``Surrogate-Key`` response header is still emitted regardless of
            this setting.
    *   -   ``quality``
        -   string
        -   ``85,75``
        -   Overrides ``GFX/jpg_quality`` for images routed through Fastly IO.
            See the `IO quality parameter
            <https://www.fastly.com/documentation/reference/io/quality/>`__.
    *   -   ``allowedExtensions``
        -   string
        -   ``jpg,jpeg,webp,avif,png,tiff``
        -   Comma-separated file extensions eligible for image optimization.
    *   -   ``ignoreAssets``
        -   boolean
        -   ``false``
        -   Excludes files under the TYPO3 ``_assets`` path from image
            optimization.
    *   -   ``vclRootPaths``
        -   string
        -   *(empty)*
        -   Comma-separated, ordered list of override paths for custom VCL
            files (Fluid-style - later entries win), e.g.
            :file:`EXT:sitepackage/Resources/Private/FastlyVCL/`. The
            extension's own VCL is always the lowest priority. See
            :ref:`vcl-customization`.

..  _configuration-secrets:

Secrets
========

..  warning::

    ``apiToken`` must never be logged, echoed in CLI output, written to
    fixtures, or committed to version control.

**Use environment variables, not the backend form.** Pasting the token into
the *Extension Configuration* form persists it in :file:`settings.php` and
possibly in your Git history. Instead, set the value as an environment
variable on the server and override it in
:file:`config/system/additional.php`, which takes precedence over whatever
the backend form stores:

..  code-block:: php

    <?php

    call_user_func(function () {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fastly']['apiToken'] = getenv('FASTLY_API_TOKEN');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fastly']['serviceId'] = getenv('FASTLY_SERVICE_ID');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fastly']['assetUrl'] = getenv('FASTLY_ASSET_URL');
    });

Then provide ``FASTLY_API_TOKEN``, ``FASTLY_SERVICE_ID`` and
``FASTLY_ASSET_URL`` through your hosting environment (an :file:`.env` file,
container environment, CI/CD secrets - whatever your deployment uses).
