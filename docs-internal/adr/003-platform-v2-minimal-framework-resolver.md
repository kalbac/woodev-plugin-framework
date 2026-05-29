# ADR-003: Platform v2 Minimal Framework Resolver
> Status: Proposed
> Date: 2026-05-29

## Context

`PLANS.md` defines the target framework as platform-first: a pure WordPress base plugin class, WooCommerce-specific subclasses, and future EDD support. `platform-v2-strategy-alignment.md` later selected a hybrid roadmap: v2.0 keeps a minimal resolver, while SkyVerge-style versioned namespaces remain a future v2.x/v3 track.

ADR-001 accepted keeping `bootstrap.php` as a broad platform-aware loader. That was a safe spike decision, but it now conflicts with the rewrite-first strategy because it preserves too much legacy entry-file behavior as architecture.

The current `woodev/bootstrap.php` combines framework version arbitration, plugin registration, WordPress and WooCommerce requirement checks, payment/shipping early module loading, incompatibility notices, deactivation recovery, and a WooCommerce-active helper. Those are not all the same architectural responsibility.

## Decision

Use a minimal framework resolver for Platform v2.0.

`woodev/bootstrap.php` remains the compatibility entry point, but the real logic should move behind it into a resolver class or resolver service set. `Woodev_Plugin_Bootstrap::instance()` may remain as a facade for existing plugin entry files.

The resolver owns:

- Plugin registration normalization.
- Framework version and path arbitration.
- Highest-compatible-framework selection.
- PHP, WordPress, framework, and platform requirement checks.
- Early platform class availability before plugin callbacks execute.
- Incompatible registration tracking.
- Admin notices and deactivation recovery for skipped registrations.
- The final `woodev_plugins_loaded` action timing.

The resolver does not own:

- Payment gateway internals.
- Shipping method internals.
- Licensing behavior.
- Plugin data migrations.
- Runtime WooCommerce behavior such as HPOS, Blocks, logger, templates, REST status, or settings wrappers.
- EDD runtime behavior.

ADR-001 should be treated as superseded for future implementation planning once this ADR is accepted.

## Alternatives Considered

- **Keep ADR-001 unchanged:** rejected because a broad platform-aware loader would keep accumulating platform decisions in the early bootstrap layer.
- **Delete `bootstrap.php` in v2.0:** rejected because production plugins include the vendored framework entry path and multi-plugin framework arbitration remains an installed-site risk.
- **Adopt SkyVerge-style versioned namespaces immediately:** rejected for v2.0 because platform boundaries are not stable enough and release automation/smoke tests are not yet defined.

## Consequences

Positive:

- Preserves the installed entry path while allowing the architecture to move away from a monolithic bootstrap class.
- Keeps multi-plugin framework arbitration as a first-class v2.0 requirement.
- Makes pure WordPress plugin loading possible without treating WooCommerce as globally required.
- Gives the next implementation spec a clear responsibility boundary.

Negative:

- Requires a careful facade design so old `register_plugin()` callers do not break before production plugins are migrated.
- Adds a new internal resolver abstraction that must be tested independently from WordPress hook timing.
- Requires explicit discipline to prevent the resolver from becoming a second framework kernel.

Follow-up requirements:

- Write `platform-v2-implementation-spec.md` before PHP implementation.
- Define resolver tests for multi-version arbitration, platform requirement checks, pure WordPress loading without WooCommerce, and WooCommerce plugin skipping when WooCommerce is unavailable.
- Decide the minimal legacy adapter surface that remains necessary during production plugin rewrites.

## Related

- [PLANS.md](../../PLANS.md) — target platform-first architecture and open bootstrap question.
- [Platform v2 Strategy Alignment](../platform-v2-strategy-alignment.md) — hybrid roadmap and minimal resolver direction.
- [Platform v2 Next Analysis](../platform-v2-next-analysis.md) — detailed resolver responsibility mapping.
- [Platform v2 Dependency Matrix](../platform-v2-dependency-matrix.md) — current bootstrap risks and module dependency evidence.
- [ADR-001](001-bootstrap-platform-aware-loader.md) — previous bootstrap loader decision to supersede or reframe.
- [Platform v2 Epic 1 Spec](../platform-v2-epic1-spec.md) — existing implementation spec requiring revision.
