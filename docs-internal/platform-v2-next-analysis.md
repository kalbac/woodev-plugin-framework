# Platform v2 Next Analysis
> Status: planning analysis complete
> Date: 2026-05-29
> Source context: `PLANS.md`, `platform-v2-strategy-alignment.md`, dependency matrix, ADR-001/002, Epic 1 spec, current `bootstrap.php`, current `Woodev_Lifecycle`, and SkyVerge loader/namespace references.

## Executive Summary

Platform v2 should continue with the hybrid roadmap chosen in `platform-v2-strategy-alignment.md`, but the next implementation spec should not continue the old Epic 1 compatibility-bridge path as written.

The recommended v2.0 shape is:

- Keep `woodev/bootstrap.php` as the compatibility entry point for vendored framework copies.
- Move the real early-loading logic into a minimal resolver class behind that entry point.
- Make the resolver responsible only for framework arbitration, registration normalization, requirement checks, early platform class availability, and incompatibility notices.
- Move runtime platform behavior into platform classes such as `Woodev_Plugin` and `Woodev_Woocommerce_Plugin`.
- Use explicit plugin loaders in production plugins instead of relying on loose `register_plugin()` args.
- Treat plugin internals as rewrite-first, but preserve installed-site contracts as release-blocking migration requirements.

ADR-001 and ADR-002 are still useful historical decisions, but they now overstate legacy-entry compatibility as a v2.0 invariant. They should be superseded or reframed by new ADRs before writing the implementation spec.

## Resolver Recommendation

### What the v2.0 resolver should do

The minimal framework resolver should own only early concerns that must happen before plugin main classes are instantiated:

- Accept registrations from plugin entry files or plugin loader classes.
- Normalize each registration into a small typed registration record.
- Sort candidate framework copies by framework version and preserve the current highest-compatible-version behavior.
- Load the selected framework copy from the winning vendored path.
- Check PHP, WordPress, framework backward-compatibility, and platform requirements.
- Check WooCommerce requirements only for WooCommerce plugin registrations.
- Ensure platform base classes needed by plugin callbacks are available before callbacks run.
- Track incompatible framework, WordPress, WooCommerce, and future platform registrations.
- Render admin notices for skipped registrations.
- Fire the existing `woodev_plugins_loaded` action after compatible plugin callbacks are invoked.

### What the resolver should not do

The resolver should not own runtime behavior or module business decisions:

- No payment gateway processing behavior.
- No shipping rate, integration, or settings behavior.
- No licensing activation logic.
- No plugin data migrations.
- No WooCommerce HPOS, Blocks, logger, template, REST status, or settings wrapper behavior after plugin instantiation.
- No EDD-specific runtime behavior.
- No broad module registry that grows into a second framework kernel.

### Compatibility entry point

Keep `woodev/bootstrap.php` as the compatibility entry point in v2.0 because production plugins already include that path from vendored framework copies.

The file should become thin:

- Define or require the resolver class.
- Keep `Woodev_Plugin_Bootstrap::instance()` available if existing plugins call it directly.
- Delegate registration, loading, requirement checks, notices, and deactivation flows to the resolver.
- Avoid adding new platform behavior to the bootstrap file itself.

This keeps the installed entry path stable without preserving the current bootstrap class as the long-term architecture.

### Current bootstrap responsibility mapping

| Current responsibility | v2.0 target | Reason |
|------------------------|-------------|--------|
| Singleton entry point and `plugins_loaded` hook | Keep as compatibility facade | Existing plugins may rely on the path and timing. |
| `register_plugin()` positional API | Keep as legacy adapter | Needed for existing plugin entry files until each plugin loader is rewritten. |
| Highest framework version sorting | Move to resolver core | This is the resolver's main job. |
| Loading `class-plugin.php` from the winning path | Move to resolver core | Must stay early and selected-path aware. |
| Backward-compatible framework window checks | Move to resolver core | Installed multi-plugin sites depend on this. |
| `minimum_wp_version` checks | Move to resolver requirements | Platform-neutral requirement. |
| `minimum_wc_version` checks | Move to platform requirement resolver | Must apply only to WooCommerce registrations. |
| `is_payment_gateway` and `load_shipping_method` module loading | Replace with loader metadata and platform class availability | Legacy flags are brittle; v2 loaders should declare platform and main class explicitly. |
| Incompatible plugin notices | Move to resolver notices service | Still early infrastructure, but should be separated from arbitration. |
| `is_woocommerce_active()` helper | Move to platform requirement checker | Bootstrap should not expose platform helpers long term. |
| Deactivation link for incompatible framework plugins | Keep through resolver notices | Existing recovery flow is useful for multi-plugin conflict cases. |

