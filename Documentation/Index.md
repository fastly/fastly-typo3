# Fastly extension documentation

- [Configuration.md](Configuration.md) — every extension configuration key, what it does, and secret handling for `apiToken`.
- [Commands.md](Commands.md) — full CLI reference: service provisioning (`fastly:service:add`/`update`/`check`) and VCL provisioning (`fastly:vcl:provision`/`diff`).
- [CustomVcl.md](CustomVcl.md) — how the shipped VCL is split per feature, the Fastly subroutine-concatenation model, and how a site package can override or extend it via
  `vclRootPaths`.
