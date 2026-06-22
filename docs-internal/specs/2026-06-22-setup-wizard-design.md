# Setup Wizard (OB-10) — Design Spec

> Date: 2026-06-22 (session 29). Status: **approved** (operator approved all design sections during the s29 brainstorm; spec review gate waived). `@since 2.0.2` (version not bumped, 2.0.1 unreleased).
> Supersedes the legacy `Woodev_Plugin_Setup_Wizard` (SkyVerge/GoDaddy server-rendered fork). Clean-break v2: the legacy wizard has **zero live consumers**, so it is removed outright with no data migration.

## 1. Context & Goals

The legacy `Woodev_Plugin_Setup_Wizard` (`woodev/admin/abstract-plugin-admin-setup-wizard.php`, ~1320 lines) is a near-exact fork of the SkyVerge/GoDaddy WooCommerce setup wizard: server-rendered HTML (`wc-setup-*` markup), WC-coupled (`manage_woocommerce` capability, `wc_{id}_setup_wizard_*` hook/option prefixes, `woocommerce_form_field()`), page-reload-per-step, no React. It is also **not instantiated** by the base (`init_setup_wizard_handler()` only `require_once`s the file), and **no shipped plugin or fixture uses it** (all four fixtures stub `init_setup_wizard_handler()` to `{}`).

Goals (operator requirements, s28 prompt + s29 brainstorm):

1. **Zero WC dependency in the base.** Neutral WP base class `Woodev\Framework\Setup\Setup_Wizard`.
2. **WC specifics isolated in a thin subclass** `Woodev\Framework\Setup\Woocommerce_Setup_Wizard`.
3. **Opt-in**: initialized only if the plugin declares an override (null-default getter), triggered on first install via `Woodev_Lifecycle`.
4. **React UI**, modeled on WooCommerce onboarding patterns; data driven by PHP (PHP-driven reusable JS principle).

## 2. Key Decisions (from the brainstorm)

