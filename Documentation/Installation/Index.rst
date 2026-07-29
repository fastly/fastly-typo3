..  _installation:

============
Installation
============

Install the extension via Composer:

..  code-block:: bash

    composer require fastly/typo3

There is no database schema to update and nothing to activate in the
**Extensions** module - the extension wires itself up via
:file:`Configuration/Services.php` (dependency injection) and
:file:`Configuration/RequestMiddlewares.php`.

..  seealso::

    *   :ref:`getting-started` for the full path from a fresh Fastly account
        to a site served through Fastly.
    *   :ref:`configuration` for every extension configuration key.
