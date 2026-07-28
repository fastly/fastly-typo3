# Custom VCL provisioning

The extension ships the VCL the Fastly service needs as **custom VCL** (not snippets) and can upload it to your service. The VCL is split per feature; a single `main.vcl` is the
Fastly *main* file that pulls every feature file in with
`include`.

## File layout

All files live in `Resources/Private/VCL/`:

| File                  | Responsibility                                                                                                                                                                                       |
|-----------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `main.vcl`            | Entry point. `include`s the feature files (at the top) and holds the `#FASTLY` boilerplate — the `vcl_*` state subroutines with the macros and the terminating returns. It does not `call` anything. |
| `ngwaf.vcl`           | Disables Next-Gen WAF/Signal Sciences inspection for static asset requests.                                                                                                                          |
| `image_optimizer.vcl` | Enables Fastly Image Optimization for the `/_image/` prefix and tunes image caching.                                                                                                                 |
| `caching.vcl`         | Generic cacheability: cookie/`private`/`no-store` handling and the default TTL.                                                                                                                      |
| `surrogate_keys.vcl`  | Strips the edge-only `Surrogate-Key` header from client responses.                                                                                                                                   |
| `grace.vcl`           | Stale-while-revalidate and grace/`stale-if-error`.                                                                                                                                                   |
| `esi.vcl`             | Enables ESI processing when the origin marks a response as ESI.                                                                                                                                      |

Feature files define the built-in subroutines (`vcl_recv`, `vcl_fetch`, …)
directly. Fastly concatenates same-named built-in subroutines in the order the compiler encounters them, so a feature is wired in purely by its `include` — no
`call` is needed. Two ordering rules follow from concatenation and are enforced by
`Tests/Unit/VclResourcesTest.php`:

1. Includes stay at the **top** of `main.vcl`. The boilerplate subroutines carry the terminating `return`s; anything a feature defines *after* a terminating return of the same
   subroutine would be unreachable.
2. Include order matters within a subroutine. In `vcl_fetch`, `caching.vcl`'s
   `return(pass)` marks a response uncacheable and terminates the subroutine, so
   `image_optimizer.vcl` precedes it and `grace.vcl` / `esi.vcl` follow it.

Because Fastly does not concatenate *custom* subroutines, feature files must only define built-in ones.

> Do not change the `/_image/` prefix in `image_optimizer.vcl` without also
> updating the FAL processor — the two agree on the string by contract.

## Overriding VCL (Fluid-style)

A site package can override any shipped file or add new ones, the same way Fluid template root paths work. Set the **`vclRootPaths`** extension configuration key to a
comma-separated, ordered list of directories:

```
vclRootPaths = EXT:sitepackage/Resources/Private/FastlyVCL/
```

Resolution is per filename:

1. `EXT:fastly/Resources/Private/VCL/` (the extension default) is always the lowest priority.
2. Each configured path is layered on top **in order** — later entries win.

So `EXT:sitepackage/Resources/Private/FastlyVCL/caching.vcl` replaces the shipped
`caching.vcl`, and a file that only exists in an override path (e.g.
`waf.vcl`) is added to the set. Remember to also override `main.vcl` if you add a new feature file, so it gets an `include`.

## Commands

`fastly:vcl:provision` and `fastly:vcl:diff` manage this VCL on the Fastly service — see [Commands.md](Commands.md) for the full command reference.

## Note

Uploading a *main* custom VCL switches the service into custom-VCL mode. This is a deliberate action performed only by `fastly:vcl:provision`.
