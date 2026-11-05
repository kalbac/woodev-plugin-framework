# «Стоимость доставки за расстояние» 2.0.0 on Framework v2 — design

**Date:** 2026-11-05 (s217). **Status:** draft, Revision 1 — critic round 1 (14 findings) and operator decisions
D14–D15 applied (changelog §11.1); the grid scheme was answered by the operator in D14–D15.
**Target:** release `2.0.0` of the existing plugin «Расчёт стоимости доставки за расстояние» (plugin id / method id
`range_rate`, EDD download `7698`, v1 1.2.0), rewritten in place on Framework v2 (2.0.25+). Price **4 000 ₽** (one site,
one year of updates); v2 ships as an ordinary update to **every active licence** (D9).

**Evidence labels:** **READ** (code/doc read this session), **UNVERIFIED** (reason given), **Provisional** — a gap this
spec closed itself; every Provisional item is repeated in §11.
**Inputs, cited as:** `D1…D13` = operator decisions (s217 brainstorm, `decisions.md` in the coordinator's scratchpad);
`R §n` = v1 recon report; `G §n` = geo-service research (05.11.2026); `v1:` = the plain tree
`/Users/maksimmartirosov/Projects/legacy-v1/woocommerce-range-shipping/`; `fw:` = this repo's `woodev/` (2.0.25, read via
Serena). Shape reference: [2026-11-04-tracking-v2-plugin-design.md](2026-11-04-tracking-v2-plugin-design.md).

## 0. Binding decisions (nothing below reopens them)

| # | Decision (operator, 05.11.2026) | Lands in |
|---|---|---|
| D1 | Full release, «second life»; straight-line mode is a must; paying foreign geo services from RF is the main sales blocker | §1, §4 |
| D2 | Warehouses table on the settings page; name + DaData address; exactly one «Основной»; method field 0/1/many states; «Ближайший к покупателю» = straight line to all, ONE route request to the winner; delete rules; v1 origin → warehouse #1 | §3.1, §2.3, §7a, §7c, §8 |
| D3 | DaData is ALWAYS the geocoder (admin + checkout) through the framework location layer; free Suggestions key is enough | §2.2, §6 |
| D4 | Six distance services; straight line default (haversine × coefficient, default 1.0); error/quota → straight-line fallback | §4 |
| D5 | Engine kept for migrated v1 rules, simple distance-band grid by default, full redesign allowed, v1 rules convert automatically with identical prices; framework cost features on top; whole-km rounding kept for migrated instances | §5 |
| D6 | Framework `Shipping_Method`; settings on woodev-settings; in-place upgrade, id `range_rate`; v1 hooks kept; travel modes fixed, «с пробками» only where supported; order meta warehouse/distance/time/service shown in admin; label suffix «(12 км)» | §2, §9, §3.4 |
| D7 | «Требовать точный адрес» (off by default) refuses submit unless house-level; otherwise approximate price + notice, placement select «Не показывать / Под методом / Под общим списком», modelled on the pickup-button placement | §6 |
| D8 | Classic AND block checkout fully in the first release | §6 |
| D9 | 4 000 ₽; v2 to every active licence | header |
| D10 | Demo site `range.wootest.ru` by Homelab AI | §10.3 |
| D11 | Services as connectors: list on the settings page, «По прямой» built in and default; each instance picks one; disconnected → instances fall back to straight line + admin notice; runtime failure → straight line, no chains | §3.2, §4 |
| D12 | ONE global road coefficient, shown in the built-in «По прямой» row; also used by the fallback; no per-method override | §3.2, §4.1, §7b |
| D13 | UI naming: section «Расчёт расстояния», each entry a «сервис» («Подключить сервис», «Проверить подключение»); method field «Расчёт расстояния»; never «шлюз»/«провайдер» in UI; code keeps `provider` | §7 |
| D14 | «+ за каждый км» on the WHOLE distance with a «Пример» column; grid drops per-minute price, quantity conditions and «× количество» (advanced keeps them for migrated rules); new instances `ceil`, migrated `round()` | §5.2, §5.3 |
| D15 | Rows contiguous by construction: only row 1 has an editable «от»; others read-only «свыше N»; inclusive «до»; below «от» not delivered; blocking row errors; delete keeps contiguity; empty last «до» = «и дальше»; empty cost invalid, explicit «Не доставляем» checkbox; decimals, rounding before lookup; 0-kg products | §5.2–5.6, §7c |
| D16 | Products without weight: the simple grid uses the framework default weight («Доставка» tab, `Default_Dimensions_Settings`, builtin 100 g); migrated advanced rules keep v1 raw cart weight; replaces D15's 0-kg rule | §5.2 |

Standing operator rules that bind every section: licence gate is the default; no invented safeguards (KISS); merchant
diagnostics only behind «Логирование»; copy for non-IT merchants; keys never printed or logged (keys-leak rule); every
automatic outbound call has backoff + cap; settings live on `woodev-settings`, never WC Integrations.

## 1. Goal, scope, non-goals

**Goal.** A courier price by distance from the shop's warehouse that works out of the box for free (DaData free key +
straight line), gets real road distance from any of five services when the merchant connects one, never silently
disappears, and keeps every installed v1 shop's prices identical after the update.

| In scope (2.0.0) | Non-goals (named once, not built) |
|---|---|
| Warehouses (N), nearest-warehouse choice | Per-warehouse stock / per-product warehouse |
| 6 distance services behind one interface, straight-line fallback | Multi-service chains, «cheapest service» auto-pick |
| Distance-band grid + weight surcharge/limit; v1 engine for migrated rules | Time-based pricing in the grid (travel time moves with traffic → unstable price); kept only in the advanced engine |
| Framework cost features (markup, free from, rounding, min/max, order-amount limits) | Delivery days display, box packing, COD, city limits, order export, «Заказы доставки» page |
| Classic + block checkout, DaData hints via framework | A plugin-owned suggestion widget (v1 `checkout.js`, `suggestions-plugin.js`, jsDelivr) |
| Order line meta (warehouse, km, min, service) | Separate order meta box, buyer e-mails with distance |
| In-place upgrade `upgrade_to_2_0_0` | Auto-adding the method to RU zones on install (v1 `class-lifecycle.php:30-63`) — dropped, Provisional P1 |

**Product facts:** price 4 000 ₽ (was 3 000 ₽, R §6), v2 to every active licence, download 7698, licence option
`woodev_range_rate_license_key` kept by construction (R §3).

## 2. Architecture

### 2.1 Base class: `Shipping_Plugin`, not `Woocommerce_Plugin`

**Decision: `Range_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin`; `get_api(): null`.** Justification by what
the framework actually requires (READ):

| Need | Only on `Shipping_Plugin` / `Shipping_Method` | Pointer |
|---|---|---|
| D6 «framework Shipping_Method» | `Shipping_Method::__construct()` calls `$this->get_plugin()->set_shipping_method()` — the plugin must be a `Shipping_Plugin` | fw `shipping-method/class-shipping-method.php:402` |
| Licence gate before any rate, manager-visible reason | `calculate_shipping()` → `License_Gate::is_in_force()` + `report_license_hidden()` | `class-shipping-method.php:1002, 1029`, `class-license-gate.php:61` |
| Cost features D5 | `apply_price_features()` (markup → free → rounding), cost limits after | `class-shipping-method.php:1315-1372, 2739` |
| Rate cache | `FEATURE_RATE_CACHE`, `Shipping_Rate_Cache::DEFAULT_TTL = 600` | `class-shipping-rate-cache.php:67` |
| Hidden-reason report (buyer sees nothing, manager sees why, log line) | `report_hidden()` | `class-shipping-method.php:1393` |
| D3 location layer, checkout hints classic + block | `get_location_service()`, `needs_location_provider()`, `get_location_adapter()`, `get_checkout_handler()` | `class-shipping-plugin.php:2944, 2974, 3072, 3118` |
| One settings tab with «Дополнительно» (logging) | `get_settings_providers()` final composite + `get_tab_settings_providers()` | `class-shipping-plugin.php:1518, 2021` |
| Rate description under the method (classic) | `render_rate_additional_info()` on `woocommerce_after_shipping_rate` | `class-shipping-plugin.php:922` |

Abstract obligations: `get_shipping_method_classes()` (one class), `get_api(): ?Shipping_API` → `null`
(`class-shipping-plugin.php:181, 190`). The plugin has no orders provider, so «Выгрузка заказов» and the orders page are
never added (`Orders_Registry::plugin_exports_orders()` is false). The trade-off R §7 named (a big base class) costs
nothing at runtime here: every subsystem it carries is opt-in.

### 2.2 Classes and responsibilities

Namespace `Woodev\Range_Shipping`, files under `includes/` (layout of the tracking template
`woocommerce-order-tracking-number`: own autoloader, `Woodev_Loader::register()` in the main file).

| Class | Responsibility | ~LOC |
|---|---|---|
| `Range_Plugin` | bootstrap, method class list, settings contributions, location opt-in (`needs_location_provider() = true`, `Range_Location_Adapter`), checkout handler, lifecycle, admin notices | 250 |
| `Range_Location_Adapter` | trivial `Location_Adapter::resolve()`: returns `['lat','lon','qc_geo']` from the record (the plugin needs no carrier id) | 40 |
| `Range_Checkout_Handler` | declares the three `Field::source_location()` fields (region, settlement, address) like other carriers; D7 validation hooks | 150 |
| `Range_Shipping_Method extends Shipping_Method` | `rate_package()`: point → warehouse → distance → rules → `Shipping_Rate` + meta; form fields; cache context | 400 |
| `Warehouses\Warehouse_Store` | read/write the warehouses option, main invariant, nearest by haversine | 180 |
| `Geo\Destination_Point_Resolver` | package → `Geo_Point{lat,lon,precision,qc_geo,source}` (§2.3 step 2) | 180 |
| `Geo\Haversine` | great-circle km, R = 6 371.0088 km | 30 |
| `Distance\Distance_Provider` (interface) + 5 adapters + `Straight_Line_Provider` | §4 | 6 × 120 |
| `Distance\Distance_Service` | picks the instance's provider, cache, budget/breaker, fallback, one call per quote | 220 |
| `Distance\Distance_Cache` | transient cache of `Distance_Result` (§2.4) | 80 |
| `Distance\Call_Guard` | atomic daily counter + circuit breaker per provider (§2.5) | 150 |
| `Rules\Band_Grid` | grid model + evaluation (§5.2) | 150 |
| `Rules\Legacy_Rules_Engine` | v1 loop ported 1:1 (§5.4) | 250 |
| `Rules\Rules_Converter` | v1 `rules` → grid or advanced, with self-check (§5.5) | 200 |
| `Settings\Range_Settings` (`Woodev_Abstract_Settings`, namespace `range_rate`) | warehouses table, «Расчёт расстояния» sections, connection probes | 300 |
| `Admin\Instance_Fields` | custom WC field types `range_warehouse`, `range_bands`, `range_advanced_rules` + one admin script (no inline JS, v1 defect R §8.11) | 250 PHP + 400 JS |
| `Checkout\Approximate_Notice` | D7 notice in three placements, classic + block | 150 PHP + 60 JS |
| `Lifecycle` (`Woodev_Lifecycle`) | `upgrade_to_2_0_0( $installed_version )` (§8) | 250 |

