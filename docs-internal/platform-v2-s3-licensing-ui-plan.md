# S3.2 Modern License-Page UI Implementation Plan

> **For agentic workers:** This plan is executed via the project **autodev-loop** (worker subagent writes the diff → adversarial critic reviews every contract-adjacent diff AND any in-place fix before commit, no self-certify → holistic critic pass at the end). Atomic task files derived from this plan live in `.autodev/queue/pending/s6-p1..p5`. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Replace the Settings-API license form with a React (`@wordpress/scripts` + `@wordpress/components`) card-grid app talking to new `woodev/v1` REST routes, while preserving every stored-data contract byte-for-byte (no migration).

**Architecture:** Three layers (spec §3): React app (`src/license-page`, built to committed `woodev/assets/build/license-page/`) → `Woodev_REST_API_License` (namespace `woodev/v1`, core `rest_api_init`, NOT WC-gated) → transport-agnostic pure operations on `Woodev_Plugins_License` (`activate()`/`deactivate()`/`set_beta_enabled()`/`get_state()`), which perform the same `Woodev_License::save()/delete()` + option writes the legacy `admin_init` handlers and `register_setting` sanitize callback did. Legacy form/renderer/handlers are **deleted** (clean-break, ADR-005).

**Tech Stack:** PHP 7.4+, WordPress REST API, `@wordpress/scripts` (webpack, deps extracted to `wp.*`), `@wordpress/components` + `@wordpress/element` + `@wordpress/api-fetch` + `@wordpress/i18n`, Brain Monkey + Mockery unit tests, PHPCS (WPCS), PHPStan level 3.

**Spec:** `docs-internal/platform-v2-s3-licensing-ui-spec.md` (design locked with operator 2026-06-10 — do NOT re-open D-1…D-6).

---

## Locked design decisions (derived from the approved spec — implementation-level)

These resolve the implementation seams the spec left open. They are recorded here so all 5 tasks share one model:

1. **Plugin-instance registry.** The bootstrap registry (`Woodev_Plugin_Bootstrap` / `Framework_Resolver::get_active_plugins()`) holds definition *arrays*, not `Woodev_Plugin` instances — it cannot resolve a `Woodev_Plugins_License` by download id. Instead `Woodev_Plugins_License` keeps its own static instance registry: the constructor records `self::$registered_instances[ (string) $plugin->get_download_id() ] = $this`; static accessors `get_registered_instances(): array` and `get_registered_instance( string $plugin_id ): ?Woodev_Plugins_License`. The REST controller and the page enqueue both read this registry. Tests reset the static via reflection (mind gotcha `testing/reflection-setaccessible-version-guard`).
2. **Single reusable `woodev/v1` registration point** (operator requirement: S3.3 adds a `license-command` endpoint beside it in s8). New tiny registrar `Woodev_REST_V1_Registrar` (`woodev/rest-api/class-rest-v1-registrar.php`): `const ROUTE_NAMESPACE = 'woodev/v1'`, static `register_controller( object $controller ): void` (dedupes by class, hooks `rest_api_init` exactly once), `@internal` static `handle_rest_api_init()` that calls each controller's `register_routes()`. The license controller registers itself through it; the s8 webhook controller will too.
3. **Key-option write parity.** In the legacy flow the `woodev_{id}_license_key` option was written by the Settings API itself (`register_setting` sanitize → `options.php` `update_option`), NOT by `activate_license()`. The new `activate()` therefore writes it explicitly (`update_option( get_plugin_option_name( 'license_key' ), sanitize_text_field( $key ) )`) before dispatching — same option name, same sanitization, same default autoload.
4. **`deactivate()` does NOT delete `woodev_{id}_license_key`.** The legacy handler only called `Woodev_License::delete()` (which deletes `woodev_{id}_license`); the key option survived deactivation. Preserve exactly that.
5. **Wiring.** The registrar + controller files are `require_once`'d **unconditionally** in `Woodev_Plugin::includes()` next to the other licensing requires (gotcha `framework/includes-wiring`: REST requests are neither admin nor WC-gated). `Woodev_REST_API_License::boot()` (idempotent, static guard) is called from `Woodev_Plugins_License::add_hooks()`.
6. **Build entry/output.** `wp-scripts build ./src/license-page/index.js --output-path=woodev/assets/build/license-page` → `index.js`, `index.asset.php`, `style-index.css` (from the imported `style.scss`). Node version pinned via `.nvmrc` + `engines`; `package-lock.json` committed; CI uses `npm ci` with `node-version-file: .nvmrc` (determinism for the build-parity gate).
7. **i18n.** No `_n()` anywhere (gotcha `i18n/russian-source-plural-n`) — count-neutral phrasing only, PHP and JS. JS uses `__()` from `@wordpress/i18n`, text domain `woodev-plugin-framework`, Russian-source user-facing strings (matching the existing admin).

