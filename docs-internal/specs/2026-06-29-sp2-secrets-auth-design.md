# SP-2 — Secrets + Auth Contract (Settings page) — Design Spec

> **Status:** design LOCKED with operator (interactive brainstorm, 2026-06-29, s37).
> Second sub-project of the Shipping Module program after SP-1 (settings page slot).
> Builds directly on the SP-1 Settings-page registry + `woodev/v1/settings` REST + the s36 UI-kit.
> **Framework-only**; validated by the «Карьер» test fixture, not a real plugin (real plugins
> migrate at the Phase-E pilot).
>
> `@since 2.0.2` — VERSION stays 2.0.1 (in-dev), do **not** bump.

## Goal

Close the **secret-masking hole** and add a **universal authorization contract** to the settings page.

Today `Field_Schema::from_handler()` emits `'value' => $handler->get_value( $id )` for **every** field —
so a password/API-key field's stored secret is shipped to the browser in the `GET woodev/v1/settings`
schema. SP-2 introduces field-level masking, an optional "secret lives in `wp-config`, not the DB" path,
and a **self-contained connection block** with a plugin-implemented test/connect action.

The design holds a hard **mechanism/domain line**: the framework provides *universal interfaces*
(field flags, masking, block presentation, a test/status seam). **How** a carrier authenticates
(token exchange, header construction, GUID handshake, the actual API call) is **domain — it stays in the
plugin**. The framework never touches a carrier API. (Reaffirms the s32 correction: framework =
mechanism + contract + hooks; domain stays in the plugin.)

## Locked decisions (s37 brainstorm)

| # | Decision | Resolution |
|---|----------|------------|
| 1 | Scope boundary | **Secrets mechanism + auth contract** (not secrets-only). |
| 2 | Test-connection / connected-state | **Seam + plugin callback.** Framework declares the block, renders the action button + REST plumbing + masking; the plugin implements the check and owns the meaning of "connected". |
| 3 | Connected-state storage | **On-demand, framework stateless.** «Проверить» runs the callback, result shown ephemerally. A persistent badge comes from an optional plugin `get_connection_status()` (the plugin caches it). No framework-stored connected flag → no stale-flag bugs. |
| 4 | Auth-scheme presets | **Dropped.** "Which fields + how to authenticate" is the plugin's domain. The framework ships **no** named scheme library (`token`/`key_secret`/…). The plugin declares free-form fields. |
| 5 | At-rest encryption | **Out of scope** (key-management overhead). `constant_name` is the supported "keep the secret out of the DB" path. |

## Architecture (delta over SP-1)

SP-1 already provides: `Settings_Page_Registry` (singleton aggregator), `Settings_Provider` (handler +
descriptor), `Settings_Section` (groups `setting_ids`), `Field_Schema::from_handler()`,
`Woodev_REST_API_Settings_Page` (`GET/POST woodev/v1/settings`), and the React `src/settings-page/` app.

SP-2 adds three orthogonal capabilities on top:

1. **Field-level masking** — `sensitive` + `constant_name` flags on `Woodev_Setting`; honored by
   `Field_Schema` (never emit the secret) and `Woodev_Setting::get_value()`/`update_value()`
   (constant precedence + skip-write + preserve-on-unchanged). Reusable for **any** secret, independent
   of the auth block.
2. **Connection block** — a `Settings_Section` flagged `is_connection`; rendered as a self-contained card
   (kit tokens): credential fields + a primary action button + result line + optional status badge.
   A provider may declare **1..N** connection blocks (Russian Post = 2: API creds + widget LK handshake).
3. **Test/status seam** — two narrow optional interfaces a settings handler may implement, routed by
   `connection_id`; a REST action route calls the test callback.

## 1 — Masking: `sensitive` field flag (orthogonal to auth)

- New boolean property `sensitive` on `Woodev_Setting` (default `false`), getter `is_sensitive()`, set via
  the existing `register_setting` path.
- **`Field_Schema::from_handler()`** for a sensitive setting emits `'value' => ''` plus
  `'is_set' => ( '' !== (string) $stored )`. The actual secret **never** reaches the browser.
- **Save semantics** (relies on the existing per-field dirty-tracking — the frontend POSTs only changed
  fields):
  - field **absent** from the POST (untouched) → **preserve** the stored value (do not overwrite);
  - **non-empty** value present → write the new secret;
  - **explicit empty string** present → clear (the user deliberately wiped it).
- The React control for a sensitive field **starts empty**, shows the placeholder «•••••• (сохранено)» when
  `is_set`, and has the kit show/hide eye. The mask is **never** the control's value — otherwise a save
  would persist the asterisks.
