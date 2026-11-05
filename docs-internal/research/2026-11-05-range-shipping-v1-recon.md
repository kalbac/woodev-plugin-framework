# Range shipping v1 1.2.0 — reconnaissance for the v2 rewrite

> s217 (05.11.2026), read-only worker report, kept because the v1 source at `~/Projects/legacy-v1/woocommerce-range-shipping` is deleted after the v2 release. Design: [the v2 spec](../specs/2026-11-05-range-shipping-v2-design.md).

Sources read: v1 tree `/Users/maksimmartirosov/Projects/legacy-v1/woocommerce-range-shipping` (paths below are relative to it), the public card (fetched OK, HTTP 200, 15 s), framework `woodev/` 2.0.25 (architecture.md + shipping-method/ + location/ + licensing), template plugin `woocommerce-order-tracking-number` 2.0.0. Nothing was edited; no API was called (no keys) — every "bug" below is read from code, not reproduced.

## 1. What v1 does, end to end

**Merchant flow.**
1. Plugin id/method id/integration id are all `range_rate` (`woocommerce-range-shipping.php:57`, `includes/class-shipping-rate.php:51`, `includes/class-integration.php:8`).
2. WC → Settings → Integration → «Стоимость за расстояние»: pick the geo provider (Mapbox/Google/2GIS) + its key, mandatory DaData key + secret, suggestion switches, debug (`includes/class-integration.php:38-126`).
3. Lifecycle `install()` auto-adds the method to every shipping zone that contains country RU and has none, stores a one-time notice option `woodev_range_rate_show_install_notice` (`includes/class-lifecycle.php:30-63`).
4. Per zone instance: title, travel mode, avoid, show distance/duration in label, warehouse address (DaData suggest in admin → hidden `from_lon`/`from_lat`), and a **rules table** (`class-shipping-rate.php:127-185`, table HTML+inline JS 259-570).
5. Checkout: WC calls `is_available()` → `get_rates()` (the whole calculation lives there, `class-shipping-rate.php:311-1075`); `calculate_shipping()` just replays `$this->available_rates` (295-302).

**Distance.** Destination address string (country name, postcode, state name, city, address_1, address_2; falls back to `WC()->customer` shipping city/address — `get_customer_address_string`, 1443-1493) → **DaData Clean** (`cleaner.dadata.ru/api/v1/clean/address`) → `geo_lat/geo_lon` → provider **route** (road distance + duration) from the stored warehouse lon/lat. Not straight-line; no haversine fallback. Distance rounded to whole km (`round($m/1000)`, line 372), time to whole minutes; rules compare these rounded values.

**Gates before computing** (`get_rates`, 311-364): skipped in admin cart-load/REST/XML-RPC; licence must be valid (`is_license_valid()`); on `update_order_review` with empty address → skip; skipped if the address string is only a 2-letter country code; needs `from_lon/from_lat`. Any exception → logged (only if debug on) and the method silently disappears (1061-1064).