## File Structure

| File | Responsibility | Change | Task |
|------|----------------|--------|------|
| `woodev/licensing/class-plugin-license.php` | pure ops `activate()`/`deactivate()`/`set_beta_enabled()`/`get_state()` + `message_variant` mapping + static instance registry; **delete** `activate_license()`/`deactivate_license()` handlers + their `admin_init` hooks; B-12 `is_active()` docblock | modify | p1 |
| `woodev/licensing/class-woocommerce-license-settings.php` | legacy WC settings-field renderer + `register_setting` sanitize callback | **delete file** | p1 |
| `woodev/class-plugin.php` | remove `load_license_settings_fields()` + its call; add `includes()` requires for registrar + REST controller | modify | p1 (remove) + p2 (requires) |
| `tests/unit/LicenseNeedLicenseFlagTest.php` | replace the deleted-handler test with the new license-free no-op test | modify | p1 |
| `tests/unit/LicensePureOperationsTest.php` | byte-for-byte write parity + `get_state()` shape + `message_variant` buckets + anti-pirate + license-free no-ops | create | p1 |
| `woodev/rest-api/class-rest-v1-registrar.php` | reusable `woodev/v1` namespace registration point | create | p2 |
| `woodev/licensing/api/class-rest-api-license.php` | `Woodev_REST_API_License` controller (GET state, POST verify/deactivate/beta) | create | p2 |
| `tests/unit/LicenseRestControllerTest.php` | permission / schema / no-silent-failure / B-7 WC-absent / registrar dedupe | create | p2 |
| `package.json`, `package-lock.json`, `.nvmrc` | `@wordpress/scripts` toolchain (rework the existing stub) | modify/create | p3 |
| `src/license-page/index.js` + `app.js` + `license-card.js` + `style.scss` | React app | create | p3 (skeleton) / p4 (full) |
| `woodev/assets/build/license-page/*` | committed build output | create | p3, rebuilt p4 |
| `docs-internal/adr/007-react-admin-stack-wordpress-scripts.md` | ADR: framework React-admin stack | create | p3 |
| `woodev/admin/class-admin-pages.php` | `license_page()` → mount div only; `load_licenses_page_scripts()` → enqueue build + `wp-components` + inline bootstrap data; remove `license_page_init()` settings-section | modify | p4 |
| `tests/unit/LicensePageRenderTest.php` | mount-point render, no `<form>`, B-7 WC-absent render | create | p4 |
| `.github/workflows/ci.yml` | `assets` build-parity job (`npm ci && npm run build && git diff --exit-code woodev/assets/build/`) | modify | p5 |
| `README.md` | contributor note: `npm run build` regenerates the committed bundle | modify | p5 |

## Contract guard-rails (every task re-checks; spec §8)

**Preserved byte-for-byte:** option keys `woodev_{id}_license` / `woodev_{id}_license_key` / `woodev_{id}_beta_version` (+ `'yes'`/absent semantics for beta); EDD `edd_action` contract (`activate_license`/`deactivate_license`/`check_license`/`get_version`) + endpoint `https://woodev.ru/` + request params shape (`license`, `item_id`, `url`, `version`); hooks `woodev_license_saved`/`woodev_license_deleted`/`woodev_enable_license_logging`; admin slug `woodev-licenses` + parent `woodev`; transient `woodev_extensions`; constant `WOODEV_LICENSE_DEBUG`. **Removed registration-time-only names** (not stored anywhere — verified): `woodev_license_fields_group`, `woodev_licenses_section`, `woodev_licenses_page`, the `{id-dasherized}-nonce` form nonce. **Additive:** REST `woodev/v1` + routes, the 4 pure ops, the instance registry, the registrar, `woodev/assets/build/**`, node toolchain files.