### 2.3 Data flow of one quote

```
package ──▶ [0] gates (framework): cart-request veto · License_Gate · is_available_for_package
          ▶ [1] address string  (v1 order: country, state, city, address_1, address_2;
                filter woocommerce_shipping_range_rate_get_customer_address_string)
          ▶ [2] Destination_Point_Resolver
                a) framework chain: Location_Service::get_customer_record_at('address')
                   used only if provider_id() === 'dadata' AND raw qc_geo + lat/lon present AND its
                   street + house + block (корпус/строение) all match package address_1/address_2
                   (normalised token compare — the chain's own staleness gate is settlement-level only)
                b) package has NO street (cart page) → settlement record, same DaData-only rule,
                   precision «approximate»
                c) else server geocode through DaData DIRECTLY (get_registered_provider('dadata'),
                   never the active provider): suggest(address string, level address, 1 row)
                   → geo_lat/geo_lon/qc_geo (free tariff, G §B); guarded by Call_Guard 'dadata_geocode'
                   (§2.5); hit cached 30 d, empty answer cached 1 h by md5(normalised string)
                d) guard closed (cap / cooldown) → settlement point from (b) if any, approximate;
                   else hidden `geocoder_unavailable`
                → Geo_Point{lat, lon, qc_geo, precision: exact (qc 0–1) | approximate (2–4) | none (5 / no coords)}
          ▶ [3] warehouse: instance «Склад» = id | '' (main) | 'nearest'
                nearest = min Haversine(point, each warehouse); tie → main, then lower id
          ▶ [4] Distance_Service.distance(warehouse, point, mode, avoid)
                straight line → computed every time, never cached (cheap maths, §2.4)
                road service: cache hit? → result · else Call_Guard reserves a slot? → adapter call
                failure / quota / breaker open / service disconnected → Straight_Line × coefficient (D11/D12)
                → Distance_Result{metres, seconds|null, provider_id, fallback:bool, reason}
          ▶ [5] round km per «Расстояние для расчёта», THEN filter
                woocommerce_range_rate_shipping_calculated_distance (km, metres) ← v1 hook, v1 order;
                the filtered value is used as returned — never rounded again (both engines)
          ▶ [6] rules: Band_Grid (mode grid) | Legacy_Rules_Engine (mode advanced) → cost | HIDE
          ▶ [7] Shipping_Rate(label + suffix, cost, meta) → framework: markup → free → rounding → min/max
          ▶ [8] description / notice (D7), WC rate cache, order line meta on checkout
```

**Why step 2a works without a paid call (READ):** the DaData mapper puts `geo_lat/geo_lon` into the record and the
whole field set (incl. `qc_geo`) into `raw` (fw `location/providers/class-dadata-provider.php:1436-1439`); the customer
store persists `Location_Record::to_array()` — `raw` included — per level in the session / user option
(`class-customer-location-store.php:1422-1465`); `get_customer_record_at()` reads it back (`class-location-service.php:1017`).
The framework itself never reads `qc_geo` (no occurrence in `woodev/`), so the plugin reads it from `raw()` in its
adapter. The chain's staleness gate compares settlement and region only (`is_customer_record_stale()`), hence the
plugin's own building check against the package address — street, house AND `block()` (DaData stores корпус/строение
separately from the house, `class-dadata-provider.php:1432-1433`), so «д 22 стр 1» is never reused for «д 22 стр 6».

**DaData ownership (D3) is guaranteed by the plugin, not by declaring fields.** `Field::source_location()` selects a
level, not a provider (fw `checkout/class-field.php:332`); the service resolves the ACTIVE configured provider first and
DaData only as fallback (`class-location-service.php:3646-3657`), and `/suggest` takes no provider override
(`rest-api/class-location-controller.php:52-55`). So on a mixed store the chain may hold another provider's records. The
plugin therefore (1) reuses a chain record only when `provider_id() === 'dadata'` (`Dadata_Provider::PROVIDER_ID`) and
its `raw` carries `qc_geo` + coordinates, otherwise re-geocodes; (2) does every server geocode through
`Location_Service::get_registered_provider( 'dadata' )` (`:3306`), using the shared `woodev_location_token` read-only —
it never changes the store's active provider or field modes; (3) re-geocodes a warehouse address picked in admin the same
way when the picker's record is not DaData's (the `location-picker` control asks the active provider). No DaData token →
`geocoder_not_configured`.

Hidden outcomes all go through `report_hidden( code, message )` (manager line + debug log, buyer sees nothing new):
`no_warehouse` «Не создано ни одного склада», `geocoder_not_configured` «Не указан ключ DaData»,
`address_not_found` «Адрес покупателя не найден», `beyond_grid` «Адрес дальше последней строки таблицы цен»,
`weight_limit`, `no_rule_matched`, `rule_hidden` (advanced «Скрыть доставку»), `no_bands` «Не заданы цены»,
`below_grid` «Адрес ближе первой строки таблицы цен», `geocoder_unavailable` «DaData временно недоступна»,
`duration_unavailable` «Правила зависят от времени в пути, а сервис расчёта расстояния недоступен» (§5.4).
`beyond_grid` / `weight_limit` / single-condition advanced misses ALSO set the v1 buyer notice through
`woocommerce_no_shipping_available_html` + `woocommerce_cart_no_shipping_available_html` (v1 `class-shipping-rate.php:785-799`),
texts ported, translatable.

### 2.4 Caching

