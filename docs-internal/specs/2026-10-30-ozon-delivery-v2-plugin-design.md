# Ozon Delivery v2 Plugin Design

**Date:** 2026-10-30 (s203)  
**Target:** the first release of a NEW plugin «Ozon Доставка для WooCommerce» (`woodev-ozon-delivery`), written on Framework
2.0.16 in the existing private repo `kalbac/woodev-ozon-delivery` (today a rig harness at `0.0.1-dev`, board №7). Ozon carries
the merchant's own parcels to pickup points, parcel lockers and the buyer's door through the Ozon Delivery API
(`api-delivery.ozon.ru`). It is not the marketplace plugin `woocommerce-ozon-seller`.  
**Evidence:** framework paths are relative to this repository at `b07fe50b` (= `v2.0.16` for `woodev/` and `src/`), read
through Serena on this worktree or taken from the gap refresh. `ozon:` = `/Users/maksimmartirosov/Projects/woodev-ozon-delivery`
(groundwork docs and the OpenAPI snapshot `docs/reference/ozon-delivery-swagger-2026-10-30.json`, cited as **OAS**); `cdek:` =
`/Users/maksimmartirosov/Projects/woocommerce-edostavka`; `yandex:` = `/Users/maksimmartirosov/Projects/woocommerce-yandex-delivery`;
`rp:` = `/Users/maksimmartirosov/Projects/woodev-russian-post`. The fact base is
[the s203 research folder](../research/2026-10-30-ozon-gap-refresh/decisions.md): **D** = decisions, **G** = the gap refresh
[report.md](../research/2026-10-30-ozon-gap-refresh/report.md), **A** = [api-diff.md](../research/2026-10-30-ozon-gap-refresh/api-diff.md),
**W** = [write-measurement.md](../research/2026-10-30-ozon-gap-refresh/write-measurement.md). Precedence for API facts: W
(measured 30.10.2026) > `ozon:docs/03-RECON-REPORT.md` (measured 21.08.2026) > OAS > the August wiki. Labels: **MEASURED**,
**READ** (code or OAS), **UNVERIFIED** (with how to settle it), **interpretation** (a gap this spec closed; listed again in
§16.2).

## 0. How to read this spec

§1 lists the binding decisions; nothing below reopens them. §2 is the product boundary and identity, §3 the capability →
seam map. §§4–12 are the design per area: transport, settings, catalogue, quoting, checkout, export and the posting model,
tracking, returns, admin UI. §13 is the worst-case traffic the operator rule demands, §14 testing, §15 out of scope, §16 the
registers (UNVERIFIED, interpretations, the answered operator questions O1–O8), §17 the work breakdown W0…Wn.

**Three source notes the reader must know.**

1. **One Ozon order is 1..100 postings, and the plugin owns them (D3).** The framework's shipment identity is one scalar per
   order (G-2). The Ozon `order_number` fills that scalar; the postings, their statuses, labels, cancellations and returns
   live in plugin meta and the plugin's own order panel; the framework orders table shows one row with an honest aggregate.
   No framework multi-shipment change is part of this release.
2. **There is no sandbox for this API (MEASURED, W «New key, scopes and sandbox»).** `api-seller-sandbox.ozon.ru` issues a
   token but answers 404 on every `api-delivery.ozon.ru` path tried; the sandbox token gets 401 on the production host. Every
   write test is a real shipment on the operator's cabinet (§14.3).
3. **`check-client` refuses ordinary-looking numbers (MEASURED, W §check-client).** `+79990000001` and `+79012345678` answer
   `false`, `+70000000000` answers `true`. It is not a «registered at Ozon» check; a refusal is a common outcome and the buyer
   must see why Ozon disappeared (D6).

## 1. Binding decisions

Source: [decisions.md](../research/2026-10-30-ozon-gap-refresh/decisions.md), walked with the operator in s203 (30.10.2026).
Where any report disagrees, that file wins.

| ID | Decision (short) | Where it lands |
|---|---|---|
| D1 | Do not wait for the framework: build on 2.0.16; the August SP-5 blocker is obsolete | §3, §17 |
| D2 | First release: pickup points + parcel lockers + courier. Courier needs address-level coordinates and a persisted export snapshot | §7, §8.4 |
| D3 | The plugin owns the postings: one framework orders-table row per order with an honest aggregate status; postings, statuses, labels, cancel in the plugin's own order UI; no framework multi-shipment change | §9, §12 |
| D4 | Returns in the first release: status, current placement, return barcode on demand (expires — never stored) | §11 |
| D5 | Live write measurement done 30.10.2026 | W, §4, §9 |
| D6 | Phone first: Ozon not offered (or shown as «укажите телефон для расчёта») until a phone is entered; re-rate on phone change; re-check at placement; the buyer sees why Ozon disappeared; classic and block checkout | §8 |
| D7 | Credentials alive; a new key with all scopes issued (the old one lacked `shipment-method`). **Amended by W (same night):** a token carries only the scopes REQUESTED, and one unapproved scope fails the whole token call (`invalid_scope`) | §4.2, §5 |
| D8 | Containers, arrival passes, drop-off point management out of the first release; revisit if the first real handover shows a method cannot be handed over without a container | §15 |
| D— | Admin «create order» wizard: not in the first release unless a plugin-side way exists (the shared wizard does not pass the buyer phone) | §12.4 |
| D— | Sandbox: emulates the Seller API, not `api-delivery`. **Settled by W:** it does not serve the delivery API at all | §0 note 2, §14 |

**Operator rules that bind every section** (project and memory rules): the storefront is fool-proof and the admin trusts the
merchant; merchant diagnostics only behind «Логирование»; no automatic external call without backoff, a cap and a stated worst
case (§13); settings on `woodev-settings`, never a WooCommerce Integrations tab; no licence in force → no storefront offer and
no new remote action, exceptions stated (§5.4); KISS — every protective mechanism names the real case it prevents; merchant
and buyer texts never say «чекаут» or «фреймворк».

## 2. Product boundary and identity

