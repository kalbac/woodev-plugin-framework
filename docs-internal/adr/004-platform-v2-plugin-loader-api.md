# ADR-004: Platform v2 Explicit Plugin Loader API
> Status: Proposed
> Date: 2026-05-29

## Context

`PLANS.md` identifies the current plugin type flags as unstable, especially `is_payment_gateway`. ADR-002 accepted inheritance as the long-term type source of truth, with metadata as a compatibility bridge. The later rewrite-first migration strategy changes the compatibility boundary: old plugin internals and entry-file shape may change, while installed-site contracts must be preserved.

The resolver still needs early metadata before plugin main classes are instantiated. It must know which framework version participates in arbitration, which platform requirement checker applies, which plugin file and ID are being registered, and which base classes must be available before the callback runs.

## Decision

Platform v2 should use explicit plugin loader definitions instead of loose legacy `register_plugin()` args.

Each production plugin loader must declare:

- Stable plugin ID.
- Human-readable plugin name.
- Plugin version.
- Framework version.
- Plugin file path.
- Platform from a closed set: WordPress, WooCommerce, future EDD.
- Requirements: PHP, WordPress, and platform-specific minimums.
- Main class and/or callback.

Runtime plugin behavior should be determined by inheritance or contracts, not by unvalidated strings. Loader metadata may request early platform class availability, but the resolver should validate the loaded main class against the declared platform and specialized contracts when feasible.

ADR-002 should be narrowed once this ADR is accepted: inheritance remains the runtime source of truth, but the metadata bridge should not become a broad new plugin type API.

## Alternatives Considered

- **Keep `is_payment_gateway` and `load_shipping_method` as legacy flags:** rejected because they are brittle and duplicate type information outside the class model.
- **Use generalized `type = payment_gateway | shipping` metadata as the main model:** rejected because it recreates the same string drift problem under a cleaner name.
- **Infer platform only from the final main class:** rejected because the resolver needs platform requirements and base class availability before the main class is safely loaded.
- **Require no metadata under rewrite-first:** rejected because framework arbitration, requirement checks, and plugin file identity are inherently early-loading concerns.

## Consequences

Positive:

- Plugin entry files become explicit and auditable.
- The resolver can support pure WordPress, WooCommerce, and future EDD plugins without guessing from legacy flags.
- Installed-site contracts can be tied to stable plugin IDs instead of incidental entry-file args.
- Runtime behavior becomes type-system driven through platform classes and specialized contracts.

Negative:

- Every production plugin needs an entry-file migration as part of the v2 rewrite.
- The framework must provide validation and clear admin/developer errors for malformed loader definitions.
- The implementation spec must decide whether the loader definition is an array, value object, or lightweight class under PHP 7.4 constraints.

Follow-up requirements:

- Define exact field names, allowed values, defaults, and validation errors in the implementation spec.
- Define how legacy `register_plugin()` calls adapt into the new definition during the migration window.
- Add fixtures for WordPress, WooCommerce, payment, and shipping plugin loaders.
- Require per-plugin migration checklists before rewriting production plugin loaders.

## Related

- [PLANS.md](../../PLANS.md) — target class hierarchy and plugin type open question.
- [Platform v2 Strategy Alignment](../platform-v2-strategy-alignment.md) — rewrite-first migration and installed-site contract policy.
- [Platform v2 Next Analysis](../platform-v2-next-analysis.md) — proposed loader shape, metadata limits, and migration checklist.
- [ADR-002](002-plugin-type-inheritance-with-metadata-bridge.md) — previous inheritance plus metadata bridge decision to narrow.
- [ADR-003](003-platform-v2-minimal-framework-resolver.md) — resolver boundary that consumes loader definitions.
- [Platform v2 Epic 1 Spec](../platform-v2-epic1-spec.md) — existing spec requiring revision before implementation.
