# TYPO3 Extension: Fastly

Fastly's edge CDN and Image Optimization integration for TYPO3 CMS.

The extension does not optimize images itself — it generates URLs that
[Fastly Image Optimization](https://www.fastly.com/documentation/reference/io/)
consumes, and it exposes TYPO3's cache tags as a `Surrogate-Key` header so Fastly can purge by key. A single global Fastly service backs the whole TYPO3 installation.

## Features

- **Image Optimization** — a FAL processor rewrites image URLs to be served through Fastly IO (`width`, `height`, `format=auto`, `quality`, `dpr`, crop/focus point, …),
  storage-agnostic and without any local image transformation.
- **Surrogate-Key middleware** — emits TYPO3's built-in CacheTag output as the `Surrogate-Key` response header, so Fastly can purge by page, FAL file, site or language.
- **ESI** — a Fluid `<f:esi>` ViewHelper for edge-side includes, paired with VCL that enables ESI processing on the response.
- **VCL provisioning** — ships the custom VCL the service needs and can diff/upload/activate it via CLI (`fastly:vcl:diff` /
  `fastly:vcl:provision`), with a Fluid-style override mechanism for site packages.
- **Service provisioning** — CLI commands to create, update and health-check the Fastly service's domains and product features (`fastly:service:add` / `update` / `check`) from
  TYPO3's own site configuration.

## Requirements

- TYPO3 `^13.4 || ^14.3`
- PHP `^8.3`
- [`fastly/fastly`](https://packagist.org/packages/fastly/fastly) `^13.1`

## Installation

```
composer require fastly/typo3
```

## Configuration

Set these under *Admin Tools > Settings > Extension Configuration > fastly*
(`ext_conf_template.txt`) — there is no per-site override, one Fastly service serves the whole installation:

| Key                    | Description                                                      |
|------------------------|------------------------------------------------------------------|
| `serviceId`            | Your Fastly service ID.                                          |
| `apiToken`             | Fastly API token. **Secret — never commit a real value.**        |
| `assetUrl`             | Base URL prepended to relative source file URLs.                 |
| `enableImageOptimizer` | Enables Image Optimization URL generation.                       |
| `enableCdn`            | Enables the CDN integration (Surrogate-Key middleware, purging). |
| `quality`              | Overrides `GFX/jpg_quality` for IO requests.                     |
| `allowedExtensions`    | File extensions eligible for image optimization.                 |
| `ignoreAssets`         | Excludes the TYPO3 assets path from processing.                  |
| `vclRootPaths`         | Override paths for custom VCL files (Fluid-style, later wins).   |

Full reference, defaults and types: [Documentation/Configuration.md](Documentation/Configuration.md).

## CLI commands

| Command                 | Purpose                                                           |
|-------------------------|-------------------------------------------------------------------|
| `fastly:service:add`    | Create a Fastly service from TYPO3 site domains.                  |
| `fastly:service:update` | Reconcile an existing service's domains/features with TYPO3.      |
| `fastly:service:check`  | Read-only health check of the service against TYPO3 site domains. |
| `fastly:vcl:provision`  | Upload and activate the extension's custom VCL (idempotent).      |
| `fastly:vcl:diff`       | Read-only diff of local VCL against the service.                  |

Full options for every command: [Documentation/Commands.md](Documentation/Commands.md).

## Documentation

- [Documentation/Configuration.md](Documentation/Configuration.md) — every configuration key in detail.
- [Documentation/Commands.md](Documentation/Commands.md) — full CLI reference.
- [Documentation/CustomVcl.md](Documentation/CustomVcl.md) — how the shipped VCL is structured and how a site package can override it via
  `vclRootPaths`.