**Anti-pirate invariant (gotcha `licensing/two-layer`):** `is_valid`/`is_active` in `get_state()` come from `is_license_valid()`/`is_active()` which consult ONLY `is_license_required()`; `is_need_license()` gates presentation + the activate/deactivate no-ops, never enforcement.

---

## Task s6-p1 (model: opus): pure operations + legacy-transport removal

**Files:** modify `woodev/licensing/class-plugin-license.php`; delete `woodev/licensing/class-woocommerce-license-settings.php`; modify `woodev/class-plugin.php` (remove `load_license_settings_fields()` + call at ~line 155); modify `tests/unit/LicenseNeedLicenseFlagTest.php`; create `tests/unit/LicensePureOperationsTest.php`.

- [ ] **Step 1: failing tests — write parity, state shape, invariants** (`tests/unit/LicensePureOperationsTest.php`, scaffolding pattern = `LicenseNeedLicenseFlagTest::make_license_for_plugin()` reflection builder + Brain Monkey `Functions\expect`):

```php
public function test_activate_writes_same_options_as_legacy_path(): void {
    // plugin stub: is_need_license true, get_plugin_option_name('license_key') = 'woodev_test_plugin_license_key'
    Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
    Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_license_key', 'KEY-123' );
    Functions\expect( 'delete_transient' )->once()->with( 'woodev_extensions' );
    // api_handler mock returns a response stub: ->license = 'valid', ->get_response_data() = $payload
    // woodev_license mock expects ->save( $payload ) once  (Woodev_License::save() itself is covered by existing behavior — same update_option('woodev_test_plugin_license', ...) + woodev_license_saved hook)
    $state = $license->activate( 'KEY-123' );
    $this->assertSame( 'valid', $state['status'] );
}

public function test_deactivate_deletes_license_data_but_keeps_key_option(): void {
    // dispatch deactivate_license succeeds → woodev_license->delete() once; update_option/delete_option on *_license_key NEVER called
}

public function test_set_beta_enabled_writes_yes_or_deletes_exactly_like_legacy_sanitize(): void {
    Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_beta_version', 'yes' );
    $license->set_beta_enabled( true );
    Functions\expect( 'delete_option' )->once()->with( 'woodev_test_plugin_beta_version' );
    $license->set_beta_enabled( false );
}

public function test_get_state_shape_and_message_variant_buckets(): void { /* every key of the spec §4.1 array; variant per bucket table below */ }

public function test_activate_transport_failure_throws_and_leaves_license_untouched(): void { /* dispatch throws → exception propagates, save() never called */ }

public function test_license_free_activate_deactivate_are_noops_returning_state(): void { /* is_need_license false → no dispatch, no option writes, returns get_state() */ }

public function test_anti_pirate_state_booleans_ignore_need_license_flag(): void { /* is_need_license false + status expired → is_valid/is_active false in get_state() */ }
```

- [ ] **Step 2: run — expect FAIL** (`./vendor/bin/phpunit tests/unit/LicensePureOperationsTest.php` → undefined method `activate`).
- [ ] **Step 3: implement the 4 pure ops + registry on `Woodev_Plugins_License`** (signatures spec §4.1; key sketches):

```php
public function activate( string $license_key ): array {
    if ( ! $this->plugin->is_need_license() ) {
        return $this->get_state(); // license-free: no-op (replaces the legacy handler early-return)
    }
    $license_key = sanitize_text_field( $license_key );
    if ( '' === $license_key ) {
        throw new Woodev_Plugin_Exception( esc_html__( 'Лицензионный ключ не указан.', 'woodev-plugin-framework' ) );
    }
    update_option( $this->plugin->get_plugin_option_name( 'license_key' ), $license_key ); // parity: the Settings API wrote this option in the legacy flow
    $this->license_key = $license_key;
    if ( $this->is_license_valid() ) {
        return $this->get_state(); // parity: legacy activate_license() early-returned when already valid
    }
    $license_data = $this->dispatch( 'activate_license', $license_key ); // throws on transport failure — caller (REST) maps to WP_Error
    if ( ! $license_data ) {
        throw new Woodev_Plugin_Exception( esc_html__( 'Не удалось получить данные лицензии. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
    }
    if ( ! empty( $license_data->license ) && 'valid' === $license_data->license ) {
        delete_transient( 'woodev_extensions' ); // parity
    }
    $this->woodev_license->save( $license_data->get_response_data() ); // parity: same payload, same option, same hook
    return $this->get_state();
}
```

