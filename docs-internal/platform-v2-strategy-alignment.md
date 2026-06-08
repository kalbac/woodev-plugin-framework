# Platform v2 Strategy Alignment
> Status: planning alignment
> Date: 2026-05-29
> Source of intent: `PLANS.md`

## Purpose

This document realigns the Platform v2 planning track with `PLANS.md` after the initial dependency matrix, ADRs, Epic 1 spec, and spike branch moved too far into an orchestration-first implementation path.

The current goal is not to implement code. The goal is to define the strategic ground rules for the future implementation spec.

## Strategic Direction

- Platform-first architecture is the priority for v2.0.
- Shipping is a critical module, but it must live inside the platform architecture, not define the platform around itself.
- Plugin internals can be rewritten for the new platform.
- Installed-site contracts must be preserved.
- `PLANS.md` remains the source of strategic intent until a dedicated implementation spec supersedes it.

## Hybrid Roadmap Decision

Use a hybrid roadmap instead of copying either the current Woodev bootstrap model or the current SkyVerge versioned-namespace model directly.

### v2.0: Platform-First Resolver Model

For v2.0, keep a minimal framework resolver, but do not keep the current bootstrap as a broad compatibility and module-loading hub.

The resolver may own only early infrastructure concerns:

- Plugin loader registration.
- Framework version and path arbitration.
- Environment and platform requirement checks.
- Early platform class availability.
- Incompatibility tracking and admin notices.

The resolver must not own runtime platform behavior or module business decisions:

- Payment gateway internals.
- Shipping module behavior.
- Licensing logic.
- Migration logic.
- Runtime WooCommerce, EDD, or future platform behavior.

### v2.x / v3: Versioned Namespace Track

SkyVerge-style versioned namespaces remain a future track after platform boundaries stabilize.

The future goal is to evaluate whether multiple WooDev plugins should be able to load different framework versions on the same site without global framework arbitration.

If this track is pursued, it must include release automation similar to SkyVerge's `update-namespace.sh`, plus stricter verification:

- Namespace/version replacement is automated.
- Old namespace fragments are not left in source or tests.
- Autoload mappings are regenerated and verified.
- A smoke test proves that two plugin fixtures can load different framework versions without fatal errors.
- `composer check` or the equivalent CI gate passes after namespace rewriting.

## Plugin Loader Direction

New plugin entry files should move toward explicit loader classes.

Each plugin loader should declare:

- Plugin ID.
- Plugin version.
- Framework version.
- Platform, such as WordPress, WooCommerce, or future EDD.
- Environment requirements.
- The callback or main plugin class to instantiate.

The loader should be explicit enough that the framework does not need to infer plugin type from legacy flags such as `is_payment_gateway` or `load_shipping_method`.

## Rewrite-First Migration Policy

The v2 platform may break old plugin developer internals because production plugins will be rewritten for the new platform.

Compatibility is required at the installed-site contract level, not necessarily at the old framework-internal API level.

Preserve these contracts:

- Existing option keys and persisted settings.
- License activation state and updater continuity.
- Payment and shipping method IDs where user configuration depends on them.
- Public hooks, actions, and filters used for site customizations.
- Scheduled events when they affect user-site behavior.
- Stored data schemas or provide explicit idempotent migrations.

The following do not need long-term compatibility unless a concrete plugin migration requires it:

- Old plugin entry-file shape.
- Legacy `register_plugin()` metadata flags.
- Old framework internal class hierarchy.
- Old module include timing.

## Lifecycle and Migrations

`Woodev_Lifecycle` remains the preferred foundation for plugin install, upgrade, activation, and deactivation flows.

For Platform v2, lifecycle work should focus on migration-safe rewrites:

- Migration routines must be idempotent.
- Settings migrations must preserve user data by default.
- License-state migrations must not force reactivation.
- Hook/filter compatibility must be audited per plugin before release.
- Migration logs or events should make support diagnostics easier.
- Each production plugin migration needs a checklist of old option keys, new option keys, hooks, scheduled events, license keys, and method IDs.

## Impact on Existing Planning Artifacts

The existing Platform v2 artifacts are useful but need reinterpretation under this alignment.

| Artifact | Current value | Required reinterpretation |
|----------|---------------|---------------------------|
| `platform-v2-dependency-matrix.md` | Good read-only dependency audit. | Keep as module ownership evidence, not as the full roadmap. |
| `adr/001-bootstrap-platform-aware-loader.md` | Correctly identifies bootstrap risk. | Revise from compatibility loader to minimal framework resolver. |
| `adr/002-plugin-type-inheritance-with-metadata-bridge.md` | Correct long-term direction toward inheritance. | Reduce legacy metadata bridge importance under rewrite-first migration. |
| `platform-v2-epic1-spec.md` | Useful platform-layer substrate spec. | Revise assumptions: platform-first remains, but old-entry compatibility is not the primary invariant. |
| `feat/platform-v2-epic1-spike` | Useful proof of class hierarchy and metadata normalization. | Treat as a spike, not as an implementation path to continue automatically. |

## Open Decisions Before Implementation Spec

1. Rename or reframe `bootstrap.php` as a resolver while preserving the existing file path for v2.0, or introduce a new resolver class/file behind the old entry point.
2. Define the exact v2 plugin loader API shape.
3. Define which metadata, if any, is needed only for early class loading.
4. Decide whether legacy `register_plugin()` args need any temporary support during active plugin migrations.
5. Define the first production plugin migration checklist template.
6. Decide which hooks and option keys are public installed-site contracts per plugin.
7. Decide when to open the versioned namespace track and what smoke tests must pass before resolver removal is considered.

## Related

- [PLANS.md](../PLANS.md) — strategic source of intent for the refactoring brainstorm.
- [Platform v2 Dependency Matrix](platform-v2-dependency-matrix.md) — existing module dependency audit.
- [ADR-001](adr/001-bootstrap-platform-aware-loader.md) — bootstrap decision that needs resolver-focused reinterpretation.
- [ADR-002](adr/002-plugin-type-inheritance-with-metadata-bridge.md) — plugin type decision that needs rewrite-first reinterpretation.
- [Platform v2 Epic 1 Spec](platform-v2-epic1-spec.md) — existing platform substrate spec to revise before implementation.
