..  _esi:

===================
Edge Side Includes
===================

``Fastly\Cdn\ViewHelpers\EsiViewHelper`` is a Fluid ViewHelper that renders an
``<esi:include src="..."/>`` tag for `Edge Side Includes (ESI)
<https://www.fastly.com/documentation/reference/vcl/esi/>`__ - letting one
page combine independently cached fragments (e.g. a fully cached page shell
around a per-user cart widget) that Fastly assembles at the edge.

..  note::

    This ViewHelper is part of the extension's public API - its argument
    names and rendered output are covered by the deprecation policy for
    backwards-incompatible changes.

..  _esi-usage:

Usage
======

TYPO3 auto-registers a Fluid ViewHelper namespace from the extension key, so
the ViewHelper is available as ``<fastly:esi>`` once the namespace is
declared on the template:

..  code-block:: html

    <html xmlns:fastly="http://typo3.org/ns/Fastly/Cdn/ViewHelpers"
          data-namespace-typo3-fluid="true">

        <fastly:esi pageUid="12" noCache="true"/>

    </html>

..  _esi-arguments:

Arguments
==========

..  list-table::
    :header-rows: 1
    :widths: 30 15 55

    *   -   Argument
        -   Type
        -   Description
    *   -   ``src``
        -   string
        -   Explicit ESI fragment URL. When set, none of the linking
            arguments below are evaluated - it is used verbatim as the
            ``src`` attribute.
    *   -   ``pageUid``
        -   int
        -   Target page UID to link to, when ``src`` is not given.
    *   -   ``additionalParams``
        -   array
        -   Query parameters attached to the resulting URI.
    *   -   ``pageType``
        -   int
        -   Target page type. See TypoLink's ``parameter`` type page
            argument.
    *   -   ``noCache``
        -   bool
        -   Disables caching for the target page. You should not normally
            need this.
    *   -   ``language``
        -   string
        -   Link to a specific language - defaults to the current language;
            use a language ID or ``"current"`` to force one explicitly.
    *   -   ``section``
        -   string
        -   Anchor added to the URI.
    *   -   ``linkAccessRestrictedPages``
        -   bool
        -   Link to access-restricted pages even though they cannot be
            accessed.
    *   -   ``absolute``
        -   bool
        -   Render an absolute URI.
    *   -   ``addQueryString``
        -   string
        -   If set, current query parameters are kept in the URL. If set to
            ``"untrusted"``, *all* query parameters are kept - be aware this
            can affect cacheability of the generated link.
    *   -   ``argumentsToBeExcludedFromQueryString``
        -   array
        -   Arguments to remove from the URI. Only applied when
            ``addQueryString`` is set.

..  _esi-enabling:

Enabling ESI processing on the response
=========================================

Rendering ``<esi:include>`` tags is only half of ESI: Fastly only parses them
in a response that is itself marked as containing ESI. The shipped VCL
enables ESI processing when the **origin response** carries either:

*   a ``Surrogate-Control`` header containing ``content="ESI/1.0"``, or
*   an ``X-Esi`` header (any value).

Your page (or a Content-Type/page-type specific override) must set one of
these headers itself - the extension only renders the ``<esi:include>`` tags,
it does not add this header for you.

..  attention::

    In a TYPO3/Extbase (non-backend) context, rendering this ViewHelper
    without a valid frontend request in the rendering context throws a
    ``RuntimeException``. It is not supported in backend context.
