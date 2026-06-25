# SP-1 — Settings Page Slot (§15) — Design Spec

> **Status:** design LOCKED with operator (interactive brainstorm, 2026-06-26, s33).
> First sub-project of the Shipping Module program (decomposition: `docs-internal/specs/2026-06-25-shipping-module-decisions.md`).
> Builds directly on the s31 Settings-API + Setup-Wizard React pattern. **Framework-only**; validated by a
> test fixture, not a real plugin (real plugins migrate at Phase E pilot).
>
> `@since 2.0.2` — VERSION stays 2.0.1 (in-dev), do **not** bump.

## Goal

A neutral **`Woodev > Настройки`** admin page: a single React slot over the platform-neutral Settings-API,
aggregating **tabs from multiple plugins + framework services** via a registry. Blocking foundation for the
rest of the shipping module (§16, §8/§11 depend on it). Reuses the proven wizard/License-page React pattern
on `woodev/v1`.

## Locked decisions (s33 brainstorm)

| # | Decision | Resolution |
|---|----------|------------|
| 1 | Provider / tab / handler model | **Provider = a `Woodev_Abstract_Settings` handler** (own `id` → own option namespace → own tab → own legacy key → own migration). A plugin contributes **one or more** providers; multi-carrier plugin = multiple tabs. A thin **registry** aggregates. |
| 2 | Storage + legacy migration | **Native per-option storage kept** (`woodev_{id}_{setting_id}`, current model — no new storage abstraction). Legacy single-array key (`woocommerce_{id}_settings`) is the **source of a one-time `Woodev_Lifecycle::upgrade_to_X()` migration** written per-plugin at the Phase-E pilot — NOT a runtime adapter, NOT in SP-1 framework scope. SP-1 only guarantees clean `setting_id` namespaces + the legacy-URL redirect. |
| 3 | §9 service-registry scope in SP-1 | Registry + **one reference provider on a fixture**, **no DaData**. §9 = only the service **seam** (id, schema→tab, hook points). DaData tab lands in SP-4. |
| 4 | REST + React shell | **One aggregated app + one controller** `woodev/v1/settings`, routed by `{provider_id}`. NOT per-provider controllers. |
| 5 | Capability resolution | Base default `manage_options` (neutral, matches hub). **WC-dependent owning plugin → default flips to `manage_woocommerce`.** Explicit per-provider declaration (§4) overrides both. Parent-menu visibility reconciled so the resolved cap actually grants reach. |

## Architecture

```
woodev (hub menu, manage_options)
 ├─ Лицензии        (existing)
 ├─ Плагины         (existing)
 └─ Настройки  ◄── NEW (woodev-settings), appears only when ≥1 provider registered
       └─ one React app  ──GET/POST──►  Woodev_REST_API_Settings (woodev/v1/settings)
                                              │
                                    Settings_Page_Registry  (aggregator, singleton)
                                       ├─ provider: handler + descriptor  (plugin A, tab «СДЭК»)
                                       ├─ provider: handler + descriptor  (plugin A, tab «Почта»)  ← multi-carrier
                                       └─ provider: framework service     (§9 seam, e.g. future DaData)
```

- **Provider = settings-handler (`Woodev_Abstract_Settings`) + presentation descriptor.** The handler owns
  storage/validation (unchanged); the descriptor owns tab metadata (label, section grouping, capability,
  legacy key/url, §4 support flags). Single-purpose separation.
- **`Settings_Page_Registry`** (singleton) aggregates providers; registered on `admin_menu` / `rest_api_init`.
  Menu + schema are built from the registry. Menu registers **only when ≥1 provider** is present (§15).
- **Framework service (§9)** is the same provider abstraction with framework (not a plugin) as owner —
  registered via `Settings_Page_Registry::register_service()` (seam: id, schema→tab, hook points). DaData → SP-4.

## Provider declaration API

- New plugin seam: **`Woodev_Plugin::get_settings_providers(): array`** (default `[]`). Returns descriptors.
  The existing single `get_settings_handler()` stays for the wizard; the plural seam feeds the settings page
  and supports multi-carrier.
