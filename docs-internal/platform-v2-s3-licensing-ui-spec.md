# Platform v2 — S3.2 Licensing: modern license-page UI (spec)

> Stage S3 sub-stage 2. Written 2026-06-10 (session 6). Status: **approved design, ready for writing-plans**.
> Decomposition reminder: S3 = (1) `is_need_license` flag + authority seam [merged, PR #25], (2) **modern license-page UI [this spec]**, (3) built-in webhooks §3.4.1.
> Companion: sub-stage-1 spec `platform-v2-s3-licensing-need-license-spec.md` (the L1/L2 model + the `is_need_license()` presentation flag this UI honors).

## 1. Problem & intent (PLANS §3.4 "UI страницы лицензий")

The "Woodev → Licenses" page is today a plain WordPress Settings API form (`<input>` + full-page
`options.php` POST). PLANS §3.4 asks to make it **maximally interactive and modern**. PLANS §6 (UI)
mandates: when the admin goes reactive, use **WordPress' built-in React** (`@wordpress/element` /
`@wordpress/components`), **not** a separate standalone ReactJS project. Operator steer (2026-06-10):
lean **maximally on `@wordpress/components`** — do not invent a bespoke design; the native WP UI is
good and matches the rest of admin.

This is the **first React surface of the framework**. The toolchain chosen here becomes the
foundation for stage S5 (React admin UI). Treat it as setting the pattern, not a one-off.

## 2. Scope decisions (resolved with operator, 2026-06-10)

