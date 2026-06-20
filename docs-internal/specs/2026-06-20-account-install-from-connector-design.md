# Design — #8 Install plugin from connector (account-based)

> Written s26-part-2 (2026-06-20). Security-critical. Operator approved the design in-session; built autonomously per the overnight-autonomy agreement. Spans two repos: **woodev_framework** (consumer) + **woodev_theme** (woodev.ru issuer / `woodev-account-connector`).

## Goal

Let a user who has connected their woodev.ru account install a purchased plugin
directly from the «Плагины» catalog / «Мои покупки» tab (the button replaces the
«Куплено» badge entry-point), without manually downloading + uploading a zip.

## Decisions (operator-approved, in-session)

1. **Delivery = EDD purchase download link, NOT the `package_download/{token}` URL.**
   `edd_get_download_file_url()` builds `site_url/index.php?eddfile=order_id:download_id:filekey[:price_id]&ttl=…&file=…&token=…`. It is bound to the **order/customer**, not to a domain — exactly the account-ownership model. The `package_download` token (used by the *updater*) hard-requires a license **activated for the downloading domain** (`EDD_SL_Package_Download::parse_url()` → `check_license()` → `site_inactive` otherwise), which the consumer site does not have and which would burn an activation slot. Rejected.
2. **No activation, no license-slot consumption.** Install ≠ license. The user still
   enters/activates a license key separately on the plugin's own «Лицензии» page.
3. **Install only — do NOT auto-activate** the plugin after install. Activating an
   incompatible plugin could WSOD the site; the user activates from the Plugins page.
4. **Download-limit handling (operator):** EDD's per-file download limit exists to stop
   uncontrolled manual downloads. Installing via this flow never puts the zip "in hand",
   so the install path **bypasses** EDD's per-file limit — but only on our **signed**
   path (manual downloads keep their limit). Abuse (connect one account on many sites,
   install unlimited) is instead capped by a connector-side **rate-limit keyed on the
   account** (`customer_id` + `download_id`), not the connection/site.
5. **Free products:** none today; may appear later; not a priority. `/download/{id}`
   is structured so a no-license branch can be added later, but it is not built now.

## Architecture

### A. Connector (woodev_theme — `woodev-account-connector`) — `GET /download/{id}`

New signed route alongside `/purchases`, in `REST_Controller`. Auth reuses the existing
HMAC `authenticate()` (Bearer token + `X-Woodev-Signature` + `X-Woodev-Timestamp`,
±300s freshness). Flow:

1. `authenticate($request)` → connection (or 401). Gives `customer_id`, `site_url`.
2. **Ownership:** resolve the deliverable order item for `(customer_id, download_id)` via
   `Purchases::owned_order_item()` (new; same completed-sale + dedup logic as
   `for_customer()`). `customer_id <= 0` or not owned → **403**.
3. **Rate-limit:** `Download_Throttle::hit( customer_id, download_id )` — transient
   `woodev_account_dl_{customer_id}_{download_id}` counting installs in a rolling window.
   Over the cap → **429**. Default cap + window filterable
   (`woodev_account_download_rate_limit` / `_window`). Keyed on the **account**, so extra
   site connections do not multiply the allowance.
4. **File key:** `EDD_SL_Download::get_upgrade_file_key()` (the SL "upgrade file"),
   fallback to the first `edd_download_files` entry.
5. **URL:** `edd_get_download_file_url( $order_item, $order->email, $file_key,
   $download_id, $price_id )`, then append the signed install marker (see below).
6. Response: `{ package: "<url>" }` (200).

**Limit bypass (signed, install-only):**
- `add_filter('edd_url_token_allowed_params', …)` — register `woodev_install` so the
  marker is covered by EDD's URL token (tamper-proof; absent on normal downloads, so
  their token is unchanged).
- Generate the URL with `woodev_install=1`.
- `add_filter('edd_is_file_at_download_limit', …, 10, 5)` — return `false` when a
  **validated** `woodev_install` marker is present in the request. EDD validates the URL
  token *before* the limit check in `edd_process_download()`, so a forged marker fails
  token validation first. Manual downloads (no marker) keep their limit.