| # | Decision |
|---|---|
| D1 | **Step model:** declarative PHP step descriptors rendered by ONE generic React shell. Plugins write zero JS for standard steps. |
| D2 | **Field data:** reuse the existing neutral Settings API (`Woodev_Abstract_Settings` + `Woodev_Setting`/`Woodev_Control`) as the single source of truth for types/validation/persistence. |
| D3 | **Scope boundary:** the wizard depends only on the neutral Settings API contract. The main plugin settings-page decision (WC_Integration vs a standalone React settings page + migration) stays out of scope (operator's `SHIPPING-PLANS.md`). The wizard is the first real consumer of the neutral Settings API and is not blocked by, nor does it predetermine, that decision. |
| D4 | **Field selection/grouping:** explicit steps referencing setting ids — `register_step(id, label, [setting_id...])` + `register_content_step(...)`. The wizard curates a subset; no `show_in_wizard` flag on the VO (kept as a possible future sugar only). |
| D5 | **First-run:** on `woodev_{id}_installed`, auto-redirect to the wizard exactly once + a notice-fallback until completed/skipped. |
| D6 | **WC wrapper:** thin — `manage_woocommerce` capability, "WC active" gate, guaranteed WC context for step callbacks, and ready-made WC-readiness checks (shipping zones configured, required WC options enabled). Ready-made full WC steps added only when a real consumer needs them (YAGNI). |

## 3. Architecture — Components & Boundaries

| Component | Location | Responsibility | Depends on |
|---|---|---|---|
| `Woodev\Framework\Setup\Setup_Wizard` (abstract) | `woodev/setup/class-setup-wizard.php` | Neutral core: step registry (`register_step`/`register_content_step`), order/navigation, started/completed/skipped state, standalone admin page + React mount, bundle enqueue, capability (`manage_options`, overridable), install listener + redirect + notice. **Zero WC.** | `Woodev_Plugin`, Settings API, REST registrar |
| `Woodev\Framework\Setup\Woocommerce_Setup_Wizard` | `woodev/setup/class-woocommerce-setup-wizard.php` | Thin subclass: capability → `manage_woocommerce`; "WC active" early-return gate; guaranteed WC context for callbacks; reusable WC-readiness check steps. | + WooCommerce |
| `Woodev\Framework\Setup\Step` (VO) | `woodev/setup/class-step.php` | One step descriptor: `id`, `label`, `type` (`settings`/`content`/`check`), `setting_ids[]` or content/callback, optional `on_save`, `is_visible`, `time`. | — |
| `Woodev_REST_API_Setup` | `woodev/rest-api/controllers/class-rest-api-setup.php` | Wizard REST controller registered through `Woodev_REST_V1_Registrar` on the neutral `woodev/v1` namespace. Serves step bootstrap/schema, saves step values, finalizes completion. | Settings API, registrar |
| React shell | `src/setup-wizard/` → built to `woodev/assets/build/setup-wizard/` | Thin: render steps from the PHP bootstrap, render controls by type/control, progress, forward/back navigation, save via REST. Zero domain logic. | `@wordpress/scripts` |

**Boundaries:** the core knows nothing about WC; React knows nothing about the domain (PHP dictates everything); `Woodev_Setting` is the only source of truth for values/types/validation; the wizard shows only a curated subset.

## 4. Step Declaration API

```php
// in the plugin's wizard handler (extends Setup_Wizard or Woocommerce_Setup_Wizard)
protected function register_steps(): void {
    $this->register_content_step( 'welcome', __( 'Добро пожаловать', '...' ), [ $this, 'render_welcome' ] );
    $this->register_step( 'connection', __( 'Подключение', '...' ), [ 'api_key', 'api_secret' ], [ $this, 'on_save_connection' ] );
    $this->register_step( 'delivery',   __( 'Доставка', '...' ),    [ 'default_tariff', 'pickup_enabled' ] );
}
```

- `register_step( string $id, string $label, array $setting_ids, ?callable $on_save = null )` — fields come from the plugin's `Woodev_Abstract_Settings` handler by id.
- `register_content_step( string $id, string $label, callable|string $content )` — welcome/finish/info steps without fields.
- Optional per-step `is_visible(): bool` and `time` (estimate). A `check`-type step (WC wrapper) is a content step whose visibility/status derives from a readiness probe.
- Steps are filterable via `woodev_{id}_setup_wizard_steps`.
- The `on_save` callback is a server-side hook for non-settings side-effects (license connect, page creation). **Contract: `on_save` MUST be idempotent** (settings are persisted first, then `on_save` runs; a thrown `on_save` reports a step error while settings are already saved).

## 5. Data Flow

### REST endpoints (`Woodev_REST_API_Setup` via `Woodev_REST_V1_Registrar`)

```
GET  woodev/v1/{plugin_id}/setup                  → bootstrap: steps + field JSON schema + current values + state + branding + finish actions
POST woodev/v1/{plugin_id}/setup/steps/{step_id}  → validate + persist step values (+ optional on_save)
POST woodev/v1/{plugin_id}/setup/complete         → finalize (server is the authority)
```

### Flows

1. **Boot (PHP → page):** when `get_setup_wizard_handler()` is non-null, the wizard admin page serializes the declaration (steps, JSON schema derived from `Woodev_Setting`, current values, completion state, header logo, finish actions, REST root + `X-WP-Nonce`) via `wp_add_inline_script` into `window.woodevSetupWizard{Id}` (PHP-driven). The React shell reads the bootstrap and renders controls by type/control.
2. **Per-step save (optimistic ≠ persisted):** "Continue" → `POST .../steps/{step_id}` (X-WP-Nonce) → controller validates via `Woodev_Setting::validate_value()` and persists via `Woodev_Abstract_Settings::update_value()/save()`, then runs the optional `on_save` → on `200` React advances; on error React shows field errors and stays.
3. **Completion:** after the last step → `POST .../complete` → sets option `woodev_{id}_setup_wizard_complete = 'completed'` (server-side, never a client flag) → React shows the finish screen with `get_finish_actions()` links.
4. **First-run (server-driven):** `woodev_{id}_installed` (Lifecycle) → set one-shot transient `woodev_{id}_setup_wizard_redirect`. On `admin_init`: if transient set AND not `activate-multi` AND not AJAX/REST/cron AND handler non-null AND not complete → delete transient → `wp_safe_redirect()` to the wizard page → `exit`. Otherwise (e.g. bulk activation) the transient is consumed and the notice-fallback shows.
5. **Notice-fallback + resume:** while not complete → admin notice "Запустить мастер настройки" + a "Setup" link in `plugin_action_links`. The wizard page lands on the first incomplete step (per-step values are already persisted on each Continue).

## 6. State & Data Contracts

All NEW (zero live consumers → no migration; deliberately chosen, contracts going forward):

- Option `woodev_{id}_setup_wizard_complete` with values `'completed'` / `'skipped'` / `''`. Both non-empty values stop the redirect and the notice; the documentation notice ("read the docs") shows only when `'completed'`.
- Transient `woodev_{id}_setup_wizard_redirect` (one-shot).
- Routes `woodev/v1/{id}/setup*`, filter `woodev_{id}_setup_wizard_steps`, the wizard admin page slug.

The legacy `wc_{id}_setup_wizard_complete` option, `wc_{id}_setup_wizard_steps` filter, and old slug are **not** data contracts (no shipped plugin used them) and are dropped. Nothing for the release-blocking preservation list.

## 7. Error Handling & Edge Cases

| Case | Behavior |
|---|---|
| Field validation failed | `Woodev_Setting::validate_value()` → `WP_Error` with per-field messages → React shows field errors, stays on step, values preserved |
| `on_save(step)` threw | Settings persisted first, then `on_save`; on throw → step-level error, settings already saved → `on_save` must be idempotent (re-run safe) |
| Nonce expired (long-open wizard) | 403 `rest_cookie_invalid_nonce` → React shows "session expired, reload" (refetch nonce / reload), no silent loss |
| Direct REST call without caps | `permission_callback` on every route mirrors the page capability (base `manage_options`, WC wrapper `manage_woocommerce`) → 403 |
| handler null / no steps | opt-in: null → nothing registers. handler present but `has_steps()` false → no page/redirect/notice |
| Multiple plugins, bulk install | only the first to consume the transient on `admin_init` redirects; others fall to the notice. Bootstrap + slug are per-plugin (`window.woodevSetupWizard{Id}`) → no collisions |
| WC wrapper, WC inactive | WC wrapper early-returns when WC is not active (the plugin is deactivated by the framework anyway) |
| Resume | lands on the first incomplete step; passed steps' values already persisted |
| Skip → return later | `complete='skipped'` stops redirect+notice, but the wizard stays reachable (plugin action link / settings); re-running and finishing sets `'completed'` |
| Already complete, revisits URL | re-running not blocked; no auto-redirect/notice |
| Bundle failed / JS off | minimal `<noscript>`/fallback message ("enable JS, reload") — no full server-rendered fallback (admin React requires JS anyway) |

**Project gotchas baked in:**
- `esc_url_raw` for all URLs sent to React/JSON (REST root, finish-action URLs, redirect) — not `esc_url` (gotcha `esc-url-raw-for-js-consumed-urls`).
- Count-neutral progress strings — no `_n()` with Russian source (gotcha `russian-source-i18n-plural-n`).
- React build: classic JSX runtime + `createElement`/`Fragment` imports (gotcha `wp-scripts-jsx-runtime-wp66`); LF EOL in `assets/build` (gotcha `build-artifacts-eol-lf-windows-parity`); step styles bundled (lesson `license-page-css-bundle-only`).

## 8. Borrowed Patterns

**From modern WooCommerce onboarding** (patterns, not the dashboard task-list model): dual-registration (PHP metadata + thin JS); state-driven UI from a small `@wordpress/data` store (not ad-hoc local state); optimistic-vs-persisted completion; conditional step visibility (`is_visible`, pairs with WC-readiness checks); anti-fatigue (respect completed/skipped, no duplicate notices); completion switches the notice to "read the docs". **Not borrowed:** dashboard task-list, SlotFill scopes, `@woocommerce/data` onboardingStore.

**From SkyVerge/GoDaddy** (`abstract-sv-wc-plugin-admin-setup-wizard.php`): the `register_step(..., $save_callback)` shape → kept as the optional `on_save`; `get_next_steps()`/`get_additional_actions()` → declarative `get_finish_actions()`; welcome/finish as first-class steps; a "Setup" plugin-action link while incomplete; `get_header_image_url()` branding; a persisted completion option (renamed neutrally); a steps filter (renamed neutrally); nonce on save (REST `X-WP-Nonce`). **Improved over GoDaddy:** React SPA (no per-step reload), neutral base capability/namespace, back navigation, resume.

## 9. Testing Strategy

**Unit (Brain Monkey, no WP):** step registration/validation; neutral-base-has-no-WC-method test (capability default `manage_options`); completion-state read/write; first-install trigger + `admin_init` redirect guard logic (bulk/ajax/rest/complete/null); REST controller permission + validation-delegation + completion; `on_save` ordering (settings persisted before `on_save`); WC wrapper capability + WC-inactive early-return + readiness checks. Isolate function-absent tests with `@runInSeparateProcess` (gotcha `brain-monkey-function-pollution`).

**Integration (wp-env):** routes register under `woodev/v1/{id}/setup*`; save round-trips through `Woodev_Abstract_Settings`; REST capability gate returns 403 — test with an **editor**, not a subscriber (gotcha `wc-blocks-subscriber-wp-admin-403-test`); fixtures mapped per `wpenv-resolver-fixture-mapping`.

**JS:** parity with existing React pages (license-page/plugins-page) — no JS-unit on the shell initially; wizard verified on the rig / in-browser by the operator.

**Update existing:** rewrite `tests/unit/PlatformNeutralSetupWizardTest.php` for the new neutral `Setup_Wizard`; drop the `init_setup_wizard_handler(){}` stubs in the four fixtures (the new opt-in getter is null by default).

## 10. File Layout, Legacy Removal, Build

**New files** (namespaced `Woodev\Framework\Setup\`, under `woodev/setup/`; `class-*.php` filenames per the framework convention; classmap + generated `class-map.php`):

```
woodev/setup/class-setup-wizard.php
woodev/setup/class-woocommerce-setup-wizard.php
woodev/setup/class-step.php
woodev/rest-api/controllers/class-rest-api-setup.php
src/setup-wizard/                       (React entry for @wordpress/scripts)
woodev/assets/build/setup-wizard/       (built bundle, LF EOL)
```

**Base wiring (`Woodev_Plugin`):** keep `get_setup_wizard_handler()` but as opt-in (null default; plugin overrides → `return new Plugin_Setup_Wizard( $this )`, the `get_competitor_notification_handler()` pattern). When non-null, the handler wires its own hooks (install listener, `admin_init` redirect, notice, page, REST). The base only calls the getter early enough.

**Remove (clean-break):** `woodev/admin/abstract-plugin-admin-setup-wizard.php`; the dead `init_setup_wizard_handler()` (require + constructor call) from the base; the `init_setup_wizard_handler(){}` overrides in the four fixtures. Regenerate `woodev/class-map.php` (`php bin/generate-class-map.php`) and add `woodev/setup` to the composer classmap (dev/test only — no Composer in shipped plugins).

**Build/hygiene:** `@since 2.0.2`, version not bumped. React via `@wordpress/scripts`, classic JSX runtime, LF in `assets/build`. Do not touch public `docs/`. Merge: branch → PR → green CI (unit matrix **and** "Run PHPStan") → `--squash --delete-branch` (never `--auto`). Codex review on architecturally-sensitive parts; in non-autonomous mode do not auto-fix findings — ask.

## 11. Implementation Convention

New code is written **directly in namespace notation** (`Woodev\Framework\Setup\*`, PSR-4) and **short array syntax `[]`** (never `array()`) — the whole framework is moving to namespaces, so the wizard is authored there from the start. This is the project default for all new code (see `AGENTS.md` Coding Conventions).

## 12. Out of Scope

- Main plugin settings-page architecture (WC_Integration vs standalone React page + migration of edostavka/yandex/gateways) — operator's `SHIPPING-PLANS.md`.
- `show_in_wizard` VO flag (possible future sugar).
- Ready-made full WC onboarding steps beyond readiness checks (added when a real consumer needs them).
- yandex pilot wizard implementation (happens at plugin rewrite).

## References

- Legacy class: `woodev/admin/abstract-plugin-admin-setup-wizard.php`.
- Settings API: `woodev/settings-api/abstract-class-settings.php`, `class-setting.php`; WC-namespaced controller `woodev/rest-api/controllers/class-plugin-rest-api-settings.php` (`wc/v3` — NOT reused by the neutral base).
- Neutral REST: `woodev/rest-api/class-rest-v1-registrar.php` (`woodev/v1` owner); precedents `class-rest-api-extensions.php`, `class-rest-api-account.php`, `licensing/api/class-rest-api-license.php`.
- Lifecycle install signal: `Woodev_Lifecycle::init()` → `do_action( 'woodev_{id}_installed' )`.
- Precedent module (neutral engine + WC renderer + opt-in null getter): `woodev/competitor/`.
- WC onboarding: <https://developer.woocommerce.com/docs/extensions/extension-onboarding/handling-merchant-onboarding>.
- GoDaddy/SkyVerge wizard: <https://github.com/godaddy-wordpress/wc-plugin-framework/blob/master/woocommerce/admin/abstract-sv-wc-plugin-admin-setup-wizard.php>.