- **Clear affordance:** because the control starts empty, an untouched empty control is indistinguishable
  from a deliberate wipe — and dirty-tracking simply does not send an untouched field, so it is preserved.
  Therefore an `is_set` sensitive field needs an explicit «Очистить» affordance that force-sends the empty
  string (the only way the "clear" branch above is reached). Plain typing can only set a new value, never
  clear.

## 2 — `constant_name` override (secret in `wp-config`, not the DB)

- New nullable property `constant_name` on `Woodev_Setting`, getter `get_constant_name()`.
- **`Woodev_Setting::get_value()`** (the VO the handler reads through): if `constant_name` is set and
  `defined( $constant_name )` → return the constant's value (**precedence over the stored option**).
  Otherwise the normal per-option path.
- **`update_value()`**: if the constant is defined → **skip-write** (the field is code-managed; nothing is
  written to the DB).
- **`Field_Schema`** for a constant-backed field: `'value' => ''`, `'is_set' => true`,
  `'constant_managed' => true`, `'constant_name' => 'NAME'`. React renders it **read-only** with the note
  «Задано в wp-config: `NAME`», excluded from save.
- A constant-backed field is **always masked**, regardless of the `sensitive` flag (the constant's value is
  never emitted either).
- Rationale: for high-sensitivity secrets the site operator puts `define( 'MY_CARRIER_API_KEY', '…' )` in
  `wp-config.php`; the secret never enters the DB / dumps / backups. The plugin marks the field
  `constant_name`; the rest is framework mechanism.

## 3 — Connection block (self-contained) + test/status seam

### Declaration

- `Settings_Section` gains an `is_connection` flag and connection metadata (working shape:
  `Settings_Section::create( $id, $label, $setting_ids, $description, $is_connection = false, $action_label = '' )`
  — final signature settled in the plan). A connection section may contain **zero or more** fields:
  - **credential block** — fields (each optionally `sensitive`/`constant_name`) + a «Проверить» action;
  - **handshake block** — **no input fields** + a «Подключить» action (e.g. Russian Post widget LK: the
    callback fetches and stores a GUID itself). Same mechanism, different button label.
- A provider declares **1..N** connection sections (multiple cards render, one per block).

### Seam (plugin callback — all behavior here)

Two narrow optional interfaces (ISP — a handler opts into each independently):

```php
interface Woodev_Settings_Connection_Test {
    public function test_connection( string $connection_id, array $values ): Woodev_Connection_Result;
}

interface Woodev_Settings_Connection_Status {            // optional — drives the persistent badge
    public function get_connection_status( string $connection_id ): ?Woodev_Connection_Result;
}
```

- `Woodev_Connection_Result` — small VO: `is_success(): bool`, `get_message(): string`; static
  constructors `success( string $message = '' )` / `failure( string $message )`.
- The framework renders the action button **only** when the handler implements
  `Woodev_Settings_Connection_Test`. The on-load status badge renders **only** when it implements
  `Woodev_Settings_Connection_Status`.
- `connection_id` routes a single handler across its multiple blocks.
- The carrier's auth mechanics — OAuth/token exchange (CDEK), `Basic base64(login:password)` + token header
  (Russian Post API), bearer token (Yandex), GUID handshake (Russian Post widget LK) — live **inside** the
  plugin's `test_connection()` and its request code. The framework supplies only the form shape, masking,
  block presentation, and the action seam.

## 4 — REST + frontend

### REST

- New action route: **`POST woodev/v1/settings/{provider_id}/connection/{connection_id}/test`** (exact path
  vs a `connection_id` body param settled in the plan). Body = the block's **current (unsaved)** field
  values, so the user can test before saving.
- **Masking vs test — server-side merge:** for an untouched sensitive field the browser does not hold the
  secret. The endpoint therefore merges per field: use the POSTed value when present, else fall back to the
  stored `get_value()`. So «Проверить» works whether or not the user just re-typed the secret.
- Access: provider capability (SP-1 resolution) + `wp_rest` nonce (same gate as save). Calls
  `$handler->test_connection( $connection_id, $merged )` → `{ success, message }`.
- `get_connection_status( $connection_id )` (when implemented) is folded into the per-block payload of
  `GET woodev/v1/settings` so the badge renders on load.
- The connection route is registered through the existing aggregated controller
  (`Woodev_REST_API_Settings_Page` / `Woodev_REST_V1_Registrar`), not a new per-plugin controller.

### Frontend (`src/settings-page/`, React on the kit)

- **Sensitive control:** password input, starts empty, placeholder «•••••• (сохранено)» when `is_set`,
  kit show/hide eye; empty = untouched (dirty-tracking does not send it).
- **Constant-managed control:** read-only, note «Задано в wp-config: `NAME`», excluded from save.
- **Connection card:** kit-token card grouping the block's fields + the primary action button
  (configurable label «Проверить»/«Подключить») + an ephemeral result line (success/fail message) +
  the optional status badge. One card per connection block.

