..  _getting-started:

===============
Getting started
===============

Everything below runs through the TYPO3 CLI (``vendor/bin/typo3``). You
can inspect the results in the `Fastly control panel
<https://manage.fastly.com/>`__ at any time, but no step requires it beyond
creating the account, setting up the domain and creating the API token.

..  _getting-started-quick:

Quick start
============

For readers who already know Fastly - every step is explained in detail
below.

#.  `Sign up for Fastly
    <https://www.fastly.com/signup/?utm_source=github&utm_medium=readme&utm_campaign=typo3-extension>`__ and `create an API token
    <https://www.fastly.com/documentation/guides/account-info/user-and-account-management/using-api-tokens/>`__
    with ``global`` scope.
#.  Install the extension:

    ..  code-block:: bash

        composer require fastly/typo3

#.  Provide the token as an environment variable and wire it up in
    :file:`config/system/additional.php` - see :ref:`configuration-secrets`.
#.  Create the Fastly service from your TYPO3 site configuration:

    ..  code-block:: bash

        vendor/bin/typo3 fastly:service:add

    Set the printed service ID as ``FASTLY_SERVICE_ID``.
#.  `Set up your domain
    <https://www.fastly.com/documentation/guides/getting-started/domains/working-with-domains/working-with-domains/>`__,
    DNS and TLS at Fastly.
#.  Connect the domain with your TYPO3 site.
#.  Upload and activate the VCL:

    ..  code-block:: bash

        vendor/bin/typo3 fastly:vcl:provision

Done - your site is now served through Fastly.

..  _getting-started-step-by-step:

Step-by-step guide
====================

..  _getting-started-account:

1. Create a Fastly account
---------------------------

`Sign up for Fastly <https://www.fastly.com/signup/?utm_source=github&utm_medium=readme&utm_campaign=typo3-extension>`__. The free developer
trial is enough to set everything up and test it.

..  _getting-started-token:

2. Create an API token
------------------------

The extension talks to the `Fastly API
<https://www.fastly.com/documentation/reference/api/>`__ on your behalf - to
create the service, upload VCL and purge the cache. For that it needs an API
token:

#.  In the Fastly control panel, go to **Account > API tokens > Personal
    tokens**.
#.  Click **Create token**.
#.  Give it the **global** scope. The provisioning commands
    (``fastly:service:*``, ``fastly:vcl:*``) modify the service
    configuration, which only the ``global`` scope allows.

If you want a tighter token for the running website (which only ever
purges), you can additionally create one with the ``purge_select`` and
``purge_all`` scopes and use the ``global`` token only where the
provisioning commands run. See `Using API tokens
<https://www.fastly.com/documentation/guides/account-info/account-management/using-api-tokens/>`__
for details on scopes.

..  attention::

    Treat the token like a password. Never commit it to your repository -
    :ref:`configuration-secrets` shows how to keep it out of your code.

..  _getting-started-install:

3. Install the extension
--------------------------

..  code-block:: bash

    composer require fastly/typo3

..  _getting-started-configure:

4. Configure the extension
----------------------------

All configuration lives in the extension configuration (*Admin Tools >
Settings > Extension Configuration > fastly*). There is no per-site override -
one Fastly service serves the whole installation. See :ref:`configuration`
for the full key reference and :ref:`configuration-secrets` for how to
provide ``apiToken`` and ``serviceId`` without committing them.

..  _getting-started-service:

5. Create the Fastly service
------------------------------

A Fastly *service* is the configuration container for your site: which
domains it answers for, where your TYPO3 server (the *origin*/*backend*)
lives, and which VCL logic runs on the edge.

You don't have to create it in the web interface - the extension reads the
domains from your TYPO3 site configuration and creates the service for you:

..  code-block:: bash

    vendor/bin/typo3 fastly:service:add

The command:

*   collects the base URLs of all TYPO3 sites and registers them as the
    service's domains,
*   configures your server as the backend,
*   prints the ID of the newly created service.

Set that ID as the ``FASTLY_SERVICE_ID`` environment variable (step 4) - all
other commands default to it.

Useful options:

*   ``--dry-run`` - show what would be created without writing anything.
*   ``--no-activate`` - create everything but leave the service version
    inactive.
*   ``--name`` / ``--comment`` - service name and comment.
*   ``--http3``, ``--bot-management``, ``--waf``, ``--ddos-protection``,
    ``--all-features`` - enable optional Fastly products (they may require a
    paid contract).

When you later add or change TYPO3 sites, reconcile the service with:

..  code-block:: bash

    vendor/bin/typo3 fastly:service:update

It adds missing domains but never removes any. To verify at any time that the
service and TYPO3 agree:

..  code-block:: bash

    vendor/bin/typo3 fastly:service:check

This is read-only and exits non-zero if a TYPO3 site domain is missing from
the service - handy in CI. See :ref:`commands` for the full option reference
of all three commands.

..  _getting-started-domain:

6. Point your domain at Fastly
---------------------------------

So far Fastly knows about your domain, but visitors still go straight to your
server. To route traffic through Fastly, change your DNS:

#.  **Set up TLS for the domain** in the Fastly control panel under
    **Domains > TLS Management** (Fastly can provision and renew
    certificates for you). This gives you the exact CNAME target for your
    domain, e.g. ``<letter>.sni.global.fastly.net``.
#.  **Create the CNAME record** at your DNS provider, pointing your domain
    (e.g. ``www.example.com``) at that target.
#.  **Apex domains** (``example.com`` without ``www``) can't use CNAME
    records - use Fastly's anycast A/AAAA addresses instead; see `working
    with domains
    <https://www.fastly.com/documentation/guides/getting-started/domains/working-with-domains/>`__.

..  _getting-started-vcl:

7. Upload the VCL
--------------------

The extension ships the `VCL
<https://www.fastly.com/documentation/guides/vcl/>`__ (Fastly's edge
configuration language) that the integration relies on: Image Optimization
routing, ``Surrogate-Key`` handling, ESI processing, grace mode and
stale-while-revalidate behavior.

First see what would change (read-only):

..  code-block:: bash

    vendor/bin/typo3 fastly:vcl:diff

Then upload and activate:

..  code-block:: bash

    vendor/bin/typo3 fastly:vcl:provision

The command is idempotent: it diffs the local VCL against the service and
only creates a new service version when something actually differs. Running
it twice in a row does nothing the second time. Use ``--dry-run`` to preview
and ``--no-activate`` to upload to a draft version without activating it.

Site packages can override or extend the shipped VCL via the
``vclRootPaths`` configuration - see :ref:`vcl-customization`.

That's it. Your site is served through Fastly, images are optimized on the
edge, and content changes purge exactly the affected pages via Surrogate
Keys.

..  _getting-started-deployment:

Updating the VCL on deployment
================================

Run ``fastly:vcl:provision`` as part of every deployment:

..  code-block:: bash

    vendor/bin/typo3 fastly:vcl:provision

Because the command is idempotent, this is safe to run on every deploy - if
the VCL is unchanged, nothing happens; if you upgraded the extension or
changed your VCL overrides, the new version is uploaded and activated.

..  important::

    Activating a new VCL version does **not** clear the Fastly cache. Cached
    objects stay in the cache until their TTL expires or they are purged
    explicitly. If a VCL change alters how responses are cached and you need
    it to take effect immediately for already-cached content, purge
    deliberately - see :ref:`surrogate-keys-flushing` before reaching for
    "flush everything".
