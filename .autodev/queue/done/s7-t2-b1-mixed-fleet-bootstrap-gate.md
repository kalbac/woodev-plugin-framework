---
id: s7-t2-b1-mixed-fleet-bootstrap-gate
title: B-1 mixed v1/v2 fleet hard-gate — soft-fail probe in the v2 entry template + register_plugin() tombstone on the v2 bootstrap
phase: Fable 5 review B-1 (Critical) — site-availability armor before the first production plugin rewrite
type: feature
model: opus
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/bootstrap.php
  - tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php
  - tests/_fixtures/woodev-test-payment-gateway/woodev-test-payment-gateway.php
  - tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php
  - tests/unit/MixedFleetBootstrapGateTest.php
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "Direction A (v2 plugin on a v1 bootstrap): every fixture entry file probes method_exists( $bootstrap, 'register_loader_definition' ) after the class_exists/require rendezvous; when absent it hooks an admin_notices warning ('update plugin X / framework outdated') and returns — plugin stays dormant, NO fatal"
  - "Direction B (v1 plugin on the v2 bootstrap): Woodev_Plugin_Bootstrap gains a register_plugin() tombstone that NEVER initializes the legacy plugin and NEVER calls the v1 callback; it records the plugin (name/path when extractable) into an incompatible list rendered through the existing bootstrap admin-notice path; accepts ANY legacy argument shape without throwing (v1 signature was register_plugin( $framework_version, $plugin_name, $path, $callback, $args = [] ) — see `git show platform-v2-pre-refactor:woodev/bootstrap.php`)"
  - "New MixedFleetBootstrapGateTest covers BOTH directions: (A) separate-process test defines a v1-shaped stub Woodev_Plugin_Bootstrap (instance() + register_plugin() only, NO register_loader_definition), includes a fixture entry file -> no Error, loader definition NOT registered, notice hooked; (B) calls register_plugin() on the real v2 bootstrap with v1-shaped positional args -> no Error, callback NOT invoked, plugin recorded + notice path engaged"
  - "No existing behavior changes for a pure-v2 fleet: all existing tests still green; register_loader_definition path untouched"
  - "No installed-site contract strings changed (option keys, hooks, ids). The tombstone is ADDITIVE armor; clean-break ADR-005 not violated (site-availability, not internal-API nostalgia)"
---

# Task

Implement Fable 5 review finding **B-1** (Critical, verified against source — see
`docs-internal/reviews/fable5-architecture-review-2026-06-10.md` §B-1). Problem: WP loads
plugins directory-alphabetically, so on a site mixing one v2-rewritten plugin with one
v1 plugin, whichever vendored copy defines `Woodev_Plugin_Bootstrap` first wins the
rendezvous. v1 exposes `register_plugin()`, v2 exposes `register_loader_definition()` —
each side calling the other's missing method is an uncaught `Error` → site-wide WSOD.
Two ~30-line guards fix both directions.

## 1. Direction A — soft-fail probe in the v2 entry-file template

The 3 fixture entry files are the canonical v2 entry template (review evidence cites
them). In each, the current shape is:

```php
if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) { require_once $framework_bootstrap; }
...
Woodev_Plugin_Bootstrap::instance()->register_loader_definition( ... );
```

Insert the probe between rendezvous and registration:

```php
$bootstrap = Woodev_Plugin_Bootstrap::instance();
if ( ! method_exists( $bootstrap, 'register_loader_definition' ) ) {
    // A legacy (v1) framework copy won the class rendezvous: stay dormant, warn, never fatal.
    add_action( 'admin_notices', <closure rendering an error-class notice naming this plugin> );
    return;
}
$bootstrap->register_loader_definition( ... );
```

Keep the probe identical across the 3 files (it IS the template future rewrites copy).
Notice text: Russian, i18n'd with text domain `woodev-plugin-framework`, matching the
style of existing bootstrap notices.

## 2. Direction B — `register_plugin()` tombstone on the v2 bootstrap

Add a public `register_plugin()` method to `Woodev_Plugin_Bootstrap`
(`woodev/bootstrap.php`). Requirements:

- Tolerates ANY argument shape (the legacy caller is unknown-version code; nothing it
  passes may cause a TypeError). A variadic `( ...$args )` with best-effort extraction
  of the plugin name (string at index 1) and path (string at index 2) is acceptable —
  document WHY the signature is intentionally loose (robustness beats the type-decl
  convention here; say so in the docblock).
- NEVER invokes the v1 `$callback` (index 3) and never initializes the plugin.
- Records the plugin into the bootstrap's incompatible-plugin machinery so the EXISTING
  admin-notice render path surfaces "plugin X is built for an older framework version —
  update it". Reuse `$incompatible_framework_plugins` + `render_deactivation_notice()` /
  `maybe_deactivate_framework_plugins()` if their record shape fits; if their shape does
  not fit, add a dedicated list + hook the notice the same way the existing ones are
  hooked. Read the existing code first (Grep/Read in THIS worktree) and pick the
  smallest correct reuse.
- Docblock: `@since 2.0.0`, explains the mixed-fleet rationale and references B-1.

## 3. Tests — `tests/unit/MixedFleetBootstrapGateTest.php`

Brain Monkey unit tests, both directions:

- **A:** `@runInSeparateProcess` + `@preserveGlobalState disabled`: define a v1-shaped
  stub `Woodev_Plugin_Bootstrap` (only `instance()` returning a singleton with a
  `register_plugin()` method) BEFORE including
  `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`; stub the WP functions the
  entry file calls (Brain Monkey `Functions\when`). Assert: include completes (no Error),
  `register_loader_definition` was never called (stub lacks it — reaching it would
  fatal), and an `admin_notices` callback was added (Brain Monkey `Actions\expectAdded`).
- **B:** instantiate/obtain the real v2 bootstrap (see how BootstrapRegistrationTest
  does it), call `register_plugin( '1.4.1', 'Legacy Plugin', '/path/legacy.php',
  function() { $GLOBALS['b1_cb'] = true; }, [] )`. Assert: no exception, callback global
  NOT set, the plugin appears in the incompatible list (reflection — mind the
  `reflection-setaccessible-version-guard` gotcha: `setAccessible(true)` guarded by
  `PHP_VERSION_ID < 80100`), notice hook engaged. Also call it with garbage
  (`register_plugin( null )`, `register_plugin()`) — still no exception.
- Mind the `brain-monkey-function-pollution` gotcha for anything needing separate process.

## What NOT to change
- `register_loader_definition()` / resolver / `load_plugins()` arbitration — out of scope (that is B-2, NOT this task).
- No installed-site contract strings (option keys, hook names, ids). Additive only.
- No deprecation shims for internal APIs (ADR-005) — the tombstone is not a shim: it never delegates, it quarantines.
- Do NOT touch the realistic/pilot fixtures (they register from test code, not entry files).
- Coding conventions: WPCS tabs, Yoda, short arrays, `@since 2.0.0`, English comments, Russian user-facing strings.

## Verification
- `composer check` green in THIS worktree (vendor/ already installed).
- New tests fail if the probe or tombstone is removed (state in the report how you spot-checked that, e.g. temporarily reverting one guard).

## Reference
- `docs-internal/reviews/fable5-architecture-review-2026-06-10.md` §B-1
- `git show platform-v2-pre-refactor:woodev/bootstrap.php` — v1 register_plugin signature
- `tests/unit/BootstrapRegistrationTest.php` — existing bootstrap test scaffolding