**Pricing formula** (rules loop 420-1051; per rule cost `rule_cost_calculation` 1184-1243):
- A rule = OR-groups of AND-conditions. Condition kinds: `distance` (km), `time` (min), `weight` (whole cart weight — `WC()->cart->get_cart_contents_weight()`, not package weight, 405), `total` (`package['cart_subtotal']`), `quantity`. Each has min/max, empty = open, inclusive both ends; the 5 `*_shipping_rule` methods 1258-1433.
- Matching rule cost = `cost_unit × units` + `cost` + `fee` (units = rounded km OR minutes, chosen per rule by `unit`; `per_qty` multiplies both parts by cart quantity; `fee` is a flat amount or `N%` of `contents_cost` through WC `get_fee`).
- **All matching rules are summed** (`$shipping_total += $rule_cost`, 1014) until a rule with `break` is hit; a matching `abort` rule hides the method (1043). If nothing matched → no rate. The first-condition type decides a buyer-facing «out of range» notice (1016-1038) via `woocommerce_no_shipping_available_html` (each instance appends a filter; last instance wins).
- No min/max cost, no rounding, no free-shipping threshold (only emulated by a rule with cost 0 + `total` condition), no markup outside the per-rule `fee`.
- Label: `title (N км.; M мин.)` per `show_distance`/`show_duration` (377-394). Rate id = `get_rate_id()`, **no rate meta**.
- Extension points (filters, keep-or-drop question): `woocommerce_range_rate_shipping_label_suffix`, `…_calculated_distance`, `…_rule_cost_calculation`, `…_rule_cost_{distance|time|weight|order_total|quantity}_shipping`, `…_rule_cost_*_shipping_match`, `woocommerce_shipping_range_rate_is_available`, `woocommerce_shipping_range_rate_get_customer_address_string`, `wc_range_rate_suggestions_plugin_default_params`, `wc_range_shipping_google_*` (dead, see §8). The rule table and these filter names are a rename of WooCommerce's «Distance Rate Shipping» extension (comments say «DRS' row»).

**Caching.** Only the framework's generic API cache: `Woodev_Cacheable_API_Base` stores every successful response in a transient `woodev_range_rate_api_response_<md5(uri+body+lifetime)>` for **24 h** (v1 `woodev/api/abstract-cacheable-api-base.php`, lifetime default 86400 in the request trait). So the same address string costs one DaData call and one route call per day. There is no rate-level cache. The plugin's own `clear_transients()` (integration 274-278, method 1502-1506) deletes `_transient_wc_range_rate_shipping_%`, a prefix **nothing ever writes** → dead code; settings changes never flush the API cache (changed mode/avoid/key change the URL so they miss it naturally; changing the warehouse changes the URL too).

## 2. Geo services