## Plugin Loader API Proposal

### Loader shape

Each production plugin should have an explicit loader in its plugin entry file. The loader registers a structured plugin definition with the resolver, then the resolver invokes a callback only after requirements and early class availability are satisfied.

Conceptual shape:

```php
Woodev_Plugin_Bootstrap::instance()->register(
    new Woodev_Plugin_Definition(
        plugin_id: 'woocommerce-edostavka',
        plugin_name: 'WooDev CDEK Delivery',
        plugin_version: 'x.y.z',
        framework_version: '2.0.0',
        plugin_file: __FILE__,
        platform: Woodev_Platform::WOOCOMMERCE,
        requirements: [
            'php'         => '7.4',
            'wordpress'   => '6.3',
            'woocommerce' => '7.0',
        ],
        main_class: Woodev\Plugins\Cdek\Plugin::class,
        callback: static function (): void {
            Woodev\Plugins\Cdek\Plugin::instance();
        }
    )
);
```

This is illustrative, not an implementation spec. The implementation may use arrays if PHP 7.4 constraints make value objects too expensive, but the implementation spec should preserve the same conceptual fields and validation rules.

### Required fields

| Field | Required | Notes |
|-------|----------|-------|
| `plugin_id` | Yes | Stable internal ID. Must match existing option and hook contracts when migrating a production plugin. |
| `plugin_name` | Yes | Human-readable name for notices. |
| `plugin_version` | Yes | Used by lifecycle and migration planning. |
| `framework_version` | Yes | Used by resolver arbitration. |
| `plugin_file` | Yes | Used for plugin basename, paths, deactivation links, and update integration. |
| `platform` | Yes | Closed set: WordPress, WooCommerce, future EDD. |
| `requirements` | Yes | PHP and WordPress always; platform-specific requirements only when platform requires them. |
| `main_class` or `callback` | Yes | At least one explicit initialization target. Prefer both when the callback wraps singleton boot. |

### Metadata for early loading

Rewrite-first does not eliminate metadata entirely. It changes what metadata is allowed to mean.

Metadata is valid when it describes early infrastructure that cannot be derived safely before the plugin class is loaded:

- Which platform base classes must be available before plugin callback execution.
- Which platform requirement checker applies.
- Which framework version participates in arbitration.
- Which plugin file and ID are used for notices, deactivation, and installed-site contracts.

Metadata should not duplicate runtime plugin type in a loose string API. Avoid `type = payment_gateway` as the primary source of truth for runtime behavior. If a specialized early module is required, prefer a closed capability/module enum or class reference with validation against inheritance or contracts.

### Platform distinction

The loader should distinguish platforms through a closed platform value, not by inferring from scattered flags:

- `wordpress`: requires PHP and WordPress only; main class should extend the base plugin class or implement a base plugin contract.
- `woocommerce`: requires PHP, WordPress, and WooCommerce; main class should extend or resolve to a WooCommerce platform plugin class.
- `edd`: reserved for a future track; no runtime implementation should be included in v2.0 unless the implementation spec explicitly opens that scope.

## Plugin Type Model

The source of truth should be layered:

1. Platform metadata chooses the early platform resolver and requirement checker.
2. Inheritance or contracts choose runtime behavior.
3. Optional early-loading capabilities declare class availability requirements before plugin callback execution.

This replaces the old model:

- `is_payment_gateway` should not become a new `type = payment_gateway` string that runtime code trusts.
- `load_shipping_method` should not become a generic boolean that silently loads an arbitrary module set.
- Payment plugins should extend a WooCommerce payment plugin base or implement a payment plugin contract.
- Shipping plugins should extend a WooCommerce shipping plugin base or implement a shipping plugin contract.
- Resolver metadata may request early availability of `Woodev_Payment_Gateway_Plugin` or `Woodev\Framework\Shipping\Shipping_Plugin`, but the resolver should validate that the final main class matches the declared platform/type contract once it is loaded.

The implementation spec should define whether this validation is runtime-only in v2.0 or also covered by static tests in fixtures.

## Migration Contract Model

### `Woodev_Lifecycle` as foundation

`Woodev_Lifecycle` already provides useful migration foundations:

- It detects install versus upgrade by comparing installed plugin version to current plugin version.
- It runs ordered upgrade routines via `$upgrade_versions` and `upgrade_to_*` methods.
- It stores install, upgrade, and migrate lifecycle events.
- It tracks activation state in stable `woodev_{plugin_id}_is_active` options.
- It exposes hooks for installed, updated, activated, deactivated, and milestone events.

### Missing for migration-safe rewrite

The current lifecycle class is not enough by itself for rewrite-first production migrations. The next spec should add or require:

