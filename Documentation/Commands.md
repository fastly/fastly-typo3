# CLI commands

All commands are Symfony Console commands, run via the TYPO3 console binary:

```
vendor/bin/typo3 <command> [options]
```

None are schedulable via the TYPO3 Scheduler (`#[AsNonSchedulableCommand]`) — they're meant to be run interactively or from deployment scripts, not as a recurring cron task.

## Service provisioning

These three commands manage the Fastly *service* itself (domains and product features), as opposed to VCL content. They read TYPO3 site configuration for absolute site base URLs
and use those as the service's domains.

### `fastly:service:add`

Create a new Fastly service from the TYPO3 site domains.

```
vendor/bin/typo3 fastly:service:add [--name=NAME] [--comment=COMMENT] [--all-features] [--http3] [--bot-management] [--waf] [--ddos-protection] [--dry-run] [--no-activate]
```

- `--name` — Fastly service name (default: `TYPO3 Fastly Service`).
- `--comment` — Fastly service comment.
- `--all-features` — enable all feature toggles below at once.
- `--http3` — enable HTTP/3.
- `--bot-management` — enable Bot Management.
- `--waf` — enable Next-Gen WAF.
- `--ddos-protection` — enable DDoS Protection.
- `--dry-run` — show planned changes without calling write APIs.
- `--no-activate` — leave the created service version inactive.

On success, prints the new service ID and a reminder to set it as the extension's `serviceId` configuration value.

### `fastly:service:update`

Reconcile an existing Fastly service's domains and features with the current TYPO3 site configuration.

```
vendor/bin/typo3 fastly:service:update [--service-id=ID] [--name=NAME] [--comment=COMMENT] [--all-features] [--http3] [--bot-management] [--waf] [--ddos-protection] [--dry-run] [--no-activate]
```

Same options as `fastly:service:add`, plus:

- `--service-id` — target service; defaults to the extension `serviceId`.

Adds missing domains; never removes domains no longer present in TYPO3 (see
`Classes/Service/FastlyServiceProvisioner.php`).

### `fastly:service:check`

Read-only. Reports whether the Fastly service's domains match TYPO3's site configuration and which optional features are active.

```
vendor/bin/typo3 fastly:service:check [--service-id=ID]
```

- `--service-id` — target service; defaults to the extension `serviceId`.

Exits non-zero if any TYPO3 site domain is missing from the Fastly service.

## VCL provisioning

These two commands manage the extension's *custom VCL* content on the service. See [CustomVcl.md](CustomVcl.md) for the file layout and the Fluid-style override mechanism
(`vclRootPaths`).

### `fastly:vcl:provision`

Uploads the resolved local VCL to the service and activates it.

```
vendor/bin/typo3 fastly:vcl:provision [--service-id=ID] [--dry-run] [--no-activate]
```

- `--service-id` — target service; defaults to the extension `serviceId`.
- `--dry-run` — show planned changes without calling any write API.
- `--no-activate` — upload to a draft version but leave it inactive.

Idempotent: diffs local files against the version it would write to (a reusable draft the extension previously created, otherwise the active version) and only
clones/uploads/activates when something actually differs. Running it twice on an in-sync service performs no writes and clones no new version. Files present on the service but not
shipped locally are left alone (upsert only).

### `fastly:vcl:diff`

Read-only. Shows how the resolved local VCL differs from the service.

```
vendor/bin/typo3 fastly:vcl:diff [--service-id=ID]
```

- `--service-id` — target service; defaults to the extension `serviceId`.

Each file is reported as `added` (not on the service), `changed`, or
`unchanged`. Makes no write calls.