- **Descriptor** (working name `Settings_Provider`) carries:
  - `id` (== handler id → option namespace), `label` (tab title), `handler` (instance),
  - **sections:** `setting_ids → sections` grouping (the same primitive that groups setting_ids into wizard
    steps — reused),
  - `capability` (optional; overrides the resolution rule below),
  - `legacy_option_key` + `legacy_page` (redirect source; value migration is the plugin's Lifecycle, not SP-1),
  - **§4 spine:** `supports_*` booleans + config-contracts — stored as a declaration mechanism (final flag
    names settled at the first plugin migration).
- **Framework service** registers the same descriptor through `Settings_Page_Registry::register_service()`.

### Capability resolution

1. Base default: `manage_options`.
2. WC-dependent owning plugin (detected by plugin type: `Woocommerce_Plugin` / shipping / payment-gateway
   base) → default becomes `manage_woocommerce`.
3. Explicit per-provider `capability` declaration overrides both.
4. **Parent-menu coordination:** the `woodev` parent menu is `manage_options` today. When a provider's resolved
   cap is broader-than-admin-only (e.g. a `manage_woocommerce` shop-manager), the settings submenu must remain
   reachable. Resolve in the plan: register the submenu under its resolved cap and verify WP surfaces the
   `woodev` menu to a `manage_woocommerce`-only user (WP promotes the first accessible submenu as the menu
   link when the parent cap is unmet); adjust registration if that does not hold.

## REST + persistence

- **`GET woodev/v1/settings`** → registry returns the schema of all tabs/sections/fields (per-provider
  `get_field_schema()`, same shape as the wizard: controlType / options / value / tooltip / min / max / step).
- **`POST woodev/v1/settings/{provider_id}`** → the tab's values route to that provider's
  `handler->update_value()` (type/option validation + richtext sanitize + numeric coercion already exist in
  the Settings-API). **Per-tab save** (one Save button per tab; on validation error, report which field).
- **Access:** page capability (resolution above) + `wp_rest` nonce; the `{provider_id}` route additionally
  checks that provider's cap.
- Controller registered once (aggregated, not per-plugin) via `Woodev_REST_V1_Registrar::register_controller()`;
  it reads the `Settings_Page_Registry`.

## Frontend (React, woodev/v1, classic JSX)

- One bundle `src/settings-page/` mounted on `woodev-settings`. Same stack as wizard/license
  (`@wordpress/components`, classic JSX runtime `createElement`/`Fragment`, LF in `assets/build`).
- **Layout:** horizontal provider tabs (WC-settings style) along the top; within a tab, sections stacked with
  sub-headings (+ anchored sub-nav when a tab has many sections). Brand tone as s31 (muted cyan `#06aedd`).
- **Controls reused from the wizard** (s31 already built text / dropdown / radio / number / range-slider /
  toggle / richtext / multiselect): extract the shared control components so they are not duplicated between
  `src/setup-wizard/` and `src/settings-page/`.
- Per-tab «Сохранить» with saving / saved / error states; loading skeleton (License-page pattern).

## Migration / back-compat

- **In SP-1 (framework):** the **legacy settings-URL → new-tab redirect** mechanism. A provider declares
  `legacy_page`; the framework, on `admin_init`, intercepts and `wp_safe_redirect`s to
  `?page=woodev-settings&tab={provider_id}`.
- **Value migration** (legacy serialized array → native per-option) is the plugin's domain Lifecycle code at
  the Phase-E pilot — **not** in SP-1. SP-1 only provides clean `setting_id` namespaces for that mapping to
  land in. Migration is idempotent + non-destructive (legacy key kept as a fallback for one version, §19 p.2).

## Testing + cross-cutting

- **Unit:** registry aggregation (tab order / dedup), capability resolution (all 4 rules), descriptor /
  section grouping, REST-save through validation (enum-by-key, richtext sanitize, numeric coercion),
  cap-based tab hiding.
- **Integration:** menu registers when ≥1 provider present, `GET`/`POST woodev/v1/settings`, legacy redirect.
- **Fixture:** a reference provider «Карьер» + a framework-service stub in `tests/_fixtures` (validates the
  whole slot without a real plugin).
- **Cross-cutting (bake in):** run `php bin/generate-class-map.php` in the **same** task after any new
  framework class (else `ClassMapCompletenessTest` reddens); no `_n()` (Russian source — gotcha
  `russian-source-i18n-plural-n`); `@since 2.0.2`, do not bump VERSION; commit built assets (assets-parity CI);
  PHPStan gate = Linux CI (local Windows segfault is environmental); run `composer phpcs` + `composer test:unit`
  locally.

## Scope boundaries (NOT in SP-1)

- ❌ DaData service (SP-4) — only the §9 service seam.
- ❌ Per-method / instance settings — stay in the WC Shipping Zones method modal.
- ❌ Order-meta migration (§19, Phase-E pilot).
- ❌ Final `supports_*` flag names / defaults (settled at first plugin migration).

## Reference (existing code this builds on)

- `woodev/admin/class-admin-pages.php` — hub menu (`woodev` top + `woodev-licenses` / `woodev-extensions`
  submenus), React enqueue pattern (asset manifest + inline bootstrap), capability `manage_options`.
- `woodev/settings-api/abstract-class-settings.php` — `Woodev_Abstract_Settings`: per-option storage
  `woodev_{id}_{setting_id}`, `register_setting` / `register_control`, `update_value` (validate + save),
  `get_value`, bool↔`yes/no`.
- `woodev/settings-api/class-setting.php` / `class-control.php` — setting + control VOs (types, options,
  min/max/step/tooltip, richtext sanitize, enum-by-key validation).
- `woodev/Setup/class-setup-wizard.php` — the reference pattern: `get_field_schema()` (resolves schema from
  `get_settings_handler()`), `register_step(id, label, setting_ids[])` grouping primitive, React shell + inline
  bootstrap, per-plugin REST controller on `woodev/v1/{id}/setup`, `Woodev_REST_V1_Registrar`.
- `src/setup-wizard/` — React controls (s31) to extract + reuse.