| Item | Value | Why |
|---|---|---|
| Repo | `kalbac/woodev-ozon-delivery` (private, existing; branch `main`) | groundwork, gotchas and the OpenAPI snapshot already live there; W0 replaces the rig harness |
| Folder / main file | **provisional** `woocommerce-ozon-dostavka` / `woocommerce-ozon-dostavka.php`; alternative `ozon-rocket-woocommerce` (the EDD slug) | O1: keep the old 1.0.3 ZIP's folder and main-file names so WordPress treats 2.0.0 as an update of the same plugin. The names are UNKNOWN (ZIP lost, card `kalbac/woodev-ozon-delivery#9`). **One place:** the `slug` variable of `scripts/build-release-zip.sh` (the ZIP's top-level folder); the repo's main file is `${slug}.php` itself — no rename step in the build. If the ZIP turns up with other names: change `slug` and `git mv` the main file, nothing else (see the text-domain row) |
| Text domain | `woodev-ozon-delivery` | decoupled from the folder slug on purpose (loaded through the plugin's own `languages/` path), so a folder rename never touches strings or `.po` files — **interpretation** |
| Plugin id | `ozon_delivery` | **interpretation** (Yandex uses `yandex_delivery`); W0 runs the wp.org collision search RP's R7 ran |
| Method ids | `ozon_delivery_pickup` (pickup points AND lockers), `ozon_delivery_courier` | one id per delivery type (shipping skill); a point's type rides on `Pickup_Point` |
| Namespace | `Woodev\Ozon_Delivery` | Yandex shape `Woodev\Yandex_Delivery` |
| Version | `2.0.0` | O1: 2.0.0 of the existing product (old «Ozon доставка» 1.0.3, a different API, dead 4+ years, no real users) |
| EDD product / `download_id` | `58107` | O1/O2: the existing download; nothing is published on it without the operator's word |
| Error reporting | GlitchTip project exists (O8) | the DSN lives in the private Ozon repo (or the build's environment) and is injected by the plugin's DSN filter; it is never written into this public repo |
| Framework | bundled 2.0.16, `framework_version` = `backwards_compatible` floor per the core skill | D1 |
| Supported features | HPOS + cart/checkout blocks, declared in the loader definition | `.ai/skills/woodev-plugin-core/SKILL.md` |

**Installed-site data.** No migration from 1.0.3 (O1): its option keys and shipping method ids are neither read nor
preserved; old buyers simply renew the licence. Every key this spec names is new and becomes the contract from 2.0.0 on
(ADR-005 applies from the first release).

**Licence gate.** Automatic through `Shipping\License_Gate` (`woodev/shipping-method/class-license-gate.php`): no rates, no
export / cancel / documents without an in-force claim; tracking and status polling keep running (G §2 item 3). The plugin's
own actions declare `read_only` where they only read (§12.2); the one explicit exception is the return barcode (§11.4).

**Merchant setup the plugin cannot do** (stated in the settings help and the merchant docs): a delivery-only cabinet; a
private application with the scopes of §4.2; at least one shipment method (handover point) created in the cabinet; a
funded balance (Ozon documents `NotEnoughBalance` at approval although W did not observe it at 0 ₽).

## 3. Capability → framework seam map

Gap = needs a framework change; «No» = plugin work on an existing seam. Verdicts are G's, re-checked where marked.

| Capability | Framework seam | Plugin work | Gap? |
|---|---|---|---|
| Methods | `Shipping_Plugin::get_shipping_method_classes()`; bases `Shipping_Method_Pickup` (abstract `get_point_source()`), `Shipping_Method_Courier` | two classes, §7 | No |
| Quote | `Shipping_Method::rate_package()` (`null` = not served, exception = failure, hidden + logged) | `order/checkout`, §7 | No |
| Rate cache | `FEATURE_RATE_CACHE`, `Shipping_Method::get_rate_cache_context()` (READ: method, instance settings, packing, package lines, destination, payment, insurance, services, selected point — no phone) | override adds phone, shipment method, client id, §7.4 | No |
| Hidden-method reason (admin) | `Shipping_Method::report_hidden()` | one helper for every refusal path, §7.5 | No |
| Phone-dependent availability | none (G-8: no phone transport, no phone cache identity); analogue `class-fee-payments.php` (payment-dependent rates) and `src/checkout-blocks/payment-recalc.ts` | phone transport on both surfaces, check-client cache, placement re-check, §8 | No (plugin) |
| Buyer-facing hint | none for «why a method is missing» | classic row + Store API extension data + small blocks script, §8.3 | No (plugin) |
| Packing | `FEATURE_BOX_PACKING`, `Packaging::get_value_context()` | box → posting, §7.2 | No |
| Location / courier coordinates | `Location_Record` (`level`, `street`, `house`, `postcode`, `lat`, `lon`; `woodev/shipping-method/location/class-location-record.php`), DaData maps `geo_lat`/`geo_lon` | address-level rule and export snapshot, §8.4 (G-7) | No |
| Pickup catalogue | `Point_Source` (`STRATEGY_VIEWPORT`, `fetch_points( Point_Query )`, `fetch_details()`), `Pickup_Point` `work_time` | own table + resumable sync, §6 (G-4) | No (plugin) |
| Pickup selection, map, blocks | `Pickup_Handler`, `class-pickup-blocks.php`, map providers | reuse, no own map | No |
| Export | `Abstract_Shipment_Handler::export()` → `Action_Result`, export lock, `extract_carrier_order_id()` | `order/create` + approve, §9 | No |
| Async approval | none generic; donor `cdek:includes/orders/class-cdek-shipment-handler.php` (`schedule_verification()`, `verify_export()`, `VERIFY_DELAYS`) | §9.4 (G-3) | No (plugin) |
| Cancel | `Abstract_Shipment_Handler::cancel()` clears the carrier id and marks `Shipment_Cancellation` on success (READ) | Yandex pattern: the client throws on accepted-pending; the plugin settles, §9.7 (G-1, G-3) | No |
| Documents | `Document_Source`, `Document_Result::binary()/failed()` (order-scoped, needs the aggregate id) | single-posting label as a document; per-posting labels from the panel, §9.6 | No |
| Statuses | `status_meta_key` + `status_map` on the provider; `Delivery_Status` (9 + `unknown`); watcher emits events/emails | aggregate raw status, §10 (G-5) | No |
| Shipment facts | `Shipment_Facts_Events::record()` (once per order/provider) | aggregate cost/date only, §10.4 | No |
| Orders page | `Orders_Provider`, `Order_Marker`, `woodev_shipping_order_actions` (`fields`, `read_only`), `woodev_shipping_order_row_flags`, `woodev_shipping_orders_preview_fields` | registration on every request (donor `yandex:includes/orders/class-yandex-order-actions.php`), §12 | No |
| Settings | `get_tab_settings_providers()`, `Woodev_Setting` (`sensitive`), `Woodev_Settings_Connection_Test`; donor `yandex:includes/settings/class-yandex-settings-handler.php` | §5 | No |
| Secrets in logs | `Woodev_API_Base::get_secret_header_names()` (masks `Authorization`), `get_secret_param_names()` | add `client_secret`, `access_token`, `refresh_token`, §4.5 | No |
| testcookie | `Woodev_API_Base::follow_challenge_redirects()` (opt-in, body-preserving same-origin replay; base default `redirection => 0`) | return `true`, §4.3 (G-6) | No |
| Admin create-order wizard | rate request omits the phone (`src/shipping-orders-page/order-wizard/delivery-state.ts`, `class-admin-rate-calculator.php`) | not offered there, §12.4 | Yes if wanted — out |
| Native per-posting rows, `out_for_delivery`, calendar schedules | — | — | Yes — out (G §3) |

## 4. Transport

### 4.1 One client, two hosts

`Ozon_Api_Client extends Woodev_API_Base implements Shipping_API` for `https://api-delivery.ozon.ru/v1/…` (every method
`POST`, JSON both ways), plus a token sub-request to `https://xapi.ozon.ru/oauth/token` (no testcookie there, F-4). User
agent: ASCII product token `woodev-ozon-delivery` (a non-ASCII product name once earned CDEK HTTP 400). Request purposes:
quotes and `check-client` under `RATES`, catalogue and point details under `REFERENCE`, everything in §9–§11 under `EXPORT`.
Timeouts: `RATES` 8 s (a buyer is waiting), others 20 s — **interpretation**.

### 4.2 OAuth2 client credentials and the token cache

- Body `{client_id, client_secret, grant_type: client_credentials, scope: [...]}` with `scope` an ARRAY (F-3).
- **Scopes are requested explicitly (MEASURED, W):** the token carries only what was asked for. Required:
  `delivery-api.delivery`, `delivery-api.delivery-point`, `delivery-api.order`, `delivery-api.posting`, `delivery-api.return`.
  Optional: `delivery-api.shipment-method` (the settings dropdown, §5.2). Never requested in this release:
  `dropoff-point`, `return-point`, `container`, `arrival-pass`, `all` (D8; asking for an unapproved one kills the call).
- **`invalid_scope` fallback (MEASURED: «scope 'delivery-api.arrival-pass' is not approved», 400 for the whole call):** the
  first token call asks for required + `shipment-method`. On `invalid_scope` naming `shipment-method`, the client repeats
  once with the required set only and stores `shipment_method_scope = absent` (option, cleared when credentials change), so
  later token calls ask for the required set directly. `invalid_scope` naming a REQUIRED scope is a configuration error:
  the connection test shows «В ключе нет доступа «{scope}». Включите его в кабинете Ozon: Настройки → Управление частными
  приложениями.», and no rate is offered (`report_hidden` reason `credentials`).
- **Cache:** one transient per site keyed on `client_id` + a hash of the secret + the scope set; TTL = `expires_in − now −
  120 s`, where **`expires_in` is an absolute Unix timestamp as a string** (F-1, gotcha
  `ozon:docs/gotchas/ozon-expires-in-absolute-timestamp.md`). A value in the past or more than 24 h ahead is treated as
  60 min from now (the measured lifetime is ~1 h). A 401 on an API call drops the cached token and retries that call once.
  The `refresh_token` is ignored (F-2: untested; re-issue is one cheap call).
- **A failed token call is backed off** under the `auth` class of §4.6 (invalid credentials, `invalid_scope` on a required
  scope, 5xx, timeout, 429). Saving new credentials resets the `auth` state.
- **Real case behind the single-flight:** a cold cache under a checkout burst issues several token calls; that is harmless
  (tokens are independent), so there is no lock.

### 4.3 testcookie

Override `follow_challenge_redirects(): bool` to return `true` (`woodev/api/class-api-base.php`, G-6). The August proof that
`wp_remote_post()` works unaided measured WordPress defaults, not the framework client, whose base sets `redirection => 0`.
Cookie persistence between calls is not built (it would only save one redirect per call; F-5).

### 4.4 Errors

| Shape (MEASURED, gotcha `ozon:docs/gotchas/ozon-two-error-envelopes.md`) | Meaning | Client result |
|---|---|---|
| 400 `{"error":{"message":"…"}}` (no `code`) | validation | `Ozon_Api_Exception` with the message |
| 400 `{"error":{"code":"posting_incorrect_state","message":"…"}}` (W, label before approve) | state refusal | exception carrying `code` |
| 404 top-level `{"code":5,"message":"Not Found","details":[]}` | unknown route — a programming error | exception, logged as error |
| 401 | token | drop token, retry once (§4.2) |
| 403 | missing scope | exception «нет доступа {method}» |
| 429 | rate limit (ceiling unknown, F-21) | `Woodev_API_Rate_Limit_Exception` honouring `Retry-After` if present (UNVERIFIED shape) |
| 200 with `results[].error` (`order/checkout`, `order/create`, `check-availability`) | per-item refusal; the key is ABSENT when fine (F-17) | typed per-item result, never an exception |
| 200 with an empty list (`posting/info` of an unknown number, F-7) | absence | the caller compares asked vs returned |

Timestamps are parsed with `new DateTimeImmutable()` (seven fractional digits break `DATE_ATOM`, F-12). Int64 ids
(`shipment_method_id` 16 digits, F-13; point ids) are kept as strings end to end and decoded with `JSON_BIGINT_AS_STRING`;
never a JavaScript number.

### 4.5 Logging and idempotency

- `x-o3-trace-id` is logged on every call, success or failure (F-18), and copied into every merchant-visible API error
  («Код запроса Ozon: …») so support can quote it.
- `get_secret_param_names()` adds `client_secret`, `access_token`, `refresh_token`; `Authorization` is masked by default. A
  unit test feeds a token request and response through the real sanitizer.
- `Idempotency-Key` on `order/create` only — **MEASURED honoured** (same key + same body 2 s later → same order and posting
  numbers). Derivation in §9.2. Retention time UNVERIFIED (U3).

### 4.6 Failure backoff for automatic calls

Real case: invalid credentials, a token-endpoint outage or a run of 429/5xx answers. The framework caches neither an
exception nor a `null` rate (`class-shipping-method.php`, `class-shipping-rate-cache.php`), so without this every checkout
re-render would call Ozon again.

- **State:** one non-autoloaded option `woodev_ozon_delivery_backoff` with three classes — `auth` (token), `check_client`,
  `quote` (`order/checkout`) — each `{failures, next_attempt_at}`.
- **What counts as a failure:** timeout / transport error, 5xx, 429, a 401 that survives the one token retry, 403, a token
  refusal. A per-item `results[].error` and a `check-client` `false` are ANSWERS, cached as such (§7.4, §8.5), never failures.
- **Healthy path:** `failures = 0` → call freely, no write.
- **In failure:** before `next_attempt_at` no call is made (the method is refused with `ozon_unavailable`; buyer side = the
  outage row of §8.3, no stale rate is served). After it, ONE request claims the probe by a compare-and-swap `UPDATE` of the
  option row that moves `next_attempt_at` forward before calling (one winner per window, so a checkout burst still sends
  one probe). Success resets the class to 0; failure increments `failures`.
- **Delay:** `min( 60 s × 2^(failures − 1), 15 min )`; a `Retry-After` larger than that wins, capped at 1 h.
- **Bound per class during a failure:** probes at 0, 1, 3, 7, 15, 30, 45, 60 min… → ≤ 8 in the first hour, then 4 per hour
  → **≤ 100 failed calls per day per class, ≤ 300 per day for all three**, whatever the checkout traffic.
- Every automatic call — checkout, catalogue sync, poller — first consults `auth`: no token while `auth` is backed off.
  Background jobs keep their own capped backoff on top (§6.3, §10.1, §11.2). Saving credentials resets `auth`.

## 5. Settings (`woodev-settings` → tab «Ozon Доставка»)

Supplied through `get_tab_settings_providers()`; per-method price options stay on the WooCommerce shipping-zone instance.
Model: `yandex:includes/settings/class-yandex-settings-handler.php`.

### 5.1 «Подключение»

| Field | Type | Notes |
|---|---|---|
| «Client ID» | text | from the private application |
| «Client Secret» | `Woodev_Control::TYPE_PASSWORD`, `sensitive` | mask when stored; blank save keeps it |
| Connection test | `Woodev_Settings_Connection_Test` | token with §4.2 fallback, then one read: `shipment-method/search` when the scope is present, else `check-client` for `+79000000000`. Shows «Подключено», the granted scopes, and whether the method list is available |

### 5.2 «Метод отгрузки»

- **With the `shipment-method` scope:** a select filled on the settings screen from `shipment-method/search`
  (`filters.statuses = [active]`, pages of 100) showing «{name} — {full_address}» per method, value = the id as a string.
  MEASURED: the operator's cabinet returns «Тестовый метод доставки до ПВЗ», `active`, type `dropoff`. Fetched when the
  screen opens (cached 10 min); never at checkout.
- **Without it (`invalid_scope` fallback, or 403):** a numeric text field «ID метода доставки» with the help «Номер штрихкода
  метода в кабинете Ozon: Методы доставки» and the sentence that adding the «Методы доставки» access to the key turns this
  into a list.
- A stored id is checked with `shipment-method/info` on save when the scope exists; a non-`active` method warns
  «Метод {name} в Ozon не активен — доставка Ozon не будет предлагаться».
- One shipment method per site in this release (several methods = several handover points; §15).

### 5.3 Other groups

- «Пункты выдачи»: read-only status of the catalogue (§6.5): points served, last complete update, a running update's
  progress, and «Обновить сейчас».
- «Упаковка» and default dimensions: the framework's packaging settings (store boxes, carrier presets none in 2.0.0).
- «Выгрузка заказов»: the framework export section (auto-export statuses, delivered/cancelled statuses, «Обновить статусы
  сейчас») plus one plugin field through `get_export_section_extension()`: «Как часто проверять статусы» (15/30/60 min,
  default 30).
- «Дополнительно»: the framework's logging switch; every merchant diagnostic of this spec appears only with it on.
- `Shipping_Plugin::is_configured()` returns true when Client ID, secret and a shipment method id are set (cheap, no call).

### 5.4 Setup wizard

None in 2.0.0: a new plugin needs no migration wizard (G §2 item 9) and the settings screen carries the connection test and
the method list. **interpretation** — a three-step onboarding wizard is a product choice for later.

## 6. Pickup and locker catalogue

### 6.1 Why local

`delivery-point/list` returns ids + `shipment_method_ids` only, accepts nothing but pagination, and `delivery/map` is 404 on
this host (H-1). 45 194 points (F-20). Checkout must never call Ozon to draw a map (G-4).

### 6.2 Table `{prefix}woodev_ozon_delivery_points`

Columns: `point_id` BIGINT PK; `type` (`pvz` | `postamat` | `unknown`); `name`; `number`; `address`; `lat`, `lon` (indexed
together); `is_active`; `is_bulky`; `storage_period_days`; restriction columns `min_weight_g`, `max_weight_g`,
`max_length_mm`, `max_width_mm`, `max_height_mm`, `min_price`, `max_price`; `method_ids` (comma list as strings);
`schedule` (JSON, the dated window); `seen_generation`, `detail_generation`; `updated_at`. Created by the lifecycle install
routine with `dbDelta`; dropped on uninstall.

### 6.3 Resumable `list → info` sync over Action Scheduler

- **Generation.** A run gets generation `G = last + 1`, stored with its state in one option
  `woodev_ozon_delivery_catalogue_sync` (`generation`, `phase`, `cursor`, `pages`, `errors`, `started_at`).
- **List phase.** Each action fetches up to 10 pages of `limit: 100` (F-19: 1000 → 400; the OAS «1000» is not trusted, A),
  upserts `point_id`, `method_ids`, `seen_generation = G`, and saves `next_cursor` VERBATIM after every page (opaque, F-10).
  An absent or null `next_cursor` ends the phase (F-11 tolerance).
- **Info phase.** Each action selects up to 10 × 100 ids with `seen_generation = G AND detail_generation < G`, calls
  `delivery-point/info` with 100 ids per call (OAS allows 1000; unproven, A), and writes details with `detail_generation = G`.
- **Finish.** Rows with `seen_generation < G` get `is_active = 0` (kept as last-good history; rows unseen for three
  generations are deleted). The option records `last_complete = G` and the time.
- **Pacing and failure.** 200 ms between calls (the measured safe spacing, F-21). A failed action is retried with backoff
  5 → 15 → 60 min, at most 5 times, then the run waits for the next schedule; the cursor survives, so a retry resumes
  rather than restarts. Real case: a hosting cron killed mid-run (~904 calls, ~8 min end to end).
- **Schedule.** Daily at 03:00 site time, and once right after valid credentials and a method are first saved. The dated
  schedule window is ~8 days (F-16), so a daily refresh keeps it current.
- **Last-good serving.** Checkout reads only `is_active = 1` rows; while run G is in progress, rows still carry G−1 data
  until overwritten with fresh data — never an empty catalogue. Before the first complete run the pickup method is not offered
  (`report_hidden` reason `catalogue_empty`; the settings group shows progress).

### 6.4 `Ozon_Point_Source` (`STRATEGY_VIEWPORT`)

- `fetch_points( Point_Query )`: an SQL bbox query on `lat/lon`, `is_active = 1`, `FIND_IN_SET( configured method, method_ids )`,
  the query's types (`pvz` → pickup point, `postamat` → parcel locker), and the LOCAL restriction filter against the packed
  parcel (every posting's weight/dimensions within the point's limits; declared value within `min_price`/`max_price`;
  non-bulky points refuse a parcel above the point limits). Capped by the framework's bbox limit; no remote call.
- `fetch_details( $id )`: the row, `null` when inactive or unknown (an authoritative negative; the `Pickup_Handler` re-check at
  confirmation calls it).
- **Schedule as text.** `work_time` is rendered at fetch time from the dated schedule: the next days from today, past dates
  skipped, e.g. «пн 03.11: 10:00–21:00; вт 04.11: 10:00–21:00; …» (7 days at most; identical consecutive days may collapse into
  one range — **interpretation**). No weekly `Pickup_Schedule` is invented (G §2 item 8).
- Buyer-visible strings only: name, address, type label, `work_time`, «Срок хранения: N дней» when known. Ozon codes never
  reach the buyer.

## 7. Quoting

### 7.1 One quote body

`order/checkout` with `recipient.phone_number` (required — wiki :199; no phone, no quote, §8), `postings[]` from packing
(§7.2) with `request_id` 1..N, the configured `shipment_method_id`, `declared_value {amount, currency_code: RUB}`, `dimensions`;
`delivery` = `delivery_point.delivery_point_id` (pickup) or `courier.coordinates` (courier). No `cutoff_at` (computed from now).

### 7.2 Packing → postings

The framework packer (`FEATURE_BOX_PACKING`) returns N boxes; **one box = one posting**. Weight in grams, dimensions in mm,
rounded up. **Declared value = goods subtotal (D, «Declared value / insurance»)**: per unit = the line's `line_subtotal` ÷
quantity at checkout and the order item's `get_subtotal()` ÷ quantity at export — before coupons, before tax, the same basis
on both surfaces. NOT `Packaging::get_value_context()`: that helper uses `line_total` (after line discounts, READ
`woodev/shipping-method/class-packaging.php`) and keeps its meaning for percentage costs. Declared value per posting = Σ unit
value × quantity of the items in that box, in kopecks, the last posting taking the rounding remainder so the sum equals the
package subtotal. Example (fixture): one line, subtotal 1 000 ₽, a 200 ₽ coupon → declared 1 000 ₽. More than 100 boxes → no rate
(`report_hidden` reason `too_many_postings`). A product without weight or dimensions uses the framework defaults; with no
defaults set, no rate (`missing_dimensions`).

### 7.3 Rate = sum, days = max, any error = no rate

Price = Σ `estimated_delivery_cost` + Σ `estimated_insurance_cost` (Ozon charges both to the merchant; the framework's price
options adjust what the buyer pays). Delivery days = max over postings. **Any posting with an `error` → no rate**, because a
partial quote would place an order Ozon cannot create. `DeliveryPointRestrictionsError`, `DeliveryAvailabilityError`,
`RecipientError` and `SameDeliveryPointError` are ordinary refusals (§7.5); `PrincipalError` (cabinet blocked) is logged as an
error.

**Pickup before a point is chosen** (settled by **O7**): the method quotes against a representative point — the
nearest active point to the destination settlement's coordinates that passes the local restriction filter — and shows that
price; once the buyer picks a point, the rate is re-quoted for that point (the selected point is in the framework rate cache context). No point within 50 km → no rate (`no_points_nearby`).

**Courier** quotes only with an address-level record (§8.4).

### 7.4 Quote cache

`FEATURE_RATE_CACHE` on; `get_rate_cache_context()` calls the parent and adds: a hash of the normalized recipient phone,
the shipment method id, the client id, (courier) the coordinates rounded to 6 decimals, and the per-line `line_subtotal`
map in kopecks (the declared-value basis of §7.2; the parent carries line totals, not subtotals). Destination, package
lines, packing and selected point are already in the parent context (READ). Real case: one checkout re-renders shipping
on every field change; without the cache each re-render is an Ozon call.

**Refusals are cached too.** A quote whose postings carry a per-item `error` is an answer: the plugin keeps it 1 h in a
transient keyed on the hash of the same context, so a destination Ozon refuses is not re-asked on every re-render (the
framework does not cache a `null` rate). Failures (timeouts, 5xx, 429) are never cached as answers — they go to the §4.6
backoff.

### 7.5 Refusal reasons (one helper)

Every `null` from `rate_package()` goes through one helper that logs and calls `report_hidden( $code, $message )`: `credentials`,
`no_method`, `catalogue_empty`, `no_phone`, `phone_invalid`, `phone_refused`, `check_client_failed`, `ozon_unavailable`
(§4.6 backoff), `no_points_nearby`,
`courier_address_imprecise`, `missing_dimensions`, `too_many_postings`, `ozon_refused:{code}` (with the trace id). The buyer
side of the phone and address reasons is §8.3.

## 8. Checkout (classic AND blocks) — phone first (D6)

### 8.1 Which phone

The recipient phone = the shipping phone when one is entered, otherwise the billing phone — the order the framework already
uses (`woodev/shipping-method/order/class-shipment-fingerprint.php`). Normalization: digits only; 11 digits starting with 7 or
8 → `+7XXXXXXXXXX`; 10 digits starting with 9 → `+7` prefix; anything else is invalid. MEASURED: Ozon itself accepts `8…`
and refuses a 10-digit number, so normalizing before the call avoids a wasted refusal.

**Store phone field hidden** (WooCommerce «Телефон: скрыто»): Ozon cannot work. The settings tab shows a warning with a link to
the WooCommerce setting, and the methods report `no_phone`. Nothing makes the field required behind the merchant's back.

### 8.2 Transport and re-rate on change

- **Classic.** The plugin adds `update_totals_on_change` to `billing_phone` and `shipping_phone` (WooCommerce re-runs
  `update_order_review` on the field's `change`, not per keystroke), reads the phone from `post_data` in
  `woocommerce_checkout_update_order_review`, and keeps the normalized value in the WooCommerce session.
- **Blocks.** The phone arrives with the customer address through the Store API customer update (UNVERIFIED U5 whether a
  phone-only edit triggers that update; the W5 spike settles it; fallback donor: `src/checkout-blocks/payment-recalc.ts`, an
  `extensionCartUpdate` on phone change).
- **Both.** A `woocommerce_cart_shipping_packages` filter writes the normalized phone hash into every package
  (`woodev_ozon_phone`), so WooCommerce's own package hash changes and shipping is re-rated; other carriers then hit their own
  rate cache. The quote cache carries the same hash (§7.4).

### 8.3 What the buyer sees

| State | Ozon rates | Hint under the shipping options |
|---|---|---|
| No phone yet | none | «Доставка Ozon: укажите номер телефона — по нему рассчитаем стоимость и срок.» |
| Phone not a Russian mobile format | none | «Доставка Ozon: проверьте номер телефона — нужен номер в формате +7 XXX XXX-XX-XX.» |
| `check-client` = false | none | «Ozon не может доставить заказ на этот номер телефона. Проверьте номер или выберите другой способ доставки.» |
| Courier, no house-level address | courier none (pickup unaffected) | «Курьер Ozon: укажите адрес с номером дома — стоимость рассчитывается по точному адресу.» |
| `check-client` or quote failed (outage) | none | no hint (an outage is not the buyer's fault); admin hint + log |
| OK | shown | none |

Hints appear only when an Ozon method is enabled in the matched zone. **Classic:** a table row printed on
`woocommerce_review_order_after_shipping` (refreshed with the order review). **Blocks:** the state travels as Store API cart
extension data (`ExtendSchema`, namespace `woodev-ozon-delivery`) and a small plugin script renders it in the
`ExperimentalOrderShippingPackages` slot. No zero-price placeholder rate: WooCommerce would pre-select it as the cheapest.

### 8.4 Courier: address-level coordinates and the export snapshot (G-7)

- The courier method rates only when the customer's address-level `Location_Record` has `house`, `street`, `postcode`, `lat`
  and `lon`. A settlement-level coordinate (city centre) is not enough. Whether DaData's suggestion coordinates are
  house-precise is UNVERIFIED (U6); the rule accepts a house-level record with coordinates.
- At order placement the plugin writes `_woodev_ozon_courier_address` (JSON: `lat`, `lon`, `zip_code`, `country`, `region`,
  `city`, `street`, `house_number`, `apartment` from `address_2` when it is a flat number, `entrance`/`floor`/`intercom` null)
  because the framework order snapshot keeps only the settlement record with `raw` stripped
  (`class-checkout-handler.php`, G-7). Export reads only this snapshot; a missing snapshot refuses export with «Нет точного
  адреса для курьера Ozon — укажите адрес в заказе и выберите доставку заново».
- `country` value form (`Россия` vs `RU`) is UNVERIFIED (U7; settled by R2).

### 8.5 `check-client` cache and cap

- Called only for a valid normalized phone, server-side, never per keystroke (§8.2). Result cached in a transient keyed on a
  hash of the phone: `true` 24 h, `false` 1 h (a buyer who corrects nothing will not flip the answer; 1 h bounds a wrong
  negative).
- **Per-session cap: 10 distinct phones per hour.** Real case: a script driving the storefront to learn which numbers Ozon
  accepts (the endpoint is a phone oracle). Above the cap the method is not offered and the outage row applies.
- **A failed call (not a `false`) is never cached as an answer** and backs off under the `check_client` class (§4.6), so
  repeated recalculations of the SAME phone during an outage send at most the §4.6 probes.
- Whether `order/checkout` itself returns `RecipientError` for a refused phone is UNVERIFIED (U4). If R1 shows it does,
  `check-client` becomes redundant for the rate and stays only for the hint; the spec keeps it either way because the hint
  needs a yes/no.

### 8.6 Re-check before placement

Through the framework's checkout validation seam (`class-checkout-handler.php` availability/validation, both surfaces, G-8):
the order's recipient phone hash must equal the hash the chosen Ozon rate was computed for (stored in the rate's meta
`ozon_phone_hash`), and `check-client` (cache allowed) must be true. Mismatch → a validation error «Номер телефона изменился —
проверьте стоимость доставки Ozon и подтвердите заказ ещё раз.»; refusal → the refusal text of §8.3. Real case: the buyer edits
the phone after choosing Ozon in a block checkout whose update had not landed yet. The pickup point re-check is the framework's
own (`fetch_details()`).

## 9. Export and the posting model (D3)

### 9.1 Order storage

| Key (order meta, HPOS-safe through `Woodev_Order_Compatibility`) | Content |
|---|---|
| framework carrier order id (`CARRIER_ORDER_ID_FIELD`) | Ozon `order_number` — the aggregate identity |
| `_woodev_ozon_postings` | JSON list, one entry per posting: `posting_number`, `request_id`, `posting_external_id`, `status`, `status_changed_at`, `dimensions`, `declared_value`, `delivery_cost`, `insurance_cost`, `estimated_delivery_days`, `original_delivery_at`, `delivery_at`, `storage_expires_at`, `approve` (`pending`/`done`/`failed:{code}`), `cancel_requested_at`, `return` (`return_number`, `status`, `placement_name`, `placement_address`, `status_changed_at`) |
| `_woodev_ozon_export_generation` | int, starts at 1 (§9.2) |
| `_woodev_ozon_export_request` | the create request of the CURRENT generation: `generation`, `idempotency_key`, `body` (JSON), `sent_at`; written before the create call, deleted once the postings are stored or on a refusal (§9.2–§9.3) |
| `_woodev_ozon_history` | earlier Ozon orders of this WooCommerce order after a full cancel (§9.7), same shape |
| `_woodev_ozon_active` | `1` while any FORWARD posting of the current generation is non-terminal — the status poller's selector (§10.1); set before the create call |
| `_woodev_ozon_return_open` | `1` while any known return (current or history) is non-terminal — the return refresher's selector (§11.2) |
| framework status meta (`status_meta_key`) | the aggregate raw status (§10.2) |
| `_woodev_ozon_courier_address` | §8.4 |

JSON in order meta is enough: ≤ 100 postings per order, read with the order. Orders are found by `_woodev_ozon_active` /
`_woodev_ozon_return_open` through the datastore-aware helper (shipping skill: `wc_get_orders()` drops `meta_query` on the
posts store).

### 9.2 `order/create` and the idempotency key

- **Body:** `order_external_id` = the order number for generation 1, `{order number}-{generation}` from generation 2 (no two
  Ozon orders of one shop share it); `recipient.phone_number` = the order's recipient phone (§8.1), `full_name` = shipping
  name, else billing name; `delivery` = the pickup point from the framework selection, or the courier snapshot; `postings[]`
  re-packed from the ORDER lines (not the cart) with `posting_external_id` = **`{order id}-{generation}-{n}`** (the
  immutable WooCommerce order id, never the order number; unique across generations, and it carries the order id a return
  is matched to, §11.3) and `description` = «{item} × {qty}, …» truncated to 500 characters (OAS: 1–500, required).
- **`Idempotency-Key`** = a UUID (v5 shape) from SHA-1 of `home_url() | order id | export generation`. Deterministic per
  generation. The generation increments only after a confirmed full cancel (§9.7), so a re-export is a new Ozon order.
- **Saved request — the same key is only ever sent with the same body.** The client's `create_order()` works in two modes:
  - **First attempt** (no `_woodev_ozon_export_request` for the current generation): a fresh `check-client` and a fresh
    `order/checkout` with the same body (Ozon's own advice). A refusal or a posting error refuses export with Ozon's reason
    and nothing is saved; a price change only adds an order note («Стоимость Ozon изменилась: было X, стало Y»). Then,
    BEFORE the create call, it writes `_woodev_ozon_export_request` and `_woodev_ozon_active = 1` and saves the order.
  - **Replay** (a saved request exists — the previous attempt's outcome is unknown): it sends the SAVED body with the
    saved key — no re-pack, no fresh phone or quote check, so recovering an order that may already exist at Ozon never
    depends on today's phone answer or price, and an order edited meanwhile cannot ride on the old key.
  - A documented non-success response of the create call (a 4xx error envelope: nothing was created) deletes the saved
    request and clears `_woodev_ozon_active` before rethrowing, so the next export rebuilds from the current order. A 200
    `order/create` has no per-posting `error` (OAS `Ozon.Delivery.Order.Create.PostingResult`, `additionalProperties: false`);
    per-item errors exist only in the `order/checkout` preflight. Any other outcome (timeout, 5xx, an undecodable 200) is
    uncertain: the saved request stays and the reconcile path above applies.

### 9.3 The create/approve boundary — create is committed before approve

Export = create + `posting/approve` for every posting, in one merchant action «Выгрузить». Real case: a `created` posting
cannot be handed over and has no label (MEASURED: `posting_incorrect_state`), so a separate «Подтвердить» step would only be
forgotten. Approve answers 200 `[]`; the posting goes `forming` → `ready_for_shipping` in ~22 s (MEASURED). The two calls are
on opposite sides of the framework's commit point, so an approve failure can never turn into an export failure (READ
`woodev/shipping-method/order/abstract-shipment-handler.php`: `create_order()` throwing → `fail_export()`, no id stored):

1. **Create** — `api->create_order()` is ONLY `order/create` (§9.2). `extract_carrier_order_id()` returns `order_number` and
   keeps the parsed postings in the handler for this request; the base `complete_export()` stores the carrier id.
2. **Store** — on the plugin's own `{hook_prefix}shipment_exported` action (fired by `complete_export()` AFTER the id is
   stored) the handler writes `_woodev_ozon_postings` with `approve = pending`, deletes `_woodev_ozon_export_request` and
   saves the order.
3. **Approve** — still in that callback: `posting/approve` for every posting, inside a catch of every
   `Woodev_API_Exception`. A refusal records `approve = failed:{code}`; a transport failure leaves `pending`. Nothing is
   rethrown — the export result is already success.
4. **Verify** — the §9.4 action is scheduled whatever step 3 returned.

**Recovery.** `supports_reconcile()` returns `true`; `find_exported_order()` is a READ-ONLY `posting/search` with
`filters.shipment_method_id` = configured and `created_at` from `sent_at − 5 min` to `sent_at + 60 min` of the saved request,
≤ 5 pages of 100, matching `posting_external_id` prefix `{order id}-{generation}-`: found → its `order_number` (the base
completes the export, step 2 writes the postings from the search result); not found → `null`, and the base's retry
(`Export_Retry`, ≤ 5 attempts) calls `create_order()` again, which REPLAYS the saved request (§9.2). A process killed after
the create call but before step 2 leaves the carrier id with no postings list: the §9.4 action (or the poller, which selects
`_woodev_ozon_active` orders) finds it and fills the list from the same window search, matching `order_number`. The created
ids are never cleared by an approval failure.

### 9.4 Async approval with polling (CDEK pattern)

One Action Scheduler action per order checks `posting/info` at +15 s, +60 s, +5 min, +15 min, +1 h (CDEK `VERIFY_DELAYS`
shape, shortened for the measured 22 s) under the export lock: postings list missing → the recovery search of §9.3;
`ready_for_shipping` → `approve = done`; still `created` (approve never landed) → re-send approve once at that step;
`forming_failed` → `approve = failed`, order note, row flag; still `forming` → next delay; delays exhausted → note «Ozon долго
подтверждает отправления — проверьте их в кабинете Ozon», then the regular poller (§10) takes over.

### 9.5 `NotEnoughBalance` and other approve errors

A per-posting `NotEnoughBalance` (documented; NOT observed on a 0 ₽ balance, W) sets `approve = failed:NotEnoughBalance`, a
row flag «Пополните баланс Ozon» and a note naming the postings; the order stays exported. The order action «Подтвердить
отправления» re-sends approve for `created` / `forming_failed` postings. Never an exception trace on screen.

### 9.6 Labels

`posting/label` returns `application/pdf`, one page, synchronously (MEASURED), only from `ready_for_shipping` until acceptance
ends. A posting label is one PDF per posting; there is no merge endpoint, and the framework document route is order-scoped.

- **One labelable posting** (the common one-box order): `Ozon_Document_Source` declares «Этикетка Ozon» and returns
  `Document_Result::binary()`; framework single and bulk printing work.
- **Several postings:** the document type is not offered; the plugin panel lists a «Этикетка» link per posting (admin route,
  nonce, `edit_shop_orders`). Settled by **O4**: per-parcel links, no merged PDF.
- Outside the window: `Document_Result::failed( 'Этикетка доступна после подтверждения отправления и до его приёмки Ozon' )`.

### 9.7 Cancel — asynchronous, without the framework's destructive cleanup

- The framework «Отменить» calls the client's `cancel_order( order_number )`: it sends `posting/cancel` for every posting not
  `delivered`/`canceled`, records `cancel_requested_at`, and throws a dedicated «accepted, pending» exception so the base never
  clears the id (Yandex pattern, `yandex:includes/api/class-yandex-api-client.php`). Message: «Ozon принял отмену. Статус
  обновится в течение нескольких минут.»
- A per-posting «Отменить отправление» lives in the plugin panel (destructive, with confirm).
- The poller sees `canceled` (MEASURED: ~18 s). When EVERY posting is `canceled`: the plugin itself moves the Ozon order into
  `_woodev_ozon_history`, clears the carrier id and `_woodev_ozon_active`, calls `Shipment_Cancellation::mark()`, sets the
  aggregate raw status `canceled`, and increments the export generation — so a re-export is possible and the returns of the
  old postings keep being followed from the history (§11). Real case: the merchant cancels to fix an address and ships again.
- **The marker stays authoritative.** D: «cancelled when all cancelled». The framework resolves a marked order to
  `cancelled` whatever the raw status says (READ `woodev/shipping-method/order/class-delivery-status.php` `resolve()`), and
  this spec does not fight it: a return found later for those postings is shown in the plugin panel and the row flags
  (§11.1), never through the aggregate. A re-export clears the marker through the base `complete_export()`
  (`Shipment_Cancellation::clear()`), and from then on the aggregate follows the NEW generation only — a return of a
  history generation never replaces a live export's aggregate.
- MEASURED: a posting cancelled before handover produces no return. A cancel after `delivered` is impossible (Ozon).

## 10. Tracking

### 10.1 Pull-only poller (Yandex pattern)

`Ozon_Status_Poller` on Action Scheduler (`yandex:includes/tracking/class-yandex-status-poller.php`): a recurring action at
the §5.3 interval selects orders with `_woodev_ozon_active = 1` (pages of 25 orders), collects their non-terminal FORWARD
posting numbers and calls `posting/info` in batches of 100. A changed status updates the posting entry; `posting/status-history`
is fetched only for the panel on demand. Terminal posting states: `delivered`, `canceled`; an order with none left drops
`_woodev_ozon_active`. An active order with no carrier id yet (create outcome unknown) is skipped — the framework's
export retry and reconcile own it (§9.3); one with a carrier id but no postings list gets the §9.3 recovery search. An order whose last change is older than 60 days stops being polled (Yandex `COMPLETED_POLL_DAYS`). A
posting asked for and absent from the answer (F-7) is left unchanged and logged, never treated as cancelled. Returns are NOT
found through this selector — they have their own discovery and refresh in the same run (§11.2), so an order that left the
active set still receives a later return. One run at a time (an Action Scheduler unique action; a run still going when the
next is due is skipped), and a failed run waits for the next interval behind the §4.6 `auth` state.

### 10.2 Ozon status → canonical, and the aggregate

Per posting (13 values, OAS enum = August):

| Ozon | Canonical | Ozon | Canonical |
|---|---|---|---|
| `unknown` | `unknown` | `on_way` | `in_transit` |
| `created` | `created` | `not_accepted_to_delivery` | `failed` |
| `forming` | `created` | `in_delivery_point` | `ready_for_pickup` |
| `forming_failed` | `failed` | `in_courier_service` | `in_transit` (raw label kept, G-5) |
| `ready_for_shipping` | `created` | `delivered` | `delivered` |
| `in_container` | `created` | `canceled` | `cancelled` |
| `acceptance_in_progress` | `created` | | |

The provider's status meta holds ONE aggregate raw status of the CURRENT generation's forward postings, computed in order
(A = postings not `canceled`; **moving** = `on_way`, `in_courier_service`, `in_delivery_point`; **failed** =
`forming_failed`, `not_accepted_to_delivery`). D: «in transit while any posting is moving; delivered when all are terminal
and at least one delivered (cancelled ones do not block); cancelled when all cancelled».

1. A empty (all cancelled) → `canceled` (→ `cancelled`; the §9.7 marker says the same).
2. All of A `delivered` → `delivered` (the raw label says «Доставлен, отменено отправлений: N» when there are cancelled
   siblings).
3. Some of A `delivered`, some not → `partially_delivered` (→ `in_transit`, label «Частично доставлен: 2 из 3»).
4. Any of A moving → the least advanced MOVING status, `on_way < in_courier_service < in_delivery_point` (→ `in_transit`, or
   `ready_for_pickup` only when every moving posting is `in_delivery_point`). **A moving posting wins over failed and
   unshipped siblings.**
5. Every posting of A failed → `forming_failed` (or `not_accepted_to_delivery` when all are that) (→ `failed`).
6. Otherwise the least advanced status of the NON-failed postings of A along `created < forming < ready_for_shipping <
   in_container < acceptance_in_progress` (→ `created`).

A failed posting inside a non-failed aggregate (rules 3, 4, 6) raises the row flag «Отправление не принято Ozon» and is
named in the panel — the partial failure is visible without making a live shipment look terminal (canonical `failed`
retires the shared «Отменить», READ `admin/orders/class-order-actions.php` `CANCEL_RETIRED_STATUSES`). Returns never enter
the aggregate (§9.7, §11.1). The status map registered on the provider covers every aggregate token; `returning` and
`returned` are not produced.

Pinned mixtures (W8 tests, before any poller code):

| Postings | Aggregate raw | Canonical | Flag |
|---|---|---|---|
| `forming_failed`, `on_way` | `on_way` | `in_transit` | not accepted |
| `ready_for_shipping`, `on_way` | `on_way` | `in_transit` | — |
| `in_delivery_point`, `in_courier_service` | `in_courier_service` | `in_transit` | — |
| `in_delivery_point`, `in_delivery_point` | `in_delivery_point` | `ready_for_pickup` | — |
| `delivered`, `on_way` | `partially_delivered` | `in_transit` | — |
| `delivered`, `forming_failed` | `partially_delivered` | `in_transit` | not accepted |
| `delivered`, `canceled` | `delivered` | `delivered` | — |
| `canceled`, `canceled` (+ a later return) | `canceled` | `cancelled` | return flag (§11.1) |
| `forming_failed`, `ready_for_shipping` | `ready_for_shipping` | `created` | not accepted |
| `forming_failed`, `not_accepted_to_delivery` | `forming_failed` | `failed` | — |
| `delivered` + a client return `moving` | `delivered` | `delivered` | — (panel shows the return) |

So the WooCommerce order is never marked delivered while a parcel is still on its way (G §2 item 6), and a failed sibling
never makes a moving shipment look failed.

### 10.3 `storage_expires_at`

Kept per posting (OAS addition, A). Shown in the panel when the posting is `in_delivery_point`; a row flag «Хранение до
DD.MM» (`warn`) when it is less than 2 days away.

### 10.4 Shipment facts

Once per poll that changes them: aggregate delivery cost (sum) and estimated delivery date (max of `delivery_at`) through
`Shipment_Facts_Events::record()`. Per-posting promised-vs-actual dates stay in the posting entries (repeated child updates
would overwrite the single aggregate slot, G §2 item 7).

## 11. Returns (D4)

### 11.1 Model

A return is attached to its posting entry (`return` in §9.1) — in the current postings list or in `_woodev_ozon_history`.
Both OAS return types are in scope: `client_return` (after delivery) and `cancellation`. 8 statuses (OAS = August):
`unknown`, `moving`, `at_pickup_point`, `received`, `utilization`, `utilized`, `written_off`, `looking_for`. Terminal:
`received`, `utilized`, `written_off`. Panel labels in Russian; `at_pickup_point` additionally a row flag «Возврат ждёт в
пункте Ozon» (`warn`) and an order note on entry with `current_placement_name` / `_address`. Returns never change the
aggregate status (§9.7, §10.2): a delivered order with a client return stays delivered, a cancelled one stays cancelled; the
return lives in the panel, the row flag and the preview fields.

### 11.2 Two jobs: refresh known returns, discover new ones

Both run inside the poller run (§10.1), after the posting batch, independent of `_woodev_ozon_active` — so a cancelled or
delivered order that left the active set still receives its later return.

- **Refresh (known open returns).** Orders with `_woodev_ozon_return_open = 1` (pages of 25) → their non-terminal return
  numbers → `return/info` with ≤ 100 `return_numbers` per call (OAS maxItems 100). A return reaching a terminal status
  leaves the set; the order drops `_woodev_ozon_return_open` when none is left. Calls per run: ⌈open returns / 100⌉.
- **Discovery (account-wide, resumable).** State in one option `woodev_ozon_delivery_return_scan`: `watermark`, `cursor`,
  `pass_from`, `pass_max_created_at`. A pass sends `return/search` with `filters.shipment_method_id` = configured,
  `filters.created_at_from` = `watermark − 24 h` (overlap for late-visible records), NO status filter, pages of 100, and
  saves `next_cursor` verbatim after every page. **At most 3 pages per run**; an unfinished pass resumes from the saved
  cursor in the next run. An empty `next_cursor` ends the pass: `watermark = pass_max_created_at`. The first watermark is
  the time of the shop's first Ozon export. A return number already attached is skipped (no API call); a new one is
  matched (§11.3) and attached; a non-terminal one sets `_woodev_ozon_return_open`.
- **Failure.** A failed page keeps the cursor and ends the job for this run; the next run is the retry (the interval is
  the backoff), behind the §4.6 `auth` state.
- **If the server ignores the filters (U8):** the pass reads the whole account history and filters locally by method and
  `created_at` — slower to catch up, but still ≤ 3 pages per run, so the bound holds.

Real case behind the page cap: one `looking_for` return open for months must not make every run re-read all terminal
returns since its creation (the earlier draft did exactly that).

### 11.3 Matching a return to a posting — UNVERIFIED (U9)

The return record carries no posting number (OAS: `return_number`, `return_external_id`, `barcode`, …). Rule:

1. `return_external_id` = one of our `posting_external_id` values `{order id}-{generation}-{n}` (§9.2): the order id is
   parsed from the key, that order is loaded, and the entry with exactly that key is found among the current postings and
   `_woodev_ozon_history`. The key is unique across generations, so a generation-1 return arriving after a re-export
   attaches to the generation-1 posting in history, never to the replacement.
2. Else `return_number` = a `posting_number`: one datastore-aware meta search over `_woodev_ozon_postings` and
   `_woodev_ozon_history` for the quoted number (≤ 300 lookups per run, bounded by the page cap).

An unmatched return is logged and listed in the settings group «Возвраты без заказа» (number, status, placement) so the
merchant still sees it. R3 (the first real return) settles the key; until then this is the release's least certain link.

### 11.4 Return barcode on demand

The barcode is an ACCOUNT-level PDF the merchant shows to collect returns at the pickup point (`return/download_barcode`, no
body). `return/reset_barcode` answers the NESTED shape `{barcode_content: {barcode, expires_at}}` (READ OAS
`Return.ResetBarcode.Response` → `Return.Types.ResetBarcodeContent`); the typed client result unwraps `barcode_content`, and a
response fixture pins it. It expires, so it is never stored.

- Where: the plugin order panel of an order with a return `at_pickup_point`, and the «Возвраты» group of the settings tab:
  «Скачать штрихкод для получения возвратов» (streams the PDF) and «Выпустить новый штрихкод» (`reset_barcode`, confirm
  «Старый штрихкод перестанет действовать»; shows the new code and its expiry).
- Capability `manage_woocommerce`, nonce, at most one download per 10 s per user (real case: a double click hitting Ozon twice).
- **Licence (settled, O3):** both stay available without an in-force licence — an explicit exception to the gate, because
  the parcels were shipped under a valid licence and the barcode is the only way to take the merchant's own goods back.
  `reset_barcode` writes at Ozon, so this is a deliberate exception, not a `read_only` declaration.

## 12. Admin order UI

### 12.1 The framework orders table (one row per order)

Registration on every request (donor `yandex:includes/orders/class-yandex-order-actions.php`): provider, shipment and tracking
handlers, document source, `Order_Marker` writer (G §2 item 2). The row shows the aggregate status (§10.2), the Ozon order
number as the tracking id, and the standard actions (export, cancel, label for single-posting orders, refresh). Tracking link
template UNVERIFIED (U10) — none until measured. Row flags (meta only, never an API call), in this priority: «Пополните
баланс Ozon», «Отправление не принято Ozon» (a failed posting beside live ones, §10.2), «Возврат ждёт в пункте Ozon», «Ждёт
подтверждения Ozon», «Хранение до DD.MM» (the framework shows ≤ 3 per row). Preview fields: number of postings,
delivered/total, returns (count and the most advanced return status, including returns of history generations).

### 12.2 Plugin order panel «Ozon Доставка» (order edit screen, HPOS and posts)

Ozon order number and generation; a postings table — number, status (Russian label + Ozon code in a tooltip), dimensions and
declared value, cost, promised vs actual date, storage expiry, «Этикетка», «Отменить отправление»; returns per posting —
number, status, placement; earlier Ozon orders from `_woodev_ozon_history`. Order actions declared through
`woodev_shipping_order_actions`: «Подтвердить отправления» (write), «Обновить статусы Ozon» (`read_only => true`, ≤ 1 per 30 s per
order), «История статусов» (`read_only`, on demand `posting/status-history`).

### 12.3 What stays where

Framework: export / cancel / bulk print / status filters / emails / delivered-cancelled order statuses — all on the aggregate.
Plugin: anything per posting or per return, and the barcode. Nothing in the plugin re-implements a framework list or email.

### 12.4 Admin create-order wizard (decision row «—»)

Not offered: in the admin rate calculator context both Ozon methods return `null` without a report, because the shared wizard
cannot carry the recipient phone (G §2 item 1). A plugin-side alternative (reading the phone the manager typed) would need the
wizard's payload — that is the framework change G names, so it stays out (§15). An order created by hand on the WooCommerce
screen with an Ozon line can still be exported when it has a phone and a point (or courier snapshot); otherwise export refuses
with the missing item named.

## 13. Worst-case Ozon traffic per shop

P = non-terminal forward postings, R = open returns, E = exports per day, N = postings per export (≤ 100). Runs per day
= 96 at the shortest poll interval (15 min). «During a failure» = Ozon or the key is broken (§4.6).

| Source | Bound (cap + backoff) | Worst case per day |
|---|---|---|
| Catalogue sync | ~452 list + ~452 info calls per run, 200 ms spacing; daily; a failed action retried 5 → 15 → 60 min, ≤ 5 times, cursor kept (§6.3) | ~904, plus a manual «Обновить сейчас» |
| Token | healthy: one per ~hour per scope set; failing: `auth` class of §4.6 | healthy ~24–48; during a failure ≤ 100 |
| `check-client` | answers cached 24 h (true) / 1 h (false); ≤ 10 phones per buyer session per hour; failures: `check_client` class of §4.6 | healthy: proportional to checkouts reaching the phone; during a failure ≤ 100 |
| Quotes | successes via the rate cache, refusals cached 1 h (§7.4); failures: `quote` class of §4.6 | healthy: proportional to checkouts; during a failure ≤ 100 |
| Export (automatic or button) | first attempt: 1 `check-client` + 1 quote + 1 create; unknown outcome: ≤ 5 `Export_Retry` attempts, each a ≤ 5-page reconcile search + at most one replayed create; then N approves | E × (3 + N) healthy; ≤ E × (3 + 30 + N) in the worst unknown-outcome case |
| Approval checks (§9.4) | ≤ 5 steps per export, each ⌈N/100⌉ `posting/info` + a re-approve only for postings still `created` | ≤ E × 5 × (1 + N) |
| Status poll (§10.1) | ⌈P/100⌉ `posting/info` per run; one run at a time | 96 × ⌈P/100⌉ |
| Return refresh (§11.2) | ⌈R/100⌉ `return/info` per run | 96 × ⌈R/100⌉ |
| Return discovery (§11.2) | ≤ 3 `return/search` pages per run, resumable cursor; a failed page waits for the next run | ≤ 288 |
| Labels, barcode, «Обновить статусы Ozon» | merchant clicks only; barcode ≤ 1 per 10 s per user, refresh ≤ 1 per 30 s per order | — |

Example: 1 000 active postings, 100 open returns → status poll + returns = 96 × (10 + 1 + 3) = **1 344 per day**, flat
whatever the age of the oldest open return. No automatic call loops on an error: every automatic call above has a cap and
a backoff, and the checkout classes cannot exceed 300 failed calls a day in total (§4.6).

## 14. Test strategy

### 14.1 Unit (PHPUnit + Brain Monkey / Mockery, no network)

Fixtures recorded from the live API (R1 re-records the read-only set; W's write responses are transcribed into fixtures by W6
— the raw files were in a session scratchpad and are not in git). Required tests: token cache from an absolute `expires_in`
(incl. a past and a far-future value), `invalid_scope` fallback and stored scope state, both error envelopes, per-item errors
with the key absent, `JSON_BIGINT_AS_STRING` ids, seven-digit timestamps, secret masking through the real sanitizer; phone
normalization table (W's numbers); quote aggregation (sum, max, any error → null, >100 boxes); declared-value split to the
kopeck from `line_subtotal` incl. the coupon example of §7.2; refusal cache; §4.6 backoff (repeated recalculations during a
token / `check-client` / quote failure send only the scheduled probes; the compare-and-swap claim lets one of two
concurrent requests through; a credential save resets `auth`; no stale rate is served); idempotency key determinism; saved
request replay (same key → same body, no fresh check; refusal deletes it); the create/approve boundary (§9.3: an approve
exception after a successful create leaves the carrier id, the postings with `approve = pending|failed`, export success;
reconcile search finds the order by `posting_external_id` prefix); aggregate status rules (§10.2: one test per rule plus
every pinned mixture); return refresh / discovery (page cap, cursor resume, watermark overlap, terminal on discovery, a
cancelled and a delivered order that left the active set still get their return); return matching (§11.3, both keys, a
generation-1 return after a re-export); `reset_barcode` nested response; catalogue sync resume from a saved cursor,
generation switch, last-good serving; `work_time` rendering skipping past dates.

### 14.2 Stub server and integration

CI uses an HTTP stub serving the fixtures (RP precedent `rp:` §16.3 of its spec) for the client and the poller; one
integration test boots the real entry path with a signed in-force licence claim (core skill).

### 14.3 Live calls — what is safe

- **Safe, read-only (worker may run with the operator's key, small volume):** token, `check-client`, `delivery/location`,
  `delivery-point/list|info|check-availability`, `order/checkout`, `posting/info|search|status-history`, `return/search|info|
  status-history`, `return/download_barcode`, `shipment-method/search|info`, `dropoff-point/search` (needs `filters`, W).
- **Needs the operator's word (real shipment or account change):** `order/create`, `posting/approve` (balance),
  `posting/cancel`, `return/reset_barcode` (invalidates the merchant's barcode), anything under `shipment-method/create|update|
  delete`. `posting/label` is read-only but exists only after an approve.
- **R2 is authorised (O5):** up to **10 real orders** on the operator's TEST cabinet, each cancelled before handover, in
  the same run. Anything beyond that, and `return/reset_barcode`, still needs his word.
- **No sandbox** (§0 note 2): settled; no O-question remains for it.

### 14.4 Rig acceptance (functional acceptance is the coordinator's)

Classic and block checkout as the ONLY Woodev plugin on the site and again beside CDEK and Yandex: the four phone states of
§8.3 with W's numbers, phone edit after choosing Ozon (re-rate and the placement error), pickup on the map in Moscow and a
small town, courier with a house-level address and with a street-only one; export of a 1-box and a 2-box order, labels,
cancel of one posting and of all, re-export; poller over a real order. Write steps per §14.3.

### 14.5 Gates

`composer test`, `composer phpcs`, `composer phpstan`, `php -l`, local-ci (`-w` per workflow) + a critic of another lineage per
PR; `npm run lint:docs` here for this spec.

## 15. Out of scope for 2.0.0

Containers, arrival passes, drop-off and return-point management (D8); several shipment methods per site or per zone instance;
the admin create/edit-order wizard (§12.4); native per-posting rows/filters in the framework orders table and an
`out_for_delivery` canonical status (G §3); a merged multi-posting label PDF (O4); insurance certificates
(`posting/get-insurance-certificate`); a buyer-facing tracking page or e-mail beyond the framework's; a «only lockers / only
pickup points» instance switch; COD (not in the API); Chestny Znak markings and cancel reasons (absent on this host, H-1); an
onboarding wizard (§5.4); a cookie cache for testcookie (§4.3).

## 16. Registers

### 16.1 UNVERIFIED

| # | Item | How to settle | Blocks release? |
|---|---|---|---|
| U1 | Real rate limit behind 429 and its body shape | observed in production logs | No — pacing and backoff exist |
| U2 | `delivery-point/info` with 1000 ids (OAS) | R1, one call | No — 100 is used |
| U3 | `Idempotency-Key` retention time | not testable cheaply | No — keys are per generation, and a replay only follows a read-only reconcile search that found nothing (§9.3) |
| U4 | Does `order/checkout` return `RecipientError` for a phone `check-client` refuses | R1 with `+79990000001` | No — §8.5 keeps check-client |
| U5 | Does a phone-only edit in block checkout reach the server | W5 spike | No — fallback donor named |
| U6 | Are DaData suggestion coordinates house-precise | R2 (courier create) + rig | No — house-level rule |
| U7 | `country` value form in the courier create body | R2 | Courier only |
| U8 | Does `return/search` honour its filters | R1 | No — local filtering fallback |
| U9 | Which field links a return to its posting | R3 (first real return) | Returns matching only |
| U10 | Public tracking URL for a posting number | R1 (read the cabinet / docs) | No — no link until then |
| U11 | `NotEnoughBalance` in practice (not seen at 0 ₽) | first funded/unfunded production shop | No |
| U12 | Whether a shipment method of type `dropoff` can be handed over without a container | first real handover (D8) | Possibly — D8 revisit |

### 16.2 Interpretations (coordinator may revisit)

Items 12–20 were added after critic round 1 (s204), each the simplest choice that closes the concrete failure the critic
named.

1. Plugin id `ozon_delivery`, method ids `ozon_delivery_pickup` / `ozon_delivery_courier` (§2).
2. One pickup method for both pickup points and lockers (§2, §6.4).
3. Rate = delivery + insurance, no setting (§7.3).
4. Representative-point quote before a point is chosen (§7.3) — now settled by O7, kept for numbering.
5. Export = create + approve in one action (§9.3).
6. Approval check delays 15 s … 1 h (§9.4).
7. `check-client` cache 24 h / 1 h, cap 10 phones per session per hour (§8.5).
8. Catalogue: daily at 03:00, 10 pages per action, inactive kept 3 generations (§6.3).
9. Labels: document source only for single-posting orders (§9.6).
10. No onboarding wizard (§5.4).
11. Status poll default 30 min (§5.3).
12. Release identity in ONE place: the build script's `slug` = ZIP folder, the repo main file is `${slug}.php`
    (provisional `woocommerce-ozon-dostavka`); the text domain stays `woodev-ozon-delivery`, decoupled (§2).
13. `posting_external_id` = `{order id}-{generation}-{n}`; `order_external_id` gains `-{generation}` from generation 2 (§9.2).
14. The create request is saved before the call and replayed verbatim with its key while the outcome is unknown; a
    refusal deletes it (§9.2).
15. Create/approve boundary: approve runs on the plugin's `shipment_exported` callback after the id is stored, catching every
    API exception; reconcile = read-only `posting/search` over `sent_at − 5 min … + 60 min`, ≤ 5 pages (§9.3).
16. Aggregate: a moving posting wins; `failed` only when every live posting failed; a failed sibling is a row flag (§10.2).
17. Returns never enter the aggregate; the cancellation marker stays authoritative (§9.7, §11.1).
18. Returns: refresh by `return/info` (100 per call), discovery by a resumable watermark scan (24 h overlap, ≤ 3 pages per
    run, no status filter) (§11.2); matching parses the order id from `return_external_id` (§11.3).
19. Failure backoff: three classes (`auth`, `check_client`, `quote`), 60 s doubling to 15 min, `Retry-After` ≤ 1 h, one
    compare-and-swap probe per window (§4.6); quote refusals cached 1 h (§7.4).
20. Declared value per unit from `line_subtotal` (cart) / `get_subtotal()` (order), not `Packaging::get_value_context()` (§7.2).

### 16.3 Operator questions — all answered

O1–O8 were answered on 30.10.2026 («всё по рекомендациям, кроме п.1»); the binding text is the «Operator answers» table of
[decisions.md](../research/2026-10-30-ozon-gap-refresh/decisions.md). Where they land: O1/O2 version 2.0.0 of EDD download
58107, old folder/main-file names kept (§2, W0); O3 barcode without a licence (§11.4); O4 per-parcel labels (§9.6); O5 R2
with ≤ 10 test-cabinet orders, each cancelled before handover (§14.3, R2); O6 R3 after the first real handover (R3); O7
nearest-point price (§7.3); O8 the existing GlitchTip project, DSN in the private repo (§2, W0). No open operator question
blocks any card.

## 17. Work breakdown (repo `kalbac/woodev-ozon-delivery`, one reviewable PR per card into `main`)

Every brief names the worker's own worktree path for Serena; `tests/**` is Serena-ignored. R-cards are measurements (Haiku with
a detailed brief), read-only unless marked. Rounds: at most 2–3 per card, then decompose.

| Card | Scope | Acceptance checks | Depends on |
|---|---|---|---|
| **W0** skeleton | **first step:** look for the old 1.0.3 ZIP (card `kalbac/woodev-ozon-delivery#9`); found → its folder and main-file names, else the provisional `woocommerce-ozon-dostavka` (§2) — W0 does NOT wait for it. Remove the rig harness; `${slug}.php` (header `Version: 2.0.0`, loader literals incl. `download_id` 58107, HPOS + blocks, `framework_version` 2.0.16), `includes/class-plugin.php` (`Shipping_Plugin`), lifecycle (install creates §6.2 table), autoloader, `composer.json`, CI + `.github/local-ci.Dockerfile`, `scripts/sync-framework.sh` and `scripts/build-release-zip.sh` (its `slug` = the ONE place of the folder name) + release workflow copied from `rp:`, `tests/bootstrap.php`, `languages/` header, DSN filter reading the DSN kept in the private repo / build env (O8) — never a DSN in this public repo; wp.org collision search for the ids | boot through `Woodev_Loader` from a ZIP-shaped tree whose folder = `slug` and main file = `${slug}.php`; header/loader version test (2.0.0, 58107); licence gate active in an integration test; no 1.0.3 option read; local-ci green | — |
| **W1** API client | `includes/api/*`: client, token (scopes + `invalid_scope` fallback), error envelopes, trace id, idempotency header, secrets, bigint/timestamp parsing, the §4.6 backoff state (three classes, compare-and-swap probe, credential reset), `reset_barcode` nested result; fixtures | §14.1 transport list; sanitizer test; §4.6 backoff tests; nested `barcode_content` fixture | W0 |
| **R1** read-only re-record | record fixtures for every safe call of §14.3 with the operator's key; settle U2, U4, U8, U10 | fixtures committed under `tests/fixtures/ozon/`, answers written into `docs-internal/research` of the plugin repo | W1 (or the recon harness) |
| **W2** settings | settings handler, connection test, method select / manual id, scope state display, phone-field-hidden warning, `is_configured()` | settings round-trip incl. secret; select from fixture; manual-id fallback on `invalid_scope`; no call at checkout | W1 |
| **W3** catalogue | table, sync job (list/info, cursor, generations, backoff), `Ozon_Point_Source`, `work_time`, settings status group | §14.1 sync tests; a stub run of 3 pages resumes after a killed action; bbox query returns only active, method-matching, fitting points | W1 |
| **W4** quoting | two method classes, packing → postings, declared value from `line_subtotal` and its split, aggregation, representative point, rate cache context (incl. the subtotal map), refusal cache, `quote` backoff, refusal helper | §14.1 quote tests incl. the coupon example; every refusal path reports once; repeated recalculation during a quote failure sends only the §4.6 probes, no stale rate | W2, W3 |
| **W5a** spike: blocks phone transport | prove U5 on the rig (phone-only edit, shipping and billing phone); pick the transport | written result + a minimal working hook | W4 |
| **W5** checkout phone-first | §8.1–8.6: normalization, session/customer transport, package hash, `check-client` cache, cap and `check_client` backoff, hints (classic row, Store API data + blocks script), courier address rule and snapshot, placement re-check; `.po` entries | unit tests for normalization, cache, cap, backoff (same phone, outage → probes only), rate meta hash; rig: the §8.3 table on both surfaces, phone edit after selection | W4, W5a |
| **W6** export | shipment handler and client: first attempt (fresh check + quote), saved request + `_woodev_ozon_active` before create, replay mode, refusal deletes the request, `posting_external_id` `{order id}-{generation}-{n}`, create/approve boundary on `shipment_exported` (§9.3), `supports_reconcile()` + `find_exported_order()` window search, approve, verification schedule incl. missing-postings recovery and re-approve, posting storage, `NotEnoughBalance`, «Подтвердить отправления», generation, export subtotal value | fixture tests incl. W's transcribed write responses; same-key replay sends the saved body with no fresh check; an approve exception after create → export success, id kept, `approve` = `pending` or `failed`; a killed request after create is recovered by the window search; a create refusal leaves no saved request | W5 |
| **R2** write round 2 (authorised, O5) | ≤ 10 real orders on the operator's TEST cabinet, each cancelled before handover in the same run: a 2-box pickup order and a courier order — create, approve, label, cancel one posting, cancel all; one same-key replay; record country form (U7) | responses recorded; every order cancelled before handover; order count ≤ 10 stated in the result | W6 |
| **W7** documents, cancel, panel | document source (single posting), per-posting label route, async cancel (client exception, settle on all-cancelled: history, marker, aggregate `canceled`), plugin order panel (postings, returns incl. history generations), order actions, row flags in the §12.1 priority, preview fields | panel from fixtures (1 and 3 postings); cancel never clears the id early; re-export after full cancel uses generation 2 and clears the marker | W6 |
| **W8** tracking | poller (one run at a time, `auth`-gated), status map, aggregate rules of §10.2, failed-sibling flag, `storage_expires_at`, shipment facts, 60-day stop | every pinned mixture of the §10.2 table is a test written BEFORE the poller code; a mixed 3-posting fixture never reaches `delivered` early; no aggregate `returning`/`returned` | W6 |
| **W9** returns | return refresh (`return/info`, `_woodev_ozon_return_open`), resumable discovery scan (watermark, 24 h overlap, ≤ 3 pages per run, cursor, local filter fallback), matching by the unique key across current + history, unmatched list, placement flag/note, barcode download/reset (nested result) with licence exception | §14.1 return tests: page cap holds with 10 000 stub returns; a cancelled and a delivered order outside the active set get their later return; a generation-1 return after a re-export attaches to history; matching both keys; barcode never stored; licence-off still downloads | W8 |
| **R3** first real return (O6) | settle U9 | recorded return record | a real handover |
| **W10** acceptance and release prep | rig acceptance §14.4, release ZIP installed on a clean site, merchant docs card (setup: application scopes, shipment method, balance; the phone-first behaviour) | rig run recorded; ZIP boots from its own `woodev/` | W5, W7, W8, W9 |

**Parallelism (disjoint files).** W1 alone after W0. Then W2 ∥ W3 (settings vs `includes/pickup/` + the table) and R1. W4 after
both. W5a → W5. W6 after W5. W7 ∥ W8 after W6 (panel/documents vs `includes/tracking/`); both add one wiring line to
`includes/class-plugin.php` — merged serially, each rebasing and re-applying its line. W9 after W8. W10 last. Release (EDD
upload, framework pin) waits for the operator.

## Related

- [Ozon s203 decisions](../research/2026-10-30-ozon-gap-refresh/decisions.md) — the binding input.
- [Framework gap refresh](../research/2026-10-30-ozon-gap-refresh/report.md) — G-1…G-8 with file:line evidence.
- [API diff 30.10 vs August](../research/2026-10-30-ozon-gap-refresh/api-diff.md) and
  [live write measurement](../research/2026-10-30-ozon-gap-refresh/write-measurement.md) — the API facts.
- [T-Bank v2 plugin design](2026-10-27-tbank-v2-plugin-design.md) — structure and how decisions are cited.
- [Russian Post v2 plugin design](2026-10-21-russian-post-v2-plugin-design.md) and
  [Yandex v2 plugin design](2026-10-12-yandex-v2-plugin-design.md) — carrier patterns reused here (poller, settings, release ZIP).
- [Framework architecture](../wiki/architecture.md) — where each shipping responsibility lives.
- [Clean-break policy](../adr/005-platform-v2-clean-break-policy.md) — installed-site data is a contract from 2.0.0 on.
