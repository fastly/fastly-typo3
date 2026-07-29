..  _image-optimization:

===================
Image Optimization
===================

A custom FAL processor (``Fastly\Cdn\Processor\ImageOptimizerProcessor``)
rewrites processed-image URLs so they are served through `Fastly Image
Optimization (IO) <https://www.fastly.com/documentation/reference/io/>`__.
The processor only builds a URL and query parameters - the actual image
transformation happens on the Fastly edge. Nothing is rendered locally with
GraphicsMagick/ImageMagick for images it handles.

..  _image-optimization-scope:

When it applies
=================

The processor takes over a FAL processing task when **all** of the following
are true:

*   ``enableImageOptimizer`` is enabled (default: yes) - see
    :ref:`configuration-reference`.
*   The task is a ``Preview`` or ``CropScaleMask`` task (the two FAL task
    types used for rendered/cropped image variants).
*   The source file's storage is public.
*   The source file's extension is in ``allowedExtensions`` (default:
    ``jpg,jpeg,webp,avif,png,tiff``).
*   The source file has known, non-zero width and height.
*   ``ignoreAssets`` is disabled, or the file's public URL does not start
    with ``/_assets/``.

If any condition fails, TYPO3 falls back to its normal local image
processing for that file.

..  _image-optimization-params:

Mapped parameters
===================

..  list-table::
    :header-rows: 1
    :widths: 25 25 50

    *   -   TYPO3 instruction
        -   Fastly IO parameter
        -   Notes
    *   -   Target width / height
        -   ``width`` / ``height``
        -   Taken from the resolved ``ImageDimension`` of the processing
            task.
    *   -   Quality
        -   ``quality``
        -   The extension's ``quality`` configuration if set, otherwise
            TYPO3's ``GFX/jpg_quality``.
    *   -   Cropping (crop variant or ``Area``)
        -   ``precrop`` + ``fit=crop``
        -   Pixel crop box (``width,height,xOFFSET,yOFFSET``) applied before
            resizing. Set whenever the processing configuration carries an
            explicit crop area - this is how TYPO3 cropping variants and
            FocusPoint selections reach Fastly IO.
    *   -   Forced-crop dimension (a ``width``/``height`` value ending in
            ``c``, TYPO3's "crop to fill" suffix)
        -   ``fit=crop`` (no ``precrop``)
        -   Center-crops to the target aspect ratio on the edge.
    *   -   Neither of the above
        -   ``fit=bounds``
        -   Resize to fit within the box without cropping.
    *   -   ``GFX/processor_allowUpscaling``
        -   ``enable=upscale``
        -   Only added when TYPO3's global upscaling setting is on.
    *   -   File content hash
        -   ``cb``
        -   The source file's SHA-1, so a changed file gets a fresh URL
            instead of serving a stale cached edge object.

..  note::

    The extension does not currently set an explicit ``format`` or ``auto``
    parameter - output format is whatever Fastly IO and your service
    configuration produce for the request. See the `IO parameter reference
    <https://www.fastly.com/documentation/reference/io/>`__ for options like
    automatic format negotiation if you need to add one via a
    :ref:`VCL override <vcl-customization>`.

..  _image-optimization-url:

How the URL is built
======================

The processor never touches the binary, so it must work for both local and
remote (e.g. S3) FAL storages, and it builds the URL differently depending on
which kind of public URL the storage returns:

Local/relative storage URLs
    When the source file's public URL is relative (e.g.
    ``/fileadmin/image.jpg``), the ``assetUrl`` configuration value (default
    ``_images/``) is prepended as a **path prefix**:
    ``_images/fileadmin/image.jpg``. The shipped VCL matches request paths
    under this prefix and marks them for Fastly Image Optimization - see
    :ref:`vcl-customization`. Do not change this prefix in ``assetUrl``
    without also updating the VCL, they agree on the string by contract.

Remote/absolute storage URLs
    When the source file's public URL is already absolute (e.g. a remote FAL
    storage serving directly from S3), it is used as-is. If ``assetUrl`` is
    itself configured as an absolute URL (e.g. ``https://cdn.example.com``),
    its host **replaces** the storage's host while the original path is kept -
    routing the request through your Fastly-fronted domain instead of
    directly at the storage. In this mode, the storage's path must itself be
    reachable through your Fastly service configuration; the ``_images``
    path-prefix VCL rule does not apply.

..  seealso::

    :ref:`configuration-reference` for the ``assetUrl``,
    ``enableImageOptimizer``, ``allowedExtensions``, ``quality`` and
    ``ignoreAssets`` keys.
