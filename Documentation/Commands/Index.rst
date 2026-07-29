..  _commands:

============
CLI commands
============

All commands are Symfony Console commands, run via the TYPO3 console binary:

..  code-block:: bash

    vendor/bin/typo3 <command> [options]

None of them are schedulable via the TYPO3 Scheduler - they are meant to be
run interactively or from deployment scripts, not as a recurring cron task.

..  _commands-service:

Service provisioning
======================

These three commands manage the Fastly *service* itself (domains and
product features), as opposed to VCL content. They read TYPO3 site
configuration for absolute site base URLs and use those as the service's
domains.

..  _commands-service-add:

``fastly:service:add``
------------------------

Create a new Fastly service from the TYPO3 site domains.

..  code-block:: bash

    vendor/bin/typo3 fastly:service:add [--name=NAME] [--comment=COMMENT] [--all-features] [--http3] [--bot-management] [--waf] [--ddos-protection] [--dry-run] [--no-activate]

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Option
        -   Description
    *   -   ``--name``
        -   Fastly service name (default: ``TYPO3 Fastly Service``).
    *   -   ``--comment``
        -   Fastly service comment.
    *   -   ``--all-features``
        -   Enable all feature toggles below at once.
    *   -   ``--http3``
        -   Enable HTTP/3.
    *   -   ``--bot-management``
        -   Enable Bot Management.
    *   -   ``--waf``
        -   Enable Next-Gen WAF.
    *   -   ``--ddos-protection``
        -   Enable DDoS Protection.
    *   -   ``--dry-run``
        -   Show planned changes without calling write APIs.
    *   -   ``--no-activate``
        -   Leave the created service version inactive.

On success, prints the new service ID and a reminder to set it as the
extension's ``serviceId`` configuration value.

..  _commands-service-update:

``fastly:service:update``
---------------------------

Reconcile an existing Fastly service's domains and features with the current
TYPO3 site configuration.

..  code-block:: bash

    vendor/bin/typo3 fastly:service:update [--service-id=ID] [--name=NAME] [--comment=COMMENT] [--all-features] [--http3] [--bot-management] [--waf] [--ddos-protection] [--dry-run] [--no-activate]

Same options as ``fastly:service:add``, plus ``--service-id`` (target
service; defaults to the extension ``serviceId``).

Adds missing domains; never removes domains no longer present in TYPO3.

..  _commands-service-check:

``fastly:service:check``
--------------------------

Read-only. Reports whether the Fastly service's domains match TYPO3's site
configuration and which optional features are active.

..  code-block:: bash

    vendor/bin/typo3 fastly:service:check [--service-id=ID]

*   ``--service-id`` - target service; defaults to the extension
    ``serviceId``.

Exits non-zero if any TYPO3 site domain is missing from the Fastly service -
useful as a CI health check.

..  _commands-vcl:

VCL provisioning
==================

These two commands manage the extension's custom VCL content on the service.
See :ref:`vcl-customization` for the file layout and the Fluid-style override
mechanism (``vclRootPaths``).

..  _commands-vcl-provision:

``fastly:vcl:provision``
--------------------------

Uploads the resolved local VCL to the service and activates it.

..  code-block:: bash

    vendor/bin/typo3 fastly:vcl:provision [--service-id=ID] [--dry-run] [--no-activate]

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Option
        -   Description
    *   -   ``--service-id``
        -   Target service; defaults to the extension ``serviceId``.
    *   -   ``--dry-run``
        -   Show planned changes without calling any write API.
    *   -   ``--no-activate``
        -   Upload to a draft version but leave it inactive.

Idempotent: diffs local files against the version it would write to (a
reusable draft the extension previously created, otherwise the active
version) and only clones/uploads/activates when something actually differs.
Running it twice on an in-sync service performs no writes and clones no new
version. Files present on the service but not shipped locally are left alone
(upsert only).

..  important::

    Activating a new version does **not** purge the Fastly cache - cached
    objects stay until their TTL expires or they are purged explicitly. See
    :ref:`surrogate-keys-flushing`.

..  _commands-vcl-diff:

``fastly:vcl:diff``
----------------------

Read-only. Shows how the resolved local VCL differs from the service.

..  code-block:: bash

    vendor/bin/typo3 fastly:vcl:diff [--service-id=ID]

*   ``--service-id`` - target service; defaults to the extension
    ``serviceId``.

Each file is reported as ``create`` (not on the service), ``update``, or
``unchanged``. Makes no write calls.