- A per-plugin migration contract document before code changes.
- Idempotency rules for every migration routine.
- Dry-run or audit helpers for option/key discovery where feasible.
- Explicit old-to-new option key maps.
- License-state continuity checks.
- Scheduled event preservation or rescheduling rules.
- Hook/action/filter compatibility maps.
- Method ID preservation rules for WooCommerce gateways and shipping methods.
- Migration event details that are useful to support diagnostics.
- Failure behavior: stop, retry, mark partial, or show admin notice.

### Installed-site contracts to audit per plugin

Every production plugin migration must audit:

- Plugin ID and slug.
- Plugin basename and update identity.
- Option keys and settings arrays.
- License key option names, activation status, instance IDs, and updater state.
- WooCommerce payment gateway IDs.
- WooCommerce shipping method IDs and instance settings keys.
- Public action and filter names.
- Deprecated hook wrappers that external sites may still use.
- Scheduled cron hook names, recurrence, and payload shape.
- Custom database tables or stored schemas.
- REST route namespaces and route names.
- AJAX action names.
- Admin page slugs and capability checks.
- Log source names.
- Background job identifiers and queue state.
- Email IDs, note sources, and system-status rows.

### Production plugin migration checklist template

Use this checklist before approving a rewrite-first migration PR for any production plugin:

```markdown
# Platform v2 Migration Checklist: {Plugin Name}
> Status: draft | ready | completed
> Date: YYYY-MM-DD
> Plugin ID: {stable plugin id}
> Source version: {current production version}
> Target version: {v2 migration version}

## Installed-Site Contracts
- [ ] Plugin ID unchanged or migration impact documented.
- [ ] Plugin basename/update identity preserved.
- [ ] Option keys audited.
- [ ] Settings arrays audited.
- [ ] License state audited and preserved.
- [ ] Updater continuity verified.
- [ ] Payment/shipping method IDs preserved where applicable.
- [ ] Public hooks/actions/filters mapped.
- [ ] Scheduled events mapped.
- [ ] Stored data schemas mapped.
- [ ] REST/AJAX/admin route identifiers mapped.

## Migration Routines
- [ ] Upgrade path from latest production version is idempotent.
- [ ] Upgrade path from at least one older supported production version is tested.
- [ ] Data writes are non-destructive by default.
- [ ] Failure behavior is documented.
- [ ] Lifecycle event/log entry is recorded.

## Verification
- [ ] Existing install upgrade test passes.
- [ ] Fresh install test passes.
- [ ] License remains active after migration.
- [ ] Settings remain unchanged after migration.
- [ ] Method remains available under the same ID.
- [ ] Public hooks still fire or deprecated wrappers exist.
- [ ] Scheduled events remain correct after activation/deactivation.
```

## ADR and Spec Revision Plan

### ADR-001 impact

ADR-001 should be superseded by a new resolver ADR.

Keep from ADR-001:

- The entry path and multi-version arbitration risk analysis.
- The need to preserve highest-version selection in v2.0.
- The need for incompatibility notices and deactivation recovery.

Change from ADR-001:

- Do not describe bootstrap as a broad platform-aware loader.
- Do not make legacy metadata bridge behavior a central design constraint.
- Reframe `bootstrap.php` as a compatibility facade over a minimal resolver.

### ADR-002 impact

ADR-002 should be superseded or narrowed by a loader API ADR.

Keep from ADR-002:

- Inheritance/contracts as runtime source of truth.
- Early class availability as a real loader constraint.

Change from ADR-002:

- Reduce the importance of deprecated metadata bridges because production plugin internals are rewrite-first.
- Replace loose plugin type metadata with explicit platform metadata plus validated early-loading capabilities.
- Make installed-site contracts, not developer-internal entry shape, the compatibility boundary.

### Epic 1 spec impact

The current `platform-v2-epic1-spec.md` is not safe as the next implementation spec without revision.

Sections to keep as evidence:

- Goal of pure WordPress base plus WooCommerce subclass.
- Dependency matrix references and module ownership evidence.
- Multi-version resolution invariants.
- Pure WordPress must not require WooCommerce.
- WooCommerce-only modules must stay isolated.
- Test expectations for pure WordPress, WooCommerce, payment, and shipping fixtures.

Sections that are stale under rewrite-first:

- The framing that `bootstrap.php` remains the platform-aware multi-version loader.
- The broad legacy `register_plugin()` bridge as a v2.0 invariant.
- `type = payment_gateway | shipping` as normalized metadata that risks becoming a new brittle string API.
- The deprecation timeline that assumes old plugin entry files must survive at least one minor v2.x release.
- The production plugin migration pass as a late phase after framework bridge validation; migration contracts should be defined before implementation.
- Bridge removal planning as a primary phase; if plugin internals are rewritten, bridge scope should be smaller and explicitly justified.