| Layer | Key | TTL | Notes |
|---|---|---|---|
| Framework rate cache | `Shipping_Rate_Cache` package context + our `get_rate_cache_context()` additions: warehouses fingerprint, instance provider id, `sha1(key)` fingerprint (never the key), coefficient, chain address-record key | 600 s (framework) | a failure / no-rate is never cached (fw `class-shipping-method.php:1085`) |
| Straight line | — | **not cached** | haversine is cheap; computed from the exact points with the CURRENT coefficient on every quote, so a coefficient change applies at once and no two shoppers share a result |
| Road distance cache `woodev_range_dist_<md5>` | provider id · mode · avoid · origin lat/lon · destination lat/lon **exactly as DaData returned them** (string form, no rounding — rounding merged price-sensitive points, Revision 1 #6) | per provider `max_cache_ttl()`: ORS 7 d, 2GIS 7 d, Yandex 7 d (licence allows ≤ 30 d, G §A), Mapbox 1 d, Google 1 d; **any traffic-aware mode 1 h** | fallback results are NOT stored under the real provider's key |
| Geocode cache `woodev_range_geo_<md5>` | normalised address string | hit 30 d; empty answer («не найден») 1 h | DaData storage terms UNVERIFIED (G «Not verified»); 30 d mirrors the strictest term we know (Yandex) |
| Settings change | — | — | saving the warehouses option or a service key bumps `woodev_range_rate_cache_gen` (part of both md5 keys) → old entries miss naturally; nothing deletes transients by prefix (v1 dead `clear_transients()`, R §1). The coefficient needs no bump (straight line is not cached; it IS in the rate-cache context) |

### 2.5 Backoff and caps (no request storms)

`Call_Guard` per guarded caller id — the five road services AND `dadata_geocode` (the automatic server geocode of
§2.3 c; interactive buyer/admin suggestion searches go through the framework REST route and are NOT counted here). Two
pieces, both checked before every outbound call:

| Piece | Rule |
|---|---|
| Daily cap | Connector field «Лимит запросов в сутки» (empty = no limit). Defaults pre-filled: ORS 1 900 (free 2 000/day, G §A), Yandex 1 000 (cheapest tariff), others empty; `dadata_geocode` fixed 5 000 (half the free 10 000/day shared with interactive hints, no UI). Counter `woodev_range_rate_calls_<id>_<Ymd UTC>` (non-autoload, created with `INSERT IGNORE` — never `add_option()`, whose read-then-insert race could reset a reserved slot; C2 critic r1, s218). **Reservation is one conditional update:** `UPDATE wp_options SET option_value = option_value + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d`; only the request whose update affected 1 row may call out (`wp_cache_delete` after). 0 rows → fallback, reason `daily_cap`. No cap → plain increment for the statistics only |
| Circuit breaker | transient `woodev_range_rate_breaker_<id>`: 401/403 «ключ отклонён» → open 1 h + admin notice. **429 is classified:** `Retry-After` present → honour it (capped at 24 h); body/headers that CONFIRM daily exhaustion (provider-specific, UNVERIFIED for each until fixtures are recorded) → until next UTC midnight; otherwise (e.g. ORS 40/min) → 60 s, doubling per repeat to 15 min. 5xx / timeout → open after 3 consecutive failures for 5 min, doubling to 1 h cap. One success closes it |
| Timeout | framework `Woodev_API_Request_Purpose::RATES` short timeout already applies inside `calculate_rate()` (fw `class-shipping-method.php:1315-1320`); never retried |
| Bound | no same-key request coalescing (KISS): two concurrent cache misses for one address may both call out, and **both are counted against the cap**, so the merchant's cap is enforced exactly. Steady state ≈ one call per distinct (address, instance) per cache TTL. A 3 000-quote/day shop with 40 % distinct addresses: ≈1 200 route calls/day, never above the cap |

### 2.6 Licence gate

Framework default, no override: no signed licence in force → no rate, `license_inactive` reason, background claim
refresh (fw `class-shipping-method.php:1029`). Admin screens stay usable without a licence (warehouses, services,
«Проверить подключение» — these are the merchant's own keys, not our licence server); no automatic outbound call runs
without a licence because quotes are the only automatic caller.

## 3. Storage

All plugin options use the settings handler namespace `range_rate` → `woodev_range_rate_<id>` (pattern of
`Pickup_Map_Settings`, fw `pickup/class-pickup-map-settings.php`).

### 3.1 Warehouses — `woodev_range_rate_warehouses` (non-autoload: read only on quotes and admin)

```php
[
  'next_id' => 3,
  'items'   => [
    1 => [ 'id' => 1, 'name' => 'Основной склад', 'is_main' => true,
           'address' => 'г Москва, Цветной б-р, д 22 стр 6',      // DaData label
           'lat' => 55.7700, 'lon' => 37.6206, 'qc_geo' => 0,
           'location' => '{…Location_Record JSON…}' ],              // from TYPE_LOCATION_PICKER
    2 => [ … 'is_main' => false … ],
  ],
]
```

Invariants (enforced in `Warehouse_Store::save()`, not in JS): exactly one `is_main` when `items` is non-empty; the first
item added becomes main; ids never reused; `name` 1–60 chars (display only, also shown in order meta); `lat/lon` required —
a picker result without coordinates is refused («Не удалось определить координаты адреса — выберите адрес из
подсказок»); `qc_geo > 1` is accepted with a warning «Адрес склада определён до улицы/города — расстояния будут
приблизительными».

Delete rules (D2): a non-main warehouse used by instances → deleted, those instances fall back to main, admin notice
«Склад «Ленина» удалён. Методы «Курьер по Москве» теперь считают от основного склада.»; main → refused until another is main
(«Сначала сделайте основным другой склад»); last one → allowed, every instance hidden with `no_warehouse`.

### 3.2 Distance services — «Расчёт расстояния»

Each service is ONE framework connection section (handler-served «Проверить подключение», masked secret field with
`is_set` / constant support, status badge — `src/settings-page/connection-block.js`). «Connected» = key saved and
non-empty; «Отключить» = clear the key (the framework's secret wipe). No new framework control is needed for this list.

| Provider id | Option(s) | Notes |
|---|---|---|
| `straight` (built in, always first, cannot be removed) | `woodev_range_rate_road_coefficient` float, default `1.0`, 1.0–3.0 | D12: the ONLY coefficient; used by straight-line instances and by every fallback |
| `ors` | `…_ors_api_key`, `…_ors_daily_limit` (1900) | |
| `2gis` | `…_2gis_api_key`, `…_2gis_daily_limit` | |
| `yandex` | `…_yandex_api_key`, `…_yandex_daily_limit` (1000) | |
| `mapbox` | `…_mapbox_access_token`, `…_mapbox_daily_limit` | |
| `google` | `…_google_api_key`, `…_google_daily_limit` | |
| (status) | `woodev_range_rate_service_status` `{provider: {ok, checked_at, message_code}}` | badge; written by probes and by the breaker (key rejected) |

**Secrets never logged.** The framework masks query/body params `api_key`, `apikey`, `access_token`, `token`
(`get_secret_param_names()`, fw `api/class-api-base.php:1526, 1567-1581`) and, SEPARATELY, request headers from
`get_secret_header_names()` (`:2539-2545` → list at `:2676`: `Authorization`, `X-Api-Key`, … but NOT `X-Goog-Api-Key`).
Neither list has the bare `key` param (2GIS). **Required:** the adapters' API classes override BOTH, merging the parent
lists — `get_secret_param_names()` adds `key`; `get_secret_header_names()` adds `X-Goog-Api-Key` (adding a header name to
the param list does nothing for headers). A unit test per adapter asserts that the sanitized request event, the final
log line and the error-path log line contain no `TEST-KEY-<id>` value. Keys never go into the rate cache context, the
distance cache key, order meta, notices or the upgrade report.

### 3.3 Instance settings — `woocommerce_range_rate_{instance_id}_settings`

| Key | Type / default | Notes |
|---|---|---|
| `title`, `description` | framework | v1 `title` kept |
| `warehouse` | `''` (= main) \| `'<id>'` \| `'nearest'` | D2 |
| `distance_provider` | `straight` | one of §3.2 ids; a disconnected one is read as `straight` + notice (D11) |
| `mode` | `driving` | `driving`, `driving_traffic`, `walking`, `cycling`; offered per provider (§4.8) |
| `avoid` | `none` | `none`, `tolls`, `highways`; offered per provider |
| `show_distance` / `show_duration` | `yes` / `no` | v1 keys kept; duration hidden for `straight` |
| `distance_rounding` | `ceil` (new) / `round` (migrated) | `ceil` whole km up, `round` nearest whole km (v1 `round()`); no other mode (critic round 2, #15) |
| `require_exact_address` | `no` | D7 |
| `approximate_notice` | `rate` | `none` \| `rate` \| `review` (§6.2) |
| `rules_mode` | `grid` | `grid` \| `advanced` |
| `distance_bands` | `[]` | §5.2 rows |
| `weight_free_kg`, `weight_price_per_kg`, `weight_limit_kg` | `''` | grid only |
| `rules` | v1 shape, untouched | read by the advanced engine; kept for rollback to 1.2.x |
| framework keys | markup, free-from, coupon, rounding, min/max cost, min/max order amount | features: `FEATURE_RATE_CACHE`, `FEATURE_MARKUP`, `FEATURE_FREE_SHIPPING`, `FEATURE_ROUNDING`, `FEATURE_COST_LIMITS`, `FEATURE_ORDER_AMOUNT_LIMITS` |
| `_v2_migrated` | `'2.0.0'` | idempotency marker (§8) |

Delivery type `TYPE_COURIER`. `shipping_from_address` (v1 text) is read once by the upgrade and then ignored.

### 3.4 Rate / order line meta (copied by WC from the rate into the shipping item)

| Key | Example | Shown in admin |
|---|---|---|
| `range_warehouse` | `Основной склад` | ✔ «Склад» |
| `range_distance` | `12 км` | ✔ «Расстояние» |
| `range_duration` | `25 мин` (absent for straight) | ✔ «Время в пути» |
| `range_service` | `2ГИС` / `По прямой (резерв: 2ГИС недоступен)` | ✔ «Расчёт расстояния» |
| `range_address_precision` | `точный` / `примерный` | ✔ «Точность адреса» |
| `_range_warehouse_id`, `_range_distance_m`, `_range_duration_s`, `_range_provider_id`, `_range_fallback`, `_range_qc_geo` | ints / `yes` | hidden (underscore) |

Labels via `woocommerce_order_item_display_meta_key`; nothing added to buyer e-mails (WC prints shipping item meta in
admin only — UNVERIFIED for every theme, checked on the rig in card C12).

## 4. Distance services

### 4.1 One adapter interface

```php
interface Distance_Provider {
    public function get_id(): string;                       // 'ors'
    public function get_label(): string;                    // «OpenRouteService» (UI: «сервис»)
    public function supported_modes(): array;               // subset of driving|driving_traffic|walking|cycling
    public function supported_avoid(): array;               // subset of tolls|highways
    public function max_cache_ttl( string $mode ): int;     // §2.4
    public function is_connected(): bool;                    // key saved
    public function distance( Geo_Point $from, Geo_Point $to, string $mode, string $avoid ): Distance_Result; // throws Distance_Exception
    public function probe(): \Woodev_Connection_Result;     // «Проверить подключение»
}
```

`Distance_Exception` carries a category (`key_rejected`, `quota`, `no_route`, `transport`, `bad_response`) for the breaker.
`no_route` (e.g. island without road) does NOT open the breaker; it falls back to straight line for that quote.
Probe = one fixed request Москва, Красная пл. 1 (55.7539, 37.6208) → Москва, Цветной б-р 22 (55.7700, 37.6206); expects
1–15 km (widened in s218: Google 5,9 km and 2GIS 7,1 km in evening traffic were real detours); result message «Подключение работает: 2,1 км» / «Ключ не принят сервисом» / «Превышен лимит запросов» /
«Сервис не ответил»; a probe counts against the daily cap; the probe response is never shown raw.

**Straight line** (`straight`): `Haversine(from, to) × coefficient` (D12 global), `seconds = null`. Always connected,
modes = none (the method hides «Способ передвижения» for it), never fails, never cached (§2.4). Also the fallback for
every other provider.

### 4.2 OpenRouteService (`ors`)

| | |
|---|---|
| Endpoint | `POST https://api.openrouteservice.org/v2/directions/{profile}` (JSON; GeoJSON variant not needed) — **UNVERIFIED**: doc page is JS-rendered; host reportedly changing in Nov 2026 (G §A [S]) → base URL is a constant + filter `woodev_range_rate_ors_base_url` |
| Auth | header `Authorization: <key>` (masked by default) — UNVERIFIED |
| Request | `{"coordinates":[[lon,lat],[lon,lat]], "options":{"avoid_features":["tollways"\|"highways"]}}` — UNVERIFIED names |
| Response | `routes[0].summary.distance` (m), `.duration` (s) — UNVERIFIED |
| Modes | driving → `driving-car`, walking → `foot-walking`, cycling → `cycling-regular`; **no traffic** (G §A) → `driving_traffic` not offered |
| Errors | 401/403 key, 429 quota (2 000/day, 40/min), 404 «no route» code — UNVERIFIED codes |

### 4.3 2GIS Distance Matrix API (`2gis`)

> **Operator decision 05.11.2026 (s218):** Distance Matrix API, NOT Routing API — it is the service made for «distance and
> travel time A → B», the same shape as Yandex (§4.4) and Google (§4.6). Replaces the coordinator's provisional P13. 2GIS
> geocoding is never used: DaData is always the geocoder (D3).

| | |
|---|---|
| Endpoint | `POST https://routing.api.2gis.com/get_dist_matrix?key=<key>&version=2.0` — path from the 2GIS Distance Matrix docs, UNVERIFIED until the worker re-reads docs.2gis.com (the host answered `403 invalid_key` to a dead key on 05.11.2026, so it exists) |
| Request | 1 × 1 matrix: `{"points":[{"lat":…,"lon":…},{"lat":…,"lon":…}],"sources":[0],"targets":[1], …mode fields}` — field names UNVERIFIED; read the docs page, do not guess; **lat/lon named fields, never positional** (v1 suspected swap, R §8.4) |
| Response | per-pair `distance` (m), `duration` (s), status — UNVERIFIED; fixture from the docs example |
| Modes | driving / driving_traffic (real-time vs statistical traffic — read the docs for the matrix's own mode names), walking, cycling only if the matrix supports them — §4.8 offers only what the docs list |
| Avoid | tolls only if the matrix supports it — UNVERIFIED |
| Billing | 1 matrix cell = 1 unit, same 6 700 ₽ / 10 000 package as Routing (research G §A); demo key: 1 month, 1 000 requests per service |

### 4.4 Yandex Distance Matrix (`yandex`)

| | |
|---|---|
| Endpoint | `GET https://api.routing.yandex.net/v2/distancematrix?apikey=<key>&origins=<lat,lon>&destinations=<lat,lon>&mode=…[&traffic=disabled][&avoid_tolls=true]` — READ (yandex.ru/maps-api/docs/distancematrix-api, 05.11.2026). **lat,lon order** |
| Request | 1 × 1 matrix (1 element = 1 billed request, G §A) |
| Response | `rows[0].elements[0].status == "OK"`, `.distance.value` (m), `.duration.value` (s) — UNVERIFIED (response page not read) |
| Modes | driving → `mode=driving&traffic=disabled`; driving_traffic → `mode=driving` (traffic is on by default, READ); walking → `walking`; cycling → `bicycle` |
| Avoid | `avoid_tolls=true` (READ); highways not offered |
| Terms | cache ≤ 30 days on the standard licence (G §A) → our 7 d / 1 h |

### 4.5 Mapbox (`mapbox`)

| | |
|---|---|
| Endpoint | `GET https://api.mapbox.com/directions/v5/mapbox/{profile}/{lon,lat;lon,lat}?access_token=<key>&overview=false` — READ (docs.mapbox.com, 05.11.2026) |
| Response | `routes[0].distance` (m), `routes[0].duration` (s), `code == "Ok"` — READ in v1, response doc UNVERIFIED |
| Modes | `driving`, `driving-traffic` (READ: «factors in current and historic traffic»), `walking`, `cycling` |
| Avoid | `exclude=toll` / `exclude=motorway`, only for the two driving profiles (READ); v1 dropped it for driving (R §8.2) — fixed |

### 4.6 Google Routes API (`google`)

| | |
|---|---|
| Endpoint | `POST https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix` — READ (developers.google.com, 05.11.2026); replaces the Legacy Distance Matrix (R §2) |
| Auth | header `X-Goog-Api-Key: <key>`; header `X-Goog-FieldMask: originIndex,destinationIndex,status,condition,distanceMeters,duration` (READ: mask is mandatory, include status) |
| Request | `{"origins":[{"waypoint":{"location":{"latLng":{"latitude":…,"longitude":…}}}}], "destinations":[…], "travelMode":"DRIVE", "routingPreference":"TRAFFIC_UNAWARE"\|"TRAFFIC_AWARE", "routeModifiers":{"avoidTolls":true,"avoidHighways":true}}` — routeModifiers field names UNVERIFIED |
| Response | JSON array of elements; `distanceMeters` (int), `duration` (`"1234s"`), `condition == "ROUTE_EXISTS"` (READ shape) |
| Modes | driving → DRIVE + TRAFFIC_UNAWARE (Essentials SKU), driving_traffic → DRIVE + TRAFFIC_AWARE (Pro SKU, 2× price, G §A), walking → WALK, cycling → BICYCLE (both beta per Google, READ) |

### 4.7 Error → fallback

Any `Distance_Exception`, cap reached, breaker open, or provider disconnected → `Straight_Line_Provider` result with
`fallback = true`, `reason`; meta `range_service` «По прямой (резерв: <label> недоступен)»; debug log line (code only);
`woodev_range_rate_distance_fallback` action. The buyer sees a normal price. The admin sees a notice only for
`key_rejected` / disconnected (actionable), never for transient errors (merchant-diagnostics rule).

### 4.8 Mode / avoid availability (only offer what the service supports)

| | straight | ors | 2gis | yandex | mapbox | google |
|---|---|---|---|---|---|---|
| «На машине» `driving` | — | ✔ | ✔ (статистика) | ✔ | ✔ | ✔ |
| «На машине с учётом пробок» `driving_traffic` | — | ✗ | ✔ | ✔ | ✔ | ✔ (Pro SKU) |
| «Пешком» `walking` | — | ✔ | ✔ | ✔ | ✔ | ✔ (beta) |
| «На велосипеде» `cycling` | — | ✔ | ✔ | ✔ | ✔ | ✔ (beta) |
| avoid tolls / highways | — | ✔ / ✔ | ✔ / ✗ | ✔ / ✗ | ✔ / ✔ | ✔ / ✔ |

A saved mode the newly chosen service lacks → effective `driving`, instance-screen notice «Сервис не поддерживает режим
«с учётом пробок» — используется «На машине»»; stored value untouched.

## 5. Rules redesign (D5)

### 5.1 What v1 does (READ, `v1: includes/class-shipping-rate.php:830-1075, 1184-1433`)

Ordered rules; a rule matches when ANY OR-group has ALL its conditions true; conditions `distance` (rounded km),
`time` (rounded min), `weight` (cart weight), `total` (`cart_subtotal`), `quantity`; min/max inclusive, `empty()` = open
(so `'0'` is open too), and **the measured value must be `> 0`** or the condition is false. Matching rule cost
= `cost_unit × units` (units = km or min per `unit`) `+ cost` (both × cart qty when `per_qty`) `+ fee` (flat or `N%` of
`contents_cost`), then two per-rule filters. All matching costs are **summed** until a matching `break`; a matching
`abort` hides the method; nothing matched → no rate. Merchants find this hard: four interacting switches per row.

### 5.2 v2 default: the distance-band grid (D14, D15)

The merchant reads it as a price list. Rows are **contiguous by construction** (D15): only the FIRST row has an
editable **«от, км»** (default 0); every other row shows a read-only «свыше N» = the previous row's «до», so overlaps and
gaps cannot be typed. Each row: **«до, км»** (inclusive; empty only in the last row = «и дальше»), **«Стоимость, ₽»**
(required; `0` = бесплатно), **«+ за каждый км»** (optional, multiplies the WHOLE distance — the v1 `cost_unit` formula,
D14; the «Пример» column shows the result), **«Не доставляем»** checkbox (the row is greyed and quoted as «не
доставляем»; needed for migrated v1 gaps / abort bands, D15 f).

```php
'distance_bands' => [
  [ 'from' => '0', 'to' => '5',  'cost' => '300', 'per_km' => '',   'no_delivery' => false ], // only row 0 stores 'from'
  [                'to' => '15', 'cost' => '200', 'per_km' => '20', 'no_delivery' => false ],
  [                'to' => '30', 'cost' => '0',   'per_km' => '35', 'no_delivery' => false ],
]                                                       // last 'to' set → «Дальше 30 км: не доставляем»
```

**Validation** (server-side in the field's `validate_*` method, mirrored in the editor; an error is highlighted on its
row and BLOCKS saving — no silent auto-fix, D15 c): `from` ≥ 0; every `to` > the previous bound (row 0: `to` > `from`);
only the last `to` may be empty; `cost` required and ≥ 0 unless `no_delivery` (an empty cost is an error — no v1
`empty()` = open trap, D15 f); `per_km` empty or ≥ 0; decimals accepted with «,» or «.» and stored with
`wc_format_decimal()` (D15 g). Rows keep table order; no drag-reorder. **Deleting a row** never opens a gap: the next
row's lower bound becomes the deleted row's lower bound (for row 0 the next row inherits its `from`); the «Пример» column
recomputes before save (D15 d).

**Additions to the grid (optional):** weight surcharge «+ X ₽ за каждый кг сверх Y кг» (`weight_price_per_kg`,
`weight_free_kg`; started kilogram counts, `ceil`), weight limit «Не доставлять тяжелее Z кг» (`weight_limit_kg`).
Weight = package contents weight in kg (store unit converted); a product without a weight counts with the framework
default weight from the «Доставка» tab (`Shipping\Settings\Default_Dimensions_Settings::current()->get_fallback()`,
builtin 100 g — the rule the box packer applies, fw `box-packer/class-wc-packer-dispatcher.php:233-249`), so no
«no weight» hint exists (D16). Migrated advanced rules keep v1 raw cart weight (missing = 0). Everything else is
the framework's: «Наценка» (fixed / % of order / % of delivery), «Бесплатно от суммы заказа», «Купон на бесплатную
доставку», «Округление», «Мин./макс. стоимость», «Мин./макс. сумма заказа».

**Dropped from the grid (kept only in the advanced engine for migrated rules, D14):** per-minute price and travel-time
conditions, quantity conditions and «× количество», per-rule %-fee, OR/AND groups, summing of overlapping rules,
break/abort switches. Each one has a clearer replacement above or is unstable (time).

### 5.3 Evaluation in plain words + worked examples

1. Take the distance (km) and round it per «Расстояние для расчёта» BEFORE the lookup (`ceil` for new instances, v1
   `round()` on migrated ones, D14) — the field hint says so: «10,2 км при округлении вверх считается как 11 км».
2. Apply the v1 distance filter to the rounded value (§2.3 step 5); its return is used as is.
3. Below row 1's «от» → not delivered (`below_grid`; D15 b — «от 1 км» = no delivery closer than 1 km).
4. Row 1 covers «от» ≤ d ≤ «до»; every next row covers previous «до» < d ≤ its own «до» («свыше N», D15 a); a last row
   with an empty «до» covers everything above. No row → not delivered (`beyond_grid` + buyer notice, D15 e).
5. Row «Не доставляем» → not delivered (same buyer notice). Else price = cost + per_km × d.
6. Weight: over the limit → not delivered; else + per_kg × ceil(max(0, weight − free_kg)).
7. Framework, fixed order: markup → free shipping → rounding → min/max (fw `class-shipping-method.php:2739`, `:1315`).

Setup for the examples: rows above (from 0); weight +50 ₽/кг сверх 10 кг, limit 50 кг; «Бесплатно от» 10 000 ₽;
rounding up to 10 ₽; `ceil`.

| # | Input | Steps | Price / label |
|---|---|---|---|
| E1 | 3,2 км, 2 кг, 2 500 ₽ | ceil → 4; row 1 (0–5) → 300 + 0; weight ≤ 10 | **300 ₽** «Курьер (4 км)» |
| E2 | 11,6 км, 13,4 кг, 4 000 ₽ | ceil → 12; row 2 (свыше 5 до 15) → 200 + 20×12 = 440; weight ceil(3,4)=4 → +200 | **640 ₽** |
| E3 | 22 км, 5 кг, 12 000 ₽ | ceil → 22; row 3 → 0 + 35×22 = 770; order above 10 000 → free | **0 ₽** (бесплатно) |
| E4 | 30,4 км | ceil → 31; no row ≥ 31 | **hidden**; buyer: «К сожалению, адрес находится за пределами зоны доставки» |
| E5 | road service over quota; straight 8,3 км, coefficient 1,3 | 8,3×1,3 = 10,79 → 11; row 2 → 200 + 220 = 420 | **420 ₽**, meta «По прямой (резерв: 2ГИС недоступен)» |
| E6 | cart page, only «Подольск» picked (qc_geo 4), 38 км; extra row «до 50 — 1 500» | row 4 → 1 500 | **1 500 ₽** + notice «Стоимость указана примерная…» |
| E7 | row 1 «от» = 2 км; address 0,8 км | ceil → 1 < 2 | **hidden** `below_grid` |
| E8 | rows «0–2,5: 150», «свыше 2,5 до 10: 300»; 2,3 км | ceil → 3 > 2,5 → row 2 | **300 ₽** (the hint explains rounding first) |

### 5.4 Advanced representation (migrated v1 rules only)

`rules_mode = 'advanced'` and the v1 `rules` array **verbatim** (no new shape → nothing to mis-map, and v1 1.2.x still
reads it after a rollback). `Legacy_Rules_Engine::evaluate( $rules, $ctx )` ports `get_rates()`'s loop and the five
`*_shipping_rule` + `rule_cost_calculation` methods **1:1**, including: legacy top-level `condition/min/max`
normalisation (`:939-952`), the `> 0` guards, `empty()` openness, whole-km/minute `round()`, sum + `break` + `abort`
order (abort checked after the cost is added, `:1042-1050`), the first-condition buyer notices (`:1016-1038`), and the
same filter names and arguments (§9). The only change is cosmetic: `abort` no longer bypasses the notice reset.

The legacy context is v1's, value for value:

| Measure | Advanced engine (migrated) | Grid (new) |
|---|---|---|
| distance | `round( m / 1000 )`, then `woocommerce_range_rate_shipping_calculated_distance` — the filtered float is matched and multiplied as returned, never rounded again (v1 `:889-891, 933`) | rounded per §5.3, then the same filter, same rule |
| weight | **whole cart** `WC()->cart->get_cart_contents_weight()` in the STORE unit, for every package (v1 `:924`) — a split cart keeps v1 prices; only without a cart (admin order wizard) the package weight is used | package contents, in kg |
| total / quantity | `package['cart_subtotal']` / Σ package quantities (v1) | framework order amount |

**Duration unavailable (Revision 1 #8, operator policy).** Straight-line results (built in or fallback) have no duration.
The engine then evaluates time conditions as UNKNOWN (three-valued: AND with a false member is false, else unknown if any
member is unknown; OR is true if any group is true, else unknown if any group is unknown). If a rule's match is unknown,
or a matching rule prices by `unit = time` with a non-empty `cost_unit`, the whole method is **hidden** with
`report_hidden( 'duration_unavailable', … )` — never a zero-minute price. A rule whose OR group matches through a non-time
branch is priced normally. The upgrade report flags migrated instances whose rules depend on duration while their service
is `straight` (an empty v1 key — such sites had no rate in v1 either).

The advanced editor (§7c) shows each rule as a card in plain words; its fields map 1:1 onto the v1 keys. A merchant can
«Перейти на простую таблицу»: allowed only when the converter (§5.5) proves equivalence; otherwise the button says
«Начать заново с простой таблицы» and discards the advanced rules after a confirm (they stay in `rules` until the next
save). New instances never get advanced mode. Provisional P4.

### 5.5 Deterministic v1 → v2 conversion

`Rules_Converter::convert( array $rules ): Conversion` — pure function, no I/O except `has_filter()`:

1. Normalise exactly as v1 (legacy single condition → `conditions[0][0]`).
2. **Grid-eligible** iff ALL: every condition in every group is `distance`; every rule's `unit` is absent or `distance`;
   no `per_qty = yes`; every `fee` is empty or a flat number (a `%` fee → advanced); and NO callback (`has_filter()` at
   upgrade time) on any per-rule v1 filter (`woocommerce_range_rate_shipping_rule_cost_*`, `…_shipping_match`,
   `…_calculation`) **nor on `woocommerce_range_rate_shipping_calculated_distance`** (a callback may return a fractional
   km that v1 matches unrounded — integer simulation cannot prove equivalence, Revision 1 #3). Otherwise → **advanced,
   done**.
3. Domain: `N = max(ceil(every finite numeric min/max)) + 1` (0 when there is none).
4. For every integer `d = 0..N` run `Legacy_Rules_Engine` with distance `d`, metres `max(d × 1000, 1)` (v1 needs
   metres > 0; `d = 0` means 0 < m < 500), no other measures. Record the outcome signature:
   `HIDE` (abort or nothing matched) or `(A, B)` with `A = Σ(cost + flat fee)`, `B = Σ cost_unit` over the counted rules.
   (OR groups of distance-only conditions are fine — the simulation evaluates them.)
5. Compress consecutive equal signatures into runs `[d_start..d_end]`, then build rows:
   - a LEADING `HIDE` run `[0..k]` → no row; row 1 gets `from = k + 1` (D15 b — the v1 «min = 1» rule's exact form);
   - every other run → row `to = d_end`, `cost = A`, `per_km = B`; a `HIDE` run in the middle → `no_delivery = true`;
   - the run that contains `N` covers every `d ≥ N` (all bounds are below it): non-`HIDE` → its row gets `to = ''`;
     `HIDE` → dropped (= not delivered beyond the last row);
   - every run `HIDE` → no rows (`no_bands`, v1 never quoted either).
6. **Validate the candidate with the same `Band_Grid` validator the editor uses** (§5.2); a candidate it would reject →
   advanced (Revision 1 #4).
7. Self-check: evaluate grid (`round` rounding) and legacy engine for `d = 0..N + 10` and for raw metres `1, 499, 500`,
   comparing after `wc_format_decimal( $x, wc_get_price_decimals() )`; any mismatch → advanced. So «grid» is only ever
   chosen when proven.
8. Set `distance_rounding = 'round'`, framework features off (their defaults are off), weight fields empty.

**The only deviation, deliberate:** destination exactly 0 m from the warehouse (same building). v1 hides the method
(distance rule needs metres > 0); v2 quotes row 1 when its «от» is 0. Provisional P5.

Worked conversions:

| v1 rules | Grid result |
|---|---|
| R1 dist 0–5 cost 300 break; R2 dist 5–10 cost 200 unit 20 break; R3 dist 10– abort | `[от 0 до 5: 300]`, `[свыше 5 до 10: 200 + 20/км]`, «Дальше 10 км: не доставляем» (d = 5 → R1 because of break = inclusive «до») |
| R1 dist 0–10 cost 100; R2 dist 5–20 unit 10 (no breaks, overlap sums) | `[0–4: 100]`, `[свыше 4 до 10: 100 + 10/км]`, `[свыше 10 до 20: 0 + 10/км]`, beyond not delivered |
| R1 dist min 1 max 5 cost 300 break | `[от 1 до 5: 300]` — raw 400 m → 0 km → `below_grid`, as v1 |
| R1 dist 5,5– cost 500 (decimal bound) | `[от 6 и дальше: 500]` |
| R1 dist 0–5 cost 300 break; R2 dist 8–10 cost 400 break (gap) | `[0–5: 300]`, `[свыше 5 до 7: не доставляем]`, `[свыше 7 до 10: 400]` |
| R1 total ≥ 5 000 cost 0 break; R2 dist 0–20 cost 400 | advanced (a `total` condition; v1 «≥ inclusive of cart_subtotal» ≠ framework «above threshold») |
| R1 dist 0–10 cost 50 per_qty | advanced |
| Legacy ≤1.0.5 rule `condition=distance,min=0,max=15,cost=250` | `[0–15: 250]` |
| any shape + a callback on `…_calculated_distance` | advanced |
| `rules = []` | grid with no rows → hidden `no_bands` (v1: no rate either) |

### 5.6 Migration test matrix (unit, `Rules_ConverterTest` + `Legacy_Rules_EngineTest`)

Every case asserts: chosen mode, rows (incl. `from`, `no_delivery`), the candidate passing the editor validator, and
v1-oracle price == v2 price for d ∈ {0, 1, each bound − 1, bound, bound + 1, N + 10}, raw metres {1, 499, 500} and the
weight/total/quantity values listed.

| # | Shape | Expected mode |
|---|---|---|
| M1 | single distance band, break | grid |
| M2 | contiguous bands, all break, shared boundaries | grid |
| M3 | overlapping bands, no break (sum) | grid |
| M4 | gap between bands | grid with a «не доставляем» row |
| M5 | abort band in the middle / at the end | grid («не доставляем» row / grid end) |
| M6 | open first band (min empty) / open last band (max empty) / `min = '0'` | grid, `from = 0` |
| M7 | decimal bounds (5,5) | grid, `from = 6` |
| M8 | flat fee on some rules | grid (fee folded into cost) |
| M9 | `%` fee | advanced |
| M10 | `per_qty` | advanced |
| M11 | `unit = time` with a distance condition | advanced |
| M12 | time / weight / total / quantity condition (each) | advanced |
| M13 | OR groups of distance conditions only | grid |
| M14 | OR + AND mix with weight | advanced |
| M15 | legacy top-level `condition/min/max` | as its content |
| M16 | rule with `conditions = []` or min > max (never matches) | grid (ignored) |
| M17 | empty `rules` | grid, no rows |
| M18 | per-rule filter has a callback | advanced |
| M19 | weight 0 cart with a weight rule (v1 `> 0` quirk) | advanced, price identical to v1 |
| M20 | d = 0 m | documented deviation P5 |
| M21 | `min = 1, max = 5`: raw 400 m and 600 m | grid `from = 1`; 400 m hidden, 600 m → 300, as v1 |
| M22 | `…_calculated_distance` callback × 1.1, rule `max 12, unit 10` | advanced; raw 10 km → 11,0 → 110 ₽; raw 11 km → 12,1 → hidden — as v1, no second rounding |
| M23 | split cart: 12 kg in two 6 kg packages; R1 `weight ≥ 10` 500 break, R2 dist 0–100 100 | advanced; 500 per package (whole-cart weight, v1 `:924`) |
| M24 | `distance 1–20, unit = time, cost_unit 10` under straight fallback | hidden `duration_unavailable`, never 0 ₽ |
| M25 | OR: (time ≤ 30) OR (distance ≤ 10), fallback, 8 km | priced through the distance branch |
| M26 | OR: (time ≤ 30) OR (distance ≤ 10), fallback, 15 km | hidden `duration_unavailable` (match unknown) |
| M27 | grid row validator: overlapping / unsorted / empty cost / empty middle `to` | each rejected with a row error |

The v1 oracle is the v1 `get_rates()` loop extracted verbatim into `tests/fixtures/v1-oracle.php` (test-only copy).

## 6. Checkout (classic AND block, D8)

### 6.1 Address hints and recalculation

- Hints = the framework location layer: the plugin declares the three `Field::source_location()` fields (region,
  settlement, address) in `Range_Checkout_Handler`; the framework provides the classic and block adapters, the REST
  `/suggest` proxy (token not in page JS), and shares one widget with CDEK/Yandex/Russian Post if installed. No plugin JS
  on the checkout except §6.2's block notice. The widget's provider is the store's (active provider first, DaData as
  fallback); the PRICE never depends on it — the point always comes from a DaData record or a DaData server geocode
  (§2.3 «DaData ownership»).
- Recalculation: WooCommerce's own triggers — classic `update_checkout` on field change (the framework fires it after a
  suggestion pick), block Store API cart update on address change. The framework rate cache keys on the destination down
  to the street (fw `Shipping_Rate_Cache::package_context`) and the plugin adds the chain address-record key, so picking a
  different suggestion for the same text re-quotes.
- `woocommerce_shipping_cost_requires_address` is not forced: the cart page quotes from the settlement point
  (approximate, §2.3 b). Same on both checkouts.

### 6.2 Approximate-price notice (D7)

Shown when `Geo_Point.precision = approximate` and `require_exact_address = no`. Text (translatable): «Стоимость указана
примерная, для точного расчёта укажите ваш точный адрес».

Modelled on the framework's pickup-button placement — the store-level setting `pickup_button_placement` with options
`rate` «В строке выбранного метода» / `review` «После списка методов» (fw `pickup/class-pickup-map-settings.php:151`,
resolved in `checkout/class-checkout-config.php:655`). **Note:** that option is store-level, not per method; ours is
per instance (D7) and reuses its two values plus `none`:

| `approximate_notice` | UI label | Classic | Block |
|---|---|---|---|
| `none` | «Не показывать» | — | — |
| `rate` (default) | «Показывать под методом» | a block `range_approximate` added through the framework filter `woodev_shipping_rate_additional_info` (fw `class-shipping-plugin.php:989`), built from the CURRENT rate's meta `_range_qc_geo` / `range_address_precision`. NOT the rate description: the classic renderer prints only the instance's static `description` option (`:950-958`) | rate `description` = merchant description + notice, via Store API (WC 9.2+, fw `apply_rate_attributes`, `class-shipping-method.php:1266-1283`); WC < 9.2 → degrades to `review` |
| `review` | «Показывать под общим списком» | row on `woocommerce_review_order_after_shipping` (same hook the framework uses for hidden reasons, `checkout-handler.php:548`) | Store API cart extension `range_rate.notices[]` + 60-line script rendering under the shipping options (pattern of the hidden-reason endpoint, `checkout-handler.php:547-600`) |

No «≈» in the label (D7). Tests: empty merchant description on classic (the notice still shows); approximate → exact
→ approximate transitions with the rate cache warm (the precision lives in the cached rate's meta, and a changed street
is a new package context, so the notice follows the address).

### 6.3 «Требовать точный адрес» (D7)

On: the cart still quotes; **placing the order** is refused unless the chosen `range_rate` rate's meta
`range_address_precision` is exact. Message: «Укажите точный адрес доставки с номером дома — от него зависит стоимость
доставки.» Classic: `woocommerce_after_checkout_validation` adds the error. Block: `RouteException` (400) from
`woocommerce_store_api_checkout_update_order_from_request`, the pattern the framework checkout handler already uses for a
missing pickup point (`checkout-handler.php:2582-2611`). The notice of §6.2 is replaced by this text while the address is
inexact.

### 6.4 Label suffix

`title + ' (12 км)'`, `'(850 м)'` below 1 km, `'(12 км; 25 мин)'` with duration, `'(12 км; 1 ч 20 мин)'` over an hour;
filter `woocommerce_range_rate_shipping_label_suffix( $suffix, $distance_km, $package )` kept. v1 printed «км.» with a dot
(`class-shipping-rate.php:897-910`) — dropped (D6 «(12 км)»). The suffix shows the charged (rounded) distance.

## 7. Admin UI (ASCII mockups, Russian strings)

### 7a. Settings tab «Расчёт стоимости доставки за расстояние» → section «Склады»

```
 Склады ───────────────────────────────────────────────────────────────────────
 (пусто)
 ┌──────────────────────────────────────────────────────────────────────────┐
 │  У вас пока нет ни одного склада. Добавьте адрес, откуда курьер везёт     │
 │  заказы, — от него считается расстояние до покупателя.                   │
 │                                                  [ + Добавить склад ]    │
 └──────────────────────────────────────────────────────────────────────────┘

 (заполнено)
 ┌────┬──────────────────┬──────────────────────────────────┬───────────┬────┐
 │    │ Название         │ Адрес                            │ Основной  │    │
 ├────┼──────────────────┼──────────────────────────────────┼───────────┼────┤
 │ 1  │ Основной склад   │ г Москва, Цветной б-р, д 22 с 6  │  (•)      │ ✎  │
 │ 2  │ Склад на Ленина  │ г Подольск, ул Ленина, д 1       │  ( )      │ ✎ 🗑│
 │    │                  │ ⚠ адрес определён до улицы       │           │    │
 └────┴──────────────────┴──────────────────────────────────┴───────────┴────┘
                                                     [ + Добавить склад ]

 (форма «Добавить склад»)
 Название  [ Склад на Ленина                    ]  — видите только вы
 Адрес     [ г Подольск, ул Лен|                ▾]
           │ г Подольск, ул Ленина, д 1        │   ← подсказки DaData
           │ г Подольск, ул Ленинградская, д 4 │
 [x] Основной склад
                                         [ Отмена ]  [ Сохранить склад ]
```

Needs framework card **F1** (generic «records table» control, §10): the settings page has no repeater/table control
today (only `boxes-table` and `location-picker`, fw `settings-api/class-control.php:75, 118`); the address cell IS the
existing `location-picker`.

### 7b. Section «Расчёт расстояния» (services, D11–D13)

```
 Расчёт расстояния ────────────────────────────────────────────────────────────
 Сервис считает расстояние от склада до покупателя. «По прямой» работает
 всегда и бесплатно; другие сервисы считают по дорогам — подключите нужный.

 ┌ По прямой (встроенный) ─────────────────────────────── ● Работает ───────┐
 │ Коэффициент дороги  [ 1.3 ]  — расстояние по прямой × коэффициент.        │
 │ Используется и как запасной вариант, если сервис недоступен.             │
 └──────────────────────────────────────────────────────────────────────────┘
 ┌ 2ГИС ───────────────────────────────────────────────── ● Подключён ──────┐
 │ Ключ API            [ •••••••••••• ]  [ Отключить ]                       │
 │ Лимит запросов в сутки [      ]  (пусто — без ограничения)                │
 │ [ Проверить подключение ]   ✔ Подключение работает: 2,1 км               │
 └──────────────────────────────────────────────────────────────────────────┘
 ┌ OpenRouteService (бесплатно, 2 000 запросов в сутки) ── ○ Не подключён ──┐
 │ Ключ API            [                ]                                    │
 │ Лимит запросов в сутки [ 1900 ]                                           │
 │ [ Подключить сервис ]                                                     │
 └──────────────────────────────────────────────────────────────────────────┘
 ┌ Яндекс ─────────────────────────────────────────────── ✖ Ошибка ─────────┐
 │ Ключ API            [ •••••••••••• ]  [ Отключить ]                       │
 │ [ Проверить подключение ]   ✖ Ключ не принят сервисом. Проверьте ключ.   │
 └──────────────────────────────────────────────────────────────────────────┘
   Mapbox ………………………………………………………………………………… ○ Не подключён   [ Подключить сервис ]
   Google ………………………………………………………………………………… ○ Не подключён   [ Подключить сервис ]
```

Each card is one framework connection section; «Подключить сервис» = the section's save + probe.

### 7c. Shipping-method instance settings

```
 Название метода          [ Курьер                                   ]
 Описание                 [                                          ]
 Склад      (0 складов)   У вас не создано ни одного склада. [ Создать склад ]
            (1 склад)     Основной склад — г Москва, Цветной б-р, 22  [ Добавить ещё склад ]
            (много)       [ Основной склад (основной)            ▾]
                          │ Основной склад (основной)            │
                          │ Склад на Ленина                      │
                          │ Ближайший к покупателю               │
 Расчёт расстояния        [ 2ГИС                                 ▾]  (По прямой · 2ГИС · …подключённые)
 Способ передвижения      [ На машине с учётом пробок            ▾]  (только режимы сервиса)
 Избегать                 [ Платные дороги                       ▾]
 Показывать в названии    [x] расстояние  [ ] время в пути
 Расстояние для расчёта   [ Округлять до целого км вверх         ▾]
 Требовать точный адрес   [ ] Не принимать заказ, пока адрес указан без номера дома
 Примерная стоимость      [ Показывать под методом               ▾]

 Цены по расстоянию ─────────────────────────────────────── (простая таблица)
 Расстояние сначала округляется (см. «Расстояние для расчёта»), потом ищется строка.
 ┌──────────┬─────────┬───────────┬────────────┬─────────┬─────────────────┬───┐
 │ От, км   │ До, км  │ Стоимость │ + за км    │ Не дост.│ Пример          │   │
 ├──────────┼─────────┼───────────┼────────────┼─────────┼─────────────────┼───┤
 │ [ 0   ]  │ [ 5   ] │ [ 300 ] ₽ │ [     ] ₽  │  [ ]    │ 5 км → 300 ₽    │ 🗑 │
 │ свыше 5  │ [ 15  ] │ [ 200 ] ₽ │ [ 20  ] ₽  │  [ ]    │ 15 км → 500 ₽   │ 🗑 │
 │ свыше 15 │ [ 20  ] │ ░░░░░░░░░ │ ░░░░░░░░░  │  [x]    │ не доставляем   │ 🗑 │
 │ свыше 20 │ [ 30  ] │ [ 0   ] ₽ │ [ 35  ] ₽  │  [ ]    │ 30 км → 1 050 ₽ │ 🗑 │
 └──────────┴─────────┴───────────┴────────────┴─────────┴─────────────────┴───┘
 [ + Добавить строку ]   Дальше 30 км: не доставляем   (пустое «До» в последней
                                                         строке = «и дальше»)
 (ошибка)  │ свыше 15 │ [ 12 ]⚠ │ … «До» должно быть больше 15 км — сохранить нельзя
 Вес: + [ 50 ] ₽ за каждый кг сверх [ 10 ] кг · Не доставлять тяжелее [ 50 ] кг
 (ниже — стандартные блоки: наценка, бесплатно от суммы, округление, мин./макс.)
```

Advanced editor (only migrated instances that could not convert):

```
 Цены по правилам (перенесены из версии 1.x) ──────────────────────────────────
 ⓘ Эти правила нельзя превратить в простую таблицу без изменения цен.
 ┌ Правило 1 ───────────────────────────────────────────────────────── 🗑 ┐
 │ Если: [сумма заказа ▾] от [5000] до [    ]   [+ и]   [+ или]           │
 │ Цена: [0] ₽ + [  ] ₽ за [км ▾]  [ ] × количество товаров  + [   ] ₽/%  │
 │ [x] Дальше правила не проверять   [ ] Скрыть доставку                   │
 └─────────────────────────────────────────────────────────────────────────┘
 [ + Добавить правило ]          [ Начать заново с простой таблицы ]
```

### 7d. Order admin (shipping line in the order items, WC native item meta)

```
 Доставка ─────────────────────────────────────────────────────────────────
 Курьер (12 км; 25 мин)                                           640,00 ₽
     Склад:              Основной склад
     Расстояние:         12 км
     Время в пути:       25 мин
     Расчёт расстояния:  2ГИС
     Точность адреса:    точный
 ──────────────────────────────────────────────────────────────────────────
 (резерв)  Расчёт расстояния:  По прямой (резерв: 2ГИС недоступен)
```

A separate meta box is not built (KISS): the shipping line is where the support question «why this price» is read.
Provisional P6.

## 8. Migration v1 → v2 (`upgrade_to_2_0_0( $installed_version )`)

Runs once through `Woodev_Lifecycle`. Each step is guarded so a re-run (or a partial earlier run) changes nothing.

| v1 key (R §3) | Stored in | v2 home | Rule |
|---|---|---|---|
| `from_lon`, `from_lat` | `woocommerce_range_rate_settings` | warehouse #1, `is_main`, name «Основной склад», address = first instance's non-empty `shipping_from_address`, else «Склад (координаты 55.77, 37.62)» | only if `woodev_range_rate_warehouses` is absent; `qc_geo = null` (unknown) |
| `shipping_from_address` | each instance | — (display only in v1; v1 used the global coords for ALL, R §8.1) | instances get `warehouse = '1'` explicitly (D2 «every existing instance points at it»); differing texts are listed in the report |
| `api_provider` + `api_key` | global | connector `mapbox` / `google` / `2gis` key option; every instance `distance_provider` = that id | key copied only if the target is empty; empty v1 key → instances `straight` |
| `dadata_key` | global | `woodev_location_token` | only if empty (do not overwrite another Woodev plugin's token) |
| `dadata_secret` | global | `woodev_location_clean_secret` | only if empty |
| `suggestions` yes/no | global | `woodev_location_address_suggestions` = `yes` / `no` (framework boolean storage, `settings-api/abstract-class-settings.php:696`) | only if that option does not exist yet (another Woodev plugin's choice is kept) |
| `region_suggestions`, `city_suggestions` yes/no | global | **not migrated** | v2 has no «off» for the region/settlement cascade: field modes are `typeahead` / `related-list` / `ajax-select2` only (fw `location/class-location-provider-registry.php:220-258`), and with DaData only `typeahead` is offered. Writing `no` into a mode would be clamped to `typeahead` anyway. `field_mode_*` options are never touched; the report says «Подсказки региона и города теперь работают всегда» for a site that had them off. Provisional P16 |
| `autocomplate_customer_address` (sic) yes/no | global | `woodev_location_default_locality_policy` = `geoip` (yes) / `off` (no) (`class-location-provider-registry.php:267-268`) | only if that option does not exist yet |
| `debug_mode` yes/no | global | `woodev_range_rate_advanced_enable_debug` = `yes` / `no` | only if that option does not exist yet. The framework's own `Advanced_Settings` carry-over reads the v1 key `enable_debug`, not range shipping's `debug_mode` (`settings/class-advanced-settings.php:26-46`), so the upgrade writes it itself, before the handler is first built in that request |
| `title`, `show_distance`, `show_duration` | instance | same keys | untouched |
| `mode` | instance | `cyclin`→`cycling`, `driving-traffic`→`driving_traffic`; Google `driving_traffic`→`driving` (v1 had no traffic for Google); 2GIS `driving`→`driving_traffic` (v1 always used jam) | |
| `avoid` | instance | same | kept as configured even for Mapbox driving where v1 silently ignored it (R §8.2), Provisional P7 |
| `rules` | instance | untouched + `rules_mode`, `distance_bands` via §5.5 | |
| — | instance | `distance_rounding = round`, `approximate_notice = rate`, `require_exact_address = no`, `_v2_migrated = 2.0.0` | |
| `woodev_range_rate_show_install_notice` | option | deleted | |
| `woocommerce_range_rate_settings` | option | **left in place** (rollback to 1.2.x) | v2 never reads it after the upgrade |
| licence `woodev_range_rate_license_key` | option | unchanged (same plugin id) | |

**What is logged:** one summary to the plugin log (written regardless of «Логирование» — it is the support record of a
one-time change) and stored in `woodev_range_rate_upgrade_report` (non-autoload): instance count, per instance id →
mode chosen (grid/advanced + reason code), provider, warehouse; «ключ перенесён: да/нет» per key; skipped targets
(«ключ DaData уже указан другим плагином — не изменён»). **Never a key value.** An admin notice once: «Плагин обновлён
до 2.0. Склад и цены перенесены автоматически. [Открыть настройки]», plus, when any instance went advanced, «Для N
методов цены сохранены в расширенном режиме — их можно оставить как есть».

## 9. Hooks

**Kept from v1 (same names, same arguments):**

| Hook | Where in v2 |
|---|---|
| `woocommerce_shipping_range_rate_is_available` ( true, $package ) | end of `is_available_for_package()` |
| `woocommerce_shipping_range_rate_get_customer_address_string` ( $parts ) | §2.3 step 1 |
| `woocommerce_range_rate_shipping_calculated_distance` ( $km, $metres ) | §2.3 step 5, both modes |
| `woocommerce_range_rate_shipping_label_suffix` ( $suffix, $km, $package ) | §6.4 |
| `woocommerce_range_rate_shipping_rule_cost_{distance\|time\|weight\|order_total\|quantity}_shipping` | advanced engine |
| `woocommerce_range_rate_shipping_rule_cost_calculation` | advanced engine |
| `woocommerce_range_rate_shipping_rule_cost_{…}_shipping_match` (5) | advanced engine |

**Retired through `Woodev_Hook_Deprecator`** (their code is gone, a callback would never run): `wc_range_rate_settings_params`
(admin JS params), `wc_range_rate_suggestions_plugin_default_params` (v1 checkout widget), `wc_range_shipping_google_*`
(3, dead geocoding path, R §8.5). Provisional P8 (D6 «keep v1 hooks» read as «keep every hook that affects a price»).

**New:** `woodev_range_rate_warehouse_for_package` (filter the chosen warehouse), `woodev_range_rate_distance_result`
(filter `Distance_Result`), `woodev_range_rate_grid_cost` (filter the grid price before framework features),
`woodev_range_rate_distance_fallback` (action: provider id, reason code), `woodev_range_rate_distance_providers`
(filter: register an extra `Distance_Provider`), `woodev_range_rate_ors_base_url`. Framework hooks
(`woodev_shipping_method_calculated_rate`, …) apply as for every carrier.

## 10. Testing and cards

### 10.1 Tests

- **Unit (PHPUnit + Brain Monkey)**, the tracking template's setup: Band_Grid (E1–E8, validator cases M27, row delete
  keeps contiguity), Legacy engine vs the v1 oracle incl. split-package weight (M23), fractional distance filter (M22)
  and duration-unavailable cases (M24–M26), Rules_Converter (matrix §5.6), Haversine (known pairs ±0.5 %),
  Warehouse_Store invariants and delete rules, Destination_Point_Resolver (chain reuse, street mismatch → server
  geocode, **same house / different строение → no reuse**, non-DaData chain record → re-geocode, qc_geo → precision,
  geocode guard closed → settlement point / `geocoder_unavailable`, empty answer cached 1 h), Call_Guard (**interleaved
  final-slot reservation at 1 899 / 1 900 → exactly one call**, breaker transitions, **minute-limit 429 with unused daily
  budget → 60 s cooldown, not until midnight**, `Retry-After` honoured), Distance_Service (cache hit/miss, fallback on
  every exception category, disconnected provider, **coefficient change after warming the rate and road caches → new
  price at once**, **two destinations 10 499 m / 10 501 m from one origin → 10 and 11 km, never sharing a cache entry**),
  every adapter against **recorded fixtures** `tests/fixtures/providers/<id>/<case>.json` (ok, no_route, 401, 429
  minute, 429 daily, 5xx, garbage) served through `pre_http_request` — no live keys in tests, fixture keys are
  `TEST-KEY-<id>`; a secret-redaction test per adapter on the sanitized request event, the log line and the error path
  (§3.2); classic notice with an empty merchant description (§6.2); upgrade_to_2_0_0 on stored v1 option fixtures incl.
  both yes/no states of every shared setting (idempotent: run twice, same result).
- **Live probe (manual, not CI):** card C7/C6 workers run each adapter once with the operator's keys through
  `wp eval` on the rig, printing distance only (keys-leak rule: never echo options or keys).
- **Integration on the rig:** classic and block checkout, both notice placements, «Требовать точный адрес» on both,
  nearest warehouse, fallback by revoking a key, order meta.
- **local-ci** on the private plugin repo is the merge evidence (private repo rule), GitHub CI only on PRs to `main`.

### 10.2 Cards (worker waves; F = framework repo, C = plugin repo)

| Card | Content | Depends | Size |
|---|---|---|---|
| F1 | Framework: generic «records table» settings control (rows of text / location-picker / main-radio cells, add/edit/delete, server-side row validation hook) + UI Kit gallery entry | — | M |
| C1 | Plugin scaffold on the template: loader (`plugin_id range_rate`, `download_id 7698`), `Range_Plugin extends Shipping_Plugin`, autoloader, phpcs/phpstan/phpunit, local-ci workflow | — | S |
| C2 | `Distance_Provider`, `Distance_Result`, `Straight_Line_Provider`, Haversine, `Distance_Cache`, `Call_Guard`, `Distance_Service` + tests | C1 | M |
| C3 | `Rules\Band_Grid`, `Legacy_Rules_Engine`, `Rules_Converter`, v1 oracle, matrix §5.6 | C1 | L |
| C4 | `Destination_Point_Resolver` + geocode cache + location opt-in (`Range_Location_Adapter`, checkout handler fields) | C1 | M |
| C5 | Warehouses: `Warehouse_Store`, settings section on F1, nearest choice, delete rules + notices | F1, C1 | M |
| C6 | «Расчёт расстояния» section: straight card (coefficient), 5 connection sections, probes, status, disconnect → notice; ORS + Mapbox adapters with fixtures | C2 | M |
| C7 | 2GIS + Yandex + Google adapters with fixtures, secret-name overrides; live probe report | C2 | L |
| C8 | `Range_Shipping_Method`: `rate_package()` wiring, label suffix, meta, cache context, hidden reasons, kept hooks | C2, C3, C4, C5 | M |
| C9 | Instance admin fields: warehouse 0/1/many, service/mode/avoid filtering, grid editor with preview, advanced editor; one admin JS file | C8 | L |
| C10 | Checkout: approximate notice (3 placements × classic/block), «Требовать точный адрес» classic + block | C8 | M |
| C11 | Order meta labels on the order screen, rig check of e-mails | C8 | S |
| C12 | `upgrade_to_2_0_0` + report + notices, v1 fixtures, idempotency | C3, C5, C6 | M |
| C13 | Rig e2e (classic + block), Russian catalogue, docs + product card + materials package, demo site with Homelab AI (D10) | all | M |

Waves: **W1** F1 · C1 · then C2 ∥ C3 ∥ C4 (disjoint dirs) → **W2** C5 ∥ C6 ∥ C7 → **W3** C8 → C9 ∥ C10 ∥ C11 →
**W4** C12 → **W5** C13. Critics of another lineage on C3, C7, C8, C12 at least.

### 10.3 Release

Version 2.0.0, «Requires Woodev Framework 2.0.26+» (F1), materials package per the release rule; demo site
`range.wootest.ru` (D10) created by Homelab AI once C13 starts.

## 11. Open — gaps filled here (Provisional) and operator items

| # | Gap | My choice | § |
|---|---|---|---|
| P1 | v1 auto-adds the method to RU zones on install | dropped for fresh installs (merchant adds it, like every v2 carrier) | §1 |
| P2 | Which v1 features the grid drops | confirmed by D14 for time / quantity / × количество; also per-rule %-fee, OR/AND, sum/break/abort — advanced only | §5.2 |
| P3 | Duration-dependent migrated rules without a duration | **settled by the operator (Revision 1 #8):** hide with `duration_unavailable`, never a zero-minute price; three-valued matching decides «depends» | §5.4 |
| P4 | Can a new instance use the advanced engine? | no; migrated only; «Перейти на простую таблицу» only when proven equivalent | §5.4 |
| P5 | 0 m destination | v2 quotes row 1 when its «от» is 0 (v1 hid the method) — the single documented deviation | §5.5 |
| P6 | «Order admin box» | shipping-line meta, no separate meta box | §7d |
| P7 | Mapbox avoid on driving was ignored by v1 | v2 applies the merchant's stored choice (distance may change, prices per km do not) | §8 |
| P8 | Dead / UI-only v1 hooks | retired via Hook_Deprecator, price hooks all kept | §9 |
| P9 | Per-km price base | settled by D14: WHOLE distance (v1 `cost_unit` semantics) + «Пример» column | §5.2 |
| P10 | New-instance distance rounding | settled by D14: `ceil` whole km, selectable; migrated `round` | §3.3 |
| P11 | Cache TTLs where terms are unread (2GIS, Mapbox, Google, DaData) | 7 d / 1 d / 1 d / 30 d (empty geocode 1 h); traffic modes 1 h; straight line never cached | §2.4 |
| P12 | Daily-cap defaults | ORS 1 900, Yandex 1 000, others unlimited; automatic DaData geocode 5 000 (no UI) | §2.5 |
| P13 | 2GIS: Routing API vs Distance Matrix | **Distance Matrix API — operator decision 05.11.2026 (s218)**, replaces the provisional «Routing API» | §4.3 |
| P14 | Default notice placement | `rate` «Показывать под методом» | §6.2 |
| P15 | Warehouse address precision | street/city-level accepted with a warning | §3.1 |
| P16 | v1 region/city hint switches set to «no» | not migrated — v2 has no «off» mode for the cascade; the report tells the merchant | §8 |
| P17 | Same-key request coalescing for road calls | not built (KISS); concurrent misses may both call, each counted against the cap | §2.5 |
| P18 | Short-throttle 429 cooldown | `Retry-After`, else 60 s doubling to 15 min; midnight only on confirmed daily exhaustion | §2.5 |

**Needs the operator:**

1. **The grid scheme and mockups §5.2, §7c** — answered in D14–D15; the revised §7c mockup (row-1 «от», «свыше N»,
   «Не доставляем») is the one to glance at.
2. **F1 is framework work** (records-table control) — fine to add to the framework, or should the warehouses table be a
   plugin-owned screen? Recommendation: F1 (reusable; Ozon/other plugins with N points benefit).
3. **Private repo** for the plugin (`kalbac/woocommerce-range-shipping`?) — exists or to be created.
4. **Payment options for foreign services** (D1) — his text at card/docs time; the settings copy names ORS as free.

**UNVERIFIED summary:** ORS endpoint/auth/body/response (JS docs page); 2GIS host, body field names, response fields,
no-traffic mode, avoid filters, paid-API caching terms; Yandex response shape; Google `routeModifiers` field names;
Mapbox response doc; DaData caching terms; how each service signals «daily quota exhausted» vs a short throttle in a 429
(needed by the breaker classification, recorded with the fixtures); WC shipping-item meta never reaching buyer e-mails on
every theme. (The shared-settings option names used by the upgrade are now READ, §8.)

### 11.1 Revision 1 (critic round 1, Codex; all 14 findings approved by the operator; D14–D15)

| # | Finding | What changed |
|---|---|---|
| 1 | Google key left in header logs | §3.2: override `get_secret_param_names()` (+`key`) AND `get_secret_header_names()` (+`X-Goog-Api-Key`), merging parents; redaction test on request event, log and error path |
| 2 | Package weight changed migrated prices | §5.4: advanced engine uses whole-cart weight in the store unit (v1 `:924`); package/kg only in the grid; M23 split-package case |
| 3 | Second rounding after the distance filter | §2.3 step 5 and §5.4: round → filter → use as returned in both engines; converter keeps instances with a `…_calculated_distance` callback in advanced; M22 |
| 4 | Converted grid could fail its own validator | §5.5 steps 5–6: leading hidden run → row-1 «от» (D15 b); candidate validated with the editor's validator, else advanced; M21 incl. raw < 500 m |
| 5 | Coefficient change kept cached straight result | §2.4: straight line not cached at all; coefficient stays in the rate-cache context; test after warm caches |
| 6 | Rounded cache coordinates merged price-sensitive points | §2.4: road cache keyed by exact DaData coordinates; straight line not cached; 10 499 / 10 501 m boundary test |
| 7 | Classic notice via rate description shows nothing | §6.2: classic uses `woodev_shipping_rate_additional_info` (`class-shipping-plugin.php:989`) reading rate meta; blocks keep the rate description; empty-description and transition tests |
| 8 | Fallback could make duration-priced rules free | §5.4: operator policy — hide with `duration_unavailable`, three-valued time matching; M24–M26; report flag; P3 settled |
| 9 | Server geocode had no cap/backoff | §2.3 c–d, §2.5: `Call_Guard 'dadata_geocode'` (5 000/day, breaker), empty answers cached 1 h, settlement point during cooldown; interactive hints not counted |
| 10 | Location fields do not guarantee DaData | §2.3 «DaData ownership», §6.1: reuse only `provider_id() === 'dadata'` records with `qc_geo`; server geocode via `get_registered_provider('dadata')`; admin picker re-geocoded; no shared config written |
| 11 | Reuse ignored корпус/строение | §2.3 a: street + house + `block()` must match; settlement reuse only when the package has no street; test |
| 12 | Shared-settings migration not implementable | §8: exact options and yes/no mapping (`address_suggestions`, `default_locality_policy` geoip/off, `woodev_range_rate_advanced_enable_debug`); region/city hints not migrated (P16); both states tested |
| 13 | Every ORS 429 treated as daily exhaustion | §2.5: `Retry-After` → honoured; confirmed daily → midnight; else 60 s doubling to 15 min (P18); test |
| 14 | Daily cap not enforced under concurrency | §2.5: conditional `UPDATE … WHERE value < cap` reservation; «one call per TTL» claim replaced by an exact-cap bound (P17); interleaved test |
| D14 | Mockup answers | §5.2: per-km on the whole distance, grid drops per-minute / quantity / × количество, ceil for new, round for migrated |
| D15 | Contiguous rows | §5.2–5.6, §7c: row-1 «от» only, read-only «свыше N», explicit «Не доставляем» checkbox, empty cost invalid, blocking row errors, delete keeps contiguity, decimals, 0-kg products hint; E7–E8 |

### 11.2 Revision 2 (critic round 2, Codex luna: 14/14 closed, one new finding; coordinator edit)

- #15: `distance_rounding` has exactly two modes, `ceil` (new) and `round` (migrated); the `none` mode is removed.
- D16: products without weight use the framework default weight in the simple grid; migrated advanced rules keep v1
  raw cart weight. Replaces D15 h.

## Related

- [2026-11-04-tracking-v2-plugin-design.md](2026-11-04-tracking-v2-plugin-design.md) — spec shape, loader and template plugin.
- [2026-10-30-ozon-delivery-v2-plugin-design.md](2026-10-30-ozon-delivery-v2-plugin-design.md) — v2 carrier spec conventions.
- [2026-10-04-sp11-block-checkout-design.md](2026-10-04-sp11-block-checkout-design.md) — block checkout adapter used in §6.
- [2026-08-15-location-chain-design.md](2026-08-15-location-chain-design.md) — the location chain read in §2.3.
- [../adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md) — installed-site contract kept in §8.
- [../wiki/architecture.md](../wiki/architecture.md) — settings tab composition (`get_tab_settings_providers`), connection sections.
