# Configuration

All configuration lives in the extension's **Extension Configuration**
(`ext_conf_template.txt`, editable under *Admin Tools > Settings > Extension Configuration > fastly*). There is no per-site (YAML) override — one global Fastly service per TYPO3
installation.

| Key                    | Type    | Default                       | Description                                                                                                                                                                                                                                           |
|------------------------|---------|-------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `serviceId`            | string  | *(empty)*                     | Alphanumeric string identifying your Fastly service.                                                                                                                                                                                                  |
| `apiToken`             | string  | *(empty)*                     | Fastly API token used for cache purge and provisioning operations. **Secret — set via an environment-backed configuration mechanism, never commit a real value.**                                                                                     |
| `assetUrl`             | string  | `_images/`                    | Base URL prepended to relative source file URLs (e.g. `https://cdn.example.com`).                                                                                                                                                                     |
| `enableImageOptimizer` | boolean | `true`                        | Enables Fastly Image Optimization URL generation.                                                                                                                                                                                                     |
| `enableCdn`            | boolean | `true`                        | Enables the Fastly CDN integration (Surrogate-Key middleware, purging).                                                                                                                                                                               |
| `quality`              | string  | `85,75`                       | Overrides `GFX/jpg_quality`. See the [IO quality parameter](https://www.fastly.com/documentation/reference/io/quality/).                                                                                                                              |
| `allowedExtensions`    | string  | `jpg,jpeg,webp,avif,png,tiff` | File extensions eligible for image optimization.                                                                                                                                                                                                      |
| `ignoreAssets`         | boolean | `false`                       | Excludes files under the TYPO3 assets path from processing.                                                                                                                                                                                           |
| `vclRootPaths`         | string  | *(empty)*                     | Comma-separated, ordered list of override paths for custom VCL files (Fluid-style — later entries win), e.g. `EXT:sitepackage/Resources/Private/FastlyVCL/`. The extension's own VCL is always the lowest priority. See [CustomVcl.md](CustomVcl.md). |

## Secrets

`apiToken` must never be logged, echoed in CLI output, written to fixtures, or committed to version control. Use a placeholder in any example or test (e.g. `API_TOKEN_PLACEHOLDER`)
and load the real value through your deployment's environment/secret mechanism.
