# ADR-001: Keep Bootstrap as Platform-Aware Loader

**Status:** accepted

**Date:** 2026-05-28

## Context

`docs-internal/platform-v2-dependency-matrix.md` identifies `bootstrap.php` as a P0 spike item because it currently combines multi-version framework resolution, WooCommerce dependency checks, and conditional module loading. `PLANS.md` section 2 defines the target model as a platform-neutral `Woodev_Plugin` base with platform-specific subclasses such as `Woodev_Woocommerce_Plugin` and future `Woodev_EDD_Plugin`. `PLANS.md` section 5 leaves the fate of `bootstrap.php` open before a full implementation spec can be written.

The top production risks are high because about 12 production plugins can load different vendored framework copies at the same time. A loader rewrite could break highest-version selection, activation order, entry-file registration, payment/shipping base availability, or WooCommerce compatibility declarations before runtime fallbacks can execute.

## Decision

- Keep `bootstrap.php` in v2.0.0 as the platform-aware multi-version loader.
- Refactor the internals of `bootstrap.php` for explicit platform detection and module isolation instead of replacing it with a new kernel entry point.
- Preserve the existing vendored-framework highest-version selection behavior across multiple active plugins.
- Move platform-specific behavior out of the platform-neutral base where possible, but let `bootstrap.php` continue to coordinate early loading decisions that must happen before plugin classes are instantiated.
- Treat this ADR as resolving the P0 spike question about the fate of `bootstrap.php`; later specs must design the internal refactor around this accepted constraint.
- Do not design payment-gateway internals, shipping UI, licensing webhooks, or the box-packer algorithm as part of this ADR.

## Consequences

Positive:

- Lowest migration risk for the existing production plugin fleet because current entry files can continue to load the framework through the same file.
- Preserves multi-plugin, multi-version resolution while v2 separates pure WordPress and WooCommerce responsibilities.
- Gives v2.0.0 a compatibility bridge for legacy `register_plugin()` arguments while platform boundaries are introduced.
- Allows the P0 planning spike to proceed into implementation specs without requiring a full loader replacement design.

Negative:

- `bootstrap.php` remains a global coordination layer and can continue to accumulate platform conditionals if the refactor is not disciplined.
- Loader tests remain more complex than they would be with a smaller new kernel.
- The boundary between version resolution, platform detection, and module loading must be documented carefully to avoid recreating the current WooCommerce-only assumptions.
- A future major version may still need a cleaner loader/kernel split after production plugins have migrated.

## Related

- [Platform v2 Dependency Matrix](../platform-v2-dependency-matrix.md) — P0 spike item, loader options, and top production risks.
- [PLANS.md](../../PLANS.md) — sections 2 and 5 define the target platform hierarchy and open loader question.
- [FUTURE-BACKLOG.md](../FUTURE-BACKLOG.md) — framework decoupling backlog and deferred post-v2 work.
