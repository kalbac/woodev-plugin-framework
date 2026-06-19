# Account-connection client (framework-side) — design

> Written s23 (2026-06-19). Phase-B follow-up to the OB-7 «Плагины» redesign.
> Spec for the framework-side `Woodev_Account_Connection` client + connected-state
> UI + installed-plugin badges. Grounds the live handshake against the already-built
> `woodev-account-connector` (woodev_theme, s22).

## Goal

Bring the «Плагины» catalog's account-connection UI to life: let a store admin
connect their woodev.ru account via an OAuth-style handshake, show the connected
state (avatar + display name + disconnect), and flag which catalog products are
already installed. This unblocks (later) "my purchases" and install-from-connector.

## Scope (s23 slice — operator decisions)

**In scope (MVP + #5):**

- Framework client `Woodev_Account_Connection`: connect → authorize → access_token
  exchange → storage → request signing → `/oauth/me` → disconnect.
- Filterable store base URL (rig prerequisite).
- Connect-init / connect-return page handlers (OAuth redirects) + a REST
  disconnect route.
- UI **#6** (connect dropdown), **#9** (connected state: avatar + name + disconnect),
  **#5** (installed-plugin badges).
- Connector change: **one line** — add `avatar` to `/oauth/me` (operator approved).
- End-to-end rig verification against the live connector; then flip the
  `woodev_extensions_account_enabled` flag.

**Deferred (later sessions):**

- **#7** "Мои покупки" tab (+ connector `/purchases` order history).
- **#8** install-from-connector (+ connector `/download/{id}` with EDD SL package URL,
  `WP_Upgrader`). Security-critical; its own session.

## Data-contract preservation (clean-break policy)

- New option key **`woodev_account_data`** — no existing installed-site contract, so
  free to design.
- The connector's REST routes, signature contract, and table are **installed-site
  data contracts on the woodev.ru side** — the client must speak them byte-for-byte;
  it must NOT require connector contract changes beyond the approved 1-line avatar add.
- New filter names (`woodev_account_api_url`) are new public hooks → `@since 2.0.2`.

## Grounding — the live connector contract (verified s23, read from source)

Plugin: `woodev_theme/plugins/woodev-account-connector`, namespace `woodev-account/v1`.

| Route | Method | Auth | Request | Response |
|---|---|---|---|---|
| `/oauth/request_token` | POST | open | `{home_url, redirect_uri}` (same-origin enforced) | `{secret}` |
| `/oauth/authorize` | GET/POST | woodev.ru login | browser screen | redirects to `redirect_uri?request_token=…` (approve) or `?woodev_account_denied=1` (deny) |
| `/oauth/access_token` | POST | signed w/ `secret` | `{request_token, home_url}` | `{access_token, access_token_secret, site_id}` |
| `/oauth/me` | GET | signed w/ token | — | `{name, email}` → **+ `avatar` (this spec)** |
| `/oauth/invalidate_token` | POST | signed w/ token | — | `{success: true}` |
| `/purchases` | GET | signed w/ token | — | `{purchases: [...]}` (deferred UI) |

**Signature contract (`Signer::sign`)** — `hash_hmac('sha256', json, key)` where
`json = wp_json_encode([host, request_uri, method (UPPERCASED), body, timestamp])`,
**in that key order**. The client must reproduce it byte-for-byte.

**Signed-request headers** the connector reads:

- `Authorization: Bearer <access_token>` (resource requests; not on access_token exchange).
- `X-Woodev-Signature: <hex hmac>`.
- `X-Woodev-Timestamp: <unix ts>` — signed (part of payload) AND freshness-checked on
  resource requests (`abs(now - ts) <= 300`). Not freshness-checked on access_token,
  but still part of the signed payload, so it must be sent and must match.

**Canonical fields as the connector's server sees them:**
`host = $_SERVER['HTTP_HOST']`, `request_uri = $_SERVER['REQUEST_URI']`,
`method = WP_REST_Request::get_method()`, `body = WP_REST_Request::get_body()`,
`timestamp = X-Woodev-Timestamp header`.

## Architecture

### `Woodev_Account_Connection` (`woodev/account/class-account-connection.php`)

Legacy-prefixed (`Woodev\Framework\*` is for newer subsystems; this mirrors the
licensing classes which are legacy-prefixed). Single responsibility: own the
handshake + signed transport + stored state. Public surface:

- `get_connect_url(): string` — the connect-init admin URL (nonce'd).
- `handle_connect_init(): void` — page handler: opens handshake, redirects to authorize.
- `handle_connect_return(): void` — page handler: verifies nonce, exchanges token, stores.
- `is_connected(): bool`.
- `get_account(): array` — `{connected, name, email, avatar, url}` for the UI/bootstrap.
- `disconnect(): bool` — signed invalidate + clear local state.
- `request( string $method, string $path, array $body = [] ): array|WP_Error` —
  signed transport used by `/oauth/me`, disconnect (and later purchases).

Private: `sign_request()`, `canonical_for( $url, $method, $body, $timestamp )`,
`store_auth()`, `get_auth()`, `clear()`, `api_base()`.

A small `Woodev_Account_Signer` static helper (mirrors the connector's `Signer`)
keeps the HMAC payload construction in one pure, unit-tested place.

### Storage — option `woodev_account_data`

```
[
  'auth' => [ access_token, access_token_secret, site_id, url, user_id, updated ],
  'auth_user_data' => [ name, email, avatar ],
]
```

Plus a short-lived transient `woodev_account_handshake` holding `{secret, redirect_uri}`
between connect-init and connect-return (TTL ~15 min, single-use, deleted on return).

### Filterable store base URL

```php
// Default https://woodev.ru; rig points this at the issuer (:8090).
$base = apply_filters( 'woodev_account_api_url', 'https://woodev.ru' );
```

Mirrors the licensing `woodev_license_base_url` filter. The catalog
`PRODUCTS_URL`/`CATEGORIES_URL` get the same treatment (filter
`woodev_extensions_store_url`) so the whole store side can be repointed for the rig.

### Handlers — page-load, query-flag triggered

OAuth needs full-page server redirects (not AJAX). Both handlers hook the extensions
admin page load (`load-{extensions page hook}`) and branch on query flags, mirroring
the just-shipped plugin-install tab redirect:

- `?woodev-account-connect=1&_wpnonce=…` → `handle_connect_init()`.
- `?woodev-account-return=1&_wpnonce=…&request_token=…` → `handle_connect_return()`.

`disconnect` is a REST route (React calls it, no full reload):
`POST woodev/v1/account/disconnect` (permission: `manage_options` + REST nonce).

## Flow

### Connect

1. React renders «Подключить аккаунт» as a link to `get_connect_url()`.
2. `handle_connect_init()`: verify nonce → build `redirect_uri` (extensions page +
   `woodev-account-return=1` + fresh nonce) → POST `request_token {home_url, redirect_uri}`
   → store `{secret, redirect_uri}` in the handshake transient → `wp_redirect()` the
   browser to `…/oauth/authorize?home_url&redirect_uri&secret`.
3. User logs in on woodev.ru (if needed) and approves.
4. Connector redirects browser back to `redirect_uri?request_token=…`.
5. `handle_connect_return()`: verify nonce → read `request_token` (or handle
   `woodev_account_denied`) → load handshake transient → sign + POST
   `access_token {request_token, home_url}` (key = stored `secret`, with
   `X-Woodev-Timestamp`/`X-Woodev-Signature`) → store `{access_token,
   access_token_secret, site_id, url, user_id, updated}` → signed GET `/oauth/me`
   → store `{name, email, avatar}` → delete handshake transient → `wp_safe_redirect()`
   to the clean extensions URL (with a success flash).

### Disconnect

REST `disconnect`: signed POST `/oauth/invalidate_token` (best-effort — clear locally
even if the remote call fails, so a revoked/unreachable connector never strands the
admin) → `delete_option('woodev_account_data')` → flush any account transients →
return `{connected: false}`.

## Signing — the fragile part

The client computes the canonical fields for the **outgoing** request exactly as the
connector's server will reconstruct them:

- `host` — the store host (e.g. `woodev.ru`, or `localhost:8090` on the rig), taken
  from the configured api base, NOT from the client's own `$_SERVER`.
- `request_uri` — the REST path as the connector serves it. Derived from the resolved
  endpoint URL's path + query. Pretty-permalink stores serve `/wp-json/woodev-account/v1/…`;
  plain-permalink stores serve `/index.php?rest_route=/woodev-account/v1/…`. The client
  must send to, and sign, whichever the store actually uses — so resolve the endpoint
  via the store's advertised REST root, not a hardcoded `/wp-json/` assumption.
- `method` — uppercased.
- `body` — the exact JSON string sent (`wp_json_encode($body)`); sign that same string.
- `timestamp` — the value placed in `X-Woodev-Timestamp`.

This reconstruction (esp. `request_uri` and `body` byte-equality) is historically where
WC-Helper-style signing breaks. It is the primary Codex adversarial-review target.

## UI (`src/plugins-page/`)

### `AccountMenu` (new component) — replaces the current account placeholder bar

- **Disconnected (#6):** button «Подключить аккаунт» → dropdown:
  `[Подключить аккаунт]` (link → `account.connectUrl`) + `[Перейти на woodev.ru/my-account]`
  (external link → `account.myAccountUrl`).
- **Connected (#9):** button shows `avatar` (img; placeholder glyph if empty) + `name`
  → dropdown: `[Перейти на my-account]` + `[Отключить аккаунт]` (calls disconnect REST,
  then re-renders disconnected).

Gated by `accountEnabled`; when off, the component renders nothing (current behavior).

### `ExtensionCard` — installed badge (#5)

If `installed.includes(product.id)` → show an «Установлен» badge in the card head and
swap the footer button «Купить …» / «Бесплатно» → «Посмотреть» (still links to the
product permalink). `installed` is the array of installed store download_ids.

### Bootstrap data (`window.woodevExtensions`) — extend

Add: `account` (`get_account()` output + `connectUrl` + `myAccountUrl`) and
`installed` (array<int> of installed download_ids). Existing keys unchanged.

## Installed-ids collector (#5)

Enumerate active framework plugins via the bootstrap resolver
(`Woodev_Plugin_Bootstrap::instance()` → active plugins; each plugin's main class is a
singleton via `::instance()`), call `get_download_id()` on each, cast to int, filter
> 0, dedupe. Exposed as `installed` in the bootstrap data. Pure/collectible in one
small method, unit-tested with a stubbed resolver.

## Connector change (woodev_theme) — `/oauth/me` avatar

In `REST_Controller::me()`, add `'avatar' => $user ? (string) get_avatar_url( (int) $connection->user_id ) : ''`
to the response. Read-only, not security-sensitive. One unit test asserting the field
is present. Local commit in woodev_theme; operator deploys to prod.

## Testing

- **PHP unit (Brain Monkey):**
  - `Woodev_Account_Signer` — payload byte-exactness: sign a fixed request, assert the
    hex equals a fixture computed by the same documented contract (the round-trip guard
    against the connector's `Signer`).
  - `Woodev_Account_Connection` — `get_connect_url` (nonce + flag), `is_connected`,
    `get_account` shape, `store_auth`/`get_auth`/`clear` round-trip, `disconnect`
    clears the option even when the remote call errors, `canonical_for` request_uri
    derivation (pretty vs plain permalink).
  - REST `disconnect` — permission gate (manage_options) + handler clears state.
  - Installed-ids collector — stubbed resolver → expected deduped int list.
  - Connector: `/oauth/me` includes `avatar` (woodev_theme test suite).
- **JS:** no runner (parity) → rig browser verification.

## Rig e2e plan

- Filter `woodev_account_api_url` → `http://localhost:8090` (issuer). Catalog store URL
  filter → issuer too (so the whole store side is local).
- Ensure an issuer user (woodev.ru-local) can log in to approve; an EDD customer with a
  completed purchase is nice-to-have (purchases UI deferred) but `/oauth/me` only needs
  the logged-in user.
- On the stand (:8888): click «Подключить» → redirected to issuer `/oauth/authorize`
  → log in on issuer → approve → back on the stand showing avatar + name. Verify
  `woodev_account_data` populated (eval-file). Then disconnect → option cleared,
  connector connection row deleted.
- Cross-origin redirects (stand ↔ issuer) in one browser.
- Drive states via `docker exec <cli> wp eval-file` (cyrillic/quoting traps; gotcha
  `wp-safe-remote-request-local-rig`). Note: `wp_safe_remote_*` to `localhost` needs the
  issuer-side SSRF bypass already in place; the **stand → issuer** request originates
  from the stand and must be allowed to reach `localhost:8090` — confirm the stand's
  `wp_safe_remote_request` permits the local issuer host (add a rig-only filter if not).

## Security / Codex adversarial review (mandatory)

- Signature reconstruction correctness (request_uri/body byte-equality; timestamp in
  payload; constant-time compare).
- Connect-return CSRF nonce; handshake transient single-use + TTL; secret never logged.
- Disconnect requires `manage_options` + REST nonce.
- No token/secret in API request logs (`woodev_{plugin_id}_api_request_performed`).
- Open-redirect: connect-init only ever redirects to the configured store origin;
  connect-return only to the local extensions page.

## File inventory

**New (framework):**
- `woodev/account/class-account-connection.php`
- `woodev/account/class-account-signer.php`
- `woodev/rest-api/controllers/class-rest-api-account.php` (disconnect route)
- `src/plugins-page/account.js` (`AccountMenu`)
- tests: `tests/unit/AccountSignerTest.php`, `AccountConnectionTest.php`,
  `RestApiAccountTest.php`, installed-ids test.

**Changed (framework):**
- `woodev/admin/class-admin-pages.php` — wire handlers + extend bootstrap data.
- `woodev/rest-api/controllers/class-rest-api-extensions.php` — store URL filter.
- `src/plugins-page/catalog.js` — installed badge + button swap.
- `src/plugins-page/style.scss` — account menu + badge styles.

**Changed (woodev_theme — operator deploys):**
- `plugins/woodev-account-connector/includes/class-rest-controller.php` — `/oauth/me` avatar.
- connector test for the avatar field.

## Out of scope (this session)

- #7 purchases tab, #8 install-from-connector, connector `/purchases` orders +
  `/download/{id}`. Rating-in-API (separate woodev_theme bug, operator-skipped s23).
