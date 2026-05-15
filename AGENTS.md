# AGENTS.md

This file gives AI agents the context they need to work productively in this
repository. It is the source of truth for architecture, conventions, and
guardrails. Read it before making any non-trivial change.

## Project

The **Fastly CDN Module for TYPO3 CMS** integrates [Fastly Image Optimization
(IO)](https://www.fastly.com/documentation/reference/io/) and the Fastly CDN
service (Varnish-based) with TYPO3. The extension does **not** optimize images
itself — it generates URLs that Fastly IO consumes, and it exposes cache tags
so Fastly can purge by Surrogate Key.

- **Extension key:** `fastly`
- **Composer name:** `fastly/typo3`
- **PHP namespace:** `Fastly\Cdn\`
- **Supported TYPO3 versions:** `^13.4 || ^14.3`
- **Minimum PHP:** `^8.3` (TYPO3 v14 raises this to `^8.3`)
- **License:** GPL-2.0-or-later
- **Maintainer:** Sascha Nowak `<sascha.nowak@fastly.com>` (Fastly Inc.)

## Architecture overview

The extension has three independent subsystems that share configuration but
should remain decoupled in code:

### 1. Image Optimization (FAL processor)

A custom **FAL processor** rewrites image URLs so they are served through the
Fastly IO endpoint. The processor only constructs URLs and appends IO
parameters — the actual transformation happens on the Fastly edge.

Key rules:
- URLs are prefixed with `/_image/` (the Fastly service routes this path to IO).
- Storage agnostic: works with local and remote FAL storages (S3, etc.) because
  we never touch the binary — we only produce URLs.
- Map as many TYPO3 processing instructions as possible to IO query parameters
  (`width`, `height`, `format=auto`, `quality`, `dpr`, `fit`, `crop`, ...).
- **TYPO3 cropping variants and FocusPoints must be translated to IO
  parameters** (focal point → `fit=crop` plus crop coordinates / focus
  parameters).

Reference implementation to learn from (do **not** copy verbatim):
<https://github.com/christophlehmann/imgproxy/blob/main/Classes/Processor/ImgProxyProcessor.php>

The IO parameter reference is here:
<https://www.fastly.com/documentation/reference/io/>

### 2. Surrogate Key exposure (PSR-15 middleware)

A PSR-15 middleware reads the cache tags that TYPO3 v13's built-in
**CacheTag middleware** has already collected and emits them on the response
as the `Surrogate-Key` header. We do **not** generate cache tags ourselves —
TYPO3 already does that for pages, FAL files, sites and languages.

Reference implementation to learn from:
<https://github.com/netlogix/nxvarnish/blob/main/Classes/Middleware/ExposeCacheTags.php>

Surrogate Key reference:
<https://www.fastly.com/documentation/reference/http/http-headers/Surrogate-Key/>

Header-size budget: Fastly accepts up to ~16 KB of response headers. If TYPO3
emits too many tags on a single response, the middleware must shorten them
(stable hashing) rather than truncate silently. Add a unit test for this case.

### 3. VCL & service provisioning (CLI command)

A Symfony Console command provisions the VCL snippets the extension relies on
and validates the Fastly service configuration (correct conditions, backends,
header logic for `Surrogate-Key`, IO setup, `_image/` path rules). The command
must be idempotent: running it twice on a clean service should not produce
diffs.

Expected subcommands (final names TBD):
- `fastly:vcl:provision` — upload/update snippets on a draft version.
- `fastly:vcl:diff` — show what would change without activating.
- `fastly:vcl:activate` — activate the draft version.
- `fastly:service:check` — read-only service health and configuration check.
- `fastly:purge:key` and `fastly:purge:all` — operational helpers.

Fastly API reference: <https://www.fastly.com/documentation/reference/api/>

### Caching behavior

The extension supports and documents:
- **Stale-while-revalidate**
- **Grace mode**
- **ESI** — exposed through a Fluid ViewHelper (`<f:esi …>` style) that emits
  `<esi:include …/>` tags. The ViewHelper is part of the **public API**.

## Configuration

All configuration lives in the **Extension Configuration**
(`ext_conf_template.txt`). There is **no** per-site (YAML) override.

- One **global Fastly service** per TYPO3 installation.
- Required keys (final names TBD): `serviceId`, `apiToken`, `ioHostname`,
  optional toggles for SWR / Grace / ESI defaults.
- The `apiToken` is a secret. It is read at runtime via
  `ExtensionConfiguration` and must **never** be logged, echoed in CLI output,
  written to fixtures, or appear in tests.

## Expected project layout

```
Classes/
  Cache/
    Backend/            # Fastly cache backend for TYPO3's caching framework
  Command/              # Symfony Console commands (VCL, purge, check)
  Middleware/           # Surrogate-Key middleware
  Processor/            # FAL processor (Fastly IO URL builder)
  Service/
    Client/             # Fastly API client (PSR-18 based)
    UrlBuilder.php      # TYPO3 → IO parameter translation, focus points, crop variants
  ViewHelpers/          # ESI ViewHelper (public API)
Configuration/
  Services.php          # DI wiring (autowire + autoconfigure)
  RequestMiddlewares.php
Resources/
  Private/Language/
  Private/VCL/
Tests/
  Unit/
  Functional/
ext_conf_template.txt
ext_emconf.php
composer.json
README.rst
AGENTS.md             # this file
```

## Development commands

There is **no DDEV** in this project. Run things directly:

```bash
# install
composer install

# static analysis
composer phpstan          # PHPStan (level TBD, target: 8+)
composer rector           # Rector (TYPO3 sets pinned to supported versions)
composer rector:dry       # Rector dry run
composer php:lint         # Lint all PHP files (syntax)

# tests
composer test:unit        # typo3/testing-framework, Unit suite
composer test:functional  # typo3/testing-framework, Functional suite

# optional, if added
composer cs:check         # php-cs-fixer / ECS (TYPO3 CGL)
composer cs:fix
composer composer:normalize
```

If a script above does not yet exist in `composer.json`, add it rather than
inventing an ad-hoc invocation.

## Continuous Integration

GitHub Actions runs on every push and PR. The matrix must cover:
- TYPO3 `^13.4` and `^14.3`
- PHP `8.3`, `8.4`, `8.5` (skip 8.2 on the v14 leg)

Jobs: composer validate, PHPStan, Rector dry-run, Unit tests, Functional
tests. Keep the workflow file minimal and readable.

## Coding conventions

- `declare(strict_types=1);` in every PHP file.
- **Constructor dependency injection only.** Do **not** use
  `GeneralUtility::makeInstance()` for our own services. The only acceptable
  uses are TYPO3 core entry points where DI is unavailable (and even there,
  prefer the DI container when accessible).
- Follow **TYPO3 CGL** (effectively PSR-12 with TYPO3-specific naming).
- Public properties only on DTO/value objects; otherwise readonly + accessors.
- Symfony Console commands extend TYPO3's `Symfony\Component\Console\Command`
  base — do not invent custom command frameworks.
- Throw typed exceptions from a small `Fastly\Cdn\Exception\` namespace.
  No bare `\RuntimeException` in production code.
- Translate user-facing strings via `LLL:EXT:fastly/...` — no hardcoded
  English in CLI output that the user sees as data (errors stay English).

## Public API

The following surfaces are **public API**. Backwards-incompatible changes
require a **deprecation path** (minimum one minor release with `@deprecated`
plus a `Documentation/Changelog/` entry) and may only be removed in a major
release.

- `Fastly\Cdn\Processor\…` — the FAL processor class name and
  `canProcessTask()` / `processTask()` contract.
- `Fastly\Cdn\ViewHelpers\EsiViewHelper` — argument names and rendering output.
- CLI command names and their documented options (everything advertised in
  `--help`).
- PSR-14 events emitted by the middleware and the API client (names, payload
  shape).
- Extension configuration keys in `ext_conf_template.txt`.

> TODO: mark each of the above with an `@api` annotation once the classes
> exist, and keep this list in sync.

## Guardrails

Hard rules. If a task seems to require breaking one of these, stop and ask.

1. **Never commit secrets.** No real `serviceId`, no `apiToken`, no production
   hostnames in code, tests, fixtures, snapshots, or CI configuration. Use
   placeholders like `SERVICE_ID_PLACEHOLDER` / `API_TOKEN_PLACEHOLDER` and
   load real values from environment variables in CI.
2. **No `GeneralUtility::makeInstance()` for our services.** Always wire
   through the constructor via `Configuration/Services.yaml`.
3. **No BC breaks without a deprecation path.** See the Public API section.
4. **Do not change the `_image/` URL prefix** without coordinating — the VCL
   snippets and the FAL processor agree on this string.
5. **Do not introduce per-site configuration.** A single global service is a
   product decision.
6. **Do not hand-roll cache-tag collection.** Always read from TYPO3's
   built-in CacheTag middleware output.
7. **Do not perform image transformations locally.** The FAL processor only
   produces URLs. If a request cannot be expressed as IO parameters, fail
   loudly with a clear exception rather than falling back to GraphicsMagick.
8. **Network calls** against the Fastly API only through the dedicated
   client in `Classes/Api/` — never `curl_*` or raw `file_get_contents`
   scattered through the codebase.

## References

- Fastly IO: <https://www.fastly.com/documentation/reference/io/>
- Fastly Surrogate Keys: <https://www.fastly.com/documentation/reference/http/http-headers/Surrogate-Key/>
- Fastly API: <https://www.fastly.com/documentation/reference/api/>
- TYPO3 v13 caching: <https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/CachingFramework/Index.html>
- Reference (FAL processor pattern): <https://github.com/christophlehmann/imgproxy>
- Reference (cache-tag middleware pattern): <https://github.com/netlogix/nxvarnish>