## 5 — Testing + fixture

**Unit:**
- `Field_Schema`: sensitive → `value=''` + correct `is_set`; constant-managed → `value=''`,
  `constant_managed=true`, `constant_name` carried, secret never leaked.
- `Woodev_Setting::get_value()`: constant precedence over the stored option; `update_value()`: skip-write
  under a defined constant + preserve-on-unchanged + clear-on-empty.
- `Woodev_Connection_Result` VO (`success()`/`failure()`/`is_success()`/`get_message()`).
- Resolution of "render test/status?" from the handler's implemented interfaces.

**Integration:**
- `POST …/connection/{id}/test`: merges stored secrets for untouched fields → calls the callback →
  `{ success, message }`; capability/nonce gate (incl. a forbidden-cap case).
- `GET woodev/v1/settings`: carries connection blocks (per `connection_id`) + status (when implemented).
- Constant override end-to-end: `define()` in the test → `get_value()` returns the constant; save does not
  overwrite it.

**Fixture:** extend the «Карьер» provider:
- a connection section with `api_key` (sensitive) + `endpoint` + a `constant_name`-backed field; a stub
  `test_connection()` returning deterministic success/failure by value; an optional `get_connection_status()`;
- a second **handshake** section with no fields and a «Подключить» stub — to validate 1..N blocks and both
  flavors without a real plugin.

## Cross-cutting (bake into the plan)

- Run `php bin/generate-class-map.php` in the **same** task after adding the new framework classes (the two
  interfaces + `Woodev_Connection_Result` VO) — else `ClassMapCompletenessTest` reddens (no Composer in
  shipped plugins).
- No `_n()` (Russian is the source — gotcha `russian-source-i18n-plural-n`).
- `@since 2.0.2`; do **not** bump VERSION. Commit built assets (assets-parity CI). CSS versioned by
  `filemtime`. LF in `assets/build`.
- Edit existing source files with the built-in `Edit` tool, not Serena `replace_content` (EOL-flip gotcha).
- Local gates: `composer phpcs` + `composer test:unit` + JS build. PHPStan gate = Linux CI «Lint»
  (local Windows segfault is environmental).
- Independent critic pass (GPT-5.5 / Codex inline-bundle) + re-critic own in-place fixes before commit.

## Scope boundaries (NOT in SP-2)

- ❌ At-rest secret encryption (overhead; `constant_name` covers "not in the DB").
- ❌ Carrier-specific auth logic (token exchange / header building / GUID fetch / the real API call) — the
  plugin's domain; the fixture only stubs `test_connection()`.
- ❌ Auth-scheme preset library (`token`/`key_secret`/`login_password_token`…) — the plugin declares its own
  free-form fields.
- ❌ Persistent framework-stored connected-state (stateless; the plugin caches via `get_connection_status()`).

## Reference (existing code this builds on)

- `woodev/settings-page/class-field-schema.php` — `Field_Schema::from_handler()`: the masking hole
  (`'value' => $handler->get_value( $id )` for every field). Where masking lands.
- `woodev/settings-api/class-setting.php` — `Woodev_Setting` VO (`get_value`/`update_value`/`get_control`);
  where `sensitive` + `constant_name` live and where constant precedence + skip-write land.
- `woodev/settings-api/class-control.php` — `Woodev_Control` (`TYPE_PASSWORD` already exists; the control
  type stays — `sensitive` is the orthogonal masking flag).
- `woodev/settings-api/abstract-class-settings.php` — `Woodev_Abstract_Settings::get_value()` reads through
  `$setting->get_value()`; `update_value()` writes per-option `woodev_{id}_{setting_id}`.
- `woodev/settings-page/class-settings-section.php` — `Settings_Section::create()`; gains `is_connection` +
  action metadata.
- `woodev/settings-page/class-settings-provider.php` / `class-settings-page-registry.php` — provider
  descriptor + aggregator (SP-1).
- SP-1 spec `docs-internal/specs/2026-06-26-sp1-settings-page-design.md` (the surface this extends).

## Related
- `docs-internal/specs/2026-06-26-sp1-settings-page-design.md` (SP-1 — the settings page this extends)
- `docs-internal/specs/2026-06-25-shipping-module-decisions.md` (§5 secrets/auth context; program map)
- `docs-internal/specs/2026-06-27-ui-kit-design.md` (the kit the connection card is built on)
- gotchas: `russian-source-i18n-plural-n`, `framework-classmap-autoload-vendored-boot`,
  `serena-replace-content-eol-flip`, `build-artifacts-eol-lf-windows-parity`,
  `settings-api-control-save-path-pitfalls`
