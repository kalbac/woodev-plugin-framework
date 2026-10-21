# Task A — inventory of the v1 Russian Post plugin (1.2.6.0)

> Part of the [Russian Post v2 fact base](README.md) (s191, 21.10.2026). Copied from the research run with credentials, account ids and
> local paths replaced by `<redacted>`. Raw response directories (`f-raw/`, `g-raw/`, `/private/tmp/...`) are NOT in the repo, so evidence-file
> references below point at files that exist only on the research machine. **Where [v-verify.md](v-verify.md) contradicts this report, v-verify wins** — see the README section «Contradictions resolved».

Read: 21.10.2026. Source: `plugins-reference/woodev-russian-post/` (all `includes/`, `templates/`, `assets/js`, `uninstall.php`, entry file, the bundled framework's licensing/lifecycle/API-base). Everything below is from reading code; **nothing was run**. "Paths" are relative to the plugin root unless noted.

**Size:** 9 842 PHP lines outside the bundled `woodev/` (framework 1.3.3, `woodev-russian-post.php:31`), plus 5 317 lines of vendored `jquery.suggestions.js`. ~12 of 40 PHP files under `includes/` are tiny interfaces/DTOs.

## 0. Headline findings (read these first)

1. **Three Russian Post services, three auth schemes, one client class** (`includes/api/api.php`). Host is built as `https://{type}.pochta.ru` (api.php:174): `widget.pochta.ru` and `cms.pochta.ru` — no auth, identified by a `guid_id`/`guid_key` pair the plugin gets from `cms.pochta.ru` at activation; `otpravka-api.pochta.ru` — `Authorization: AccessToken <token>` + `X-User-Authorization: Basic base64(login:password)` (api.php:211-212). Tracking is a separate SOAP service `tracking.russianpost.ru/rtm34` (soap/tracking.php:35).
2. **Rates are NOT calculated by the plugin or by otpravka**: they come from the *widget* service `POST /api/pvz/courier_tariff_public` and `/pick_up_tariff_public` (api.php:28,35), keyed by the merchant's `guid_id`. This is an undocumented-looking, CMS-module endpoint (documentation status UNVERIFIED — no public spec read). A redesign must decide whether to stay on it.
3. **Every non-CMS API request — including `PUT` order export, `DELETE` order cancel and `GET` order refresh — is cached for 24 h** (`API_Request` uses `Woodev_Cacheable_Request_Trait`, default `$cache_lifetime = 86400`, api-request.php:10, woodev/api/traits; only `CMS_TYPE` calls `bypass_cache()`, api.php:277). Cache key = uri + body (abstract-cacheable-api-base.php:66-72). Consequence by reading: re-exporting an identical payload within 24 h returns the cached answer without calling Russian Post; «Update» can return day-old data. **Not reproduced.**
4. **A failed `clean/address` call crashes rate calculation**: `get_objects()` swallows `Woodev_API_Exception` and returns `null` (api.php:290-293); callers then do `new Address_Normalize( $clean_address )` whose parameter is `array` (address-normalize.php:69) → `TypeError` (an `Error`, not caught by `catch ( \Woodev_Plugin_Exception )` at abstract-shipping-methods.php:383, nor `\Exception` at ajax.php:197). Same pattern: checkout.php:542-549, otpravka.php:115-123, widget.php:65-73.
5. **The CDEK integration cannot work with CDEK v2**: it keys off `WC_CDEK_SHIPPING_VERSION` and ~10 `wc_edostavka_*` symbols; none exists in CDEK v2 (section 4). It silently turns itself off; no error.
6. **Installed-site contracts that are painful**: the order's shipping-item meta `wc_russian_post_rate` and the customer user-meta `wc_russian_post_customer_delivery_point` store **PHP-serialised objects** (`Address_Normalize`, API `stdClass`) — a rewrite must keep class `Woodev\Russian_Post\Classes\Address_Normalize` loadable (or migrate) to read old orders (section 2).

## 1. Feature map

Legend for robustness: ✅ looks sound · ⚠ works with defects (see §6) · ❌ dead/broken by reading.

| # | Capability | Files (v1) | Russian Post API (host / path / auth) | Robustness |
|---|---|---|---|---|
| 1 | **Shipping method «Courier»** (`wc_russian_post_courier`), shipping-zone instance settings | `classes/shipping-method-courier.php`, `abstracts/abstract-shipping-methods.php` | rates: `widget` `POST /api/pvz/courier_tariff_public` (no auth, `account_id`=guid in body) | ⚠ one tariff per instance in practice (§6 #15) |
| 2 | **Shipping method «Postal office / postamat»** (`wc_russian_post_postal`) | `classes/shipping-method-postal.php` | `widget` `POST /api/pvz/pick_up_tariff_public` | ⚠ same |
| 3 | Rate calculation: address clean → (fallback postcode lookup) → tariff request; weight = max(physical, volumetric `L*W*H/5` g); declared value = cart total only for COD or site setting `useCartValue[Courier]` else 1 ₽ | abstract-shipping-methods.php:290-416,226-244,683-686 | `otpravka-api` `POST /1.0/clean/address`; `GET /postoffice/1.0/settlement.offices.codes` (api.php:164); then widget tariff | ⚠ 2-3 sequential remote calls per method instance per cart refresh; 24 h cache (#3) |
| 4 | Method availability rules: min/max cart, free-cost threshold, free-shipping coupon, shipping class, allowed shipment type, allowed payment gateways, `%parcel_type%` in title, description text, delivery time text | abstract-shipping-methods.php:79-197,545-579; checkout.php:341-389,435-463 | — (uses API answer `delivery_interval->description`) | ⚠ shipping-class check broken (#14) |
| 5 | **Pickup point / postamat chooser** — modal with Russian Post's own map widget, button under the methods or inside the chosen method, point stored per customer, auto-close | checkout.php:134-151,295-389; `assets/js/frontend/map.js`; `templates/views/html-modal-map.php`; ajax.php:43-200 | JS loads `https://widget.pochta.ru/map/widget/widget.js` (checkout.php:71) and calls global `ecomStartWidget` (map.js:19); server then `otpravka-api` `POST /1.0/clean/address` for the chosen address | ⚠ trusts browser for point id/type/mail type (§6 #9); JS bug (#19) |
| 6 | Postamat rules: COD forbidden for postamat/ECOM; only `ONLINE_PARCEL`/`ECOM_MARKETPLACE` to postamat; parcel dimensions vs cell size S/M/L/XL | checkout.php:489-558,640-679; functions.php:445-468 | — | ✅ logic reads sensibly; `can_fit_dimension_type` is a heuristic |
| 7 | **Address normalisation / validation** (ФИАС guids, quality+validation codes → `is_usable()`), city/region prefix stripping | `classes/address-normalize.php` (410 l.), functions.php:139-156 | `otpravka-api` `POST /1.0/clean/address` | ⚠ crashes on API failure (#4); object is serialised into meta |
| 8 | **Checkout address suggestions** (DaData) for region/city/address + postcode fill; settings toggles; Russian Post "passport" proxy URL is commented out (checkout.php:123) | checkout.php:106-128; `assets/js/frontend/{address-suggestions,suggestions-plugin,jquery.suggestions}.js` | **DaData** directly from the browser with the merchant's DaData token printed into page JS (checkout.php:124). CSS from `cdn.jsdelivr.net` (checkout.php:42) | ⚠ JS bug (#18); disabled when CDEK v1 suggestions are on |
| 9 | Checkout field tweaks: reorder state/city/address/postcode; hide address+postcode when "to postal office" (classic checkout only, fragments hack) | checkout.php:212-293 | — | ⚠ classic-only (`supported_features.blocks` = false, entry:73-77) |
| 10 | Payment-gateway restriction per shipping method instance | checkout.php:435-463 | — | ✅ |
| 11 | Checkout validation: pickup point required & in same ФИАС place; courier address must be `is_usable()` | checkout.php:489-558 | `clean/address` again at validation (checkout.php:542) — unguarded | ⚠ (#4) |
| 12 | Mark order as Russian Post, copy chosen point into the shipping-item meta, initial order status `new` | checkout.php:567-614 | — | ⚠ wrong order-note text (#17) |
| 13 | **Order export** — two modes: «Otpravka» (creates a backlog entry, returns barcode) and «Widget» (sends the order to the CMS account) ; manual (list, meta-box), bulk, auto on selected WC statuses | `classes/order.php:224-301`, `export-order/otpravka.php` (385 l.), `export-order/widget.php`, `abstract-export-order.php`, `admin/orders-admin.php`, `admin/admin.php` | Otpravka: `otpravka-api` `PUT /2.0/user/backlog`; Widget: `cms` `POST /api/cms/orders_public` | ⚠ money truncation (#5), 0 % VAT dropped (#6), cached PUT (#3) |
| 14 | Order refresh («Update») | order.php:303-366 | Otpravka: `GET /1.0/backlog/{id}`; Widget: re-posts the export payload | ⚠ 24 h cache |
| 15 | Order cancel (Otpravka only) incl. when WC order is cancelled or the post is deleted | order.php:368-405; admin.php:271-290; products-admin.php:52-79 | `otpravka-api` `DELETE /1.0/backlog` | ⚠ barcode not cleared (#7); `before_delete_post` ignores HPOS (#25) |
| 16 | International shipping (≈250 country codes, TNVED per product, customs declaration, transliteration of fields) | functions.php:176-438; otpravka.php:248-289; products-admin.php:23-50 | same `PUT /2.0/user/backlog` | ⚠ code table wrong (#12) |
| 17 | Box dimension for export: single-box packer (≤2 items) or custom `Package_Packer` (>2) | `classes/package-packer.php`, otpravka.php:295-343 | — | ⚠ uses parent product for variations (#13) |
| 18 | **Russian Post orders admin page** (WP_List_Table under WooCommerce menu, status views, bulk export/update/cancel, search, new-count bubble) + order meta-box | `admin/admin.php`, `admin/order-list-table.php` (535 l.), `templates/views/html-order-meta-box.php` | — | ⚠ reflected XSS (#21) |
| 19 | **Labels / documents (F7, F103, address labels), batches** | **absent** — plugin has no label, document, batch or «pickup call» code. Merchant uses the Russian Post dashboard link instead (`get_account_url`, entry:598-611) | — | n/a |
| 20 | **Tracking** — SOAP `getOperationHistory`, status mapping (delivered/cancel/in-transit/accepted by operation type ids), WC status switching, history shown to customer, cron every 12 h via Action Scheduler (50 orders/run) | `classes/soap/{tracking,soap-client}.php`, order.php:407-503, functions.php:516-585, entry:268-296 | SOAP `https://tracking.russianpost.ru/rtm34?wsdl`, creds in SOAP header (settings `tracking_login/password`), `WSDL_CACHE_NONE` | ⚠ blocking SOAP + WSDL fetch per order in cron (#16); requires `ext-soap` |
| 21 | Tracking e-mail to the customer (HTML+plain, placeholders, once per barcode) | `classes/tracking-email.php`, `templates/emails/*` | — | ✅ |
| 22 | Webhooks from Russian Post CMS: barcode and status push | `classes/webhooks.php` — `wc-api=russian_post_barcode`, `wc-api=russian_post_status` | inbound; shared secret `guid_id`+`guid_key` in JSON body | ⚠ plain `!==` compare, status string unvalidated (webhooks.php:75) |
| 23 | **Module registration** with `cms.pochta.ru` on activation/upgrade; unregister on deactivate; site settings cache (24 h) used for sender zip, fragile/SMS flags, shipment priorities | `classes/lifecycle.php:38-104`, entry:618-645, install/uninstall modules | `cms` `POST /api/cms/install`, `/api/cms/uninstall`; `widget` `POST /api/sites/public_show` | ⚠ secret in e-mail (#22); sync HTTP on (de)activation |
| 24 | Dashboard e-mail to site admin containing the account link with `guid_id`+`guid_key` | lifecycle.php:92-101 | — | ⚠ #22 |
| 25 | **Setup wizard** (welcome, auth, dimensions, orders, tracking, additional) on the bundled framework v1 wizard | `admin/setup-wizard.php` (799 l.) | — | ✅ but 6 steps duplicate the settings screen field-by-field |
| 26 | Settings screen as a `WC_Integration` (Integrations tab); live "account connected / N of M requests" probe on every render | `classes/integration.php` (419 l.) | `otpravka-api` `GET /1.0/settings/limit` | ⚠ plain-text password (#23) |
| 27 | CDEK v1 integration (shared suggestions, location sync from picked point) | `integrations/integration-edostavka.php`, `classes/integrations.php`, parts of checkout/ajax/functions | — | ❌ against CDEK v2 (§4) |
| 28 | Milestone notices, licence gate (settings save blocked and method hidden without a valid licence), updater | bundled `woodev/` + abstract-shipping-methods.php:61-77,179-181 | Woodev EDD store | n/a for this audit |
| 29 | Dead code: `get_post_office` ajax (no JS caller), `get_index_public`, `get_show_public`, `get_post_office_services`, `get_update_order` (commented out, order.php:324), `wc_russian_post_get_user_options`, 12 `interfaces/*` | ajax.php:13-41; api.php | — | ❌ |

## 2. Installed-site data contracts (release-blocking)

Per ADR-005 (`docs-internal/adr/005-platform-v2-clean-break-policy.md`): option keys, licence option names, shipping-method IDs + instance ids, order/user meta, cron hooks, public hooks, admin slugs, EDD ids must survive. Plugin id = `wc_russian_post` (entry:66); text domain `woocommerce-russian-post`.

| Kind | Name / value | file:line |
|---|---|---|
| Plugin id / framework id | `wc_russian_post`; `Woodev_Plugin` id passed to ctor | entry:66,70 |
| EDD download id | `8772` | entry:502 |
| Main settings option (WC_Integration id) | `woocommerce_wc_russian_post_settings` | integration.php:17; entry:270 |
| Method ids | `wc_russian_post_courier`, `wc_russian_post_postal` | courier:12, postal:12; entry:399-404 |
| Rate ids (derived) | `wc_russian_post_courier:{instance}`, `wc_russian_post_postal:{instance}` (no suffix) | abstract-shipping-methods.php:524 |
| Instance settings options | `woocommerce_wc_russian_post_courier_{instance}_settings`, `…_postal_{instance}_settings` (keys in §3) | read directly at checkout.php:449-453 |
| Site-level options | `wc_russian_post_guid_id`, `wc_russian_post_guid_key` (module credentials from cms.pochta.ru), `woocommerce_wc_russian_post_upgraded_to_1_2_0`, `wc_russian_post_user_options` (read helper, no writer) | install-module.php:15; uninstall-module.php:11,18; lifecycle.php:21; functions.php:6 |
| Framework options (v1 bundle) | `woodev_wc_russian_post_license_key`, `woodev_wc_russian_post_license`, `woodev_wc_russian_post_version`, `woodev_wc_russian_post_is_active`, `woodev_wc_russian_post_milestone_version`, `woodev_wc_russian_post_milestone_messages`, event-history option; legacy `russian_post_ems_integration_with_woocommerce_license[_key]` migrated in 1.2.3 | woodev/licensing/class-license-store.php:134,219; class-lifecycle.php:130,142,175,19,34,59; lifecycle.php:25-34 |
| Order meta (WC_Order, HPOS/legacy) | `_wc_russian_post_is_russian_post`, `_wc_russian_post_status`, `_wc_russian_post_export_type` (`otpravka`\|`widget`), `_wc_russian_post_tracking_number`, `_wc_russian_post_tracking_number_email_sent`, `_wc_russian_post_russian_post_id` (otpravka `result-id`), `_wc_russian_post_latest_order_update_time`, `_wc_russian_post_tracking_history` (array of history items) | order.php:512-535; checkout.php:580; tracking-email.php:73-79 |
| Order shipping-item meta | `wc_russian_post_rate` = array{`method_type` courier\|postal, `rate` (API `stdClass`: `shipment_type`,`price`,`delivery_interval`…), `normalize_address` (**serialised `Address_Normalize`**), `delivery_point` (array incl. a serialised `Address_Normalize`)} | abstract-shipping-methods.php:528-534; checkout.php:578-595 |
| User meta | `wc_russian_post_customer_delivery_point` (array incl. serialised `Address_Normalize`) — same string is the WC session key and the `WC_Data` object type | customer-delivery-point-data.php:33; customer-data-store.php:28,47; session store:20 |
| Product meta | `_tnved_code` | products-admin.php:34,47; order-item.php:63 |
| Transients | `wc_russian_post_site_settings` (24 h), `wc_russian_post_delivery_point_data_{id}` (week), `wc_russian_post_tracking_history_{barcode}`, `wc_russian_post_setup_wizard_redirect`, plus API-response transients `woodev_wc_russian_post_api_response_{md5}` | entry:620; order.php:57,428,433; lifecycle.php:44; abstract-cacheable-api-base.php:67 |
| Cron / Action Scheduler hook | `wc_russian_post_orders_update` (recurring, 12 h, group none) | entry:290-294; functions.php:517 |
| `wc-api` endpoints (registered with Russian Post at activation as `barcode_link`/`status_link`) | `?wc-api=russian_post_barcode`, `?wc-api=russian_post_status` | webhooks.php:10-11; install-module.php:36-41 |
| AJAX actions | `get_post_office` (priv+nopriv), `set_russian_post_point` (priv+nopriv, nonce `wc_russian_post_point_notice`), `russian_post_order_action` (priv; nonce `russian-post-order-action`), `russian_post_set_customer_location` (priv+nopriv, CDEK only) | ajax.php:13-19; integration-edostavka.php:26-27 |
| Admin page | `admin.php?page=wc_russian_post_orders` (submenu of `woocommerce`, cap `manage_woocommerce`); screen option `wc_russian_post_orders_edit_per_page`; list query args `russian_post_order_status`, `russian_post_status` | admin.php:24,121-128,86-92 |
| E-mail | WC email id `wc_russian_post_tracking_email` → option `woocommerce_wc_russian_post_tracking_email_settings` (+ field `message_text`); templates `emails/tracking-number.php`, `emails/plain/tracking-number.php` (theme-overridable under the plugin template path) | tracking-email.php:16,26-27,98 |
| Public filters | `wc_russian_post_{parcel_types, method_title, rates_item, rate_cost, formatted_address, shipping_rate_cart_total, cart_content_total, checkout_script_params, suggestions_plugin_default_params, additional_info_strings, need_reload_shipping_methods, integration_form_fields, order_statuses, allow_country_codes, clear_address_part, clear_address_part_patterns, tracking_url, tracking_update_interval, max_orders_per_update, order_needs_insurance_cost, order_otpravka_export_data, order_otpravka_export_item_data, order_otpravka_translate_fields, order_widget_export_data, disabled_statuses_for_{export,complete,delivering,canceled}}`, `wc_russian_mail_category` (sic, no `_post_`), `woocommerce_shipping_wc_russian_post_{courier\|postal}_is_available` | grep of `apply_filters` in `includes/` |
| Public actions | `wc_russian_post_{rate_calculate_shipping, order_tracking_number_changed, order_status_changed, order_status_changed_to_{s}, order_status_changed_from_{a}_to{b}` (sic: missing `_` before `to`, order.php:187), `success_exported_order, after_order_update}`; framework `woodev_wc_russian_post_{installed,updated,milestone_reached}` | order.php:149-193,280,348 |
| Removed-in-1.2 hooks (deprecator list) | 10 names in entry:195-240 | entry:195 |
| Filters on core | `woocommerce_validate_postcode` (RU = `^[1-9]\d{5}$`, **site-wide**, entry:307-312) | entry:90 |
| REST routes | **none** (no `register_rest_route`) | grep |
| Custom tables | **none** | grep |
| Order list table, internal ids | row id `wc-russian-post-order-{id}`, status CSS `status-russian-post-{status}` (CSS contract for themes) | order-list-table.php:532; meta-box tpl |
| Internal order statuses (meta, not WC statuses) | `new, exported, accepted, dispatched, failed, delivered, canceled` | functions.php:158-168 |
| Uninstall | deletes `wc_russian_post_%` and `woodev_wc_russian_post_%` options only; **not** the main settings option, instance settings, order meta | uninstall.php:11-12 |

## 3. Settings

### 3.1 Global (`woocommerce_wc_russian_post_settings`; integration.php:51-339; defaults as coded)

| Key | Type / default | Notes |
|---|---|---|
| `account_name` | text, none; validated email-or-phone | integration.php:59,357 |
| `account_password` | **text input (plain)**, none | :72 |
| `account_token` | text, none | :84 |
| `minimum_weight` / `_height` / `_width` / `_length` | text, 500 g / 150 / 150 / 150 mm | :104-126 |
| `dadata_api_key` | text, none (suggestions don't work without it) | :133 |
| `enable_state_suggestions`, `enable_city_suggestions`, `enable_address_suggestions`, `fill_postcode_by_address` | checkbox, `yes` ×4 | :139-166 |
| `hide_fields` | checkbox, `yes` — hide address+postcode for "to post office" | :167 |
| `auto_close_map` | checkbox, `yes` | :178 |
| `map_button_place` | select `under_methods` \| `inside_method`, default `under_methods` | :185 |
| `export_type` | select `otpravka` \| `widget`, default `otpravka` | :200 |
| `auto_export_orders` | checkbox, `no` | :211 |
| `export_statuses` | multiselect of WC statuses minus completed/cancelled/refunded/failed (filterable) | :218 |
| `vat_rate` | select `-1` (no VAT), `0`,`10`,`20`,`22`; default `-1`. (Wizard offers only up to 20 — setup-wizard.php:411-416) | :237 |
| `enable_tracking` | checkbox, `no` — **only if `ext-soap`** | :270 |
| `tracking_login`, `tracking_password` | text (plain) | :276-287 |
| `tracking_complete`, `tracking_delivering`, `tracking_canceled` | select WC status or `none`, default `none` | :288-331 |
| `enable_debug_mode` | checkbox, `no` | :255 |

### 3.2 Per method instance (courier and postal, abstract-shipping-methods.php:79-171)

`title` (default «Russian Post Delivery», `%parcel_type%` placeholder) · `min_price`, `max_price`, `free_cost` (price, empty = off) · `coupon_free_shipping` (checkbox `no`, only shown when WC coupons are enabled) · `show_delivery_time` (`no`) · `description_rate` (textarea) · `shipping_class_id` (`any`\|`none`\|term id; default `any`) · `allowed_shipment_type` (`any` or one parcel type; courier list: ONLINE_COURIER, EMS, BUSINESS_COURIER, BUSINESS_COURIER_ES, ECOM_MARKETPLACE_COURIER; postal list: POSTAL_PARCEL, ONLINE_PARCEL, EMS_OPTIMAL, PARCEL_CLASS_1, ECOM_MARKETPLACE, ECOM, COMBINED, SMALL_PACKET) · `allowed_payments` (gateway ids; empty = all).

### 3.3 Values read from the Russian Post *site settings* (cms.pochta.ru account, cached 24 h; functions.php:596-631)

`senderZipCode`, `transportType`, `defaultBoxSize`, `useFragile`, `useFragileCourier`, `useSmsAlertByDefault`, `useSmsAlertByDefaultCourier`, `useCartValue`, `useCartValueCourier`, `shipmentPriorities[]` (id/shipmentType/enabled/priority), `lastUpdate` (added locally). These are configured **in the merchant's Russian Post account, not in WordPress** — a second configuration surface the merchant must keep in sync (admin notice entry:362-377 nags if unset).

## 4. `includes/integrations/integration-edostavka.php` and CDEK

**What it does** (integration-edostavka.php; integrations.php:22):
- Activated only if plugin file `woocommerce-edostavka.php` is active AND constant `WC_CDEK_SHIPPING_VERSION >= 2.2` (integrations.php:22; again `version_compare( …get_version(), 2.2 )` at integration-edostavka.php:20).
- (a) Filter `wc_russian_post_integration_form_fields`: disables RP's four suggestion toggles when CDEK's suggestions are active (`wc_russian_post_is_allow_edostavka_address_suggestions`, functions.php:58-77).
- (b) `woocommerce_cart_shipping_packages` @25: if package has no postcode but `$package['edostavka_customer_location']['city_code']` exists, calls `wc_edostavka_get_location_postcodes( city_code )` and injects the best-match index (integration-edostavka.php:68-92).
- (c) Forces `__return_true` on `wc_edostavka_checkout_hidden_or_remove_state_field` and `…_postcode_field` (:23-24).
- (d) AJAX `russian_post_set_customer_location` (priv+nopriv): resolves a city through `CDEK->get_api()->get_location_cities()` and writes CDEK's customer location via `get_customer_handler()->set_location()` (:94-145). Called from address-suggestions.js:92 when `function_exists('wc_edostavka_set_customer_location')`.
- (e) After a pickup point is chosen, ajax.php:69-122 writes CDEK location (`wc_edostavka_get_location_cities`, `wc_edostavka_get_locale`, `get_customer_handler()->set_city_code/set_country_region/set_region_code…`, and reads CDEK setting `enable_dropdown_city_field`).
- (f) checkout.php:90,97,118; map.js:75-91: `wc_edostavka_has_fee_payments`, `wc_edostavka_set_customer_location`, global JS object `window.checkoutView` (CDEK's "related fields" Backbone model).
- Bugs inside it: `$data` is read at :97 before it is assigned at :101; `$data['city'] || $data['settlement']` at :109 yields a boolean, not a city.

**Existence in CDEK v2** — checked 21.10.2026 by `grep -rnF` over `<local woocommerce-edostavka checkout>` (branch `v2`, HEAD `dad3b9b` «CDEK 2.3.0.4», `includes/`, `assets/`, entry file; excluding `vendor/`, `tests/`, `woodev/`):

| v1 dependency | In CDEK v2? | Evidence |
|---|---|---|
| constant `WC_CDEK_SHIPPING_VERSION` | **No** — v2 defines `WOODEV_EDOSTAVKA_VERSION` (and `WC_CDEK_SHIPPING_DEVELOPER_KEY`) | woocommerce-edostavka.php:22-25 |
| function `wc_edostavka_shipping()` and class `WC_Edostavka_Shipping` | **No** (no hits) | grep |
| `wc_edostavka_get_location_postcodes`, `wc_edostavka_get_location_cities`, `wc_edostavka_set_customer_location`, `wc_edostavka_get_locale`, `wc_edostavka_has_fee_payments` | **No** (no hits) | grep |
| `->get_api()->get_location_cities()`, `->get_customer_handler()` (+ setters) | **No** | grep |
| package key `edostavka_customer_location` | **No** (only the legacy order meta `_wc_edostavka_customer_location` is read for old orders: orders/class-cdek-order-city.php:21,110) | grep |
| filters `wc_edostavka_checkout_hidden_or_remove_state_field` / `…postcode_field` | **Removed**: listed in the deprecated-hook table with replacement `null` | includes/class-plugin.php:1229-1230 |
| action `wc_edostavka_set_customer_location` / `wc_edostavka_customer_location_data` | **Renamed** → `woodev_customer_location_saved` (framework `Customer_Location_Store::ACTION_SAVED`) | class-plugin.php:1258-1259; woodev/shipping-method/location/class-customer-location-store.php:110 |
| CDEK settings keys `enable_dropdown_city_field`, `enable_suggestions_state/city/address` | `enable_dropdown_city_field`: **no v2 reader**; `enable_suggestions_*`: **no hits**. Settings option `woocommerce_edostavka_settings` still exists (includes/settings/class-cdek-settings.php:32 per `docs/migration-v1-table.md`) | grep; docs/migration-v1-table.md rows 13-14 |
| JS global `window.checkoutView` (Backbone "related fields" model) | **No** (v2 location UI is the framework's React/JS layer) | grep of `assets/` |

**Conclusion:** with CDEK v2 active the integration is inert (constant missing → `Integrations::$edostavka` stays `null`), and everything it did is now done by the **framework's shared location layer** (one customer location for all carriers, event `woodev_customer_location_saved`). A Russian Post v2 plugin needs no CDEK-specific integration; it needs to be a `Location_Provider`-aware carrier (§5). The only cross-plugin concern left is two carriers' suggestions fighting — which the framework layer owns.

## 5. Mapping onto framework v2

Reference read: `docs-internal/wiki/architecture.md` (sections «Shipping settings», «Checkout location layer», «Shipping orders page»), the class tree `woodev/shipping-method/` (Serena was **not** used — I never called its `initial_instructions`, so no session id; class names come from `grep ^class|interface` and directory listing, bodies were not read), and the CDEK v2 / Yandex plugin file lists (CDEK `includes/` = 85 files; Yandex `includes/` = ~13.8k lines). Whether a *specific field/behaviour* matches v1 is UNVERIFIED unless stated.

| v1 feature | Framework v2 equivalent (class / spec) | Gap for Russian Post |
|---|---|---|
| Plugin skeleton, licence, updater, lifecycle, milestones, logging | `Woodev\Framework\Shipping\Shipping_Plugin` (abstract `get_shipping_method_classes()`, `get_api()`), `Woodev_Plugins_License`, `Woodev_Lifecycle` | none; plugin-id/EDD id/licence option names must be kept (§2) |
| Courier method | `Shipping_Method_Courier` (final `get_delivery_type()` = `courier`) | none structurally |
| «To post office / postamat» method | `Shipping_Method_Pickup` + a carrier `Point_Source` (`pickup/interface-point-source.php`, `Abstract_Bulk_Point_Source`, `Location_Aware_Point_Source`) — NOT `Shipping_Method_Postal` (that base means "needs a customer postcode") | **Gap:** the source must wrap Russian Post's point catalogue. v1 gets points only from the *browser widget* (`ecomStartWidget`); no server-side point-list endpoint is used in v1 (`get_index_public`/`get_show_public` unused). Whether a server-side bulk endpoint exists is UNVERIFIED |
| Pickup map modal | `Pickup\Pickup_Handler` + `Map\Map_Provider` (`Embedded_Map_Provider`, `Yandex_Map_Provider`, `Map_Provider_Registry`), settings section «Карта» (`Pickup_Map_Settings`) | v1 embeds Russian Post's own widget; v2 draws points on the framework map. Needs a bulk/queryable point source; postamat vs office type, cell size S/M/L/XL, `mail_type` per point → `Pickup_Point` fields + `Constraint_Checker` (`pickup/class-constraint-checker.php`) — fit unverified |
| Per-customer chosen point (session + user meta) | `Pickup_Selection_Service` / `Pickup_Selection` / `Store_API_Pickup`, §8 checkout field layer | none (replaces `Customer_Delivery_Point_Data` + 3 data-store classes). Old user-meta/shipping-item meta needs a migration |
| Block checkout | framework block checkout support (`checkout/blocks`, `Store_API_Pickup`, `Checkout_Handler`) | v1 had none (`blocks` false) — a gain, but his note: block-only gaps don't block release |
| Address suggestions, city/region/address fields, postcode fill | `Location_Service`, `Location_Provider_Registry`, bundled `Dadata_Provider` (`location/providers/class-dadata-provider.php`), `Checkout_Field_Policy` / `Checkout_Field_Settings` (region/postcode `remove`, address `hide_for_pickup`), `address_suggestions` gate | none for the generic part; v1's own jQuery-suggestions + DaData key in page JS disappear. DaData key lives in framework settings (`woodev_location_*`) |
| Address normalisation (`clean/address`, ФИАС, quality code) | no direct equivalent: `Location_Record` (+ `Location_Adapter`) models locality; address cleaning is a carrier concern | **Gap:** keep a Russian-Post-specific address cleaner (`/1.0/clean/address`) behind a typed value object, with API-failure handled (v1 #4) |
| Rate calculation | `Shipping_Method` features `rate-cache`, `declared-value`, `cod`, `insurance`, `cost-limits`, `shipping-classes`, `box-packing`, `fee-payments` (class-shipping-method.php:45-106); `Shipping_Rate`, `Shipping_Rate_Cache`, `Rates_Controller` (REST), `Packaging`, `Box packer` (`woodev/box-packer`) | **Gap/decision:** the rate source (`widget` `*_tariff_public`) is a CMS-module endpoint tied to `guid_id`; alternative is otpravka's tariff API (not used in v1; availability UNVERIFIED). `Carrier_Service` (`class-carrier-service.php`) fits extra services (SMS, fragile) |
| min/max cart, free threshold, coupon free shipping, shipping class, allowed payments, title/description | `FEATURE_COST_LIMITS`, `FEATURE_SHIPPING_CLASSES`; payment filtering: Yandex `Yandex_Pickup_Guard`/CDEK `Cdek_Payment_Gateway_Filter` show the per-carrier pattern | exact parity of each field UNVERIFIED; "allowed shipment type" and `%parcel_type%` are carrier-specific |
| Order export (create shipment) | `Order\Abstract_Shipment_Handler`, `Export_Queue`, `Export_Retry`, `Order_Lock`, `Order_Automation` (auto-export on statuses), `Shipment_Fingerprint`, `Action_Result` | Two v1 modes (otpravka/widget) collapse to one: **decision needed**. Money must be built in minor units from `Carrier_Order_Line(s)` (fixes #5/#6) |
| Order cancel | `Shipment_Cancellation`, `Carrier_Cancel`, `Unchosen_Carrier_Cleaner`, `Cancelled_Order_Status` | none |
| Orders page (list, filters, bulk) | `Shipping orders page` in WC Admin React (`Orders_Controller`, `shipping/admin/orders/`) — one page for all carriers | v1's own WP_List_Table page and menu slug `wc_russian_post_orders` go away; no data contract (admin slug) in the sense of stored data, but bookmarks break |
| Tracking | `Abstract_Tracking_Handler`, `Delivery_Status` / `Delivery_Status_Watcher` / `Delivery_Sync_Status`, `Status_Sync_Tool`, `Abstract_Webhook_Handler`, `Delivery_Status_Email(s)`, `Shipment_Facts*` | **Gap:** Russian Post tracking is SOAP (`ext-soap`) with separate credentials; wrapper needed (WP HTTP can post SOAP envelopes — design choice). The 12 h cron → framework watcher |
| Tracking e-mail | `email/class-delivery-status-email(s).php` | v1 e-mail id `wc_russian_post_tracking_email` (settings option) is a data contract if the merchant customised it |
| Labels / documents (F7, F103) | `Document_Source`, `Bulk_Document_Source`, `Document_Type_Declarations`, `Document_Controller`, `Document_Result` | v1 has **none**; whether otpravka offers label/document endpoints is UNVERIFIED (not read). This is the biggest *new-capability* opportunity, not a port item |
| Setup wizard | `Woodev\Framework\Setup\Setup_Wizard` (React, UK-3/4), plus CDEK/Yandex `*_Migration_Wizard` as the v1→v2 pattern | v1 wizard replaced; a **migration wizard** is needed (the data in §2 must be carried) |
| Settings screen | `Woodev > Настройки` React page (`Settings_Provider`, `Settings_Group`), `Shipping_Integration` | v1's WC Integration tab goes away; **option key stays** (CDEK/Yandex carry settings "verbatim" in the same option — `woocommerce_edostavka_settings`) |
| Credentials handling | Yandex/CDEK `*-token-check`, settings handlers | Russian Post has *three* credential sets (otpravka token+login/password, tracking login/password, module guid). Plain-text storage should be revisited |
| Error reporter | `Woodev\Framework\Error_Reporting\Error_Reporter` | none |
| v1→v2 data migration | patterns: `Cdek_V1_Migration`, `Cdek_V1_Report`, `Cdek_V1_Census`, `Cdek_V1_Cleanup`, `Cdek_Wpdb_Zone_Method_Store` (CDEK `includes/lifecycle`, `includes/setup`; Yandex has `class-yandex-v1-*`) | all of §2 needs a migration plan; serialised `Address_Normalize` in meta |
| Shipping-method IDs/instances in zones | CDEK `Cdek_Wpdb_Zone_Method_Store` shows how v2 rewrites zone methods | must decide: keep ids `wc_russian_post_courier\|postal` or migrate |
| CDEK coexistence | framework shared customer location (`Customer_Location_Store`) | no integration code needed |

## 6. Code-quality red flags (concrete)

Severity = my reading of impact on a real shop; "by reading" = not executed.

| # | Flag | file:line |
|---|---|---|
| 1 | **No injected dependencies**: 98 calls of the global `wc_russian_post_shipping()` and 60+ `->get_settings_instance()->get_option()`; every layer reaches into plugin singleton and settings array | whole `includes/` |
| 2 | **God-object `Order extends \WC_Order`** (535 l.): domain state, API calls, e-mail triggering, status machine, tracking, meta — and constructed with `new Order($id)` all over (checkout.php:610; admin; webhooks) | order.php |
| 3 | **24 h cache on state-changing calls** (PUT export, DELETE cancel, GET refresh) — see §0.3 | api-request.php:10; api.php:261-296 |
| 4 | **Crash on API failure**: null → `Address_Normalize(array)` TypeError, ×5 copies of the same "build address string → clean → normalise" block, each building the address differently | abstract-shipping-methods.php:305; checkout.php:542; ajax.php:57; otpravka.php:115; widget.php:65 |
| 5 | **Money truncation**: `intval( $order->get_item_total() ) * 100` drops kopecks (199.99 ₽ → 19 900 kop instead of 19 999); `get_total() * 100` / `get_subtotal()*100` are unrounded floats sent as JSON; `intval(shipping_total)*100` | export-order/order-item.php:67; widget.php:36,44,109; abstract-export-order.php:98,111 |
| 6 | **`array_filter` strips legitimate zeros**: item `vat-rate => 0` (0 % VAT — offered in the settings) and other zero fields are removed from the payload; `fragile=false` etc. | otpravka.php:19,59,86,384 |
| 7 | **Cancel leaves the barcode**: `update_order_meta()` returns early on empty value (`! $meta_value`), so `set_tracking_number( null )` never clears `_wc_russian_post_tracking_number`; also `trim(null)` deprecation | order.php:512-516 vs :389,134 |
| 8 | **Webhook** auth by plain `!==` of a secret that arrives in the body; status value passes straight to `set_order_status` (TODO at :75); `get_api_error_code/message` are empty TODO stubs, so API errors read «Code: , Message: » | webhooks.php:44-50,75; api-response.php:15-27 |
| 9 | **Trust boundary**: `set_russian_post_point` stores `id`, `pvzType`, `mailType`, `indexTo`, `boxSize` straight from `$_POST['data']` (only nonce-checked, all `$data[...]` unguarded); the available `get_pvz_by_id()` is not used to confirm the point at selection time. Nonce is guest-visible by design | ajax.php:51-195 |
| 10 | **Open API proxy**: `wp_ajax_nopriv_get_post_office` forwards any postcode to `otpravka-api` with the merchant's credentials, no nonce, **no JS caller in the plugin** (dead and abusable — burns the account's request limit shown in settings) | ajax.php:13-41 |
| 11 | **Secrets**: account password, token, tracking password stored and shown as plain `text` inputs (`type => 'text'`); debug log writes request headers and only masks `Authorization` — **`X-User-Authorization: Basic base64(login:password)` is logged in clear** when debug mode is on; the dashboard link with `guid_id`+`guid_key` is e-mailed | integration.php:72-83; woodev/api/class-api-base.php:302-309 + api.php:212; lifecycle.php:94 |
| 12 | **Country-code table is wrong/duplicated**: 8 numeric codes are each used by two countries (784: AE+OM, 410: KP+KR, 534: SX+MF, 662: LC+RS, 760: SY+SK, 729: SD+SR, 258: PF+HR, 140: CF+TD) — computed from the file. Correct ISO 3166-1 numerics (OM 512, KP 408, MF 663, RS 688, SK 703, SR 740, HR 191, TD 148) are from memory — UNVERIFIED; check before use | functions.php:332,361,365-366,372-375,380-381,409-411,412-413 |
| 13 | Variation weight/dimensions taken from the **parent** product (`get_api_order_line_id()` = `get_product_id()`) | order-item.php:21-23; otpravka.php:318 |
| 14 | **Shipping-class filter never matches a specific class**: option is a string (`'12'`), `get_shipping_class_id()` is an int, compared with `!==` | abstract-shipping-methods.php:632 |
| 15 | With `allowed_shipment_type = any`, every returned tariff is added with the **same rate id** (`get_rate_id()` has no suffix); WC keys rates by id so the last wins (WC internals UNVERIFIED, not read) | abstract-shipping-methods.php:523-537 |
| 16 | **Blocking SOAP in cron**: `WSDL_CACHE_NONE` (WSDL downloaded on every call), no timeout, ≤50 orders sequentially; wizard text says «once a day», scheduler is 12 h | soap-client.php:24; tracking.php:35,136; functions.php:560; setup-wizard.php:467 |
| 17 | `sprintf` with 3 args for 2 placeholders → the order note names the *method title* instead of the new shipment type | checkout.php:588 |
| 18 | JS: `$.inArray( x, [ … ] === 0 )` — the `=== 0` sits inside the call (compares the array to 0), filter is meaningless; `'undefined' !== resetSelectedPlacemarkInEcomWidget` compares a string to the function (ReferenceError if the widget global is missing) | address-suggestions.js:212; map.js:176 |
| 19 | `Abstract_Customer_Data_Store::has_changes()` uses `array_intersect_key( get_data_keys(), array_keys( $changes ) )` (list vs list → index overlap, not key overlap): "has changes" is true whenever there is any change | abstract-customer-data-store.php:51 |
| 20 | Over-engineering: a `WC_Data` subclass + 3 data-store classes to persist one array (`Customer_Delivery_Point_Data` 227 l.); 12 interface files and 2 DTOs for 2 getters; `Install_Module`/`Uninstall_Module` read the same option | customer-delivery-point-data.php; interfaces/; DTO/ |
| 21 | **Reflected XSS in admin**: `$_GET['s']` echoed unescaped in the page header | admin.php:149-151 |
| 22 | Registration/side effects at activation & deactivation: synchronous remote `install`/`uninstall`, e-mail with secret link; `woocommerce_validate_postcode` overrides RU postcode rule **for the whole site** | lifecycle.php:38-104; entry:307-312 |
| 23 | **`uninstall.php` calls `WC()`** — not loaded at uninstall (fatal by reading); and it does not remove the main settings, instance settings or order meta | uninstall.php:8 |
| 24 | `set_cancelled_status` throws from a `woocommerce_order_status_cancelled` hook for RP orders in other internal statuses (WC swallows it into a note → Woo order cancelled while the RP shipment lives) | admin.php:277-288 |
| 25 | `before_delete_post` handler lives in `Products_Admin` and never fires on HPOS; `$_POST['_tnved_code']` saved unsanitised | products-admin.php:17,44,52 |
| 26 | `get_cart_total_for_order()` / `get_cart_subtotal_for_order()` are called but **not defined in the plugin**; whether WC provides them is UNVERIFIED (no WC source on this Mac) — if not, every Widget export and COD-less insurance branch fatals | order.php:153-159 → widget.php:36,44; abstract-export-order.php:111 |
| 27 | `wc_russian_post_update_all_orders` passes a query with no placeholders to `$wpdb->prepare()` (WP `_doing_it_wrong`) and string-concatenates order types | functions.php:541-555 |
| 28 | Hook name typos baked into the public API: `wc_russian_mail_category`, `…_status_changed_from_{a}_to{b}` | abstract-export-order.php:177; order.php:187 |
| 29 | No tests, no CI config, no static analysis in the reference tree (none shipped); Russian strings hard-coded in PHP/JS next to a 1 808-line `.po` — mixed i18n | tree listing; map.js, integration.php:67-93 |
| 30 | Third-party hosts hit from the browser at checkout: `widget.pochta.ru`, DaData, `cdn.jsdelivr.net`, plus `wp.i18n`/jquery-confirm bundled from the framework | checkout.php:42,71,124 |

## 7. Not checked / UNVERIFIED

- **No code was executed**; all "by reading" items (#3, #4, #7, #14, #15, #23, #26) need a repro before being quoted to anyone.
- Russian Post API **documentation** was not read (no web fetch): endpoint availability today, whether `courier_tariff_public`/`pick_up_tariff_public` are supported for third-party use, whether otpravka offers tariff/label/document/«call courier» endpoints, current SOAP vs REST tracking, rate limits. Candidate sources for the next step: otpravka specification `https://otpravka.pochta.ru/specification` (cited in v1 comments, functions.php:10,441; abstract-export-order.php:149 — not opened by me), `https://tracking.pochta.ru/access-settings` (cited in v1).
- Framework mapping is by class names/docs; no framework method body was read (Serena session not established), so field-level parity (cost limits, payment filter, "pickup point" attributes) is UNVERIFIED.
- Block checkout, the 5 317-line vendored `jquery.suggestions.js`, `.po` strings, CSS, and the framework 1.3.3 bundle internals beyond licensing/API-base/lifecycle were not audited.
- Real-shop frequency of each bug is not known (e.g. how many shops use 0 % VAT, per-class shipping, non-RU destinations).

## Related

- [README.md](README.md) — consolidated fact base and the resolved contradictions.
- [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) — the operator's brainstorm agenda built on these reports.
