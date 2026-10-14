# Yandex Delivery v2 Plugin Design

**Date:** 2026-10-12  
**Target:** new line `2.0.0` of the existing plugin `woocommerce-yandex-delivery` (plugin id `yandex_delivery`, EDD download 821), written from scratch on Framework v2. The second carrier required for the v2 release (#1179).  
**Release rule:** #1179 is closed only when two plugins (CDEK + this one) run on Framework v2, are tested and work together on one site (coexistence scenarios, D9).  
**Evidence:** code claims cite repository-relative file:line. Framework paths are relative to the framework repo root; `v1:` means `plugins-reference/woocommerce-yandex-delivery` (1.3.7, read-only); `cdek:` means `/Users/maksimmartirosov/Projects/woocommerce-edostavka`, branch `v2`, read-only. Measurements cite the three research folders of 12.10.2026. Anything not established in code or by measurement is marked **UNVERIFIED**.

## 0. How to read this spec

Section 1 lists the binding operator decisions (s174, 12.10.2026); nothing below reopens them. Section 2 is the v1 inventory. Section 3 maps every capability onto a framework seam and names the gaps. Sections 4–10 are the design per area. Section 11 is the installed-site contract, section 12 the migration, sections 13–14 coexistence and acceptance. Section 16 lists everything UNVERIFIED and the three small items the spec had to interpret. Section 17 is the card list.

**Amendments dated 14.10.2026:** the binding s178 decisions below supersede affected paragraphs; see [Amendments s178](#amendments-s178-14102026) and the inline pointers.

## Amendments s178 (14.10.2026)

Sources are the operator's comments on Yandex cards #65, #66, #67, #69, #71 and #76, Framework cards #1253(b) and #1277, plus the implementation evidence in `.orca/reports/Y69.md`, `.orca/reports/Y67.md`, `.orca/reports/Y71b.md` (Round Y71c), and `.orca/reports/T1253.md`. These decisions amend the original design as follows:

- **Insurance (D3, §5.3, O1):** restore the method option «Учитывать страховку» using the v1 `include_insurance` key. Yandex's measured insurance charge is `floor₂(0.0061 × assessed)` RUB, identical for prepaid and COD. The option controls only whether the buyer's quote includes that charge; when the shop absorbs it, quote without insurance, while every export still sends the assessed value and the carrier price includes insurance. D11 compares carrier price with carrier price, including insurance. This supersedes the original claim that the key no longer has an effect or that `FEATURE_INSURANCE` is not declared.
- **Method price options:** restore free-from (`free_cost`), free-shipping coupon (`coupon_free_shipping`), markup (`fee`, `fee_type`), rounding (`round_cost`, `round_cost_range`), and order amount limits (`min_order_cost`, `max_order_cost`) under their v1 keys. Zero/empty maximum or minimum delivery cost means no limit, as in v1. COD commission remains inside the delivery price; there is no separate line. Paid services (`fitting`, `particular_items_refuse`, `forbid_unboxing`) are after v2.
- **Settings:** use a «Тестовый режим» toggle backed by the built-in public test token, replacing the «Режим» selector while preserving the shop token. Use one «Допустимый рост цены» setting accepting a RUB amount or percent (default 10%; 300 RUB is an example). Keep sections comparable to CDEK. Put Yandex export fields in Yandex-titled cards inside the Framework export section (#1277).
- **Map and matching:** single map pins use the v1 office/terminal SVG assets by point type, with selected variants; cluster appearance is unchanged. Settlement matching requires a whole-word name, uses region aliases, and handles federal-city suburbs. The measured implementation and cases are recorded in Yandex #66/#67 and their reports.
- **Unknown export outcome:** retry is through the Framework's explicit «Выгрузить повторно (я проверил кабинет)» action from #1253(b), not a Yandex-owned retry action.

The corresponding original paragraphs carry `s178 amendment` pointers; where this section and an earlier description differ, this section governs.

## 1. Operator decisions (binding, #1179 comments of 12.10.2026)

The comments number their items twice (two «3», two «6»); the labels below disambiguate.

| ID | Decision | Where it lands |
|---|---|---|
| D1 | **Settlement detection stays on `geo_id`.** Bounding-box search does not replace it. Try string forms of `location/detect` in order; require a whole-word leading settlement name (ё = е; hyphen is part of the word), use region aliases to disambiguate, and handle federal-city suburbs; a village additionally needs its type or parent city plus a district check. Otherwise no method and a logged reason. **Never the smallest `geo_id`.** Cache about a week, like the CDEK adapter. The measured cases and implementation are in the s178 amendment above. | §4 |
| D2 | **5Post — variant A.** A per-method «Сети ПВЗ» setting (Yandex / 5Post), the filter goes into the request (`operator_ids`), and single map pins use office/terminal SVGs by point type (v1 assets); cluster appearance is unchanged. No «network» field in the framework. Price before a point is chosen = the cheapest point of the enabled networks. No Kaliningrad hint. **s178 amendment:** see above. | §6 |
| D3 | **Price —** real place dimensions (`dx/dy/dz`), the assessed value in export, and under COD the delivery cost. Quote insurance follows the method's `include_insurance` option; export always sends the assessed value. Oversized parcels are not shown for terminals up front. A courier is hidden for COD. Quote and export parameters intentionally differ when the shop absorbs insurance. **s178 amendment:** see above for the measured charge. | §5 |
| D4 | The plugin registers **no location provider**; one `Location_Adapter` serves DaData and CDEK records. **Map: the framework's built-in map only**; the Yandex widget is removed. The v1 Metrika counter (`100393788`) is removed. | §4, §6 |
| D5 | **Express (#1195) is not in the first version** («После v2»). When it is built, `referral_source` is mandatory there too (find the `claims/create` analogue). | §15 |
| D6a | **`info.referral_source = 'woodev'` in every request/offer body** (v1: `includes/class-order.php:179`, `prepare_order_data()`). Yandex pays the operator a partner commission by it. Pinned by a contract test. | §8 |
| D6b | **Warehouse.** A drop-off point («привезу сам») is chosen on the map from `available_for_dropoff` points. «С моего склада» is **one text field `station_id`** in every Yandex method (a check button is desirable, not required). The v1 warehouse table is **not** carried. There is no «my warehouses» API method; `type: warehouse` returns only Yandex sorting centres; the station code is issued by the Yandex manager. | §6.4, §12 |
| D7 | **Delivery date / interval** (pickup and courier; v1 calls `offers/info` for both). Per-method setting: «Не показывать» (default; nothing shown, no interval sent) / «Показывать», and then (nested, `show_if`): «Списком» — the buyer picks an interval, the chosen one is sent; «От – до» — show «N–M дней», no choice; «Крайнюю дату» — show the latest date and **explicitly send** the latest interval. Migration: a shop with intervals on keeps them on. Implemented in the plugin on the framework's checkout fields; generalise to the framework when express (#1195) or Pochta arrives. | §7 |
| D8 | **A method never disappears silently — all three:** (1) the reason (settlement → `geo_id` → number of points → reason) always goes to the WC log, not only in debug; (2) token check on save: `y0_` format, a probe request to the selected contour, plain texts («токен Яндекс GO», «боевой токен на тестовом контуре»); (3) an admin-only hint on the checkout (the buyer sees nothing) saying why the method is hidden. Item 3 is a **framework** mechanism, usable by CDEK. | §10 |
| D9 | **Coexistence with CDEK — rig acceptance before release** (both active, pickup + courier of each in one zone): (1) CDEK point → Yandex → CDEK: the order carries the right carrier's point and address; (2) re-checkout after a failed payment with a carrier switch clears the previous carrier's data (support case 16988); (3) both exported, each sees only its own on the orders page; (4) two framework versions inside the plugins: the newer one runs; (5) deactivating one leaves the other and its orders untouched. | §13 |
| D10 | **Migration v1 → v2 as in CDEK v2:** instances `yandex_delivery_other_day` are rewritten by tariff — `time_interval` → `yandex_delivery_courier`, `self_pickup` → `yandex_delivery_pickup` — with their settings (markup, free-from, min/max, rounding, insurance, intervals, the method's warehouse `station_id`). Instances of the stub `yandex_delivery_express` are deleted. The framework contract test on method names is redone: «old names are rewritten into new». Carried: `woocommerce_yandex_delivery_settings`, order meta `_yandex_delivery_*`, the licence, #1163 item 1. **Not** carried: the warehouse table, the widget, Metrika, «Отключить поле Область/район». **Version 2.0.0**; from this plugin on, ordinary version rules apply (first digit = incompatible update). | §11, §12 |
| D11 | **Export — one path for manual and automatic, through offers:** `offers/create` → choose an offer → `offers/confirm`. A **price-growth threshold**: if the carrier price is above the carrier price saved at quote time by more than the threshold, do not confirm; an order note «цена выросла с X до Y», the shop decides. Offers of one request differ in **dates**, not price; offer lives 15 minutes. Offer choice by interval mode: «Не показывать» / «От – до» — the earliest; «Списком» — the offer with the buyer's interval; «Крайняя дата» — the latest. **s178 amendment:** compare carrier price with carrier price, with insurance included on both sides. | §8 |
| D11a | **Stale interval at export.** The stored interval is not among fresh offers: «Не показывать» — earliest fresh; «От – до» — earliest, an order note if later than promised; «Крайняя дата» — latest fresh, a note if later than promised; «Списком» — **not exported automatically**, export error «выбранный покупателем интервал больше недоступен», the shop picks a new one from fresh offers by hand. Auto-export never silently changes the day the buyer chose. A buyer email «дата изменилась» is not in v2.0.0 (the framework event exists). | §8 |
| #1163 | Insurance migration notes: (1) saved instances with `include_insurance = delivery_payment` are not in the payment registry until re-saved — fixed by §12 step 5; (2) the declared value changes (v1: cart before discounts; framework: after discounts, ex tax) — deliberate, not a regression; (3) the order wizard does not know the payment method, so its quote ignores conditional insurance (known, same as markup #1144). | §5.3, §12 |

## 2. v1 (1.3.7) feature inventory

The following is the legacy inventory; s178 restores the listed method price rules and insurance behavior in v2 as specified in §0.

| # | Surface | v1 behaviour | Evidence |
|---:|---|---|---|
| 1 | Identity | Plugin id `yandex_delivery`, EDD 821, bootstrap `backwards_compatible 1.2.1`, blocks `false`, HPOS `true`. | v1:woocommerce-yandex-delivery.php:62,66-75,309 |
| 2 | Methods | `yandex_delivery_other_day` (tariff `time_interval` / `self_pickup`); `yandex_delivery_express` is a stub, not registered. | v1:includes/class-shipping-method-other-day.php:11,48-56; woocommerce-yandex-delivery.php:184-189; includes/class-shipping-method-express.php |
| 3 | Instance fields | `title`, `min_order_cost`, `max_order_cost`, `min_cost`, `max_cost`, `free_cost`, `coupon_free_shipping`, `fee`, `fee_type`, `round_cost`, `round_cost_range`, `include_insurance`, `show_commission`, `description_rate`, `shipping_class_id`; other-day adds `tariff`, `shipment_type`, `platform_station`, `warehouse_id`, `show_time_interval`. | v1:includes/abstract-shipping-method.php:25-146; class-shipping-method-other-day.php:46-91 |
| 4 | Global settings | `auth_token`, `environment`, `warehouses`, `default_weight/length/width/height`, `widget_type`, `map_type`, `point_types`, `widget_position`, `replace_address_fields`, `disable_country/state/postcode_field`, `pickup_disabled_fields`, `order_export_statuses`, `order_delivered_status`, `order_cancelled_status`, `order_status_update_interval` (180), `package_method`, `vat_rate`, DaData keys and switches, `disable_cod_payment`, `debug`. | v1:includes/class-integration.php:64-375 |

| 5 | Quote | Calculator call per method; ceil of `pricing_total` («123.4 RUB»); fee, free-from, min/max, rounding applied to the rate. | v1:class-shipping-method-other-day.php:95-263; abstract-shipping-method.php:202-307 |
| 6 | Geo | `location/detect` on a hand-built string; `geo_id` = first variant; cached with the API response cache. | v1:includes/functions.php:44-133; includes/class-checkout.php:467-483; api/class-api.php:47-57 |
| 7 | Points | `pickup-points/list` by `geo_id`, `type`, `payment_method`; response cache **forced off** («MySQL server has gone away»). | v1:api/class-api.php:66-84 |
| 8 | Checkout | Own map widget (standard or Yandex native), select list fallback; hidden fields `yandex_pickup_point`, `yandex_pickup_point_address`, `yandex_geo_id`, `yandex_time_interval`; COD gateway removed for courier and for points that do not accept it. | v1:includes/class-checkout.php:210-253,562-583,842-914 |
| 9 | Intervals | `offers/info` list at checkout when `show_time_interval` is on; chosen `from::to` stored in order meta. | v1:includes/functions.php:234-308; class-checkout.php:688-693 |
| 10 | Export | Manual: `offers/create` → admin picks → `offers/confirm`. Auto (status trigger, AS single `wc_yandex_export_order`): `request/create`. `referral_source = woodev`. | v1:includes/class-order.php:169-355; woocommerce-yandex-delivery.php:165-171,291-303; includes/class-ajax.php |
| 11 | Status | AS recurring `wc_yandex_orders_update` (180 min) → `request/info`; AS single `wc_yandex_update_order`; delivered/cancelled → WC status; `sharing_url` email. | v1:includes/class-integration.php:42-62; functions.php:508-598; class-order.php:357-396; includes/class-sharing-url-email.php |
| 12 | Documents | `request/generate-labels`, `request/get-handover-act` → PDF stream. | v1:includes/class-order.php:446-476; functions.php:710 |
| 13 | Orders page | `wc-yandex-orders` under WooCommerce, per-page option `wc_yandex_orders_per_page`, status counts, metabox. | v1:includes/admin/class-admin.php:39-129 |
| 14 | Warehouses | Custom table `{prefix}wc_yandex_delivery_warehouses`, WC data store, REST `yandex-delivery/warehouses`. | v1:includes/class-lifecycle.php:54-73; includes/rest-api/class-warehouses-rest-api.php |
| 15 | Order meta | `_yandex_delivery_request_id`, `_destination_station_id`, `_destination_station_address`, `_destination_interval_from`, `_destination_interval_to`, `_sharing_url`, `_state_status` (`NEW` written at checkout). | v1:includes/class-order.php:40-137; class-checkout.php:671-698 |
| 16 | Extras | Competitor notice for the Yandex GO plugin; milestones; Metrika counter (removed, D4). | v1:includes/class-plugin-competitor-notices.php; woocommerce-yandex-delivery.php:143-163 |

**s178 settings layout amendment (Yandex #69):** replace the «Режим» selector with a «Тестовый режим» toggle using the built-in public test token while retaining the shop token; replace the two price-growth controls with one «Допустимый рост цены» input (RUB or percent, default `10%`, e.g. `300` RUB). Group settings like CDEK. Yandex export options belong in titled Yandex cards inside the framework export section (#1277): «Параметры отправления» for price growth and status interval, «Пункты выдачи» for point types. Plugin settings use «Подключение» (token, toggle, connection test); do not create a duplicate export section.

## 3. Capability → framework seam map

Gap = the framework has no mechanism and the plugin cannot reasonably build it alone. Everything marked «No» is plugin work on an existing seam.

| Capability | Framework seam (file:line) | Plugin work | GAP? |
|---|---|---|---|
| Register methods | `Shipping_Plugin::register_shipping_methods()` is final (woodev/shipping-method/class-shipping-plugin.php:619); bases `Shipping_Method_Courier` / `Shipping_Method_Pickup` (class-shipping-method-courier.php:17; class-shipping-method-pickup.php:27). | `Yandex_Courier_Method` (`yandex_delivery_courier`), `Yandex_Pickup_Method` (`yandex_delivery_pickup`). | No |
| Quote | `calculate_rate()` is a final template (class-shipping-method.php:922), the plugin implements `rate_package()` (:988); API failure hides the method and fires `woodev_shipping_method_rate_calculation_failed` (:1504). | Calculator call, price parity, cheapest-network rule (§5, §6.2). | No |
| Rate cache (default 600 s) | `FEATURE_RATE_CACHE` opt-in (:76); context is `get_rate_cache_context()` (:1989); TTL `Shipping_Rate_Cache::DEFAULT_TTL = 600` (class-shipping-rate-cache.php:67). | Opt in; add token fingerprint, contour, source station, networks, interval mode, point types (§5.5). | No |
| Declared value / insurance | `Shipping_Helper::get_package_declared_value()` (class-shipping-helper.php:31); `FEATURE_INSURANCE` + `include_insurance` key kept for Yandex (class-shipping-method.php:59-64); `FEATURE_DECLARED_VALUE` (:68). | Quote insurance follows `include_insurance`; export always sends assessed value (§5.3; s178 amendment). | No |
| **Price depends on the payment method** | `Fee_Payments` plumbing (a recalculation on payment change) is on **only** when a saved instance has a non-empty `fee_payments` or `include_insurance = delivery_payment` (woodev/shipping-method/class-fee-payments.php:61,386-412, conditions at :395-396). | Yandex's price differs by payment method **always** (COD commission, §5.4), so the plumbing must be on for every Yandex instance. | **Yes — G2** (declaration that a method's price depends on payment) |
| Packing | `FEATURE_BOX_PACKING` (:53); algorithms `separately` / `single` / `boxes` (woodev/box-packer/class-packer-dispatcher.php:37,51,61); default dimensions settings. | Translate packer parcels to `places[]` (§5.2). v1 `sku_boxes` ≈ `separately`, `common_box` ≈ `single`. | No |
| Location adapter | `Location_Adapter::resolve()` (woodev/shipping-method/location/interface-location-adapter.php:38,90); `Shipping_Plugin::needs_location_provider()` (class-shipping-plugin.php:2573) and `get_location_adapter()` (:2603); lazy, session-cached `Location_Resolution_Cache::resolve_for()` (location/class-location-resolution-cache.php:133; key `woodev_location_resolution_cache` :72; TTL filter :97). | `Yandex_Location_Adapter` (§4). The session cache covers one session; the plugin adds a transient (HIT 7 d / MISS 1 h). | No |
| Pickup point source | `Point_Source::STRATEGY_BULK` (pickup/interface-point-source.php:27); `Abstract_Bulk_Point_Source` supplies only `fetch_details()` as a scan over the plugin's own `raw_bulk_points()` (pickup/abstract-bulk-point-source.php:71,127). **The framework stores nothing** — storage of a large list is the source's job. | `Yandex_Pickup_Point_Source`; chunked storage (§6.3). | No |
| Point model | `Pickup_Point::from_array()` (pickup/class-pickup-point.php:226); per-point `icons` (:363), `accepts_cod` (:653), type `{code,label}`. Map icon cascade: point icon → type icon → default; Yandex mixes operators under one type code (assets/js/frontend/map-provider-yandex.js:117-126). | Map `operator_id` → per-point icon; `type` → `pickup_point` / `terminal`. | No |
| Point constraints | `Constraint_Checker::check()` (pickup/class-constraint-checker.php:128). | COD acceptance per point; parcel envelope (§6.5). | No |
| Map | Built-in map provider registry (class-shipping-plugin.php, `get_map_provider_registry`), `Yandex_Map_Provider` (map/class-yandex-map-provider.php:49). | Use it; delete the v1 widget (D4). | No |
| Checkout fields | `Checkout_Handler` (class-shipping-plugin.php:2675), `Pickup_Handler` (:2692, pickup/class-pickup-handler.php:99), presets `Pickup_Field`, `Dependent_Select`; field options via `Field_Source_Controller` (rest-api/class-field-source-controller.php:48). | Wiring as in `cdek:includes/class-plugin.php:1096-1186`; interval field (§7). | No |
| COD / gateway coordination | Filter `woodev_shipping_available_payment_gateways` (checkout/class-checkout-handler.php:4043,4081). | Hide COD for non-accepting points; hide the courier for COD (§5.6). | No |
| Instance field visibility | `Instance_Field_Conditions` reads a field's `show_if` (class-instance-field-conditions.php:34,55). | Nested interval-mode field (D7). | No |
| Quote display attributes | `Shipping_Rate::get_post_add_rate_attributes()` → `delivery_time`, `description` (class-shipping-rate.php:349; applied in class-shipping-method.php near :880-900). | «N–M дней» (D7). | No |
| Export | `Abstract_Shipment_Handler::export()` (order/abstract-shipment-handler.php:218) calls **one** `api->create_order()` (:296), idempotent, locked, retries only when `supports_reconcile()` (:509); the plugin implements `extract_carrier_order_id()` (:1014). | The offers→confirm sequence lives inside `Yandex_Api_Client::create_order()` (§8). | No |
| Auto-export | `Order_Automation::SETTING_AUTO_EXPORT` (order/class-order-automation.php:48); default OFF. | Map v1 `order_export_statuses`; default OFF for new installs. | No |
| Cancel | `Abstract_Shipment_Handler::cancel()` (:761). | `request/cancel`. | No |
| Tracking / statuses | `Abstract_Tracking_Handler::map_events()` (order/abstract-tracking-handler.php:202); `Delivery_Status` canonical states (order/class-delivery-status.php:40-75); `Delivery_Sync_Status` records freshness only, schedules nothing (order/class-delivery-sync-status.php:45). | Poller (Action Scheduler), `request/info` + `request/history` (§9). Yandex has **no webhook**; `get_webhook_handler()` (class-shipping-plugin.php:2722) stays `null`. | No |
| Delivery date event | `Shipment_Facts::with_delivery_date()` (order/class-shipment-facts.php:176); event `woodev_shipping_delivery_date_changed` (order/class-shipment-facts-events.php:665). | Feed the date from `request/info` (§9). | No |
| Documents | `Document_Source` (order/interface-document-source.php:14) and `Bulk_Document_Source` (:22 of interface-bulk-document-source.php). | Label + handover act (§9). | No |
| Orders page | `Orders_Provider::create()` (admin/orders/class-orders-provider.php:305); the plugin supplies the page slug (admin/class-shipping-admin.php:150). | Register provider; slug `wc-yandex-orders`; marker `_yandex_delivery_state_status` (§9.4). | No |
| **Tracking link per order** | The link is `template` + `{tracking}` only (admin/orders/class-order-row-builder.php:754; email/class-delivery-status-email.php:185-188). Yandex gives a full per-request `sharing_url` (v1 meta `_yandex_delivery_sharing_url`). | Without a seam the buyer email and the orders page cannot show the link. | **Yes — G3** |
| **Admin-only checkout hint** | Nothing exists. Searched `woodev/shipping-method` for `current_user_can`: only `Pickup_Handler::render_missing_selection_scope_notice` (pickup/class-pickup-handler.php:2283), an `admin_notices` notice about a missing scope, not a checkout hint. CDEK uses `current_user_can` only to widen its settings reads (`cdek:includes/shipping/class-cdek-shipping-rate-service.php:890-893`). | D8 item 3. | **Yes — G1** |
| Packages with no `Pickup_Point` cells | `Pickup_Point::cells` exists (`class-pickup-point.php`, `sanitize_cells`). | Whether Yandex points expose cell sizes is **UNVERIFIED**; the 5Post envelope is handled by §5.7. | No |
| Contract tests for method names | `tests/unit/Contract/YandexShippingMethodIdContractTest.php` pins the **old** ids `yandex_delivery_express` / `yandex_delivery_other_day`; `YandexWarehouseTableContractTest.php` pins the warehouse table; recipes under `tests/unit/Contract/recipes/yandex-*.recipe.json`. | D10: «old names are rewritten into new»; the warehouse test is retired. | **Yes — G4** (framework tests) |
| Competitor notices | `Carrier_Catalogue` entry `yandex` (woodev/competitor/class-carrier-catalogue.php:45) already lists `yandex-go-delivery/yandex-go-delivery.php`. | Reuse. | No |

### Gaps (candidate framework cards)

- **G1** admin-only checkout hint (D8.3) — §10.2.
- **G2** a method declares «my price depends on the payment method» so the payment-change recalculation plumbing is on for it.
- **G3** a per-order tracking URL (meta key) for the orders page and status emails.
- **G4** framework contract tests redone for the new Yandex ids (and the warehouse test retired).

Not gaps: bulk point storage (plugin's job, framework offers none by design), polling (carrier responsibility), the delivery-interval checkout field (built in the plugin, generalised later per D7).

## 4. Settlement → `geo_id` (D1, D4)

### 4.1 Why the adapter is plugin code

`Location_Adapter::resolve()` receives a provider-neutral `Location_Record` and returns the carrier's own identity or `null` («the carrier does not serve it»); a throw is reserved for transient failures and is never cached (interface-location-adapter.php:90). The framework caches the outcome in the session only (`woodev_location_resolution_cache`, location/class-location-resolution-cache.php:72), so the first resolution per session always reaches the adapter — it must carry its own transient, as CDEK's does (`cdek:includes/location/class-cdek-location-adapter.php:74-88,160-168`: prefix, HIT 604800 s, MISS 3600 s, key from scope, country, FIAS, folded name, folded region, coordinates rounded to 2 decimals).

### 4.2 Input

The adapter receives records from either provider: DaData records carry FIAS ids; CDEK's own provider records carry **none** (the CDEK API has `fias_guid`, the provider does not map it). `location/detect` accepts no FIAS id. Matching is therefore by **name, region, settlement type, district and parent city**, read through `Location_Record` accessors (`settlement()`, `region()`, `district()`, `ancestors()`) and, for the DaData type token, the raw payload; the exact raw keys a CDEK-provider record exposes are **UNVERIFIED** (the adapter's unit tests must use both record shapes).

### 4.3 Rule (R5 of the measurement, plus the two village checks)

1. Build the candidate strings, in this order: `Россия, <Region full>, <Settlement>` (form A); for a village `Россия, <Region full>, <District>, <type> <Settlement>` (form F); `<Settlement>` alone last (form D). The measurement shows forms A, A0, B, C, E, F, G, G2, H return the same set; only D differs (found 20/28, versus 24/28 for the region forms, and 1/9 small places), so more than three calls are never needed. Cities need A (and D as a fallback); villages A then F then D. Maximum three `location/detect` calls per cache miss.
2. For each form, scan **all** returned variants (not only the first) and require a whole-word leading settlement name — after folding ё→е and case; hyphen remains part of the word, and the name ends at a comma or end of address. This rejects prefix collisions such as Пушкин/Пушкино and Королёв/Королев. Scan all variants, not just the first; Volzhsk's correct id is at position 3 behind Тольятти.
3. Region text is optional when a bare city answer is needed, but when present use canonical region aliases (including republic and autonomous-region names) to disambiguate candidates. Federal-city suburbs are matched using the federal city and, when supplied, its district; examples include Pushkin, Peterhof, Kolpino and Sestroretsk in Saint Petersburg. A bare city answer remains a lower-confidence fallback (Yandex's Samara, geo 51, has no region text). These rules and measured cases are the s178 amendment from Yandex #67 / PR #72.
4. A village-level place (DaData settlement, not city) is accepted only with its own type («деревня», «посёлок», «станица», with «поселок» = «посёлок») **or** its parent city, **and** a district (area) match against the variant's address. This is what rules out the same-name village in another district (Дрожжино: the first leading variant is the Угранский one, the right one is Вяземский).
5. No variant passes → return `null` and log the reason; two different variants pass and the district does not separate them → `null`, reason `ambiguous`. **Never the smallest `geo_id`.** The nearest-point tie-break of the CDEK adapter (`single()`, `cdek:includes/location/class-cdek-location-adapter.php:336`) does not carry over: the measurement material shows no coordinates on `location/detect` variants (UNVERIFIED), and the `geo_id` point list is itself not a reliable position witness (12 of 17 points of the Drozhzhino-Smolensk list lie outside a ±0.2° box, median 23.5 km).
6. Cache: HIT 7 days, MISS 1 hour, key `woodev_yandex_geo_<md5(contour|country|folded name|folded region|folded district|type)>`; a transport error or 5xx is thrown and **not** cached.

### 4.4 Measured basis

| Rule | Agrees with the measured `geo_id` (28 places) | Cities (19) | Small places (9) | Picks a `geo_id` where none was found |
|---|---:|---:|---:|---:|
| v1: variant[0] of the old string | 21 | 18 | 3 | 4 |
| city only, smallest `geo_id` | 20 | 19 | 1 | 4 |
| leading + region required | 21 | 14 | 7 | 1 |
| **R5: first variant that leads with the place, region optional** | **26** | **19** | **7** | **1** |

(docs-internal/research/2026-10-12-yandex-geo-measurement/README.md, «Matching-rule comparison»). R5's two misses are Drozhzhino-Smolensk and Pushkino-village-Domodedovo. The district check MAY separate the two Drozhzhino villages (UNVERIFIED, README «UNVERIFIED»). Pushkino-village is NOT recoverable by any check: no measured form returned it in the top four (README conclusion 7), so the rule correctly yields `null` and the method is hidden with a logged reason. No coverage figure beyond the measured 26/28 is claimed; re-running the matcher needs fresh raw data (the 85 MB raw archive is not committed). The label itself («correct geo_id») is the measurement's own, not Yandex's (UNVERIFIED against Yandex). Four settlements return no variant under any of the ten forms, so a village can legitimately resolve to `null`.

### 4.5 v1's failures

The brief's Sochi→Chelyabinsk and «Самара не найдена» did **not** reproduce in the measurement (Sochi 239 and Samara 51 are at position 0 in all ten forms). The likely cause is v1's own string building (`wc_yandex_delivery_clear_address_part` patterns stripping the first letter, «амара»; v1:includes/functions.php:365-381), which the framework locality record makes moot: the adapter receives structured name/region/district, never a hand-assembled string.

### 4.6 What the adapter does not do

It does not decide whether the carrier serves the place for a given parcel or payment method (that is the point list and the calculator) and does not read the point list to verify itself. A resolved `geo_id` with zero points is **not** a failure of the adapter; it is reported by the source (§10.1).

## 5. Price: one parcel, one body, two calls (D3)

### 5.1 What was measured

Over 170 cases (12.10.2026): the checkout (calculator) price is higher than the cabinet (offer) price in 158 cases, lower in 11, equal in 1; range −51.86 % … +10.49 %. Three terms explain it:

1. The calculator charges a **volumetric** step (+28 RUB per whole kg once L×W×H/5000 exceeds the actual weight — divisor and step inferred, UNVERIFIED against Yandex's tariff text). v1's offer sent only `predefined_volume`, which the offer ignores, so the cabinet charged less than the buyer paid (up to 239 RUB for 5post medium).
2. The offer **always** carried the assessed value (about 18 RUB); the calculator only when `include_insurance` was on in the original measurement. The s178 decision restores the option: the buyer quote can omit insurance when the shop absorbs it, while export continues to send the assessed value (§5.3).
3. Under COD the offer adds about 3 % of `billing_info.delivery_cost` (8 to 41 RUB); the calculator never receives it (sending it does not move the calculator).

With the box forced to 1 cm and insurance on, calculator and offer match within 1 RUB in all 170 cases (prepaid residual −1…0; COD with the delivery-cost term removed −1). Follow-up (real dimensions in the offer): `dx/dy/dz` on the **places** make the offer charge the same volumetric step (Moscow courier medium 364 → 420 vs calculator 421; large 701 → 841 vs 842; Samara medium 577 → 689 vs 690; 5post medium 262 → 347 vs 348); dimensions on items alone change nothing; `predefined_volume` = real volume equals the v1 shape.

### 5.2 One builder

A single `Yandex_Parcel_Builder` turns the framework's packed result (`Woodev_Packer_Result`; box-packing declared, class-shipping-method.php:53) into the `places[]` array **once**, and `offers/info`, `pricing-calculator` and `offers/create` all consume that array. Parity then holds by construction, and a contract test compares the places arrays of the three bodies. Rules:

- Units: weight in grams (integer; `total_weight` must be an integer, the offer endpoint reads grams — confirmed by the 1500-kg refusal when multiplied by 1000), dimensions in cm, money in kopecks. v1 mapping is kept: `dx` = length, `dy` = height, `dz` = width (v1:class-shipping-method-other-day.php:128-135).
- Each place carries `physical_dims {weight_gross, dx, dy, dz}`; `predefined_volume` is also sent (harmless, v1 compatibility).
- Algorithm mapping: v1 `sku_boxes` → `separately`, `common_box` → `single`; `boxes` is new (framework).
- Place barcode: `separately` — product global id, else SKU, else product id (the v1 rule); `single` — the order number; `boxes` — `<order number>-<n>` (the spec's proposal, §16 O3).
- Missing product weight/size → the migrated default dimensions (v1 500 g, 10×10×10 cm; migrated in §12 step 4).

### 5.3 Assessed value and the v1 insurance setting

The assessed value is the input to insurance calculation in a quote when the option includes insurance, and is sent in every offer/export (D3). The value is `Shipping_Helper::get_package_declared_value()` — after discounts, excluding tax and shipping (class-shipping-helper.php:31); the export uses the order's line totals the same way (`Shipping_Method::resolve_insurance_for_order`, class-shipping-method.php near :2160). This is the declared-value change of #1163 item 2; the quote exception is the s178 amendment below.

The method option «Учитывать страховку» reads the v1 `include_insurance` key and declares `FEATURE_INSURANCE`. The measured charge is `floor₂(0.0061 × assessed)` RUB (Yandex #71 Round Y71c): linear, truncated to kopecks, identical for prepaid and COD. «Нет» omits `total_assessed_price` from the quote calculator request, so the buyer sees a lower price and the shop absorbs insurance; «Всегда» includes it, and `delivery_payment` includes it only for COD. Every export still sends the assessed value in `items[].billing_details.assessed_unit_price`, regardless of this setting, so Yandex still insures and charges the parcel. The carrier price stored for D11 includes insurance even when the shop absorbs it; D11 compares it with the offer's carrier price on the same basis. This replaces the original O1 interpretation: `include_insurance` is read, not merely copied. The assessed-value basis remains after discounts, excluding tax and shipping; see #1163 item 2. **s178 amendment:** see §0.

### 5.4 Payment method and COD

Price depends on the payment method **by nature** (COD commission about 91.5 RUB at a 3000 RUB `client_price`; removing `client_price` from the calculator gives the prepaid price). The calculator body therefore carries `payment_method` (`already_paid` / `card_on_receipt`) and, for COD, `client_price` (goods total × 100). The framework recalculates a rate on payment change only when the plumbing is on (`Fee_Payments`, class-fee-payments.php:386-412) — **G2** adds a declaration for it; until G2 lands, the plugin must write the registry by the same migration path as §12 step 5.

The COD delivery-cost term (3 %): the calculator cannot take it, so a COD quote would be below the real offer by 8–41 RUB. D3 sends `billing_info.delivery_cost`; the calculator receives it (harmless, ignored), and the plugin adds the measured term to the COD quote. **Measured by R2 (12.10.2026, 24 COD points, residual 0.00 RUB):** the offer's COD commission is `floor₂(0.0305 × (G + D))`, where `G` is goods total; the calculator already carries the `G` term, so add `floor₂(0.0305 × D)` where `D` is the quoted delivery amount. The commission stays inside the delivery price; there is no separate line (Yandex #71 operator decision). Whether merchant markup belongs in `D` is the plugin's choice: the API charges 3.05 % of whatever is sent. **s178 amendment:** see §0.

### 5.5 Rate-cache context

`FEATURE_RATE_CACHE` is opted in; the context adds to the framework default (class-shipping-method.php:1989): a token fingerprint (never the token), contour, source station (method setting), enabled networks, point types, interval mode, the parcel envelope, the COD flag and the assessed value. A change of token, contour or network setting must produce a different key, otherwise the old tariff is served (the framework docblock's own warning).

### 5.6 Hide rules (no method rather than a wrong price)

| Case | Source | Behaviour |
|---|---|---|
| COD + courier | `pricing-calculator` returns 400 «Payment method 'card' is not allowed for courier delivery» for every courier COD request (36 of 36) | Courier hidden for COD; reason logged. |
| COD + point that does not accept it | per-point `payment_methods` (`cash_on_receipt`, `card_on_receipt`) → `accepts_cod` | Point not selectable under COD; the gateway filter hides COD when the chosen point refuses it (class-checkout-handler.php:4081). Moscow 5post and all Kaliningrad points refuse COD. |
| Oversized for terminal / 5post | with real `dx/dy/dz` the offer and the calculator both refuse 5post large (60×40×40 cm, 15 kg): «Dimensions should not exceed limit»; the v1 shape was accepted (469 RUB) — v1 priced such parcels wrongly | Hide terminals and the 5post network up front (D3), see §5.7. |
| Courier address not geocodable | «can't restore coordinates from address» in Kaliningrad, Volzhsk, Drozhzhino-MO (4 address variants each) | Courier hidden with a logged reason; the cause (no service vs wrong addresses) is unresolved — UNVERIFIED. |

### 5.7 Envelope for terminals / 5post

The refusal threshold is **not known** — only two points are measured (60×40×40 cm / 15 kg refused, 40×30×20 cm / 3 kg accepted). Design: a measured static pre-filter (research card R1 bisects the limit with calculator calls only) plus a data-driven fallback — a 400 «Dimensions should not exceed limit» on a candidate network removes that network for this parcel, is cached by the rate cache and is logged. **Measured by R1 (12.10.2026, 56 calculator calls, inclusive limits):** market_l4g `terminal` — 20 kg, longest side 40 cm, sum of sides 118 cm; `5post` — 15 kg, longest side 70 cm, sum of sides 136 cm; a market_l4g `pickup_point` accepts 60×40×40 cm / 15 kg. Refusal texts are `dimensions_limit` or `no_delivery_options`, and the text does not tell which limit was hit, so the fallback treats both as «network unavailable for this parcel». Moscow has no terminals; one point per network was measured (research/2026-10-12-yandex-terminal-limits/README.md).

## 6. Pickup points (s178 map-pin amendment)

### 6.1 API call

`pickup-points/list`, filters `geo_id`, `type` (`pickup_point` | `terminal`), `operator_ids` (`market_l4g`, `5post`), `payment_method(s)`, `available_for_dropoff`, lat/lon ranges. The measured facts: a bounding box **does not** replace `geo_id` (Moscow 2276 points by `geo_id`, 258 inside ±0.05° of the centre, median 13.8 km for the points outside it; in villages the box collects the neighbours' points, name share ≈ 0); the response has no pagination field (UNVERIFIED that no list is truncated; the largest is Moscow 2276); some places have no points under any method (Yakutsk geo 74: 0 points).

### 6.2 Networks (D2)

Network = `operator_id`. Shares by `geo_id` list: Moscow 499 of 2276 = 22 % 5post (1777 `market_l4g`); Samara 74 of 311; Sochi 75 of 195; Irkutsk 23 of 70; **Kaliningrad 79 of 79 = 100 % 5post**. The per-method setting «Сети ПВЗ» (both on by default; stored as a list) puts `operator_ids` into the request. Disabling 5Post in Kaliningrad leaves no points; diagnostics (§10) name the cause when the list is empty because of the filter. The Kaliningrad hint is removed without replacement (s178 amendment, Yandex #71).

Price before a point is chosen = the cheapest quote over the enabled networks. The quote is made once per enabled network against a representative point (the first point of that network that accepts the payment method — v1's rule, v1:class-shipping-method-other-day.php:216-221); the minimum is shown. Whether the price varies **within** a network inside one `geo_id` was not measured (UNVERIFIED); after a point is chosen the rate is recalculated for that point (the default cache context already includes the selected point, class-shipping-method.php:1989).

On the map, single pins use the v1 `yandex-delivery-map-pin-{office,terminal}{,-active}.svg` assets by point type (`pickup_point` → office; `terminal` → terminal); the selected point uses the active SVG. Network icons «A»/«5» are not used, and cluster appearance is unchanged (s178 amendment, Yandex #66). The v1 global `point_types` remains the plugin's setting «Типы пунктов».

### 6.3 Storing a large list

The framework does not store bulk lists (§3). v1 forced the cache off because a 2000+ point response in one transient produced «MySQL server has gone away» (v1:api/class-api.php:71). Two numbers frame the risk: the rig's earlier live-Yandex fixture cached 812 Moscow points in one transient for a day (tests/_fixtures/woodev-test-shipping-method/class-test-live-yandex-point-source.php:211,363), and Moscow is now 2276 points; a memcached item is 1 MB and a default `max_allowed_packet` can be small. The size of the mapped Moscow payload was **not measured** (UNVERIFIED).

Design (copied from the CDEK location cache fix #81, `cdek:includes/location/class-cdek-location-provider.php:85,653-712`): map each raw point to a **slim** record (id, coordinates, address, type, operator, payment methods, schedule — nothing the map does not use), store the list as chunks of 500 behind an index transient (`generation`, `count`, `signature`) with a per-request in-memory copy; a missing middle chunk or a failed write makes the list **absent**, never partial; a paging or truncation sign makes the call throw instead of caching an incomplete list. TTL: lists change slowly — 1 hour for a warm list, with the index carrying the `geo_id`, types, networks and contour in its key (CDEK's point cache is 600 s, `cdek:includes/pickup/class-cdek-pickup-point-source.php:51`). `fetch_details()` scans the cached chunks of the locality the customer is in (the bulk base class's contract, pickup/abstract-bulk-point-source.php:94-103).

**Completeness is not guaranteed by chunking.** The endpoint exposes no pagination or total-count field, so a silently capped response would be stored faithfully and incomplete. Y5 acceptance therefore includes a probe: repeated uncached fetches of a large locality (Moscow) compare the count and a stable tail sample of ids; if the API offers no completeness signal, the diagnostics log says «list completeness unknown» rather than implying a complete list.

### 6.4 Drop-off point and the sender station (D6b)

Two sender modes per method instance:

- **«Привезу сам»** (drop-off): a point chosen from `pickup-points/list` with `available_for_dropoff = true` for a city picked in the settings. The decision says «on the map». The framework has no admin map picker for an instance form; the shared map provider is checkout-oriented, so embedding it in the zone-method modal is **UNVERIFIED**. Fallback in the same card: a searchable select of dropoff addresses (the CDEK sender-office search pattern, `cdek:includes/shipping/class-cdek-sender-office-search.php`). The stored value is `{id, address, geo_id}` (the v1 `platform_station` shape).
- **«С моего склада»**: one text field `station_id` per method. A check button that calls the API with that station as `source` is desirable, not required (the card marks it optional). In the test contour the public test station is used regardless (v1 constant `WC_YANDEX_SHIPPING_TEST_PLATFORM_STATION_ID`, v1:woocommerce-yandex-delivery.php:23).

### 6.5 Mapping and constraints

`Yandex_Pickup_Point_Mapper::map()` → `Pickup_Point::from_array()`: id, name, address, coordinates, `type {code: pickup_point|terminal, label}`, `payment_methods` strings (`cash_on_receipt`, `card_on_receipt`) and `accepts_cod` derived from them, schedule (the live-Yandex fixture already maps the structured schedule: class-test-live-yandex-point-source.php:634), per-point icon by operator. `max_weight`: the Yandex record carries **no** weight-limit field (the fixture's own survey says so), so the checker only enforces COD and the §5.7 envelope. A stale selection (a network or type disabled after the buyer chose; a COD switch) is refused by a guard in the manner of `Cdek_Pickup_Type_Guard` (`cdek:includes/class-plugin.php:1131`).

## 7. Delivery date and interval (D7)

### 7.1 Setting

Per method instance (courier and pickup both): `delivery_dates` = `none` (default) / `show`; and when `show` (a nested field with `show_if`, class-instance-field-conditions.php:55): `delivery_dates_mode` = `list` / `range` / `latest`.

| Mode | Buyer sees | Sent | Stored on the order |
|---|---|---|---|
| none | nothing | no interval | nothing |
| show + `list` | a select of intervals from `offers/info` | the chosen interval | `_yandex_delivery_destination_interval_from/to` (v1 keys) |
| show + `range` | «N–M дней» in the rate (`delivery_time`) | no interval | nothing |
| show + `latest` | the latest date | the latest interval, explicitly | the latest interval's `from/to` |

### 7.2 Implementation

`offers/info` returns only intervals (no price); in the measurement it returned 5 intervals for Moscow/Samara pickup, 11 for Samara courier, in ascending order, the same set as `offers/create` offers on all four routes (docs-internal/research/2026-10-12-yandex-export-path/README.md, steps 1–2). The «N–M дней» is computed from the earliest and the latest interval, and rides the rate through `get_post_add_rate_attributes()` (class-shipping-rate.php:349) so it is cached with the rate. The list is a checkout field (a `Field` of type `select`, options through the field-source REST route, rest-api/class-field-source-controller.php:48). The route hands the source a **sanitized** context (id, search text, parent), not the cart or the chosen method; whether the source can read the WC session in a guest REST request on both classic and block checkout is **UNVERIFIED** and is the first thing card Y8 checks (a spike before any UI). Fallback if it cannot: carry the intervals through the rate meta and render them from the rate in both checkouts.

A buyer who has chosen an interval and then changes the address or the method gets a fresh list; a stale selection is dropped (the §13 scenario 2 rule applies to it too). The checkout error texts of v1 («Вы не выбрали желаемую дату», «Дата … не может быть меньше текущей», class-checkout.php:648-654) are kept in meaning.

Migration: v1 `show_time_interval = yes` → `delivery_dates = show`, `delivery_dates_mode = list` (v1 showed a list and sent the chosen interval); `no` → `none`.

Time zone: `offers/info` returns UTC bounds (`interval_utc`); the display time zone is the delivery point's, which is not documented in the measurement — UNVERIFIED (v1 used `wc_yandex_delivery_get_actual_datetime`, v1:includes/functions.php:754). **This is part of Y8's blocking spike**, not a final-rig assumption: establish whether the bounds are UTC instants and which zone to show for a courier address and a pickup point (the point carries `time_zone`, pickup/class-pickup-schedule.php:208), define the date-boundary rule, and use ONE parser/formatter for checkout display, order storage and export matching (a mismatch makes a valid interval look stale at export). If the destination zone cannot be established, the fallback is the store time zone, stated in the UI and tested.

## 8. Export through offers (D6a, D11, D11a)

### 8.1 The sequence

The framework calls `api->create_order( $order )` once (order/abstract-shipment-handler.php:296). `Yandex_Api_Client::create_order()` performs the whole sequence inside it:

1. Build the body from the order and the method instance (§5.2 places, §5.3 assessed value, COD `billing_info {payment_method, delivery_cost}`, destination, `last_mile_policy`, `info {operator_request_id = order number, referral_source = 'woodev'}`, recipient). **No `destination.interval_utc`** (v1's `create_offers()` strips it, v1:includes/class-order.php:345-347; the offer step does not take it).
2. `offers/create` → a list of offers; all share one price and differ in dates (measured: 4 of 4 routes, 11 offers one price in the 11-offer route); each lives 15 minutes (`expires_at` − `pickup_interval.min` = 15 min).
3. Choose the offer by the interval mode (D11 table; D11a for a stale interval).
4. Price-growth check on the **chosen offer's** price (§8.3).
5. `offers/confirm` on the chosen offer → `request_id` → `extract_carrier_order_id()` (:1014).

Manual and automatic export call the same `Abstract_Shipment_Handler::export()` (:218); v1's admin chooser modal is not rebuilt. `request/create` is not used — whether it honours `destination.interval_utc` is **not verified** (the live test account refuses every request creation: HTTP 404 `invalid_payment_method`, «Ваш корпоративный счёт больше не активен»; 0 requests were created, `ledger.json` empty, nothing to cancel).

### 8.2 `referral_source`

`info.referral_source = 'woodev'` sits in the one function that builds the request body and is therefore present in the `offers/create` body (and in any `request/*` body a later card might add). A contract test (unit, no network) asserts it in the built body for pickup and courier, prepaid and COD, and fails if the value changes or the key moves out of `info`. D5 repeats the rule for express.

### 8.3 Price-growth threshold

Compare the chosen offer's `pricing_total` with the **carrier** price saved at quote time. The quote stores it in the shipping-line meta next to the framework's quoted-services meta (pattern: class-shipping-method.php `META_QUOTED_SERVICES` for services, #1145) — the carrier price without merchant markup, including COD commission and insurance, whether insurance is shown to the buyer or absorbed by the shop. If the growth exceeds the threshold, do **not** confirm; write the order note «цена выросла с X до Y», return a failed `Action_Result` (not «unknown», not retried), leave the order exportable by hand. The threshold is one **shop setting** (s174, s178/Yandex #69): fixed RUB or percent, default **10 %**; for example `300` means RUB and `10%` means percent. Compare carrier price with carrier price, including insurance on both sides (s178 amendment, Yandex #71 Round Y71c). Comparing against the buyer's *shown* price instead would let a markup absorb real growth silently; the spec compares the carrier component and says so.

### 8.4 Stale interval (D11a)

Fresh offers are the ones just created. If the stored interval is not among them: `none` — earliest; `range` — earliest, note if later than the promised upper bound; `latest` — latest, note if later than the promised date; `list` — **refuse automatically**: the export fails with «выбранный покупателем интервал больше недоступен», no confirm. The shop then runs the extra order action «Выгрузить с интервалом» (`woodev_shipping_order_actions`, with `fields` of type `date` and `time_range`, admin/orders/class-order-action-fields.php); the plugin matches the typed date and window to fresh offers. Whether the action dialog can show the *current* list of fresh intervals (its `options` are declared statically) is UNVERIFIED; the date + time-range pair is the fallback that needs no dynamic options.

### 8.5 Errors and reconciliation

| Outcome | Classified as | Behaviour |
|---|---|---|
| Carrier refusal (4xx), price grew, stale `list` interval, empty offers | refusal (a non-transport exception, so `is_transport_failure()` is false, :480) | Not retried; the failure carries the carrier text. |
| Timeout / 5xx on `offers/confirm` | «unknown» | The offer may have been confirmed. `supports_reconcile()` is false (:509): no endpoint to look an order up by `operator_request_id` among those the plugin uses (UNVERIFIED that none exists), so no auto-retry. The «unknown» state is **sticky**: neither automatic nor ordinary manual export may repeat. Retry only through the Framework action «Выгрузить повторно (я проверил кабинет)» from #1253(b), which explicitly authorizes a retry after the merchant checks the Yandex cabinet (s178 amendment). If `operator_request_id` proves idempotent or queryable, that evidence makes reconciliation the normal retry path instead. |
| HTTP 429 | throttled | The framework retries after `Retry-After` (:218 doc, :403-408); each retry builds fresh offers. |
| 15-minute expiry between create and confirm | refusal | Re-run `offers/create` once inside the same export; a second failure is a refusal. |

### 8.6 Acceptance contour

Live create/confirm/cancel is **impossible** on the operator's account (corporate billing inactive) and the operator will not top it up for tests. Export is verified on (a) the **test contour** (`https://b2b.taxi.tst.yandex.net`, Moscow only, the public test token from Yandex's documentation) and (b) a **stub server** in CI; **live credentials never enter CI** (the CDEK rule, cdek spec §5).

## 9. Statuses, documents, orders page

### 9.1 Polling (no webhook)

Yandex has no inbound webhook (v1 checklist: outbound-only). Status comes from `request/info`, history from `request/history`. v1 ran an Action Scheduler recurring hook every 180 minutes (`wc_yandex_orders_update`, v1:includes/class-integration.php:42-62) plus single `wc_yandex_update_order` per order. The framework only records freshness (`Delivery_Sync_Status`, order/class-delivery-sync-status.php:45). The plugin keeps the AS poller, with the interval in a setting (default 180 min kept), and `Abstract_Tracking_Handler::map_events()` (order/abstract-tracking-handler.php:202) maps `request/history`. The v1 hooks `wc_yandex_orders_update` (recurring) and `wc_yandex_update_order` (single, payload key order `order_id`, `slim`) are drained and replaced in §12 step 7.

### 9.2 Statuses

v1 stores the raw upper-case status in `_yandex_delivery_state_status` (a data contract, kept) and writes local values `NEW`, `EXPORTING_PROCESS`, `EXPORT_INVALID`, `MARK_AS_CANCEL` itself (v1:includes/functions.php:406-451). The plugin maps the raw set onto the canonical states (`pending`, `created`, `in_transit`, `ready_for_pickup`, `delivered`, `returning`, `returned`, `failed`, `cancelled`, `unknown` — order/class-delivery-status.php:40-75) through a table in `Yandex_Delivery_Status`, with the raw label preserved. `DELIVERY_DELIVERED` → the configured delivered WC status; `CANCELLED` → the configured cancelled status (framework `Export_Settings::SETTING_STATUS_DELIVERED` / `SETTING_STATUS_CANCELLED`, v1 `order_delivered_status` / `order_cancelled_status` migrate there). The delivery date goes through `Shipment_Facts::with_delivery_date()` (order/class-shipment-facts.php:176), which fires `woodev_shipping_delivery_date_changed` (class-shipment-facts-events.php:665) — no buyer email for it in 2.0.0.

### 9.3 Documents and emails

Label (`request/generate-labels`) and handover act (`request/get-handover-act`) return a PDF; both go through a `Document_Source` (order/interface-document-source.php:14) and the framework download route. The v1 `sharing_url` email is replaced by the framework's status email (email/class-delivery-status-email.php), whose `{tracking_url}` needs **G3**: until G3 lands the email carries no link. G3 contract: the framework template seam composes a URL from a provider template plus a tracking number (admin/orders/class-order-row-builder.php:748-762, email/class-delivery-status-email.php:185-198), which cannot rebuild Yandex's opaque per-request link — so F3 adds a stored **absolute** URL in order meta that both the orders page and the status email render directly when present. Yandex source: v1 stored `_yandex_delivery_sharing_url` from the request data; which v2 response carries it (`offers/confirm` or `request/info`) is UNVERIFIED and is checked on the test contour in Y10b (fixture + output tests). If `offers/confirm` has none, the poller fills it from `request/info` on its first run. If neither yields it, v2.0.0 ships without the buyer link and says so — a documented limitation, not a silent gap. The key is the v1 one, `_yandex_delivery_sharing_url` (§2 row 15, §11): new v2 orders write it, migrated orders already carry it, and F3 renders it through the framework's absolute-URL seam.

### 9.4 Orders page

`Orders_Provider::create( 'yandex_delivery', …)` (admin/orders/class-orders-provider.php:305) with the page slug `wc-yandex-orders` (a bookmarked URL; the plugin supplies the slug, admin/class-shipping-admin.php:150), status map and labels, `carrier_order_id_meta_key = _yandex_delivery_request_id`, `pickup_point_meta_key = _yandex_delivery_destination_station_id`, `supports_label_printing = true`. **Marker:** the framework queries markers with `EXISTS` (admin/orders/class-orders-query.php, marker keys). v1 has no dedicated marker, but writes `_yandex_delivery_state_status = NEW` on every Yandex order at checkout (v1:includes/class-checkout.php:695), so that existing key is the marker and **no backfill is needed**; new orders get it from a `marker_writer`. Orders created in the admin without a checkout may lack it in v1 (UNVERIFIED).

## 10. A method never disappears silently (D8)

### 10.1 Always-on log line

Whenever a Yandex method yields no rate, one line goes to the WC log (source `yandex-delivery`), **not** gated by the debug switch (v1 gated it: v1:woocommerce-yandex-delivery.php:253-277): `<settlement> → geo_id <id|none> → points <n> (<network filter>, <type filter>) → <reason>`. Reasons are a closed list: `no_geo_id`, `ambiguous_geo_id`, `no_points`, `no_points_after_network_filter`, `cod_not_accepted`, `courier_cod`, `oversize_terminal`, `address_not_geocodable`, `api_error`, `token_invalid`. Lines are written on cache misses only (the geo cache and the rate cache absorb repeats), so the log does not flood. Do not log the carrier's raw message to the screen (the framework's own warning on the failure action, class-shipping-method.php:1504).

### 10.2 Admin-only hint on the checkout — framework card G1

D8.3 is a framework mechanism usable by CDEK. Verified first: nothing like it exists (§3 row «Admin-only checkout hint»). Proposal for the card: a small API `Shipping_Method::report_hidden( string $reason_code, array $context )` stored for the request, and a renderer that shows the reasons to users with `manage_woocommerce` only — a notice under the shipping block on the classic checkout and through the Store API extension data on the block checkout; the buyer path is untouched; the reason texts are the plugin's. Hidden for guests and non-admins by capability check (not by obscurity). The CDEK plugin can adopt it in a separate small card.

### 10.3 Token check on save

On saving the token: (a) format — a production-contour token starts with `y0_`; a token that does not is rejected with a text («Похоже, это токен Яндекс GO, а не Яндекс Доставки»: Yandex GO tokens are the common misconfiguration); (b) a probe to the **selected** contour (a cheap authenticated call, e.g. `location/detect` for a known city — the exact call is UNVERIFIED), 401/403 → a plain text; (c) a `y0_` token on the test contour → «боевой токен на тестовом контуре» (the test contour serves Moscow only and has its own public token); the public test token on production is refused the same way. Hosts: production `https://b2b-authproxy.taxi.yandex.net`, test `https://b2b.taxi.tst.yandex.net`.

## 11. Installed-site data contract

ADR-005 makes installed-site contracts release-blocking (docs-internal/adr/005-platform-v2-clean-break-policy.md). The earlier checklist (docs-internal/migration/yandex-data-preservation-checklist.md, 04.06.2026) is **superseded where D10 says so**; the table gives the verdict per row.

| Contract | v1 value | v2 verdict |
|---|---|---|
| Plugin id / EDD | `yandex_delivery`; 821 | **Keep** (updater and `woodev_yandex_delivery_*` lifecycle options derive from the id). |
| Method ids | `yandex_delivery_other_day`, `yandex_delivery_express` | **Changed (D10):** `yandex_delivery_courier`, `yandex_delivery_pickup`; the old ids are rewritten, express rows are deleted. Instance ids and zone membership are unchanged. |
| Main settings option | `woocommerce_yandex_delivery_settings` | **Keep** the key (`YandexSettingsOptionKeyContractTest`). |
| Instance options | `woocommerce_yandex_delivery_other_day_{instance}_settings` | Copied into `woocommerce_yandex_delivery_courier_{instance}_settings` / `…_pickup_…` (CDEK's `instance_option()` pattern, `cdek:includes/lifecycle/class-cdek-v1-migration.php:2208`); the old option stays for rollback. |
| Order meta | `_yandex_delivery_*` (seven keys, §2 row 15) | **Keep** byte for byte (`YandexOrderMetaPrefixContractTest`). |
| Orders page | slug `wc-yandex-orders`; screen option `wc_yandex_orders_per_page` | **Keep**. |
| Licence | EDD licence options of v1 | **Carried** (D10), as `cdek:` `carry_license` (cdek: includes/lifecycle/class-cdek-v1-migration.php:886-922): the source and destination option names are derived by that routine for plugin id `yandex_delivery` and written into Y12; an existing destination value is NOT overwritten; the source stays intact for rollback; the copy is verified and journalled. |
| Warehouse table + REST | `{prefix}wc_yandex_delivery_warehouses`; `yandex-delivery/warehouses` | **Dropped (D10, D6b).** The table is left untouched in the database (nothing reads it; no `DROP`), the REST routes are not registered; `YandexWarehouseTableContractTest` is retired (G4). The migration reads the table once only to copy a method's `station_id` (§12 step 3). |
| Session keys `chosen_yandex_pickup_point(_test)`, `chosen_yandex_time_interval` | transient session state | **Not carried** — not persisted data; the framework stores selections. |
| Hidden checkout fields `yandex_*` | form fields | **Not carried** (the framework fields replace them). |
| AJAX actions (`get_yandex_delivery_*`, `yandex_delivery_*_order`) | admin-ajax | **Not carried**; replaced by the framework REST routes. Third-party scripts calling them (UNVERIFIED that any exist) break; ADR-005 allows it. |
| Cron / queue | AS recurring `wc_yandex_orders_update`; AS single `wc_yandex_update_order`, `wc_yandex_export_order` | **Drained and replaced** (§12 step 7). |
| Public hooks | `wc_yandex_order_status_changed*`, `wc_yandex_order_mark_as_delivered/cancelled`, `wc_yandex_delivery_rate_cost`, `…prepare_order_data`, `…order_statuses`, `wc_yandex_disabled_statuses_for_export`, `woodev_yandex_map_api_key`, … | Preserve those whose event still exists (status changes, delivered/cancelled, map key); alias only if a consumer is known; **never silently drop** (CDEK spec §3 rule). The list of dropped hooks goes into the release notes. |
| Log source | `yandex-delivery` | **Keep.** |
| REST namespace | `yandex-delivery` | Kept as the framework's namespace derives from the plugin id. |
| Metrika `100393788`, widget, «Отключить поле Область/район» | v1 features | **Removed** (D4, D10). |

## 12. Migration v1 → v2.0.0

Mirrors `cdek:includes/lifecycle/class-cdek-v1-migration.php`: every step idempotent, decided from the data found, a journal option (not autoloaded) recording what a manual rollback needs, a site-wide run lock, every write read back, a failure throws and the stored version does not advance. The oldest version carried over is a decision for card Y12a (the CDEK minimum was 2.2.0; v1's first 1.x line has the same option shape since 1.0 — UNVERIFIED).

1. **Split instances** (D10). For each zone row with `method_id = yandex_delivery_other_day`: read the instance option, `tariff` → target (`time_interval` → `yandex_delivery_courier`, `self_pickup` → `yandex_delivery_pickup`; missing/unknown → courier, journalled). Journal the plan **before** any row changes, switch the rows through a zone-method store seam (`cdek:includes/lifecycle/interface-cdek-zone-method-store.php`; WooCommerce has no API to change a row's method id), read the rows back, copy the instance settings, flush the shipping caches. Express rows (`yandex_delivery_express`) are deleted and listed in the journal.
2. **Rewrite settings that hold method ids** (WooCommerce gateway `enable_for_methods`), original kept in the journal (`cdek:` step 2, `rewrite_gateway_methods`, :1061).
3. **Instance fields.** `fee`, `fee_type`, `free_cost`, `min_cost`, `max_cost` → the framework's keys (`min_cost`/`max_cost` are the same keys, class-shipping-method.php:95-98); `round_cost`, `round_cost_range`, `min_order_cost`, `max_order_cost`, `coupon_free_shipping`, `shipping_class_id`, `description_rate` → framework/plugin equivalents (`description_rate` → `description`); `include_insurance` is read by the insurance option (§5.3); `show_time_interval` → D7 settings (§7.2); `shipment_type = pickpoint` → drop-off point from `platform_station`; `shipment_type = warehouse` → `station_id` read from the v1 warehouse table row `warehouse_id` (only this read of the table); `show_commission` remains carried but unused: COD commission stays inside the delivery price and has no separate line (s178 amendment).
4. **Global settings.** The main option stays. DaData keys (`dadata_token`, `dadata_secret`) → the framework location settings (`woodev_location_token`, `woodev_location_clean_secret`, as `cdek:` `copy_dadata_keys` :1501); default dimensions (grams / cm → store units, once, journalled, `cdek:` :1548); `package_method` → packing algorithm; `debug` → `Advanced_Settings::SETTING_ENABLE_DEBUG`; `order_export_statuses` → `Order_Automation` statuses + auto-export on only when the list was non-empty; delivered/cancelled statuses → `Export_Settings`; `point_types`, `vat_rate`, `disable_cod_payment`, `order_status_update_interval` stay plugin settings; `disable_country_field` / `disable_postcode_field` / `pickup_disabled_fields` → framework field settings where an exact counterpart exists (`cdek:` step 10 pattern); `disable_state_field` («Отключить поле Область/район») is **not** carried (D10). New settings default: networks both on, interval mode as step 3, price-growth threshold default, auto-export OFF.
5. **Payment-method registry (#1163 item 1).** The `Fee_Payments` registry (`woodev_shipping_fee_payment_instances`, class-fee-payments.php:61) is filled only by option writes. For every migrated instance the migration writes the instance option through `update_option` (not `add_option` of an identical value) so the registry hooks run, **until G2 ships**; with G2 the declaration replaces this step and the step becomes a no-op.
6. **Journal and flags.** Informational flag `wc_yandex_upgraded_to_2_0_0` (never read as a guard), journal `wc_yandex_upgrade_2_0_0`.
7. **Queue.** Cancel the pending AS actions `wc_yandex_update_order`, `wc_yandex_export_order` and the recurring `wc_yandex_orders_update` (as `cdek:` `cancel_v1_order_actions` :1619); the new poller schedules itself. A shop with orders in `EXPORTING_PROCESS` is reported, not auto-changed.
8. **Not touched:** order meta, the warehouse table, the v1 instance options (rollback). The **legacy source** licence option is retained after the copy; the copied destination option is verified and journalled (§11).

Idempotence rules, the unsupported-origin branch and the journal shape are copied from CDEK. A dry-run report (a census of instances by tariff, express rows, warehouse rows, intervals on/off) is produced first, as `cdek:includes/setup/class-cdek-v1-census.php` does, so the operator sees what the migration will do on a real shop before release.

## 13. Coexistence with CDEK (D9)

The five acceptance scenarios, how each is verified, and where the risk is:

| # | Scenario | Verified by | Risk |
|---|---|---|---|
| 1 | CDEK point → Yandex → CDEK in one session: the order carries the right carrier's point and address | Rig, both plugins active; assert the order meta of each carrier and the shipping address after each switch | Both plugins write the shipping address / postcode from the chosen point; the framework `Pickup_Handler` is per plugin id, the Location layer is shared. |
| 2 | Failed payment, re-checkout with a carrier switch: the previous carrier's data is cleared (support case 16988) | Rig: fail the payment gateway, retry with the other carrier; assert no stale `_yandex_delivery_destination_*` / CDEK point on the order | Order meta written at the first attempt survives on the reused order. The checkout must clear the other carrier's meta whenever its method is not the chosen one (a `forget_except` pattern exists: admin/orders/class-carrier-field-set.php:328). |
| 3 | Both exported: each carrier's orders page sees only its own | Two `Orders_Provider` registrations with distinct markers (`_yandex_delivery_state_status`, CDEK's `_wc_edostavka_shipping`); the registry returns each provider's orders (admin/orders/class-orders-query.php scopes by marker). | The Yandex marker is `state_status`, which every Yandex order has; make sure a CDEK order never gets it. |
| 4 | Two framework versions inside the plugins: the newer runs | The bootstrap `register_plugin` version rule; rig with one plugin carrying an older framework copy | The CDEK plugin pins a framework commit (`cdek:` last commits «pin framework to 27df83b5»); the Yandex plugin pins its own; the rig test uses two different pins. |
| 5 | Deactivate one: the other and its orders are untouched | Rig: deactivate each in turn; assert settings, methods, orders page and an export of the remaining plugin | Shared options (`woodev_location_*`, DaData keys, popular settlements) must survive deactivation of either owner. |

## 14. Testing and acceptance

- **Unit (Brain Monkey / Mockery):** adapter rule over both record shapes and the 28-place table (offline, from recorded variants committed as test fixtures, not the 85 MB raw archive); parcel builder and **body parity** (calculator vs offers/info vs offers/create places); `referral_source` contract test; price-threshold arithmetic; stale-interval decision table; point mapper; chunked storage (multi-chunk round trip, missing middle chunk, failed write — the three CDEK #81 cases); migration planner.
- **Integration (WordPress + WooCommerce, single-flight DB, never run beside another suite):** real plugin entry load with `Woodev_Loader` (the CDEK lesson: do not reference a framework constant before the framework loads, docs-internal/gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md); zone method persistence and the v1 → v2 migration over a fixture of a v1 database; HPOS and CPT order meta; classic and block checkout rate/pick/save.
- **Stub server in CI:** an in-repo HTTP stub that answers every endpoint of §4–§9 (`location/detect`, `pickup-points/list`, `pricing-calculator`, `offers/info`, `offers/create`, `offers/confirm`, `request/info`, `request/history`, `request/cancel`, `request/generate-labels`, `request/get-handover-act`) from recorded fixtures, including the refusal responses seen in the measurements. **No live credentials in CI.**
- **Test contour (manual/rig):** Moscow only, public token: create/confirm/cancel, `request/info`, labels. Record the account type used.
- **Production checks (read-only, the operator's account, small load; the account is a test account):** `location/detect`, `pickup-points/list`, `pricing-calculator`, `offers/info`, `offers/create` — enough to verify quote parity and points; never `offers/confirm`/`request/*` (billing inactive).
- **Rig acceptance:** buyer-visible rates and point selection in classic and block checkout, with changed and unchanged packages and cities; the five D9 scenarios; the oversized-terminal case; the COD courier case; no Kaliningrad hint (s178 amendment).
- **CI gates:** plugin unit/integration, PHP style and static analysis, JS build and lint; `npm run lint:docs` in the framework repo for this spec.

Acceptance of #1179 = both plugins green on the above, D9 scenarios 1–5 passed, the migration run on a real v1 shop copy.

## 15. Out of scope for 2.0.0

- **Express** («Доставка в течение дня», #1195, «После v2»); `referral_source` analogue in `claims/create` is a requirement of that card.
- A framework «network/brand» pickup field — only if Pochta needs it (D2).
- A buyer email when the delivery date changes (the event exists, D11a).
- The Metrika counter, the Yandex pickup widget, the warehouse table, the field-disable switch for «Область/район» (D4, D10). Yandex services (`fitting`, `particular_items_refuse`, `forbid_unboxing`) are after v2 (s178, Yandex #76).
- Reconcile-by-lookup for an unknown export (no lookup endpoint in the set used).
- Generalising the interval mechanism into the framework (D7: when express or Pochta arrives).

## 16. UNVERIFIED register and interpretations

### UNVERIFIED (not established in code or by measurement)

1. The correct-`geo_id` labels (the measurement's own); that the district check separates the two Drozhzhino villages (§4.4 claims no figure beyond the measured 26/28).
2. The raw keys of a CDEK-provider `Location_Record`; whether `location/detect` variants carry coordinates.
3. The byte size of the mapped Moscow point list; truncation of a very large list; whether the price varies by point **within** a network inside one `geo_id`.
4. The volumetric divisor 5000 and the 28 RUB/kg step (inferred); the internal mechanism of the COD commission (measured as 3.05 % of goods + delivery cost, R2; whether it is 2.5 % + VAT is not known); VAT inclusion on either side; whether the offer price depends on the source station.
5. The 5post/terminal limits beyond the one point per network R1 measured (prepaid only); whether Yandex points expose cell sizes; whether the refusal holds for other combinations.
6. Whether `request/create` honours `destination.interval_utc`; whether `offers/confirm` books the chosen interval and price; whether `request/cancel` works on test-contour requests; time zone of displayed intervals.
7. Whether an `operator_request_id` lookup or duplicate rejection exists (reconcile).
8. Whether the field-source REST route can read the session on classic and block checkout; whether an admin map can be embedded in the zone-method modal; whether the order-action dialog can list fresh intervals.
9. Which cheap authenticated call best probes a token.
10. Whether admin-created Yandex orders carry `_yandex_delivery_state_status`; whether third-party code calls the v1 AJAX actions.
11. The courier geocode failures of Kaliningrad, Volzhsk, Drozhzhino-MO.
12. ~~The repository~~ — settled by the operator (s174): v1 has no repository; v2 gets a **new** repository (all plugins without one will get one over time). Creating it is part of Y1.
13. Prices are a snapshot of 12.10.2026.

### Interpretations the spec had to make (not new decisions)

- **O1** ~~D3 («assessed value always») and D10 («insurance carried») meet at `include_insurance`: the spec keeps the key but stops reading it (§5.3).~~ Superseded by the s178 insurance decision in §0 and §5.3: the option controls buyer quote only; exports always carry assessed value.
- **O2** ~~D11's threshold unit~~ — settled by the operator (s174): a shop setting, fixed RUB or percent, default 10 %; compared on the carrier component (§8.3).
- **O3** Place barcodes for the `boxes` algorithm (§5.2).
- **O4** ~~`show_commission` is not in the D10 list (§12 step 3).~~ Superseded by the s178 decision: commission stays inside the delivery price; no separate line or setting is needed.

## 17. Card decomposition (proposed; not filed)

Repo codes: **Ф** = framework (`kalbac/woodev-plugin-framework`); **П** = the Yandex plugin repository (a NEW repository, created in Y1; §16 item 12). «≤400» = one worker, about 400 changed lines or fewer. Titles are Russian (card titles go to the backlog).

| # | Repo | Title | Scope | Depends on |
|---|---|---|---|---|
| F1 | Ф | Подсказка администратору на оформлении: почему способ скрыт | Общий механизм (G1): способ сообщает причину скрытия, администратор (`manage_woocommerce`) видит её в обычном и блочном оформлении, покупатель — нет. ≤400 | — |
| F2 | Ф | Способ доставки сообщает, что цена зависит от способа оплаты | Объявление (G2): пересчёт при смене оплаты включён для способа без правки каждого экземпляра; перенос реестра `Fee_Payments`. ≤400 | — |
| F3 | Ф | Ссылка отслеживания из данных заказа | Ключ мета заказа со ссылкой (G3): страница заказов и письмо статуса показывают её. ≤400 | — |
| F4 | Ф | Контрактные тесты Yandex под новые имена способов | G4: «старые имена переписываются в новые»; тест таблицы складов снять; рецепты мутаций обновить. ≤200 | — |
| R1 | П | Замер предельных габаритов постамата и 5Post | Только вызовы калькулятора, ≤60 запросов; результат — константы и таблица в `docs-internal/research`. Haiku по подробному заданию. | — |
| R2 | П | Замер надбавки при наложенном платеже за стоимость доставки | `offers/create` с разной `delivery_cost`: линейна ли надбавка, к чему применяется 3 %. Только чтение. | — |
| Y1 | П | Каркас плагина 2.0.0 | Загрузчик, пространство имён, закрепление фреймворка, id `yandex_delivery`, лицензия, CI, пустая группа настроек. ≤400 | — |
| Y2 | П | API-клиент Яндекс Доставки и заглушка | Клиент всех эндпоинтов, контуры, единицы, скрытие секретов, построитель тела с `referral_source`, HTTP-заглушка для CI. ≤400 | Y1 |
| Y3 | П | Проверка токена и настройки подключения | Формат `y0_`, пробный запрос в выбранный контур, тексты ошибок (D8.2). ≤300 | Y2 |
| Y4 | П | Определение населённого пункта: адаптер `geo_id` | `Yandex_Location_Adapter`, правило D1, кэш 7 суток / 1 час, оффлайн-тесты на 28 местах, причины в журнал всегда. ≤400 | Y2 |
| Y5 | П | Источник ПВЗ Яндекса с хранением крупных списков | Bulk-источник, фильтры, сети, значки на карте, `accepts_cod`, хранение кусками. ≤400 | Y2, Y4 |
| Y6 | П | Способы «Курьер» и «ПВЗ» и их настройки | Два метода, поля экземпляра (сети + подсказка про Калининград, `station_id`, точка сдачи), правила скрытия, контекст кэша цены. ≤400 | Y1, Y5 |
| Y7 | П | Цена: общий построитель мест и разбор калькулятора | `Yandex_Parcel_Builder`, тело калькулятора, оценочная стоимость, наложенный платёж, самая дешёвая сеть, надбавка за доставку по R2; тест равенства тел. ≤400 | Y2, Y6, R2 |
| Y8 | П | Дата и интервал доставки на оформлении | Настройка D7, `offers/info`, поле интервала в обычном и блочном оформлении, «N–M дней»; сначала проверка источника поля. ≤400 | Y6, Y7 |
| Y9 | П | Выгрузка через офферы | `create_order` целиком, выбор оффера, порог роста цены (настройка магазина), устаревший интервал, действие «Выгрузить с интервалом», «неизвестный» исход после таймаута подтверждения блокирует повторную выгрузку до явного разрешения магазина, контрактный тест `referral_source`. ≤400 | Y7, Y8 |
| Y10a | П | Статусы и опрос | Опрос `request/info`/`history`, карта статусов, даты доставки, страница заказов `wc-yandex-orders`. ≤400 | Y9 |
| Y10b | П | Документы и ссылка отслеживания | Этикетка и акт, ссылка отслеживания по контракту G3 (откуда берётся, проверка на тестовом контуре), письма статуса. ≤300 | Y10a, F3 |
| Y11 | П | Миграция v1 → v2: способы доставки и их настройки | Разделение экземпляров по тарифу, удаление экспресса, перенос полей, `station_id` склада, журнал и блокировка. ≤400 | Y6 |
| Y12 | П | Миграция v1 → v2: общие настройки, очередь и лицензия | Ключи DaData, габариты, статусы, поля, очистка очереди, реестр оплат (ветка до G2 и после — явно), лицензия по `carry_license`, отчёт-перепись. ≤400; если больше — делить по образцу миграции СДЭК. | Y11, F2 |
| Y13 | П | Выбор точки сдачи на карте и проверка `station_id` | Выбор из `available_for_dropoff` (карта или выпадающий список), кнопка проверки склада. ≤300 | Y6 |
| Y14 | П | Приёмка на тестовом контуре и в заглушке | Создание/подтверждение/отмена в Москве и в заглушке, запись результатов. ≤300 | Y9, Y10a |
| Y15 | П | Приёмка совместной работы со СДЭК | Пять сценариев D9 на риге, скрипты и отчёт. | Y10b, Y12, оба плагина на v2 |
| Y16 | П | Подсказка администратору для Яндекса | Подключить F1: причины скрытия способа. ≤200 | F1, Y7 |

### Parallelism (disjoint files)

- **Wave 0, all at once:** F1, F2, F3, F4 (framework, separate areas), R1, R2 (read-only research).
- **Wave 1:** Y1 alone (everything in the plugin repo waits for the skeleton).
- **Wave 2:** Y2; after it Y3 ∥ Y4 (different files: settings vs location).
- **Wave 3:** Y5 (after Y4) ∥ Y3.
- **Wave 4:** Y6 (after Y5), then Y7 ∥ Y11 ∥ Y13 (pricing, migration of methods, drop-off picker). File ownership must be named in each brief before dispatch: Y11 (lifecycle/) and Y13 (method settings) can collide on shared settings or lifecycle registration — if they do, run them in sequence.
- **Wave 5:** Y8 (after Y7), Y12 (after Y11).
- **Wave 6:** Y9 (after Y8), then Y10a, then Y10b (needs F3).
- **Wave 7:** Y14 ∥ Y16, then Y15.

Rounds: at most 2–3 per card; a card that needs a fourth is decomposed (CLAUDE.md). The riskiest cards are Y8 (checkout field source on both checkouts) and Y5 (list storage); both start with a spike that is allowed to change the card's scope before code.

## Related

- [CDEK v2 plugin design](2026-10-06-cdek-v2-plugin-design.md) — the first consumer this spec mirrors.
- [Shipping module decisions](2026-06-25-shipping-module-decisions.md) — locked module boundaries (location, checkout, shipment, email).
- [Yandex data-preservation checklist](../migration/yandex-data-preservation-checklist.md) — the earlier contract list, superseded where §11 says so.
- [Clean-break policy](../adr/005-platform-v2-clean-break-policy.md) — internal API and installed-site contract policy.
- [Framework architecture](../wiki/architecture.md) — subsystem and base-class reference.
- [V2 extension-point pattern](../wiki/v2-extension-point-pattern.md) — plugin loading and extension pattern.
- [Local rig](../wiki/local-rig.md) — dev/test setup and the live-Yandex fixture switch.
- [Geo measurement](../research/2026-10-12-yandex-geo-measurement/README.md) — `location/detect` and point-list measurements.
- [Price measurement](../research/2026-10-12-yandex-price-measurement/README.md) — calculator vs offer, including the real-dimensions follow-up.
- [Export-path measurement](../research/2026-10-12-yandex-export-path/README.md) — offers/info vs offers/create, the billing blocker.
- [Loader gotcha](../gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md) — load ordering.