`deactivate()`: license-free no-op → `dispatch( 'deactivate_license', $this->license_key )` (throws propagate) → `$this->woodev_license->delete()` → state. **Never touches `*_license_key`.** `set_beta_enabled()`: exact legacy sanitize semantics (`update_option(..., 'yes')` / `delete_option`). `get_state()`: spec §4.1 array; `status` = raw `$this->woodev_license->license`; `status_label` = `'' === $status ? '' : $this->get_license_status( $status )`; `message` = `( new Woodev_License_Messages( $this->woodev_license ) )->get_message()`; `expires` raw; booleans from the real accessors; `beta_enabled` = `$this->plugin->is_beta_allowed()`.

`message_variant` buckets (single source of truth, private `get_message_variant(): string`): **error** = `expired`, `disabled`, `revoked`, `missing`, `missing_url`, `invalid`, `invalid_item_id`, `item_name_mismatch`, `key_mismatch`, `site_inactive`, `no_activations_left`, `license_not_activable`; **warning** = `valid` with non-`lifetime` expiry within `MONTH_IN_SECONDS` (compute the timestamp the way `Woodev_License_Messages::__construct()` does — do NOT copy the broken `is_numeric(...) ?: strtotime(...)` line from the deleted renderer); **success** = `valid` otherwise; **info** = everything else (`''`, `deactivated`, unknown).

Registry: `private static $registered_instances = [];` populated at the end of `__construct()`; `public static function get_registered_instances(): array` / `get_registered_instance( string $plugin_id )`.

- [ ] **Step 4: delete the legacy transport** — remove `activate_license()`/`deactivate_license()` + their two `admin_init` `add_action` lines; delete `woodev/licensing/class-woocommerce-license-settings.php`; remove `Woodev_Plugin::load_license_settings_fields()` + its constructor call. Keep `notices()`, `plugin_row_license_missing()`, `verify_license()`, `validate_license()`, `plugin_screen_scripts()` and all read accessors untouched (spec §4.2).
- [ ] **Step 5: B-12 — `is_active()` docblock** documenting the three meanings of `true` (genuinely-active license / not-known-bad i.e. no failing status recorded / license-free product per server authority); behavior unchanged.
- [ ] **Step 6: update `LicenseNeedLicenseFlagTest`** — replace `test_deactivate_license_no_wp_die_when_license_not_needed` (handler is gone) with the license-free `deactivate()` no-op assertion.
- [ ] **Step 7: run** `./vendor/bin/phpunit tests/unit/LicensePureOperationsTest.php tests/unit/LicenseNeedLicenseFlagTest.php` → PASS, then `composer check` green.
- [ ] **Step 8: adversarial critic** (contract zone: option writes) → fix → re-critic → **commit** `feat(s3): extract transport-agnostic license operations, drop legacy Settings-form transport`.

## Task s6-p2 (model: opus): REST controller + reusable `woodev/v1` registration

**Files:** create `woodev/rest-api/class-rest-v1-registrar.php`, `woodev/licensing/api/class-rest-api-license.php`, `tests/unit/LicenseRestControllerTest.php`; modify `woodev/class-plugin.php` (`includes()` requires), `woodev/licensing/class-plugin-license.php` (`add_hooks()` boot call).

