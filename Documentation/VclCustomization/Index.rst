..  _vcl-customization:

===================
VCL customization
===================

The extension ships the VCL (`Fastly's edge configuration language
<https://www.fastly.com/documentation/guides/vcl/>`__) that the integration
relies on as **custom VCL**, and can upload it to your service with
:ref:`fastly:vcl:provision <commands-vcl-provision>`.

..  _vcl-customization-layout:

File layout
============

All shipped ``*.vcl`` files live in :file:`Resources/Private/VCL/`. Today
that is a single file, :file:`main.vcl`, which is the Fastly *main* VCL
file - the one whose subroutines actually run. It inlines the behaviors the
integration needs directly in the standard ``vcl_*`` state subroutines, next
to Fastly's own ``#FASTLY ...`` boilerplate markers:

..  list-table::
    :header-rows: 1
    :widths: 20 80

    *   -   Subroutine
        -   Responsibility (see the file itself for the exact logic)
    *   -   ``vcl_recv``
        -   Force HTTPS, DDoS Protection check, client IP/ASN/JA4 capture,
            strip cookies and disable NGWAF inspection for static/asset
            paths, detect the image-optimizer path prefix (see
            :ref:`image-optimization-url`) and mark it for Fastly IO, strip
            tracking-only query parameters and normalize the remaining ones,
            build ``X-Forwarded-For``, force a pass for admin/backend/
            authenticated requests.
    *   -   ``vcl_hash``
        -   Cache key: request URL plus ``Host``.
    *   -   ``vcl_hit`` / ``vcl_miss`` / ``vcl_pass``
        -   Standard delivery/fetch/pass, with the documented
            ``X-Forwarded-Host`` shielding workaround.
    *   -   ``vcl_fetch``
        -   5xx stale-object/retry handling, image response cache
            normalization and default TTL, pass-through rules (cookies,
            private/no-store), default TTL fallback, ESI enablement (see
            :ref:`esi-enabling`), Brotli/gzip compression for text
            responses, stale-while-revalidate/grace/stale-if-error.
    *   -   ``vcl_error``
        -   Synthetic responses for the forced-HTTPS redirect and DDoS
            mitigation, stale delivery on 5xx.
    *   -   ``vcl_deliver``
        -   Strip identifying response headers, enable the compression
            hint, set HSTS over TLS.
    *   -   ``vcl_log``
        -   Fastly boilerplate only.

..  attention::

    The image-optimizer path prefix matched in ``vcl_recv`` and the
    ``assetUrl`` extension configuration agree on the same string by
    contract - see :ref:`image-optimization-url`. Changing one without the
    other breaks image delivery.

..  _vcl-customization-override:

Overriding VCL (Fluid-style)
==============================

A site package can override any shipped file, or add new ones, the same way
Fluid template root paths work. Set the ``vclRootPaths`` extension
configuration key to a comma-separated, ordered list of directories:

..  code-block:: text

    vclRootPaths = EXT:sitepackage/Resources/Private/FastlyVCL/

Resolution is per filename:

#.  :file:`EXT:fastly/Resources/Private/VCL/` (the extension default) is
    always the lowest priority.
#.  Each configured path is layered on top, **in order** - later entries
    win.

So :file:`EXT:sitepackage/Resources/Private/FastlyVCL/main.vcl` replaces the
shipped :file:`main.vcl` entirely, and a file that only exists in an override
path is added to the uploaded set under its own name.

..  important::

    Exactly one file named ``main`` must exist in the resolved set - it is
    flagged as the Fastly *main* VCL file, the only one whose subroutines
    run. Provisioning fails with a clear error if none is found. Any
    additional ``*.vcl`` file you add is uploaded to the service but is
    otherwise inert: Fastly does not automatically run non-main custom VCL,
    so ``main.vcl`` must ``include`` it for it to take effect.

..  seealso::

    :ref:`configuration-reference` for the ``vclRootPaths`` key, and
    :ref:`commands` for ``fastly:vcl:provision`` and ``fastly:vcl:diff``.

..  _vcl-customization-note:

Notes
======

*   Uploading a *main* custom VCL switches the Fastly service into
    custom-VCL mode. This is a deliberate action performed only by
    :ref:`fastly:vcl:provision <commands-vcl-provision>`.
*   Provisioning is idempotent and upsert-only: files present on the service
    but not shipped locally (and not added via an override path) are left
    untouched.