### Spike branch impact

Treat `feat/platform-v2-epic1-spike` as proof, not as the implementation path.

Keep as evidence:

- A class hierarchy split is feasible.
- Bootstrap metadata can make base classes available before callback execution.
- Fixtures can model pure WordPress, WooCommerce, payment, and shipping registrations.

Throw away or redesign:

- Any implementation that grows bootstrap into a broader platform-aware loader.
- Any generalized string metadata that duplicates runtime type.
- Any compatibility bridge that exists only to preserve old developer-internal entry shape without a production migration need.

## Versioned Namespace Future Track

SkyVerge's `update-namespace.sh` demonstrates a practical release-time namespace rewrite:

- Reads old version from `composer.json`.
- Builds `vX_Y_Z` namespace fragments.
- Updates Composer package version and PSR-4 mappings.
- Replaces namespace fragments in source and tests.
- Updates framework version constants and changelog heading.

What is worth borrowing:

- Automated version-to-namespace conversion.
- Composer autoload rewrite as part of the release process.
- Source and test namespace replacement in one script.
- Loader awareness of framework version namespace.

What must be stricter for WooDev:

- Fail if `NEW_VERSION` is missing or not valid semver.
- Fail if old namespace fragments remain after replacement.
- Fail if unexpected new namespace fragments appear outside allowlisted paths.
- Regenerate Composer autoload files and verify mappings.
- Run `composer check` after rewrite.
- Run fixture smoke tests with two plugins loading different framework versions.
- Verify plugin updater and license state across namespaced framework copies.
- Verify WordPress hooks and option keys are not accidentally versioned.
- Verify generated artifacts are reproducible in CI.

Recommended timing: do not open this as v2.0 scope. Revisit in v2.x only after platform boundaries are stable and at least one migrated production plugin proves the resolver model. Move it to v3 if v2.x still needs global framework arbitration or if release automation remains fragile.

## Risks and Open Questions

| Risk / question | Recommendation |
|-----------------|----------------|
| Resolver facade still grows into a hidden kernel | ADR-003 should explicitly limit resolver responsibilities and require tests for responsibility boundaries. |
| Loader metadata becomes another brittle string API | ADR-004 should use a closed platform value and validated class/contract relationships. |
| Rewrite-first breaks installed production sites | Migration checklist must be required before each production plugin rewrite. |
| Lifecycle remains WooCommerce-coupled through helpers such as `wc_clean()` and admin note deletion | Implementation spec should include lifecycle decoupling or platform adapter work before pure WordPress support is declared complete. |
| Public hooks are not fully known | Per-plugin migration audits must enumerate hooks before rewrite approval. |
| EDD support leaks into v2.0 scope | Reserve EDD metadata only; do not implement EDD runtime in v2.0. |
| Versioned namespace track distracts from v2.0 | Keep it as future v2.x/v3 research until platform boundaries are stable. |

## Proposed Next Artifact

The next session should write a new implementation spec that supersedes the stale parts of `platform-v2-epic1-spec.md`.

Recommended title:

`docs-internal/platform-v2-implementation-spec.md`

The spec should include:

- Resolver facade and class responsibilities.
- Explicit plugin loader definition format.
- Platform class boundary for `Woodev_Plugin` and `Woodev_Woocommerce_Plugin`.
- Early class availability rules.
- Migration contract gates before production plugin rewrites.
- Fixture and test matrix.
- Explicit list of old Epic 1 spike ideas to keep or discard.

## Related

- [PLANS.md](../PLANS.md) — strategic source of intent for Platform v2.
- [Platform v2 Strategy Alignment](platform-v2-strategy-alignment.md) — hybrid roadmap, rewrite-first migration, and resolver direction.
- [Platform v2 Dependency Matrix](platform-v2-dependency-matrix.md) — module dependency evidence and risk inventory.
- [ADR-001](adr/001-bootstrap-platform-aware-loader.md) — earlier bootstrap loader decision now superseded by resolver analysis.
- [ADR-002](adr/002-plugin-type-inheritance-with-metadata-bridge.md) — earlier type metadata bridge decision now narrowed by rewrite-first policy.
- [ADR-003](adr/003-platform-v2-minimal-framework-resolver.md) — proposed minimal resolver decision.
- [ADR-004](adr/004-platform-v2-plugin-loader-api.md) — proposed explicit plugin loader API decision.
- [Platform v2 Epic 1 Spec](platform-v2-epic1-spec.md) — existing spec that must be revised before implementation.
- [FUTURE-BACKLOG.md](FUTURE-BACKLOG.md) — v2.0 framework decoupling backlog.