| Service | Used for | Endpoint / version (file) | Merchant enters | Status |
|---|---|---|---|---|
| **DaData Clean** | the ONLY geocoder of the customer address (mandatory) | `https://cleaner.dadata.ru/api/v1/clean/address`, POST `[address]`, headers `Authorization: Token <key>`, `X-Secret` (`api/dadata/class-api-dadata.php:23-39`) | `dadata_key` + `dadata_secret` | Current. Paid per record (v1 UI text: balance must be ≥ 1 ₽). `qc_geo` accuracy flag is **ignored** — a city-level hit gives a city-centre point silently. |
| **DaData Suggestions** (browser) | admin warehouse picker; checkout region/city/address hints; IP geolocation (`iplocate/address`) | CDN `suggestions-jquery@22.6.0`, partner `6846` (`class-checkout.php:18-55`, `assets/js/admin/shipping-method.js`) | same `dadata_key` (put into page JS as the token) | Current API; library 22.6.0 (2022) pinned from jsDelivr. |
| **Mapbox** (default) | route distance+duration | `GET /directions/v5/mapbox/{driving, driving-traffic, walking, cycling}/{lon,lat;lon,lat}`(`api/requests/…mapbox…:9-37`) | `api_key` (access_token) | Directions v5 still the documented API. Geocoding calls (`/geocoding/v5/mapbox.places`) exist but are never invoked (§8). Free tier exists; limits not visible in code. |
| **Google** | route distance+duration | `GET maps.googleapis.com/maps/api/distancematrix/json` (`…google…:46-80`) | `api_key` | **Distance Matrix API is "Legacy" since 1 Mar 2025** (Google's own migration page → Routes API `routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix`, POST + field mask). Still works for existing projects per Google's text; new projects may not be able to enable it. Card quotes «~$0,01 за запрос». |
| **2GIS** | route distance+duration | `POST catalog.api.2gis.com/carrouting/6.0.0/global` (`…2gis…:9-34`) | `api_key` | **Directions API / carrouting 6.0.0 is the old API**; 2GIS docs say Directions is replaced by Routing API (`/routing/7.0.0/global`, announced 28.07.2023). Card tells buyers to e-mail a named 2GIS employee for a key — stale. Request puts `x=lat, y=lon` (`…2gis…:14-23`); 2GIS convention is x=lon, y=lat → **suspected swapped coordinates, untested**. |
| **Yandex Geocoder** | nothing — response class `api/responses/class-wc-yandex-geocode-response.php` is orphaned (replaced by DaData in 1.2.0, changelog only says «интегрирован DADATA») | — | — | dead |

Mode mapping: Mapbox gets the raw mode; Google maps driving*/walking/cycling→bicycling; 2GIS: driving & driving-traffic both → `type=jam` (always traffic), anything else → `pedestrian`. Avoid: Mapbox `exclude` (but see bug in §8), Google `avoid` (tolls/highways), 2GIS none.

## 3. Settings inventory

Storage facts first (they decide migration):
- Integration page and shipping method **share the option key** `woocommerce_range_rate_settings` (both id `range_rate`, no instance suffix). `WC_Shipping_Method::get_option()` falls back to that global option for any key not in the instance fields (verified in WC 11.2.1 `abstract-wc-shipping-method.php:520-529`) — this is how the instance reads `dadata_key`, `from_lon`, `from_lat`.
- Instance settings: `woocommerce_range_rate_{instance_id}_settings`.
- Licence: `woodev_range_rate_license_key` (+ record option); upgrade 1.2.0 already moved it from `woocommerce_stoimost_dostavki_za_rasstoyanie_license_key` (`class-lifecycle.php:15-28`). Download id 7698. A v2 plugin that keeps plugin id `range_rate` keeps this key by construction (`get_plugin_option_name`, identical formula in v2 `class-plugin.php:1717`).

| id | stored in | type / default | meaning |
|---|---|---|---|
| `api_provider` | `woocommerce_range_rate_settings` | select mapbox/google/2gis, default `mapbox` | route provider (one for the whole site) |
| `api_key` | same | text, required | key of that provider (one field for all three) |
| `dadata_key`, `dadata_secret` | same | text, required | DaData token / Clean secret |
| `region_suggestions`, `city_suggestions`, `suggestions` | same | checkbox `no` | DaData hints on state / city / address; auto-reset to off if DaData keys empty; disabled if CDEK ≥2.2 or Russian Post ≥1.2 already provide them (`class-integration.php:129-228`, `woocommerce-range-shipping.php:222-262`) |
| `autocomplate_customer_address` (sic) | same | checkbox `no` | fill city from IP via DaData |
| `debug_mode` | same | checkbox `no` | log requests/responses to `range_rate` WC log |
| `from_lon`, `from_lat` | same (**global, written by an instance save**) | string | warehouse coordinates, set by the admin suggestion JS |
| `title` | instance | text, «Стоимость за расстояние» | method title |
| `mode` | instance | select `driving`/`driving-traffic`/`walking`/`cyclin` (**typo, should be cycling**), default `driving` | travel mode |
| `avoid` | instance | select none/tolls/highways | avoid routes (driving only) |
| `show_distance` / `show_duration` | instance | checkbox `yes` / `no` | label suffix |
| `shipping_from_address` | instance | text (custom `select_shipping_address`) | warehouse text; **only a display string** — the coordinates live in the global option |
| `rules` | instance | array of rules (custom table) | see below |

Rule row (`validate_distance_rate_rule_table_field`, 78-264): `conditions[or][and]{condition,min,max}`, legacy `condition/min/max` (kept for rules from ≤1.0.5), `cost`, `cost_unit`, `unit` (`distance`|`time`), `per_qty` yes/no, `fee` (number or `N%`), `break` yes/no, `abort` yes/no. Prices stored with `wc_format_decimal`, `total` min/max decimal, `quantity` int.

## 4. Checkout

- **Classic checkout only.** No block code anywhere (no `woocommerce_blocks`, no Store API, no `declare_compatibility` for cart_checkout_blocks; only `supports_hpos`). On a block checkout the DaData hints do not appear; the rate itself still works because `is_available()` reads `package.destination`, which blocks fill.
- `class-checkout.php` loads suggestions-jquery from jsDelivr on checkout and `edit-address`, only if any of the three hint switches is on and `is_suggestions_allowed()` says no other Woodev plugin already does it. `checkout.js` (176 lines) binds on `updated_checkout`: region (`#billing_state`, bounds `region-area`), city (`#billing_city`, `city-settlement`, drops FIAS level 65, fills postcode, IP-detect via `iplocate/address`), address (`#billing_address_1`, `street-flat`, constrained by city; on select fills city + postcode and triggers `update_checkout`). `suggestions-plugin.js` is a thin jQuery wrapper.
- **Selectors are billing-only** (`#billing_*`). The rate reads the *package destination* (shipping address, which differs from billing when «Доставить по другому адресу» is ticked) → hints never help the field that actually drives the price in that case.
- Recalculation triggers: WC's own `update_checkout` on field change; the JS triggers it itself after an address pick and on `suggestions-clear`. On the cart page the destination has no street, so the quote is DaData's answer for «country, postcode, state, city» — a city-centre distance, and `requires_address` (WC option) only gates the IP-detect refresh. Nothing is stored on the session; every package recalculation re-enters `get_rates()` (API hits mitigated only by the 24 h transient).
- Buyer-facing text: label suffix and the out-of-range notice (Russian, hard-coded in PHP, no i18n function anywhere).

## 5. Order data

None. No order meta, no order notes, no rate meta, no admin order display, no emails (grep for `meta_data`/`add_order_note`/`update_meta` over `includes/` and `api/` is empty). The distance/time the buyer saw exists only inside the label text of the stored shipping line. Consequence: a v2 rewrite has no order-data contract to preserve, but also nothing to show in the admin order.

## 6. Public card vs code (https://woodev.ru/downloads/range-shipping-cost)

Card facts: 3 000 ₽ / 1 year updates+support, v1.2.0, 100+ installs, rating 5 (3 reviews), «Обновлён 2 июня 2025» although the 1.2.0 changelog date is 20.11.2023 and the header says WC tested up to 8.3.0.

| Card says | Code |
|---|---|
| Rules by distance, travel time, order total, weight, quantity | ✔ |
| Mapbox / Google Matrix / 2GIS | ✔ — Google API is Legacy, 2GIS is the old Directions API |
| «Расчётное время с учётом пробок (или без них)» | partly: only Mapbox `driving-traffic` is real; Google has no traffic parameter (`driving-traffic` is mapped to plain driving), 2GIS always uses `jam` |
| «Для работы нужен API-токен одного из трёх сервисов» | **misleading**: a paid DaData Clean key+secret is mandatory (`dadata_key`/`dadata_secret` required; no DaData → no rate). Card names DaData only in the 1.2.0 changelog line. |
| 2GIS key «свяжитесь с petrov.al@2gis.ru» | stale contact; 2GIS key flow changed |
| «Подходит для дропшиппинга» | no code behind it |
| Not on the card, in code: OR/AND condition groups + «Расширенный режим» (changelog only), per-quantity multiply, per-rule %-markup, break/abort, walking/cycling modes, avoid tolls/highways, distance/time in the label, checkout hints, IP auto-fill, auto-adding the method to RU zones | — |
| Changelog 1.0.5 «единый сервис Yandex Geocoding» | removed in 1.2.0 without a changelog line |

## 7. What breaks / changes on v2

**v1 framework APIs used, and their v2 fate** (all verified by grep in `woodev/`):
- `Woodev_Plugin_Bootstrap::register_plugin(...)` with `load_shipping_method`, `backwards_compatible '1.2.1'`, `minimum_*_version` → **tombstone, never registers**; replace with `Woodev_Loader::register( __FILE__, [ plugin_id 'range_rate', download_id 7698, framework_version, backwards_compatible, requirements, main_class, callback, supported_features ] )` (template: tracking plugin main file).
- `final class … extends Woodev_Plugin` + `parent::__construct('range_rate', V, ['supports_hpos'=>true])` → extend `\Woodev\Framework\Woocommerce_Plugin`; HPOS declared via loader `supported_features`. `get_download_id()` override is no longer needed (concrete, from loader); `get_documentation_url`, `get_sales_page_url`, `get_support_url` still exist.
- `WC_Integration` as the settings page + `get_integration_handler()` / `is_plugin_settings()` / `get_settings_url()` hacks → woodev-settings page via `get_settings_providers()` returning `Settings_Provider`/`Settings_Section` + a `Woodev_Abstract_Settings` subclass (tracking plugin `class-plugin.php`, `settings/class-tracking-settings.php`). `generate_checkbox_html` conflict logic with CDEK/Russian Post disappears (shared layer, see below).
- `Woodev_Lifecycle` (`upgrade_to_1_2_0()` without arg; `install()` zone auto-add) → still the same class; v2 passes `$installed_version` to `upgrade_to_*` (PHP tolerates the missing param, but write it). The `get_license_instance()->verify_license()` licence move for pre-1.2.0 sites can be dropped or kept as a no-op chain.
- `get_license_instance()->is_license_valid()` (rates) → `Shipping\License_Gate::is_in_force( $plugin )` (signed claim; `is_license_valid` still exists in `class-plugin-license.php:1059` but the gate is the contract); gate hides the method and tells a manager why — matches the "licence gate is the default" rule.
- `Woodev_Cacheable_API_Base`, `Woodev_API_JSON_Request/Response`, `Woodev_Cacheable_Request_Trait`, `Woodev_API_Exception`, `woodev_{api_id}_api_request_uri` filter, `Woodev_Helper::{get_requested_value,list_array_items,str_starts_with,str_convert,get_wc_log_file_url,is_current_screen}`, `load_class`, `get_admin_notice_handler`, `log_debug` — all still present, so the API layer ports almost as is.
- Custom `log()` override reading the integration `debug_mode` → framework logging setting (`log_debug`).

**What the 2.x framework already replaces:**
- *DaData address geocoding + suggestions + IP locate*: `Location\Providers\Dadata_Provider` with capabilities `normalize` (= v1's `cleaner…/clean/address`, returns `Location_Record` with `lat()/lon()`, `class-dadata-provider.php:590-606`, `record_from_dadata_fields` 1436-1437), `suggest`, `locate`; credentials `woodev_location_token` and `woodev_location_clean_secret` (option name = `'woodev_location_' . field id`, `class-dadata-provider.php:1724`, fields 385-410); the «Подсказки адреса» switch `woodev_location_address_suggestions`; field modes for region/settlement (`field_mode_region`, `field_mode_settlement`). The suggestion token no longer has to be dumped into page JS (REST `/suggest` proxy). `Shipping_Plugin::get_location_service()` exposes it. A plugin on this layer drops: `class-checkout.php`, `checkout.js`, `suggestions-plugin.js`, the DaData API classes (~280 lines), the suggestion settings and the competitor-conflict logic.
- *Shipping method scaffolding*: `Shipping\Shipping_Method` (needs `Shipping_Plugin`, abstract `get_method_id`, `get_delivery_type`, `get_method_form_fields`, `rate_package`; plugin `get_shipping_method_classes`, `get_api(): ?Shipping_API` may be null) already provides licence gate, `FEATURE_RATE_CACHE` (a transient of a few minutes keyed on package), min/max cost, markup, rounding, free-shipping threshold/coupon, order-amount limits, delivery-time display, hidden-reason report for managers, `woodev_shipping_method_calculated_rate` filter. Those are exactly the things v1 lacks. Trade-off: `Shipping_Plugin` is big (3 367 lines; orders registry, REST, checkout handler, settings tab) — it is built for carrier plugins; whether a pure calculator should extend it, or stay `Woocommerce_Plugin` + own `WC_Shipping_Method` (like the tracking plugin) is an open design call, not verified here by building anything.
- What the framework does **not** have: a route/distance client (no Mapbox/Google/2GIS code), a distance/time/weight rule table, an origin-point picker. Those stay plugin code.
- Whether the checkout location record keeps `lat/lon` between requests (so the plugin could skip the paid Clean call when the buyer picked a suggestion that already carries `geo_lat/geo_lon`) was **not verified**: `Location_Record` carries them and the DaData mapper fills them, but I did not trace `Customer_Location_Store` persistence.

**Installed-site data contract (ADR-005 lists these as release-blocking):**
- keep plugin id/method id `range_rate` (instance keys `woocommerce_range_rate_{id}_settings`, WC shipping zone method rows, the licence option); keep the `rules` array shape or write an upgrade routine;
- global option `woocommerce_range_rate_settings`: either keep reading it (api_provider, api_key, from_lon/lat, debug) or migrate; `dadata_key`/`dadata_secret` → `woodev_location_token`/`woodev_location_clean_secret` if the shared layer is adopted (do not overwrite a token another Woodev plugin already stored); the 4 suggestion checkboxes → framework switches;
- `from_lon/from_lat` are global → a site with two zone instances has ONE shared origin (see §8); migration should copy the global pair into every instance if per-instance origins are wanted;
- the typo value `cyclin` is stored on sites that chose it; stored `mode` values must be mapped;
- zone-method auto-install and the install notice option can be left behind (not part of the contract).

## 8. Size and quality

LOC (non-vendored, Σ 3 634): `includes/class-shipping-rate.php` 1 508 (≈ 300 of it inline admin HTML/JS in `generate_distance_rate_rule_table_html`, ≈ 270 rule validation, ≈ 360 calculation, ≈ 200 five near-identical `*_shipping_rule` methods), main file 341, integration 278, checkout PHP 61, lifecycle 69, `api/` ≈ 1 030 PHP (3 providers+req+resp ≈ 750, DaData ≈ 280), frontend JS 235, admin JS 17, CSS 98. Vendored old `woodev/` 177 files (not counted).

Concrete defects / dead code seen in the code:
1. **Origin is global, not per instance**: `validate_shipping_address` → `$this->update_option('from_lon'|'from_lat')` (`class-shipping-rate.php:206-207`); `from_lon/lat` are not instance fields, so `WC_Settings_API::update_option` writes `woocommerce_range_rate_settings`. Two zones/instances with different warehouses → the last saved warehouse wins for all, while each instance still displays its own address text.
2. **Mapbox `avoid` is silently dropped for driving**: `get_distance` builds `exclude`, then reassigns `$this->params` for driving/driving-traffic (`…mapbox…-request.php:12-24`) — the exclude param never reaches the URL.
3. **`cyclin` typo** (`class-shipping-rate.php:146`) → Mapbox path loses its profile, Google receives `mode=cyclin` (invalid), 2GIS treats it as pedestrian.
4. 2GIS `x=lat/y=lon` (suspected swap) and old carrouting 6.0.0; 2GIS `jam` is always on (the «без пробок» mode cannot be honoured).
5. Dead code: `get_coordinates_by_address`, `get_geolocation` in all three providers + their request/response methods (≈ 250 lines), the Yandex geocode response, `get_yandex_geocoding_api()` (deprecated), `is_configured()` (deprecated no-op), `get_total_duration/destination` (Mapbox response, the loop shadows its own variable), the two `clear_transients()`, `process_admin_options()` overrides that only call parent, the commented-out `output=simple`.
6. Destination sync bug: hints bind billing fields only (§4).
7. `get_rates()` reads cart weight from `WC()->cart` not the package (wrong in multi-package / admin recalculation); `abort` returns `false` from inside the try, bypassing the notice reset.
8. Cache never invalidated by the plugin; stale prefix. DaData's `qc_geo` ignored (a street-less city-centre point yields a plausible but wrong distance, no signal to the buyer).
9. Debug mode logs request/response; the provider key travels in the query string (Google/Mapbox/2GIS), so whether the log redacts it depends on the v1/v2 logger — **check before shipping debug logs**.
10. All UI strings hard-coded Russian, no i18n; typos «доствки», «удались», «ощибки», `autocomplate_*`.
11. Rule-table inline `<script>` in PHP with `confirm()`; the table is raw string HTML with no escaping of `$this->id`/rule values in places (`echo $this->id`, `'wc_format_localized_price'` values).
12. Unit tests: none in the tree.

## 9. Concrete brainstorm questions (decisions for the operator)

Each ties to a fact above; (R) = my recommendation.

1. **Is DaData still the only geocoder, and the shared framework layer the place for it?** Fact: v1 hard-requires a paid Clean key (§2, §6); framework already has `Dadata_Provider::normalize()` returning lat/lon and a merchant-visible `woodev_location_token/clean_secret`. Options: (a) use the framework location layer, keys shared with every Woodev plugin **(R)**; (b) keep a private DaData client in the plugin; (c) also add a second geocoder (e.g. the route provider's own) so a shop without DaData still works. Decide also: what happens with no DaData key — method hidden (v1) or fallback.
2. **Which route providers survive?** Fact: Google Distance Matrix is Legacy, 2GIS code targets the old Directions API with suspected swapped coordinates, Mapbox is the card's recommended default. Options: (a) Mapbox only **(R, with the provider interface kept so others can be added)**; (b) Mapbox + 2GIS Routing 7 (RU coverage) ; (c) all three, port Google to Routes API. Needs live-key testing either way (none done here).
3. **Straight-line fallback / no-API mode?** Fact: v1 has no non-API path; every quote costs a route call (+ a DaData call), cached 24 h. Options: (a) route only (v1) **(R)**; (b) add «по прямой» (haversine from the DaData point) as a free mode and as the fallback when the route API fails; (c) both with a switch per instance.
4. **Formula scope — keep the rule table as is, or simplify?** Fact: OR/AND groups, sum-all-matching, break/abort, per-qty, %-fee, five condition types (§1). Options: (a) port 1:1 including `rules` storage so installed sites work untouched **(R — the data contract demands it; ≥100 installs)**; (b) simplified tier table (distance bands → price), migrate old rules into it where possible, hand-review the rest; (c) keep the shape but drop OR-groups.
5. **Adopt the framework's cost features instead of per-rule hacks?** Fact: `Shipping_Method` has min/max cost, rounding, free-shipping-from, markup, order-amount limits; v1 emulates free shipping with a cost-0 `total` rule. Options: (a) offer them as new instance options and keep per-rule `fee` **(R)**; (b) ignore, v1 parity only. Includes the question of rounding the distance (v1: whole km before comparing) — keep, or compare exact km?
6. **Extend `Shipping_Plugin`/`Shipping_Method`, or stay a lean `Woocommerce_Plugin` + own `WC_Shipping_Method`?** Fact: §7 trade-off; the tracking plugin is lean; the framework method supplies licence gate, hidden-reason reporting, rate cache, cost features, checkout location policy. Options: (a) framework `Shipping_Method` **(R, if the location layer is chosen in Q1 — they come together)**; (b) lean plain WC method with `License_Gate` only.
7. **Checkout UX for the address.** Fact: v1 = billing-only classic hints; framework layer = shared chooser/hints (classic + block adapter) and `address_suggestions` switch. Options: (a) whatever the framework layer gives, no plugin JS **(R)**; (b) plus a «Адрес доставки уточнён / приблизительный расстояние» hint; (c) none. Also: does the quote wait for a street (cart page shows a city-centre distance today) — require a full address (`woocommerce_shipping_cost_requires_address`-style) or keep approximate quotes?
8. **Block checkout.** Fact: v1 has zero block code; his rule: block-only gaps do not block the v2 release, classic is release-critical. Options: (a) classic + «it works if the framework layer works on blocks» **(R)**; (b) test and document blocks explicitly before release.
9. **Where does the warehouse live?** Fact: bug #1 — one global origin shared by all instances while the address text is per instance. Options: (a) per-instance origin (coordinates stored with the instance, picker via the shared location suggest) **(R)**; (b) one site-wide origin on the settings page; (c) multiple origins per instance (nearest warehouse wins) — new feature, not in v1.
10. **Settings page placement and what moves.** Fact: today = WC Integrations (your standing rule: never WC Integrations). Options: (a) woodev-settings section for provider, keys, debug, plus the framework's location tab for DaData **(R)**; (b) keep a minimal WC integration for compatibility. Migration: copy `api_provider/api_key/debug_mode` to the new store, copy `dadata_*` to `woodev_location_*` only if empty.
11. **Existing-site migration depth.** Fact: instance keys `woocommerce_range_rate_{id}_settings` and the `rules` shape, global option, `cyclin`, global lon/lat (§7). Options: (a) in-place upgrade, same plugin id, one `upgrade_to_2_0_0` that normalises everything and logs what it changed **(R)**; (b) ship as a new plugin and ask merchants to re-enter rules. Also: copy the global origin into every existing instance?
12. **What do we do with the public filters/hooks (`woocommerce_range_rate_*`)?** Fact: ~12 filters, some named `…rule_cost_*_shipping_match`; ADR-005 allows breaking only dependants being rewritten, and public hook names are listed as a contract. Options: (a) keep all names, document the ones we support **(R, low cost: they sit in the code being ported)**; (b) keep a short list, deprecate the rest through `Woodev_Hook_Deprecator`; (c) clean break.
13. **Show the distance/time to the buyer — and persist it on the order?** Fact: label suffix only, nothing in order meta (§5). Options: (a) keep the suffix, add rate meta (distance, minutes, provider) visible to the admin on the order **(R, tiny, helps support)**; (b) suffix only (v1); (c) drop the suffix by default.
14. **Traffic / modes.** Fact: «с пробками» is real only on Mapbox; `avoid` is broken on Mapbox driving; walking/cycling are in the UI. Options: (a) keep driving, driving-traffic, walking, cycling with the typo fixed and avoid repaired **(R)**; (b) drop walking/cycling (a delivery plugin for a car/courier); (c) keep only driving and "with traffic".
15. **Card, price and positioning.** Fact: the card hides the mandatory DaData cost, lists a stale 2GIS contact, claims «подходит для дропшиппинга» with no code behind it. Decide whether v2 is a paid upgrade for the existing 100+ installs or included in the licence year (affects migration/licence gating), and the new card must state DaData + route-key costs openly.

---
Not checked: no live call to any geo API (nothing proves the 2GIS coordinate swap or that Google/Mapbox responses still parse), no WordPress run of v1, `Customer_Location_Store` persistence of lat/lon, whether the framework logger redacts query-string keys, the public documentation page and the demo site, and the v1 vendored `woodev/` beyond the cache classes.
Risks: the rule data contract (`rules` array on ≥100 sites, no tests in v1) is the real migration risk; DaData Clean cost is paid by the merchant per distinct address and the current hard dependency makes the method vanish silently on any DaData error; two of the three providers sit on legacy APIs, so "keep all providers" means re-testing against live keys.

## Related

- [v2 design spec](../specs/2026-11-05-range-shipping-v2-design.md)
- [geo services research](2026-11-05-range-shipping-geo-services.md)
