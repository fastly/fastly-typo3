# TYPO3 Extension: Fastly

Fastly's edge CDN and Image Optimization integration for TYPO3 CMS.

[Fastly](https://www.fastly.com/) is a global edge cloud platform: a network of
caching servers (a CDN) close to your visitors that serves your pages and images
so most requests never reach your TYPO3 server. This extension connects TYPO3
to Fastly:

- It generates image URLs that
  [Fastly Image Optimization](https://www.fastly.com/documentation/reference/io/)
  transforms on the edge - the extension never optimizes images itself.
- It exposes TYPO3's cache tags as a `Surrogate-Key` header, so Fastly can purge
  exactly the pages that changed when you edit content.
- It provisions and maintains the Fastly service itself via CLI - you never
  have to click through the Fastly web interface if you don't want to.

A single global Fastly service backs the whole TYPO3 installation.

**New to Fastly?** [Create a free account](https://www.fastly.com/signup/?utm_source=github&utm_medium=readme&utm_campaign=typo3-extension)
first - you need one before anything below works.

## Features

- **Image Optimization** - a FAL processor rewrites image URLs to be served through Fastly IO (`width`, `height`, `quality`, `dpr`, crop/focus point, …),
  storage-agnostic and without any local image transformation.
- **Surrogate-Key middleware** - emits TYPO3's built-in CacheTag output as the `Surrogate-Key` response header, so Fastly can purge by page, FAL file, site or language.
- **ESI** - a Fluid `<esi:include>` ViewHelper for edge-side includes, paired with VCL that enables ESI processing on the response.
- **VCL provisioning** - ships the custom VCL the service needs and can diff/upload/activate it via CLI (`fastly:vcl:diff` /
  `fastly:vcl:provision`), with a Fluid-style override mechanism for site packages.
- **Service provisioning** - CLI commands to create, update and health-check the Fastly service's domains and product features (`fastly:service:add` / `update` / `check`) from
  TYPO3's own site configuration.

## Quick start

For readers who already know Fastly - every step is explained in detail in the
[step-by-step guide](#step-by-step-guide) below.

1. [Sign up for Fastly](https://www.fastly.com/signup/?utm_source=github&utm_medium=readme&utm_campaign=typo3-extension)
   and [create an API token](https://www.fastly.com/documentation/guides/account-info/user-and-account-management/using-api-tokens/) with `global` scope.
2. Install the extension:

   ```bash
   composer require fastly/typo3
   ```

3. Provide the token as an environment variable and wire it up in
   `config/system/additional.php`:

   ```php
   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fastly']['apiToken'] = getenv('FASTLY_API_TOKEN');
   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fastly']['serviceId'] = getenv('FASTLY_SERVICE_ID');
   ```

4. Create the Fastly service from your TYPO3 site configuration:

   ```bash
   vendor/bin/typo3 fastly:service:add
   ```

   Set the printed service ID as `FASTLY_SERVICE_ID`.
5. [Setup your domain](https://www.fastly.com/documentation/guides/getting-started/domains/working-with-domains/working-with-domains/), DNS and TLS at Fastly.
6. Connect the domain with your TYPO3 service
7. Upload and activate the VCL:

   ```bash
   vendor/bin/typo3 fastly:vcl:provision
   ```

Done - your site is now served through Fastly.

## Step-by-step guide

Everything in this guide runs through the TYPO3 CLI (`vendor/bin/typo3`). You
can inspect the results in the [Fastly control panel](https://manage.fastly.com/)
at any time, but no step requires it beyond creating the account, setup the Domain
and create the API token.

### 1. Create a Fastly account

[Sign up for Fastly](https://www.fastly.com/signup/?utm_source=github&utm_medium=readme&utm_campaign=typo3-extension).
The free developer trial is enough to set everything up and test it.

### 2. Create an API token

The extension talks to the [Fastly API](https://www.fastly.com/documentation/reference/api/)
on your behalf - to create the service, upload VCL and purge the cache. For
that it needs an API token:

1. In the Fastly control panel, go to **Account > API tokens > Personal tokens**.
2. Click **Create token**.
3. Give it the **`global`** scope. The provisioning commands
   (`fastly:service:*`, `fastly:vcl:*`) modify the service configuration, which
   only the `global` scope allows.

If you want a tighter token for the running website (which only ever purges),
you can additionally create one with the `purge_select` and `purge_all` scopes
and use the `global` token only where the provisioning commands run. See
[Using API tokens](https://www.fastly.com/documentation/guides/account-info/account-management/using-api-tokens/)
for details on scopes.

**Treat the token like a password.** Never commit it to your repository - the
next step shows how to keep it out of your code.

### 3. Install the extension

```bash
composer require fastly/typo3
```

### 4. Configure the extension

All configuration lives in the extension configuration
(*Admin Tools > Settings > Extension Configuration > fastly*). There is no
per-site override - one Fastly service serves the whole installation:

| Key                    | Description                                                      |
|------------------------|------------------------------------------------------------------|
| `serviceId`            | Your Fastly service ID (you'll get it in step 5).                |
| `apiToken`             | Fastly API token. **Secret - never commit a real value.**        |
| `assetUrl`             | Base URL prepended to relative source file URLs.                 |
| `enableImageOptimizer` | Enables Image Optimization URL generation.                       |
| `enableCdn`            | Enables the CDN integration (Surrogate-Key middleware, purging). |
| `quality`              | Overrides `GFX/jpg_quality` for IO requests.                     |
| `allowedExtensions`    | File extensions eligible for image optimization.                 |
| `ignoreAssets`         | Excludes the TYPO3 assets path from processing.                  |
| `vclRootPaths`         | Override paths for custom VCL files (Fluid-style, later wins).   |

Full reference, defaults and types: [Documentation/Configuration.md](Documentation/Configuration.md).

**Best practice: use environment variables.** Don't paste the API token into
the backend form (it would end up in `settings.php` and possibly in your git
history). Instead, set the values as environment variables on the server and
wire them up in `config/system/additional.php`, which overrides whatever the
backend form stores:

```php
<?php

call_user_func(function () {
    // fastly settings
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fastly']['apiToken'] = getenv('FASTLY_API_TOKEN');
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fastly']['serviceId'] = getenv('FASTLY_SERVICE_ID');
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['fastly']['assetUrl'] = getenv('FASTLY_ASSET_URL');
});
```

Then provide `FASTLY_API_TOKEN`, `FASTLY_SERVICE_ID` and `FASTLY_ASSET_URL`
through your hosting environment (`.env` file, container environment, CI/CD
secrets - whatever your deployment uses).

### 5. Create the Fastly service

A Fastly *service* is the configuration container for your site: which domains
it answers for, where your TYPO3 server (the *origin* or *backend*) lives, and
which VCL logic runs on the edge.

You don't have to create it in the web interface - the extension reads the
domains from your TYPO3 site configuration and creates the service for you:

```bash
vendor/bin/typo3 fastly:service:add
```

The command:

- collects the base URLs of all TYPO3 sites and registers them as the
  service's domains,
- configures your server as the backend,
- prints the ID of the newly created service.

Set that ID as the `FASTLY_SERVICE_ID` environment variable (step 4) - all
other commands default to it.

Useful options:

- `--dry-run` - show what would be created without writing anything.
- `--no-activate` - create everything but leave the service version inactive.
- `--name` / `--comment` - service name and comment.
- `--http3`, `--bot-management`, `--waf`, `--ddos-protection`,
  `--all-features` - enable optional Fastly products (they may require a paid
  contract).

When you later add or change TYPO3 sites, reconcile the service with:

```bash
vendor/bin/typo3 fastly:service:update
```

It adds missing domains but never removes any. And to verify at any time that
service and TYPO3 agree:

```bash
vendor/bin/typo3 fastly:service:check
```

This is read-only and exits non-zero if a TYPO3 site domain is missing from
the service - handy in CI.

### 6. Point your domain at Fastly

So far Fastly knows about your domain, but visitors still go straight to your
server. To route traffic through Fastly, change your DNS:

1. **Set up TLS for the domain** in the Fastly control panel under
   **Domains > TLS Management** (Fastly can provision and renew certificates
   for you). This tells you the exact CNAME target for your domain, e.g.
   `<letter>.sni.global.fastly.net`.
2. **Create the CNAME record** at your DNS provider, pointing your domain
   (e.g. `www.example.com`) at that target.
3. **Apex domains** (`example.com` without `www`) can't use CNAME records -
   use Fastly's anycast A/AAAA addresses instead; see
   [working with domains](https://www.fastly.com/documentation/guides/getting-started/domains/working-with-domains/).

### 7. Upload the VCL

The extension ships the [VCL](https://www.fastly.com/documentation/guides/vcl/)
(Fastly's edge configuration language) that the integration relies on: the
Image Optimization routing, Surrogate-Key handling, ESI processing, grace mode
and stale-while-revalidate behavior.

First see what would change (read-only):

```bash
vendor/bin/typo3 fastly:vcl:diff
```

Then upload and activate:

```bash
vendor/bin/typo3 fastly:vcl:provision
```

The command is idempotent: it diffs the local VCL against the service and only
creates a new service version when something actually differs. Running it
twice in a row does nothing the second time. Use `--dry-run` to preview and
`--no-activate` to upload to a draft version without activating it.

Site packages can override or extend the shipped VCL via the `vclRootPaths`
configuration - see [Documentation/CustomVcl.md](Documentation/CustomVcl.md).

That's it. Your site is served through Fastly, images are optimized on the
edge, and content changes purge exactly the affected pages via Surrogate-Keys.

## Updating the VCL on deployment

Run `fastly:vcl:provision` as part of every deployment:

```bash
vendor/bin/typo3 fastly:vcl:provision
```

Because the command is idempotent, this is safe to run on every deploy - if
the VCL is unchanged, nothing happens; if you upgraded the extension or
changed your VCL overrides, the new version is uploaded and activated.

**Activating a new VCL version does *not* clear the Fastly cache.** Cached
objects stay in the cache until their TTL expires or they are purged
explicitly. If a VCL change alters how responses are cached and you need it
to take effect immediately for already-cached content, purge deliberately -
and read the next section before you reach for "flush everything".

## Cache clearing and backend load

Day-to-day cache invalidation is automatic and cheap: when an editor changes
content, TYPO3 flushes the affected cache tags and the extension translates
that into targeted Surrogate-Key purges - only the affected pages leave the
Fastly cache.

**"Flush all caches" is a different story.** The extension registers a cache
backend in TYPO3's `pages` and `all` cache groups, so both **Flush all
caches** and **Flush frontend caches** in the TYPO3 backend trigger a *full*
Fastly purge. Afterwards, every request is a cache miss and hits
your TYPO3 server directly.

On a small site that's harmless. On a large or high-traffic site it means the
full traffic load lands on your backend at once until the cache is warm
again - which can take it down. So:

- Prefer letting the tag-based purging do its job; you rarely need a full
  flush.
- If you do need one, pick a low-traffic window and watch your backend load.
- Don't wire "flush all caches" into your deployment routine - deployments
  don't need it (see above).

## CLI reference

| Command                 | Purpose                                                           |
|-------------------------|-------------------------------------------------------------------|
| `fastly:service:add`    | Create a Fastly service from TYPO3 site domains.                  |
| `fastly:service:update` | Reconcile an existing service's domains/features with TYPO3.      |
| `fastly:service:check`  | Read-only health check of the service against TYPO3 site domains. |
| `fastly:vcl:provision`  | Upload and activate the extension's custom VCL (idempotent).      |
| `fastly:vcl:diff`       | Read-only diff of local VCL against the service.                  |

Full options for every command: [Documentation/Commands.md](Documentation/Commands.md).

## Documentation

- [Documentation/Configuration.md](Documentation/Configuration.md) - every configuration key in detail.
- [Documentation/Commands.md](Documentation/Commands.md) - full CLI reference.
- [Documentation/CustomVcl.md](Documentation/CustomVcl.md) - how the shipped VCL is structured and how a site package can override it via
  `vclRootPaths`.