### B. Framework (woodev_framework) — `POST /woodev/v1/account/install`

New route on `Woodev_REST_API_Account` (same `woodev/v1` registrar).

- **Permission:** `current_user_can( 'install_plugins' )` (stricter than the page's
  `manage_options`) + the core REST cookie nonce (apiFetch sends `X-WP-Nonce`).
- **Body:** `download_id` (positive int).
- Flow:
  1. Not connected → `WP_Error` (`not_connected`).
  2. `Woodev_Account_Connection::request( 'GET', '/download/' . $id )` → `{ package }`.
     `WP_Error` / missing `package` → error.
  3. **SSRF guard:** `Woodev_Account_Installer::is_trusted_package_url()` — the package
     host MUST equal the configured store origin (`woodev_extensions_store_url`, default
     `https://woodev.ru`); scheme must be http(s). Any mismatch → reject. The framework
     never follows an issuer-chosen arbitrary URL.
  4. Install via `Plugin_Upgrader` + a quiet skin (`Automatic_Upgrader_Skin`), pulling
     the package URL through WP's own `download_url()` (→ `wp_safe_remote_get`, blocks
     private hosts) into a temp file, then `unzip_file()` into `wp-content/plugins`
     (core handles zip path-traversal). **No `activate_plugin()`.**
  5. Response: `{ installed: true }` or a `WP_Error` with a localized message.

New class `Woodev_Account_Installer` (pure-ish seam): URL trust check + the upgrader
call, so the trust logic is unit-testable without a live upgrader.

### C. UI (`src/plugins-page/`)

- `ExtensionCard` + `PurchasesTab`: where `purchased && ! installed`, render an
  «Установить» button. States: `idle` → `installing` («Установка…», disabled) →
  `done` («Установлено — активируйте на странице плагинов») / `error` (retry).
- Install calls `apiFetch({ path: '/woodev/v1/account/install', method: 'POST',
  data: { download_id } })`.
- **Known nuance (documented):** install is inactive, and the `installed` set is derived
  from *active* plugin instances (an inactive plugin's `download_id` is unknowable until
  it loads). So after a page reload the button returns to «Установить». Per-session
  `done` state is client-side only. Acceptable for MVP.

## Security surface (Codex adversarial-review targets)

- **SSRF** on the package URL (framework must pin the host to the store origin).
- **Ownership** enforcement on the connector (`customer_id` truly owns `download_id`).
- **Capability + nonce** on the framework install route (`install_plugins`).
- **Limit-bypass safety** — marker must be signed (token-covered), not forgeable; manual
  downloads unaffected.
- **Rate-limit** keyed on the account, not the connection.
- **Zip / unpack** — rely on core `unzip_file`; no custom extraction.

## Out of scope

- Auto-activation; license auto-entry on the consumer; free-product delivery path;
  update-from-account (the updater already handles updates for licensed sites);
  bulk install.

## Test plan

- **Connector (woodev_theme):** `Purchases::owned_order_item()` (owned / not-owned /
  guest), `Download_Throttle` (under/over cap, per-account keying), `/download/{id}`
  handler (401 unauth, 403 not-owned, 429 throttled, 200 returns a package URL),
  limit-bypass filters (marker registered, limit false only with marker).
- **Framework:** `Woodev_Account_Installer::is_trusted_package_url()` (same host ok,
  foreign host / scheme / userinfo tricks rejected); install route permission (no
  `install_plugins` → 403), not-connected → error, bad package → error, happy path
  installs without activating (upgrader mocked).
- `composer check` green from the 707 baseline; JS build committed (LF).

## Hygiene

`@since 2.0.2`. Public `docs/` untouched. Framework PR is the deliverable; the connector
change is a separate commit in the outer **woodev_theme** repo (nested woodev-theme repo
untouched). One Codex/GPT-5.5 adversarial-review on the complete diff + re-critic of own
fixes (critic budget ~9% until 06:08). Merge: branch → PR → green CI → `--squash
--delete-branch` (not `--auto`) → resync main.