| # | Decision | Choice |
|---|----------|--------|
| D-1 | Authoring/build toolchain | **`@wordpress/scripts` + JSX** (webpack build; `@wordpress/*` + React externalized to `wp.*` via the bundled DependencyExtractionWebpackPlugin). ADR-worthy: framework React-admin stack. |
| D-2 | Data transport | **REST** + `wp.apiFetch`. New additive routes, namespace **`woodev/v1`**, registered on core `rest_api_init` (NOT WooCommerce-gated). |
| D-3 | Legacy Settings form / back-compat | **Drop it** (clean-break internal — ADR-005). No `<noscript>` fallback, no two-renderer overhead. wp-admin is never JS-disabled in practice; WP core admin is itself blank without JS. |
| D-4 | Stored-data contract | **Preserved byte-for-byte, no migration.** REST reads/writes the *same* option keys the old form did — there is nothing to translate. |
| D-5 | Layout | **Card grid** (adaptive 2–4 columns; each plugin = one `Card`). |
| D-6 | License-key display | `get_state()` returns the **full** key (admin-only `manage_options` context; key already in the admin's DB). React renders it **masked** with an **eye toggle** to reveal — client-side only, no extra request. |

### 2.1 What "drop the legacy form" means precisely

**Removed (internal plumbing — free to break on v2 per ADR-005):**
- the `<form action="options.php">` render in `Woodev_Admin_Pages::license_page()`;
- `Woodev_Woocommerce_License_Settings::register_license_settings()` / `do_license_fields()` and the
  `add_settings_section()` in `Woodev_Admin_Pages::license_page_init()`;
- the `register_setting()` sanitize callback;
- the `admin_init` POST handlers `Woodev_Plugins_License::activate_license()` /
  `deactivate_license()` and their `option_page === 'woodev_license_fields_group'` + `{id}-nonce`
  gating (the form nonce dies with the form; REST uses the `wp_rest` nonce instead);
- the settings group/section/page names `woodev_license_fields_group` / `woodev_licenses_section` /
  `woodev_licenses_page` (registration-time only; not stored anywhere).

**Preserved byte-for-byte (RELEASE-BLOCKING — installed-site data / external contracts):**
- option keys: `woodev_{id}_license` (serialized `Woodev_License`), `woodev_{id}_license_key`,
  `woodev_{id}_beta_version`; activation state / instance ids inside the license object;
- EDD `edd_action` API contract (`activate_license` / `deactivate_license` / `check_license` /
  `get_version`) + endpoint `https://woodev.ru/`;
- public hooks `woodev_license_saved` / `woodev_license_deleted` / `woodev_enable_license_logging`;
- admin page slug `woodev-licenses` (bookmarkable URL) and its menu registration;
- transient `woodev_extensions`; constant `WOODEV_LICENSE_DEBUG`.

> No DB migration is required: stored license data is identical before and after. The only thing that
> changes is the *transport* (REST instead of `options.php` POST) and the *renderer* (React instead of
> the Settings form). The same `Woodev_License::save()/delete()/update()` writes run underneath.

## 3. Architecture — three layers

```
┌──────────────────────────────────────────────────────────────┐
│ React app (src/license-page, @wordpress/scripts + JSX)       │
│  Card grid → wp.apiFetch → /woodev/v1/licenses/{id}/...       │
└───────────────┬──────────────────────────────────────────────┘
                │ REST (X-WP-Nonce, manage_options)
┌───────────────▼──────────────────────────────────────────────┐
│ Woodev_REST_API_License  (namespace woodev/v1, rest_api_init) │
│  verify · deactivate · beta · GET state                       │
└───────────────┬──────────────────────────────────────────────┘
                │ calls transport-agnostic operations
┌───────────────▼──────────────────────────────────────────────┐
│ Woodev_Plugins_License — pure operations (single writer)      │
│  activate() · deactivate() · set_beta_enabled() · get_state() │
│  → Woodev_License::save()/delete()  (unchanged option keys)   │
└──────────────────────────────────────────────────────────────┘
```

The **pure operations are the single source of truth for writes**; REST is the only transport. Because
they perform the same `dispatch()` + `Woodev_License` writes the old `admin_init` handlers did, the
stored-data contract is preserved with zero special-casing.

## 4. PHP layer

### 4.1 `Woodev_Plugins_License` — extract transport-agnostic operations

New public methods (no `$_POST`/REST awareness):

```php
/** Activate a key against the store; persists + returns the new state. @since 2.0.0 */
public function activate( string $license_key ): array;   // throws Woodev_Plugin_Exception on transport/validation failure

/** Deactivate the current key; clears stored license + returns the new state. @since 2.0.0 */
public function deactivate(): array;                       // throws on transport failure

/** Persist the beta opt-in (writes the woodev_{id}_beta_version option). @since 2.0.0 */
public function set_beta_enabled( bool $enabled ): void;

/** Structured license state for the React app + REST responses. @since 2.0.0 */
public function get_state(): array;
```

`get_state()` shape (the React contract):

```php
[
  'plugin_id'       => '216',                 // (string) get_download_id()
  'plugin_name'     => 'WooCommerce Edostavka',
  'license_key'     => 'A1B2-C3D4-...',       // full key (masked client-side); '' when none
  'status'          => 'valid',               // raw EDD status token, or '' 
  'status_label'    => 'License is valid',    // get_license_status() localized
  'message'         => '...',                 // Woodev_License_Messages::get_message() (already-built HTML/text)
  'message_variant' => 'success',             // success | warning | error | info  (maps the current CSS status buckets)
  'expires'         => '2027-03-12',          // raw 'lifetime' | date | ''
  'is_valid'        => true,                  // is_license_valid()
  'is_active'       => true,                  // is_active()
  'is_need_license' => true,                  // Woodev_Plugin::is_need_license() (presentation flag)
  'beta_enabled'    => false,
]
```

- **`message_variant`** centralizes the status→bucket mapping that the legacy CSS did inline
  (`woodev-licenses-status-*`): `expired`/`error`/`invalid`/`missing`/`site_inactive`/`item_name_mismatch`
  → `error`; `expires-soon` → `warning`; `valid` → `success`; else → `info`. Encode it once in PHP so
  React and any future consumer share it.

**Anti-pirate invariant (carried from sub-stage 1):** `is_valid`/`is_active` come from
`is_license_valid()`/`is_active()`, which depend only on `is_license_required()` (server authority) —
**never** on `is_need_license()`. A license-free card (`is_need_license=false`) renders the "not
required" state but its enforcement booleans still reflect real license state. See gotcha
`licensing/two-layer`.

**License-free behavior:** when `is_need_license() === false`, `activate()`/`deactivate()` are no-ops
that return the current `get_state()` (the card shows "license not required"; no key controls). This
replaces the old early-returns in the `admin_init` handlers.

### 4.2 Remove the legacy transport

Delete the `admin_init` activate/deactivate handlers, the sanitize callback, and the WC license
settings field renderer (§2.1). Keep `notices()`, `plugin_row_license_missing()`, `validate_license()`,
`weekly_license_check()` and all read accessors (`is_license_valid`, `is_active`, `is_expired`, …)
untouched — they remain L1 presentation / L2 enforcement surfaces unrelated to the page transport.

### 4.3 REST controller `Woodev_REST_API_License`

New file `woodev/licensing/api/class-rest-api-license.php` (cohesive with the existing
`woodev/licensing/api/` layer), registered from the licensing subsystem on `rest_api_init` (core, not
WC-gated):

| Method + route | Body | Action |
|----------------|------|--------|
| `POST /woodev/v1/licenses/{plugin_id}/verify` | `{ license_key }` | `activate($key)` → state |
| `POST /woodev/v1/licenses/{plugin_id}/deactivate` | — | `deactivate()` → state |
| `POST /woodev/v1/licenses/{plugin_id}/beta` | `{ enabled: bool }` | `set_beta_enabled()` → state |
| `GET  /woodev/v1/licenses/{plugin_id}` | — | `get_state()` (refresh) |

- `{plugin_id}` = the EDD download id (`get_download_id()`), `[\w-]+` constrained; resolves the right
  registered plugin's `Woodev_Plugins_License` instance via the bootstrap registry.
- **Permission callback:** `current_user_can( 'manage_options' )`. REST nonce (`wp_rest`) verified by
  core via the `X-WP-Nonce` header that `wp.apiFetch` sends.
- **Schema:** typed args (`license_key` string, `enabled` boolean) with `validate_callback` /
  `sanitize_callback`. Every endpoint returns the `get_state()` array (or `WP_Error` with a
  user-facing `message`).
- Errors (transport down, invalid key) → `WP_Error( 'woodev_license_*', $message, [ 'status' => 4xx ] )`;
  the app surfaces `$message` in a `Notice status="error"`. **No silent failure** — every catch maps to
  an explicit error response (silent-failure-hunter gate).

### 4.4 `register_routes` wiring

`Woodev_REST_API::register_routes()` is WC-gated (WC REST). The license controller must work for
WC-agnostic admin, so register it **independently** from the licensing subsystem's own
`add_hooks()` on `rest_api_init`, not through `Woodev_REST_API`. (Side benefit: the licensing UI/REST
layer becomes WC-agnostic — a small down-payment on §6 decoupling. Full pure-WP licensing stays out of
scope.)

## 5. JS layer (`@wordpress/scripts` + JSX)

### 5.1 Build setup
- `package.json`: devDependency `@wordpress/scripts`; scripts `"build": "wp-scripts build"`,
  `"start": "wp-scripts start"`. Entry `src/license-page/index.js`.
- Output committed to `woodev/assets/build/license-page/`: `index.js`, `index.asset.php`
  (`['dependencies' => [...], 'version' => '<hash>']`), `style-index.css`.
- `.gitignore`: add `node_modules/`. **Commit** `woodev/assets/build/` (vendored consumers get the
  prebuilt bundle without running node).

### 5.2 App
- Components: `Card`, `CardHeader`, `CardBody`, `CardFooter`, `Flex/FlexBlock/FlexItem`,
  `TextControl` (stable; the key input), `Button` (`variant`, `isBusy`, `isDestructive`),
  `Notice` (`isDismissible={false}`), `ToggleControl`, `Spinner`, a status badge (small pill).
- State: top-level list comes from localized initial state; per-card local state for input value,
  busy, reveal-key toggle, and the live `state` object returned by each REST call.
- Actions via `wp.apiFetch`: Verify (`isBusy` on the button + `Spinner`), Deactivate (destructive,
  `__experimentalConfirmDialog`/`Modal` optional), Beta `ToggleControl` (persists on change).
- License-free card (`is_need_license=false`): render only the `Notice status="info"` "license not
  required" + beta toggle; no key controls.
- Key field: masked (`••••-••••-…-XXXX`) with an eye `Button` icon toggling full reveal client-side.
- i18n via `@wordpress/i18n` (`__`), text domain `woodev-plugin-framework`.

### 5.3 Enqueue + page render
- `Woodev_Admin_Pages::license_page()` renders **only** `<div id="woodev-licenses-app"></div>`
  (drop the form). The `get_settings_section()` documentation block below MAY stay (unrelated).
- `load_licenses_page_scripts()`:
  - `wp_enqueue_style( 'wp-components' )` (native component styles) + the framework's small
    `style-index.css`;
  - `wp_enqueue_script( 'woodev-license-app', build/index.js, $asset['dependencies'], $asset['version'], true )`
    reading deps/ver from `index.asset.php`;
  - inline bootstrap data via `wp_add_inline_script`:
    `window.woodevLicenses = { restRoot, restNonce, plugins: [ get_state() for each registered Woodev plugin ] }`.
- The menu/page is base (`Woodev_Admin_Pages`), so the modern UI is **not** WC-gated.

## 6. Build / release / CI
- `woodev/assets/build/` is committed. Add a CI job `assets` to `ci.yml`: `npm ci && npm run build`,
  then fail if `git diff --exit-code woodev/assets/build/` is dirty — guarantees the committed bundle
  matches `src/` (no stale-bundle drift). Document the `npm run build` step for contributors.
- ADR: "Framework React-admin stack = `@wordpress/scripts` + JSX; `@wordpress/*` externalized to
  `wp.*`; build output committed." Foundation for S5.

## 7. Testing (Brain Monkey, unit)
- **Parity / writes:** `activate()` writes the same `woodev_{id}_license` / `_license_key` the old
  `activate_license()` path wrote (assert on the `Woodev_License::save()` payload + option writes);
  `deactivate()` deletes identically; `set_beta_enabled(true/false)` writes/deletes
  `woodev_{id}_beta_version` exactly as the old sanitize callback did.
- **`get_state()`** shape + `message_variant` bucket mapping for each status token.
- **Anti-pirate:** `is_valid`/`is_active` in `get_state()` are unaffected by `is_need_license()`
  (false flag + no verified claim → still the paid-license outcome).
- **License-free:** `activate()`/`deactivate()` are no-ops returning current state when
  `is_need_license()===false`.
- **REST:** permission callback rejects without `manage_options` / bad nonce (`rest_forbidden`/401-403);
  happy-path verify/deactivate/beta invoke the matching pure op once and return its state; schema
  rejects malformed bodies; transport failure → `WP_Error` with a `message` (no silent success).
- **Render:** `license_page()` outputs the `#woodev-licenses-app` mount point and no `<form>`.
- JS is unit-tested only insofar as `wp-scripts test-unit-js` is cheap to wire; otherwise the bundle is
  exercised via the build-parity CI job. (No browser E2E in this sub-stage.)

## 8. Release-blocking contracts — preserved byte-for-byte
Per §2.1 "Preserved": option keys, EDD `edd_action` contract + endpoint, public hooks, slug
`woodev-licenses`, transient `woodev_extensions`, constant `WOODEV_LICENSE_DEBUG`. **No migration.**
**Additive:** REST namespace `woodev/v1` + license routes; `Woodev_Plugins_License::activate()` /
`deactivate()` / `set_beta_enabled()` / `get_state()`; `woodev/assets/build/license-page/*`;
`package.json`.

## 9. Autodev decomposition (atomic tasks; each worker → adversarial critic → commit)
1. **`s6-p1`** — `Woodev_Plugins_License`: extract `activate()`/`deactivate()`/`set_beta_enabled()`/
   `get_state()` (+ `message_variant` mapping); **delete** the `admin_init` POST handlers, sanitize
   callback, and WC settings-field renderer; parity + anti-pirate + license-free unit tests.
   *(contract-adjacent → mandatory adversarial critic on byte-for-byte option writes.)*
2. **`s6-p2`** — `Woodev_REST_API_License` controller + `woodev/v1` registration on `rest_api_init`;
   permission/schema/no-silent-failure tests. *(silent-failure-hunter critic.)*
3. **`s6-p3`** — `@wordpress/scripts` scaffold: `package.json`, `src/license-page/`, build pipeline,
   committed `build/` artifacts, `.gitignore` `node_modules/`, ADR.
4. **`s6-p4`** — React app (card grid, `@wordpress/components`, apiFetch actions, masked-key+eye,
   license-free card) + enqueue + `license_page()` mount; render test.
5. **`s6-p5`** — CI `assets` build-parity job in `ci.yml`; holistic whole-feature critic pass.

## 10. Out of scope
- Built-in webhooks §3.4.1 (sub-stage 3 — reuses the §4 Ed25519 primitive).
- Full pure-WP (non-WC) licensing decoupling (§6) — only the UI/REST layer is made WC-agnostic here.
- Browser E2E / visual regression tests.

## Related
- [platform-v2-s3-licensing-need-license-spec.md](platform-v2-s3-licensing-need-license-spec.md) — sub-stage 1 (L1/L2 model, `is_need_license`)
- [platform-v2-program-tracker.md](platform-v2-program-tracker.md) — S3 stage
- [PLANS.md](../PLANS.md) §3.4 (UI) / §6 (built-in WP React)
- CLAUDE.md → Backward Compatibility (clean-break vs installed-site data contracts), ADR-005
- gotcha `licensing/two-layer` — the L1/L2 naming trap (anti-pirate invariant)