- [ ] **Step 1: failing tests** — registrar hooks `rest_api_init` once for N controllers; routes registered with `Woodev_REST_V1_Registrar::ROUTE_NAMESPACE === 'woodev/v1'`; permission callback false without `manage_options`; unknown `{plugin_id}` → `WP_Error` 404; happy-path verify/deactivate/beta call the matching pure op **once** and return its state; `Woodev_Plugin_Exception` from an op → `WP_Error` with non-empty `message` (no silent success); schema rejects empty `license_key` / non-bool `enabled`; **B-7**: with no WC function defined (Brain Monkey defines none), registration + request handling completes without `Error`.
- [ ] **Step 2: run — expect FAIL.**
- [ ] **Step 3: implement registrar** (decision #2 above) + **controller**:

```php
class Woodev_REST_API_License {
    public static function boot(): void { /* static $booted guard → Woodev_REST_V1_Registrar::register_controller( new self() ) */ }
    public function register_routes(): void {
        register_rest_route( Woodev_REST_V1_Registrar::ROUTE_NAMESPACE, '/licenses/(?P<plugin_id>[\w-]+)', [ /* GET get_item */ ] );
        register_rest_route( ..., '/licenses/(?P<plugin_id>[\w-]+)/verify', [ /* POST, args: license_key (string, required, sanitize_text_field, validate non-empty) */ ] );
        register_rest_route( ..., '/licenses/(?P<plugin_id>[\w-]+)/deactivate', [ /* POST */ ] );
        register_rest_route( ..., '/licenses/(?P<plugin_id>[\w-]+)/beta', [ /* POST, args: enabled (boolean, required) */ ] );
    }
    public function check_permissions() { return current_user_can( 'manage_options' ) ? true : new WP_Error( 'woodev_license_forbidden', __( 'Недостаточно прав.', 'woodev-plugin-framework' ), [ 'status' => rest_authorization_required_code() ] ); }
    private function resolve_license( string $plugin_id ) { /* Woodev_Plugins_License::get_registered_instance() ?: WP_Error 'woodev_license_unknown_plugin' 404 */ }
    // each handler: try { op } catch ( Woodev_Plugin_Exception|Exception $e ) { return new WP_Error( 'woodev_license_request_failed', $e->getMessage() ?: __( 'Не удалось выполнить запрос к серверу лицензий.', 'woodev-plugin-framework' ), [ 'status' => 502 ] ); }
}
```

- [ ] **Step 4: wiring** — `includes()` requires (unconditional, next to the licensing requires at `class-plugin.php:529-534`); `Woodev_Plugins_License::add_hooks()` calls `Woodev_REST_API_License::boot()`. NOT via `Woodev_REST_API` (it stays WC-gated, spec §4.4).
- [ ] **Step 5: run tests → PASS; `composer check` green.**
- [ ] **Step 6: silent-failure-hunter critic → fix → re-critic → commit** `feat(s3): woodev/v1 REST license controller + reusable namespace registrar`.

## Task s6-p3 (model: sonnet): `@wordpress/scripts` scaffold + ADR

**Files:** modify `package.json` (rework stub: `"private": true`, devDependency `@wordpress/scripts` pinned exact, scripts `"build"`/`"start"` per decision #6); create `.nvmrc` (current LTS), `src/license-page/index.js` (minimal mount rendering an empty `<div className="woodev-licenses-grid" />`), `src/license-page/style.scss` (grid skeleton); run `npm install` → commit `package-lock.json`; run `npm run build` → **commit `woodev/assets/build/license-page/`** (`index.js`, `index.asset.php`, `style-index.css`); create ADR `docs-internal/adr/007-react-admin-stack-wordpress-scripts.md` (stack choice, externalized `wp.*` deps, committed-build rationale, S5 foundation) + index line in `adr/README.md`.

- [ ] Verify `.gitignore` already covers `node_modules/` (it does, line 1) — no change needed; verify `index.asset.php` lists `wp-components`/`wp-element`/`wp-api-fetch`/`wp-i18n` style deps and a content-hash version.
- [ ] Verify the committed bundle is reproducible: `npm run build` twice → `git status` clean the second time.
- [ ] `composer check` green (no PHP touched) → critic (light) → **commit** `feat(s3): @wordpress/scripts build scaffold for the license-page app + ADR-007`.

## Task s6-p4 (model: sonnet): React app + enqueue + mount

**Files:** expand `src/license-page/` (`index.js` — apiFetch nonce+root middlewares + `createRoot( document.getElementById( 'woodev-licenses-app' ) ).render( <App /> )`; `app.js` — reads `window.woodevLicenses.plugins`, responsive card grid; `license-card.js` — `Card/CardHeader/CardBody/CardFooter`, status badge pill from `status_label`+`message_variant`, `Notice` (`isDismissible={false}`) with `message`, masked key `••••-…-{last4}` + eye `Button` toggle (client-side only), `TextControl` for key entry, Verify `Button` (`variant="primary"`, `isBusy`), Deactivate `Button` (`isDestructive` + `__experimentalConfirmDialog`), `ToggleControl` beta persisting on change, license-free card = info `Notice` + beta toggle only; per-card state replaced by each REST response); `style.scss` (grid + badge only — lean on `wp-components` styles); rebuild + commit `woodev/assets/build/license-page/`; modify `woodev/admin/class-admin-pages.php`; create `tests/unit/LicensePageRenderTest.php`.

- [ ] **PHP side:** `license_page()` → `echo '<div id="woodev-licenses-app"></div>';` + keep the `get_settings_section()` include (spec §5.3 — unrelated block MAY stay); remove `license_page_init()`'s `add_settings_section` + `license_section_description()` (the React app renders the intro text); `load_licenses_page_scripts()` → `wp_enqueue_style( 'wp-components' )`, enqueue `build/license-page/style-index.css` + `index.js` reading deps/version from `index.asset.php`, `wp_add_inline_script( ..., 'window.woodevLicenses = ' . wp_json_encode( [ 'restRoot' => esc_url_raw( rest_url() ), 'restNonce' => wp_create_nonce( 'wp_rest' ), 'plugins' => $states ] ), 'before' )` where `$states` = `get_state()` for each `Woodev_Plugins_License::get_registered_instances()`; drop the legacy `woodev-license-page.css` enqueue.
- [ ] **Render tests:** `license_page()` outputs `#woodev-licenses-app` and NO `<form`; enqueue reads `index.asset.php` and localizes `restRoot`/`restNonce`/`plugins`; **B-7**: both run with zero WC functions defined (Brain Monkey) → no `Error`.
- [ ] i18n check: no `_n()`/plural in JS or PHP; Russian-source strings count-neutral.
- [ ] Rebuild bundle, `composer check` green → adversarial critic → **commit** `feat(s3): React license-page app (card grid) + mount/enqueue, legacy form removed`.

## Task s6-p5 (model: sonnet): CI build-parity + holistic critic

- [ ] Add `assets` job to `.github/workflows/ci.yml` (independent job, like `lint`): checkout → `actions/setup-node` with `node-version-file: .nvmrc` + npm cache → `npm ci` → `npm run build` → `git diff --exit-code woodev/assets/build/` (fails on stale bundle). Add `src/` + node files to the release-zip excludes review: keep `woodev/assets/build/` IN the zip (it already is — under `woodev/`), add `--exclude='src'` to the release rsync.
- [ ] README: short "Building admin JS" contributor section (`npm ci && npm run build`; bundle is committed; CI enforces parity).
- [ ] `composer check` green → **commit** `ci(s3): assets build-parity job (committed bundle must match src/)`.
- [ ] **Holistic whole-feature critic pass** over s6-p1…p5 (contracts table above + spec §7 test matrix + anti-pirate invariant); fix → re-critic any fixes → final commit(s).

---

## Self-review (against the spec)

- §2.1 removals: form (p4), renderer+sanitize (p1 file delete), admin_init handlers (p1), section/group names (p1+p4). ✓
- §2.1 preserved list → "Contract guard-rails" + p1/p2 parity tests + holistic pass. ✓
- §4.1 ops + state shape + `message_variant` → p1 (incl. the legacy expires-soon bug NOT copied). ✓ §4.2 keep-list → p1 step 4. ✓
- §4.3 routes/permission/schema/no-silent-failure → p2. §4.4 non-WC-gated registration + reusable point (operator s8 requirement) → p2 registrar. ✓
- §5.1 build/commit/gitignore → p3 (gitignore already covers `node_modules/`). §5.2 components incl. masked key+eye, license-free card → p4. §5.3 enqueue + mount + non-WC-gated page → p4. ✓
- §6 CI parity + ADR → p5 + p3. §7 test matrix → p1/p2/p4 test files (JS unit tests skipped per spec — build-parity covers the bundle). ✓
- B-7 (works without WooCommerce) → explicit tests in p2 AND p4. B-12 (`is_active()` three-meanings docblock) → p1 step 5. i18n no-plural-`_n()` → decision #7, checked in p4. ✓
- Out of scope honored: no webhooks, no full WC decoupling, no E2E. ✓

## Related
- Spec: [platform-v2-s3-licensing-ui-spec.md](platform-v2-s3-licensing-ui-spec.md)
- Tracker: [platform-v2-program-tracker.md](platform-v2-program-tracker.md)
- Autodev tasks: `.autodev/queue/pending/s6-p1-*.md` … `s6-p5-*.md`
- ADR-005 (clean break), gotchas `licensing/two-layer`, `i18n/russian-source-plural-n`, `framework/includes-wiring`, `testing/reflection-setaccessible-version-guard`, `testing/brain-monkey-function-pollution`
