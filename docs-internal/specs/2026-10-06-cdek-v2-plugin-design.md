# CDEK v2 Plugin Design

**Date:** 2026-10-06  
**Target:** new line in kalbac/woocommerce-edostavka, version 2.3.0.0; write from scratch on a new branch and leave old master untouched.  
**Release rule:** ship to clients only after stages 1–4 reach full observable parity with 2.2.5.5.  
**Evidence:** code claims cite repository-relative file:line. Ranges identify the specific function, registration, constant or settings group. The old plugin under plugins-reference/ is read-only evidence.

## 1. 2.2.5.5 feature inventory

One row describes one observable feature, settings group, endpoint or action. Settings rows list exact stored fields. In this section, paths beginning includes/ are relative to plugins-reference/woocommerce-edostavka/.

| # | Surface | 2.2.5.5 behavior / setting | Evidence |
|---:|---|---|---|
| 1 | Checkout | Registers edostavka shipping method and integration settings. | plugins-reference/woocommerce-edostavka/woocommerce-edostavka.php:84-90,230-248; method id :438-440 |
| 2 | Checkout | Per-zone basics: title, tariff, sender_city, delivery_point, dropoff_address, services. | includes/class-wc-edostavka-shipping-method.php:94-150 |
| 3 | Checkout | Per-zone display: show_delivery_time, additional_time, rate_instruction. | includes/class-wc-edostavka-shipping-method.php:152-175 |
| 4 | Checkout | Per-zone price fields: fee, fee_type, fee_payments, static_price, free, round_cost, round_cost_range. | includes/class-wc-edostavka-shipping-method.php:177-248 |
| 5 | Checkout | Per-zone availability fields: shipping_class_id, min_price, max_price, location_limit, states, cities, payments, coupon_free_shipping. | includes/class-wc-edostavka-shipping-method.php:250-340 |
| 6 | Checkout | Calculates tariffs, applies fees/rounding/currency conversion and service/box costs; filters can alter quote request and rates. | includes/class-wc-edostavka-shipping-method.php:642-711,716-744,760-818,858-889 |
| 7 | Checkout | Global auth: api_login, api_password, auth status; further sections appear only when credentials exist. | includes/class-wc-edostavka-integration.php:87-116,833-835 |
| 8 | Checkout/export | Sender data: sender_company, sender_name, sender_email, sender_phone, sender_address. | includes/class-wc-edostavka-integration.php:118-158 |
| 9 | Checkout/export | True-seller data: seller_name, seller_inn, seller_phone, seller_ownership_form, seller_address. | includes/class-wc-edostavka-integration.php:159-205 |
| 10 | Checkout | Destination defaults and city choice: customer_default_city, enable_dropdown_city_field (all/zone/none/related), enable_custom_city. | includes/class-wc-edostavka-integration.php:207-248 |
| 11 | Checkout | Address control: hide_single_country, disable_state_field, disable_address_field, clean_address_field, disable_postcode_field. | includes/class-wc-edostavka-integration.php:250-299 |
| 12 | Checkout | Map options: map_type, map_button_position, action_button_color, choose_button_color, enable_fill_postcode_filed, show_search_field_on_map. | includes/class-wc-edostavka-integration.php:300-363 |
| 13 | Checkout | Package defaults default_weight/height/width/length; packing_method, boxes, unpacking_item_method, name_single_box, name_item_box. | includes/class-wc-edostavka-integration.php:364-449 |
| 14 | Checkout | DaData: dadata_token, dadata_secret, suggestions per state/city/address, reload_checkout_fields, fill_postcode_field, enable_detect_customer_location. | includes/class-wc-edostavka-integration.php:534-608 |
| 15 | Checkout | Global currency, disable_methods_on_cart, enable_debug options. | includes/class-wc-edostavka-integration.php:609-635,675-688 |
| 16 | Checkout | Adds/refreshes city, state, address and postcode; requires selected pickup point and validates weight/dimensions/COD. | includes/class-wc-edostavka-checkout.php:314-385,519-602,692-744 |
| 17 | Checkout | DaData suggestions, postcode fill and geolocation populate locality/address. | includes/class-wc-edostavka-checkout.php:240-310; geo lookup includes/functions-api.php:290-310 |
| 18 | Checkout | Pickup office/postamat map, selected address, point choice, optional postcode fill; standard Yandex map or native CDEK widget. | includes/class-wc-edostavka-checkout.php:178-223,605-650 |
| 19 | Checkout | Saves chosen point, locality and selected point address into order meta and billing/shipping address. | includes/class-wc-edostavka-checkout.php:926-958 |
| 20 | Checkout | Alters address-field formatting/refresh and filters state/postcode display. | includes/class-wc-edostavka-checkout.php:12,314-383,585-600 |
| 21 | Checkout | Modifies local-pickup availability/settings and checkout address fields. | includes/class-wc-edostavka-local-pickup-shipping-modifier.php:8-20,90-119 |
| 22 | Checkout | Customer location stores country, region, city/code, coordinates and timezone in WC customer/session stores. | includes/class-wc-edostavka-customer-location-data.php:33-69,89-108; session group includes/data-stores/class-wc-edostavka-customer-session-data-store.php:49-60 |
| 23 | Checkout endpoint | Authenticated AJAX edostavka_get_tariff_by_code. | includes/class-wc-edostavka-ajax.php:11-12 |
| 24 | Checkout endpoint | Authenticated and guest AJAX edostavka_get_location_cities. | includes/class-wc-edostavka-ajax.php:13-14 |
| 25 | Checkout endpoint | Authenticated and guest AJAX get_related_location_cities. | includes/class-wc-edostavka-ajax.php:16-17 |
| 26 | Checkout endpoint | Authenticated and guest AJAX edostavka_get_deliverypoints. | includes/class-wc-edostavka-ajax.php:19-20 |
| 27 | Checkout endpoint | Authenticated and guest AJAX edostavka_set_customer_location; also WC AJAX. | includes/class-wc-edostavka-ajax.php:22-23,30 |
| 28 | Checkout endpoint | Authenticated and guest AJAX edostavka_set_customer_location_model. | includes/class-wc-edostavka-ajax.php:25-26 |
| 29 | Checkout endpoint | WC AJAX edostavka_set_customer_location_dadata. | includes/class-wc-edostavka-ajax.php:31-34 |
| 30 | Checkout endpoint | WC AJAX edostavka_get_offices_for_widget. | includes/class-wc-edostavka-ajax.php:36 |
| 31 | Checkout endpoint | Guest and authenticated AJAX edostavka_set_customer_location_by_id. | includes/class-wc-edostavka-ajax.php:38-42 |
| 32 | Checkout endpoint | Guest and authenticated AJAX edostavka_set_delivery_point. | includes/class-wc-edostavka-ajax.php:44-45 |
| 33 | Shop endpoint | Authenticated AJAX edostavka_order_action dispatches export/update/cancel. | includes/class-wc-edostavka-ajax.php:47; handler :457-534 |
| 34 | Shop endpoint | Authenticated AJAX edostavka_create_courier_call requests pickup. | includes/class-wc-edostavka-ajax.php:48; handler :358-422 |
| 35 | Shop endpoint | Authenticated AJAX edostavka_get_order_details returns preview data. | includes/class-wc-edostavka-ajax.php:49; response :534-560 |
| 36 | Shop/admin | Dedicated WooCommerce CDEK orders page: status views, search, page size, sort, bulk export/update/cancel and row actions. | includes/admin/class-wc-edostavka-admin.php:58-85,148-235,286-301; table includes/admin/class-wc-edostavka-order-list-table.php:33-108,284-334 |
| 37 | Shop/admin | Order table shows order/customer/address, shipping/payment, carrier status/tracking, tracking link, preview, export/update/waybill/barcode/cancel. | includes/admin/class-wc-edostavka-order-list-table.php:128-197,205-237,280-334 |
| 38 | Shop/admin | Order editor metabox shows CDEK status/point and shipment actions. | includes/admin/class-wc-edostavka-admin.php:104-134 |
| 39 | Shop/admin | Orders page renders order-preview and courier-call modals. | includes/admin/class-wc-edostavka-admin.php:356-367; preview template templates/views/html-order-preview-modal.php:1-70 |
| 40 | Shop/admin | Generates/downloads waybill and barcode PDF; barcodes_format setting. | includes/admin/class-wc-edostavka-admin.php:397-445; setting includes/class-wc-edostavka-integration.php:455-468 |
| 41 | Shop/admin | Order search indexes _wc_edostavka_status and _wc_edostavka_tracking_code. | includes/admin/class-wc-edostavka-admin.php:536-543 |
| 42 | Shop/admin | Cancels carrier shipment on order deletion/cancellation; order item edits/removals invalidate shipment data. | includes/admin/class-wc-edostavka-admin.php:304-354; main hooks woocommerce-edostavka.php:91-93,378-430 |
| 43 | Shop/admin | Auto-export settings: order_prefix, barcodes_format, auto_export_orders, export_statuses, vat_rate, delivery_vat_rate; configured order statuses trigger export. | includes/class-wc-edostavka-integration.php:450-533; hooks woocommerce-edostavka.php:95-110,491-503 |
| 44 | Shop/admin | Export eligibility, order/status transitions and email preview can be customized via plugin hooks. | includes/class-wc-edostavka-order.php:58-75,280-292,438-447; preview admin/class-wc-edostavka-admin.php:490-530 |
| 45 | Background | Periodic status refresh settings: auto_update_orders, cron_auto_update_orders, status_delivered, cron_auto_update_orders_interval, cron_update; manual update control. | includes/class-wc-edostavka-integration.php:636-674; cron includes/class-wc-edostavka-cron.php:24-78 |
| 46 | Background | Inbound order_status webhook refreshes CDEK status and maps delivered to WC status. | includes/webhooks/class-wc-edostavka-order-webhook.php:17-69; callback base includes/webhooks/abstract-wc-edostavka-webhook.php:28-36 |
| 47 | Background | print_form webhook receives async print completion; download_photo is registered but process_request() is empty. | includes/webhooks/class-wc-edostavka-print-form-webhook.php:9-16; class-wc-edostavka-download-photo-webhook.php:9-14 |
| 48 | Background | Creates, queries and deletes remote CDEK webhooks; saves their ids. | includes/webhooks/class-wc-edostavka-webhook-handler.php:36-105 |
| 49 | Buyer email | Tracking email includes tracking number and CDEK tracking link. | includes/emails/class-wc-edostavka-tracking-email.php:13-79 |
| 50 | Buyer email | Delivered order email. | includes/emails/class-wc-edostavka-delivered-email.php:13-67 |
| 51 | Buyer email | Not-delivered email. | includes/emails/class-wc-edostavka-not-delivered-email.php:13-59 |
| 52 | Buyer email | HTML/plain templates use WooCommerce email template hooks. | includes/emails/abstract-wc-edostavka-email.php:96-119; concrete template paths in three email class files:13-20 |
| 53 | Background | Editing/removing order items invalidates shipment data so it can be re-exported. | woocommerce-edostavka.php:91-93,378-430 |
| 54 | Admin | Help tabs on settings and CDEK order pages, configurable by wc_edostavka_enable_admin_help_tab. | includes/admin/class-wc-edostavka-admin.php:48-52; includes/admin/class-wc-edostavka-admin-help.php:14-54 |

**Blocks checkout:** Search of legacy PHP/JS for woocommerce_blocks, IntegrationInterface and register_block_type returned no hits. No block support was verified; legacy checkout is attached through classic hooks (includes/class-wc-edostavka-checkout.php:11-36). Framework has locality and pickup Block integrations (woodev/shipping-method/checkout/blocks/class-locality-blocks.php:69-116; class-pickup-blocks.php:68-112). Stage 1 must support Blocks as well as classic checkout, a new capability beyond legacy parity.

## 2. Feature → framework seam map

| Feature | Stage | Framework seam (source) | Plugin work | GAP? |
|---|---:|---|---|---|
| Declare shipping methods | 1 | Shipping_Plugin::register_shipping_methods() is final and drops classes not extending Shipping_Method (woodev/shipping-method/class-shipping-plugin.php:448-490); Shipping_Method::calculate_shipping() is final (:402-456). | Register concrete subclasses and supply tariff calculations through supported seam. | No |
| CDEK quote, tariffs, price rules | 1 | Method lifecycle and rate object; API contract in woodev/shipping-method/api/interface-shipping-api.php:37-220 | Implement CDEK client, tariff/service mapping, price and failure policy. | No; carrier domain |
| Rate cache | 1 | get_rate_cache_context(), FEATURE_RATE_CACHE, explicit add_support() opt-in (class-shipping-method.php:1196-1240,1353-). | Include origin, destination, parcel, credentials and tariff config in key, then opt in. #786 s148 says opt-in. | No |
| Packing/default dimensions | 1 | woodev/box-packer/ and shipping-method/settings/class-default-dimensions-settings.php:39; method packing support class-shipping-method.php:1176-1189. | Translate cart data to CDEK parcels; migrate nonempty v1 defaults from grams/cm to store units in plugin lifecycle (decision #786 s148). | No |
| Location/address fields | 1 | Shipping_Plugin::get_location_service() (class-shipping-plugin.php:1911); shared location and checkout field services. | Declare required fields; bridge locality to CDEK ids only if API needs them. | No |
| Pickup source and selection | 1 | Shipping_Method::get_pickup_point_source() (class-shipping-method.php:996); Pickup_Handler, Point_Source, selection and REST controller. | Implement CDEK source, normalize points, constraints and selection. | No |
| Map and checkout UI | 1 | Map registry/provider and shared pickup UI; Blocks integration class-pickup-blocks.php:68-112. | Use CDEK points in shared Yandex map or CDEK iframe/widget if selected. | No |
| Classic/Blocks checkout persistence | 1 | Checkout fields and locality/pickup Blocks adapters under checkout/blocks/. | Configure required fields/validation and persist CDEK point. Legacy has no Blocks support. | No |
| Shared orders page/editor | 2 | Registry/provider, row builder/editor and REST controllers under shipping-method/admin/orders/ and rest-api/. | Register CDEK provider, marker/meta fields and shipment actions. | No |
| Manual shipment export | 2 | Abstract_Shipment_Handler::export() supplies idempotence/lock/reconcile/retry; sole abstract response mapper extract_carrier_order_id() (order/abstract-shipment-handler.php:221-260,392-512,902-910). | Extend; implement abstract mapper, CDEK request and response mapping, carrier id/tracking storage. | No |
| Auto-export by order status | 2 | Order_Automation queues configured status export/cancel actions (order/class-order-automation.php:50-182). | Configure trigger statuses and handler; default OFF per decisions §12. | No |
| Retry/unknown export outcome | 2 | Abstract_Shipment_Handler, Export_Retry and Order_Lock (abstract-shipment-handler.php:392-512; class-export-retry.php:276-363; class-order-lock.php:47-83). | Map carrier exceptions and reconciliation. | No |
| Shipment cancellation | 2 | Abstract_Shipment_Handler::cancel/cancel_under_lock and Shipment_Cancellation (abstract-shipment-handler.php:764-865; class-shipment-cancellation.php:51-124). | Map CDEK cancel endpoint/refusal. | No |
| Waybill/barcode/download | 2 | Search rg -ni "print|waybill|document|courier|intake" woodev/shipping-method found no production document source/download class. Decisions §12 describes get_document(type) and binary/URL/local strategies, but code does not implement them. | Async print requests, callback completion, PDF retrieval, filename/download and downloaded state. | **Yes: document-source/download seam** |
| Courier call | 2 | Shared order action registry/execution in admin/orders/class-order-actions.php:39-49; framework search found no courier operation. | CDEK POST /intakes, action control and validation. | No; domain action |
| Tracking history | 3 | Abstract_Tracking_Handler::get_history() calls API; plugin implements map_events() (order/abstract-tracking-handler.php:68-212). | Map status events and preserve raw labels. | No |
| Canonical statuses | 3 | Delivery_Status provides canonical states/normalization (order/class-delivery-status.php:40-235). | Map CDEK codes and WC status transitions. | No |
| Inbound status webhook | 3 | Abstract_Webhook_Handler registers route, verifies signature, parses payload and dispatches. Concrete methods: verify_signature(), parse_payload(), get_namespace(), get_route() (order/abstract-webhook-handler.php:81-125,154-240). It does not update orders or dedupe carrier event ids (:154-180). | Implement four methods, subscribe/update order, dedupe and keep old callback during migration. | No; plugin duties |
| Scheduled polling | 3 | Delivery_Sync_Status records freshness but explicitly does not schedule/run carrier cron (order/class-delivery-sync-status.php:26-39,71-149). | Implement polling/cron and webhook reconciliation. | No; carrier responsibility |
| Buyer status emails | 3 | Search rg -n "WC_Email|woocommerce_email_classes" woodev found no shipping email base/status email. Order editor mail path is different (admin/orders/class-order-editor.php:1021-1056). | Add three status emails, templates, settings and dedup flags. | **Yes: status email framework** |
| Data migration | 4 | Generic framework lifecycle; old lifecycle maps integration and licensing options (plugins-reference/woocommerce-edostavka/includes/class-lifecycle.php:17-88). | Versioned/idempotent options, method/zones, customer/order/item data, webhooks and email settings. | No; plugin-specific |

### Visible shared behavior

Legacy supplies city AJAX and address-field switches (plugins-reference/woocommerce-edostavka/includes/class-wc-edostavka-ajax.php:13-42; class-wc-edostavka-checkout.php:314-383). Framework has shared DaData locality fields (woodev/shipping-method/location/class-location-service.php:28; checkout/class-checkout-fields.php:90), so suggestion order, cascade and labels may differ. Legacy renders CDEK/Yandex maps and saves office address (legacy class-wc-edostavka-checkout.php:178-223,926-958); framework uses normalized point source/shared picker (woodev/shipping-method/pickup/class-pickup-handler.php:100; rest-api/class-pickup-controller.php:80). Map/list presentation and selected-point display can visibly differ. Blocks support is new.

## 3. Installed-site data contract

ADR-005 says installed-site contracts are release-blocking (docs-internal/adr/005-platform-v2-clean-break-policy.md:18). Checklist is a starting point; values below were checked against legacy source. Unverified means no concrete value was established by source search.

| Contract | Exact value(s) | Verification/treatment |
|---|---|---|
| Identity/version | plugin/method edostavka; installed version 2.2.5.5; EDD download 216 | woocommerce-edostavka.php:23,33,438-440. Keep identity; new version decision is 2.3.0.0. |
| Main settings option | woocommerce_edostavka_settings | Read by bootstrap and cron (woocommerce-edostavka.php:95; includes/class-wc-edostavka-cron.php:30). Keep key. |
| Earlier integration option | woocommerce_edostavka-integration_settings → woocommerce_edostavka_settings | Lifecycle migration includes/class-lifecycle.php:17-29. |
| Other options | wc_edostavka_webhook_ids; wc_edostavka_shipping_fee_payments; wc_edostavka_upgraded_to_2_2_2_0; cdek_woocommerce_shipping_method_license_key; cdek_woocommerce_shipping_method_license | webhook ids includes/webhooks/class-wc-edostavka-webhook-handler.php:80-105; fee constant main:27,363-366; upgrade flag main:513/lifecycle:86; license read/delete lifecycle:74-83. Checklist omitted _license option; confirmed in source. |
| Bundled framework lifecycle options | woodev_edostavka_is_active; woodev_edostavka_milestone_messages; woodev_edostavka_milestone_version | These are derived from the plugin id in vendored framework lifecycle (plugins-reference/woocommerce-edostavka/woodev/class-lifecycle.php:130-175,381,513,528-540). Preserve state during framework updates; they are distinct from Edostavka settings. |
| Other direct option keys read | admin_email; date_format; time_format; woocommerce_enable_coupons; woocommerce_enable_shipping_calc; woocommerce_shipping_cost_requires_address; woocommerce_shipping_debug_mode; woocommerce_email_footer_text; dynamic woocommerce_local_pickup_{instance_id}_settings | These are WordPress/WooCommerce settings consumed by old screens/calculation/templates rather than Edostavka-owned migration options: integration.php:142; order-list-table.php:270; method.php:329,515,811,858; checkout.php:295; local-pickup-shipping-modifier.php:98,236; email templates plain/*.php:11,25. Keep framework/core behavior intact; no plugin migration needed. |
| Shipping method and instance settings | id edostavka; candidate option woocommerce_edostavka_{instance_id}_settings | ID main:438-440 and method constructor includes/class-wc-edostavka-shipping-method.php:13-16. Per-instance key form is only potential in checklist “WooCommerce Shipping-Zone Persistence”; no literal concatenation found. Verify live zone rows before cutover. |
| All order metadata keys from rg | _wc_edostavka_shipping; _wc_edostavka_status; _wc_edostavka_chosen_delivery_point; _wc_edostavka_customer_location; _wc_edostavka_cdek_order_id; _wc_edostavka_tracking_code; _wc_edostavka_can_courier_call; _wc_edostavka_courier_already_called | Checkout writes first four (includes/class-wc-edostavka-checkout.php:926-958); workflow id/status functions.php:1140-1155,1220-1245; search admin.php:536-543; courier flag ajax.php:500-510. Checklist missed shipping and courier keys. Preserve bytes and inspect nested schemas. |
| Shipping-item meta | edostavka_rate | Checkout reads for estimate (includes/class-wc-edostavka-checkout.php:433-445); preserve historical items. |
| Customer/session stores | customer-location; customer-location-session; group customer_location_{customer_id}; flag customer_location_was_changed | Registration woocommerce-edostavka.php:328-350; group includes/data-stores/class-wc-edostavka-customer-session-data-store.php:49-60; flag includes/class-wc-edostavka-customer-location-data.php:443-449. Checklist omits group/flag. |
| Customer-location fields | country_code, region_code, region, sub_region, city_code, city, longitude, latitude, timezone | includes/class-wc-edostavka-customer-location-data.php:89-108,130-270. |
| AJAX action names | edostavka_get_tariff_by_code; edostavka_get_location_cities; get_related_location_cities; edostavka_get_deliverypoints; edostavka_set_customer_location; edostavka_set_customer_location_model; edostavka_set_customer_location_dadata; edostavka_get_offices_for_widget; edostavka_set_customer_location_by_id; edostavka_set_delivery_point; edostavka_order_action; edostavka_create_courier_call; edostavka_get_order_details | Complete registrations includes/class-wc-edostavka-ajax.php:11-49; checklist only examples. |
| Plugin REST routes | None found; plugin PHP has no register_rest_route call. | Checklist wc/v3 assertion is discrepant/unverified as plugin route. Old webhooks are WC API callbacks. |
| Webhook routes | woocommerce_api_wc_edostavka_order_status; woocommerce_api_wc_edostavka_print_form; woocommerce_api_wc_edostavka_download_photo | Dynamic base includes/webhooks/abstract-wc-edostavka-webhook.php:28-36; resource constructors order webhook:17-20, print form:9-12, photo:9-12. Keep while remote callbacks point to them. |
| Cron names | wc_edostavka_orders_update hook; wc_edostavka_orders schedule | includes/class-wc-edostavka-cron.php:56-68; integration change handler includes/class-wc-edostavka-integration.php:42-54. Drain/bridge before removal. |
| Email ids | edostavka_tracking; edostavka_delivered_order; edostavka_not_delivered_order | Each concrete email class sets id on line 13. Ids derive from method id; preserve WC settings. |
| Email override paths | woocommerce/emails/edostavka/tracking-code.php; woocommerce/emails/edostavka/plain/tracking-code.php; matching delivered-email.php and not-delivered-email.php | Template base abstract email:20-22; plugin get_template_path woocommerce-edostavka.php:541-546; concrete relative paths in email classes:13-20; wc_get_template_html receives base abstract email:96-119. Checklist path is now source-confirmed. |
| Public filters/actions | woocommerce_edostavka_shipping_id; wc_edostavka_location_cities_sort_results; wc_edostavka_checkout_params; wc_edostavka_widget_map_params; wc_edostavka_native_widget_map_params; wc_edostavka_yandex_map_apikey; woodev_yandex_map_api_key; wc_edostavka_suggestions_plugin_default_params; wc_edostavka_checkout_hidden_or_remove_state_field; wc_edostavka_checkout_hidden_or_remove_postcode_field; wc_edostavka_method_instruction_text; wc_edostavka_method_additional_info_strings; edostavka_update_order_review_state_args; edostavka_update_order_review_address_args; edostavka_update_order_review_postcode_args; woocommerce_edostavka_cart_delivery_points_template; wc_edostavka_after_details_order_table; wc_edostavka_before_order_preview_table; wc_edostavka_after_order_preview_table; wc_edostavka_customer_allow_geo_location; wc_edostavka_customer_default_city_code; wc_edostavka_customer_location_data; wc_edostavka_shipping_method_delivery_points_request_params; wc_edostavka_delivery_rates_item_total_cost; wc_edostavka_delivery_rates_item; wc_edostavka_delivery_rate_calculate_shipping; wc_cdek_shipping_rates_calc_params; wc_edostavka_set_customer_location; wc_edostavka_order_preview_details; wc_edostavka_enable_admin_help_tab; wc_edostavka_create_order_waybill_params; wc_edostavka_create_order_barcode_params; wc_edostavka_integration_params; wc_cdek_shipping_integration_form_fields; woocommerce_edostavka_order_number; wc_edostavka_export_order_api_params; wc_edostavka_before_order_update; wc_edostavka_order_mark_as_delivered; wc_edostavka_before_order_update_save_meta; wc_edostavka_after_order_update; wc_edostavka_order_is_editable; wc_edostavka_order_is_delivered; wc_edostavka_order_status_changed; wc_edostavka_order_status_changed_from_{status}; wc_edostavka_order_status_changed_to_{status}; wc_edostavka_order_need_create_notice; wc_edostavka_order_statuses_to_create_notice; wc_edostavka_after_send_email_{email-id}; wc_edostavka_update_tracking_code; wc_edostavka_delete_tracking_code; woocommerce_edostavka_email_tracking_core_url; wc_edostavka_location_regions_list; wc_edostavka_excluded_location_region_ids; wc_edostavka_allowed_countries_for_state; woocommerce_edostavka_disallow_city_ids; wc_edostavka_preloaded_data_locations; wc_edostavka_is_admin; wc_edostavka_locale; wc_edostavka_currencies; wc_edostavka_customer_set_default_destination; wc_edostavka_customer_location_fields; wc_edostavka_extra_services_list; wc_edostavka_carton_boxes; wc_edostavka_order_statuses; wc_edostavka_status_keys_for_show; wc_edostavka_generate_delivery_point_button_args; wc_edostavka_order_package_item; wc_edostavka_order_package; wc_edostavka_disabled_statuses_for_export; wc_edostavka_shop_tariffs_data; wc_edostavka_delivery_tariffs_data; wc_edostavka_china_tariffs_data; dynamic woocommerce_shipping_{method_id}_{suffix} | Extracted by Python literal scan of apply_filters()/do_action() in plugin PHP, excluding bundled woodev. Sources: main id main file:438-440; checkout includes/class-wc-edostavka-checkout.php:62,151-164,193-219,304-306,364-383,472-512,585-600,645-646,1002; method includes/class-wc-edostavka-shipping-method.php:392-394,642,679-711,806; order includes/class-wc-edostavka-order.php:58-75,280-292,335-391,438-447; helpers includes/functions.php:18-23,148,210-224,341-350,400,523-551,637-672,778-827,1061-1073,1307; preview template templates/views/html-order-preview-modal.php:15,68; email abstract includes/emails/abstract-wc-edostavka-email.php:59-64. The email hook suffix is an email id. Email templates also call standard WooCommerce hooks woocommerce_email_header, woocommerce_email_order_details, woocommerce_email_order_meta, woocommerce_email_customer_details, woocommerce_email_footer and filter woocommerce_email_footer_text (templates/emails/:8-25). Preserve old plugin hooks; alias only if consumers justify it, never silently drop. |
| Admin identifiers | page wc_edostavka_orders; screen option wc_edostavka_orders_edit_per_page; action names edostavka_order_action, download_order_file | includes/admin/class-wc-edostavka-admin.php:74-85,286-301,369-445; order-list-table.php:284-334. |
| Block checkout | No legacy block registration/IntegrationInterface found. | Search exact terms woocommerce_blocks, IntegrationInterface, register_block_type across plugin PHP/JS. |

## 4. Verified framework gaps (candidate cards; do not file)

| Candidate title (Russian) | Body (Russian) | Stage blocked | Size |
|---|---|---:|---|
| Добавить общий источник документов перевозчика и скачивание файлов | В старом плагине магазин создаёт накладную или штрихкод, ждёт готовности файла от СДЭК, затем скачивает PDF из заказа. В решениях по модулю доставки описаны три способа получения документа, но поиск rg -ni "print|waybill|document|courier|intake" woodev/shipping-method не нашёл общего источника документа или безопасного скачивания. Без него новый плагин не сможет вернуть привычное получение файла через страницу заказов. | 2 | M |
| Добавить письма покупателю по статусу доставки | Старый плагин отправляет три письма: номер отправления, доставлено, не удалось доставить. Поиск rg -n "WC_Email|woocommerce_email_classes" woodev не нашёл писем перевозчика или общей основы; письмо редактора заказа решает другую задачу. Без этого покупатель не получит привычные сообщения, а каждому перевозчику придётся отдельно разрабатывать настройки, шаблоны и защиту от повторной отправки. | 3 | M |

Courier call is not a framework gap: shared order action plumbing exists, while POST /intakes is CDEK domain behavior. Polling/cron is carrier responsibility; Delivery_Sync_Status records freshness but deliberately does not schedule/run carrier cron (woodev/shipping-method/order/class-delivery-sync-status.php:26-39).

## 5. Plugin skeleton, loading, rig and verification

Use a fresh branch in kalbac/woocommerce-edostavka, code from scratch, version 2.3.0.0. Runtime is include-based; each production plugin ships a framework copy. Suggested structure:

    woocommerce-edostavka.php
    woodev/                         # pinned framework runtime
    includes/Plugin.php
    includes/Shipping/Shipping_Plugin.php
    includes/Shipping/Courier_Method.php
    includes/Shipping/Pickup_Method.php
    includes/Api/Cdek_Client.php
    includes/Api/Cdek_Auth.php
    includes/Location/Cdek_Location_Adapter.php   # if needed
    includes/Pickup/Cdek_Point_Source.php
    includes/Orders/Cdek_Shipment_Handler.php
    includes/Orders/Cdek_Tracking_Handler.php
    includes/Orders/Cdek_Webhook_Handler.php
    includes/Orders/Cdek_Document_Handler.php     # after gap 1
    includes/Emails/Tracking_Email.php             # after gap 2
    includes/Lifecycle.php
    tests/unit/ and tests/integration/

Use loader definitions with plain strings/paths. Do not refer to a framework class constant before loading the framework: PHP evaluates the definition array first (docs-internal/gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md:30-56). The pilot fixture tests resolver registration with the framework already loaded, not the real entry path (docs-internal/migration/edostavka-data-preservation-checklist.md:15-24); add a real loader-entry test.

Stage 1 classes: Plugin mounts framework extension and lifecycle; Shipping_Plugin declares methods/services; Courier_Method and Pickup_Method provide tariff behavior; Cdek_Client handles auth and API; Cdek_Point_Source supplies normalized points. Use provider settings for credentials/DaData and WooCommerce method instances for tariff, fee, packing settings per decisions §15 (docs-internal/specs/2026-06-25-shipping-module-decisions.md).

Rig: dev shop :8973, tests :8974, mount notes docs-internal/wiki/local-rig.md:296-327. Exact mount command for the future plugin checkout is unverified because its branch does not exist yet. Add it to existing dev rig after branch creation; document concrete mount in the plugin repo. Integration DB is single-flight.

Testing: Brain Monkey/Mockery unit tests for auth, endpoint mapping, quote parsing, tariff selection, packing, migration and error policy. WooCommerce integration tests for actual plugin entry loading, zone method persistence, HPOS/CPT order metadata, classic/Blocks checkout and migration. CDEK sandbox credentials and configurable test endpoint are required; CI uses a stub server, never live credentials. Rig acceptance compares buyer-visible rates and pickup selection in classic and Blocks. CI: plugin unit/integration, PHP style/static analysis and JS build/lint.

## 6. Stage 1 plan (worker-sized tasks)

### CDEK API v2 endpoints found in old client

Source: plugins-reference/woocommerce-edostavka/api/class-wc-edostavka-api-request.php; wrapper calls: api/class-wc-edostavka-api.php.

| Stage | Method/path | Actual purpose |
|---|---|---|
| 1–3 | POST /oauth/token | Client-credentials token using api_login/api_password; auth request api/class-wc-edostavka-auth-request.php:18-26; base API api/class-wc-edostavka-auth-api.php:9-30. |
| 1 | POST /calculator/tariff | Quote (request.php:7-12; wrapper api.php:23-27). |
| 1 | GET /deliverypoints | Pickup list/map (:14-19; wrapper :29-34). |
| 1 | GET /location/regions; GET /location/cities; GET /location/suggest/cities; GET /location/postalcodes | Region/city search and postal code (:21-47; wrapper :36-96). Share DaData where suitable, but CDEK IDs/capability data can require carrier lookup. |
| 2 | POST /orders; GET /orders/{uuid}; PATCH /orders; DELETE /orders/{uuid}; POST /orders/{uuid}/refusal | Create/read/update/cancel/refuse (:49-77; wrapper :99-139). |
| 2 | POST /intakes | Courier collection (:98-102; wrapper :179-184). |
| 2 | GET /print/orders/{uuid}; POST /print/orders; POST /print/barcodes; GET /print/{orders|barcodes}/{uuid}.pdf | Generate/download waybill and barcode (:104-134; wrapper :186-218); async params filtered :109-128. |
| 3 | POST /webhooks; GET /webhooks/{id}; DELETE /webhooks/{id} | Register/query/remove callback (:79-96; wrapper :141-177). |
| 3 | GET /orders/{uuid} | Pull status (:49-55; wrapper :106-110). |
| 3 | Separate tracking endpoint | No method in old class-wc-edostavka-api.php; old updater uses GET /orders/{uuid}. Other path is unverified. |

Auth/test: inspect includes/class-wc-edostavka-auth-api.php and token cache before implementation. Token URL and sandbox base URL are unverified by the request class. Keep API base configurable for tests; use CDEK sandbox credentials only, and stub network in CI. Read gotchas from rg -l test-cdek docs-internal/gotchas: fixture credentials are not shipping API credentials (the-cdek-fixture-credentials-are-not-the-option-they-look-like.md:8-48); mock locality can be geographically wrong (a-mocked-provider-proves-the-mock-not-the-contract.md:23-33).

| Task | Depends on | Observable acceptance |
|---|---|---|
| 1. Real loader, namespace map, framework pin and CI skeleton | — | Fresh checkout loads with Woodev_Loader::register() in integration test; no framework constant referenced before load. |
| 2. API/auth client and stub harness | 1 | Tests verify token cache/refresh, expiry, auth failure, timeout and stage-1 URL/method/body/query; no secret in logs. |
| 3. Shipping plugin and courier method | 1, 2 | Zone UI exposes CDEK method; framework accepts concrete Shipping_Method subclass; unconfigured method does not quote. |
| 4. Tariff mapping and rate/pricing | 2, 3 | Fixture responses produce eligible tariffs, prices and estimates; malformed/API errors hide rate and log safely; no stale fallback. |
| 5. Package dimensions and packing | 3 | Missing product dimensions use migrated defaults; parcel payload and quotes reflect supported boxes/pack mode. |
| 6. DaData locality to CDEK id bridge | 2, 3 | City/region/address map to valid CDEK location code; mismatched provider ids rejected; verified carrier data used, not test-cdek mock alone. |
| 7. Pickup source/constraints | 2, 3, 6 | Offices/postamats filter by city/tariff/parcel/COD; API failure differs from valid empty list. |
| 8. Classic checkout/order persistence | 4, 5, 7 | Rates and point selector appear; required point blocks submit; selected point/address/locality saved; capture visible differences. |
| 9. Blocks adapter | 7, 8 | Locality/point picker render; required point blocks submit; saved order data matches classic. |
| 10. Rate cache and rig acceptance | 4–9 | Cache context varies by origin, destination, parcel, account, tariff; verify classic and Blocks with unchanged and changed packages/cities. |

## 7. Operator decisions (s157, 06.10.2026)

1. **Address fields/locality search — framework fields.** The buyer sees the framework's shared
   locality fields (DaData, popular settlements, region → settlement cascade) in both classic and block
   checkout; the old CDEK city dropdown and "custom city" toggle are NOT rebuilt. Old field hide/show
   switches migrate into the framework's field settings where a counterpart exists; the plugin resolves
   the CDEK city code itself when quoting. Operator: «ради этого всё и задумывалось».
2. **Pickup office picker — framework map only.** The native CDEK widget is NOT offered (neither as the
   default nor as an option), although the framework's embedded-provider seam could host it. Shops on the
   widget today get the framework picker after the upgrade.
3. **Buyer status emails** are framework work (#714), not plugin work, and gain a fourth email
   «Заказ ждёт в пункте выдачи» on `ready_for_pickup` (default ON) — a change against §14.
4. **Carrier documents** (waybill/barcode) are framework work (#1134), blocking stage 2 only.
5. Settled earlier and not re-asked: old non-empty default dimensions migrate in the plugin lifecycle (s148).

## Related

- [Shipping module decisions](2026-06-25-shipping-module-decisions.md) — locked module boundaries for location, checkout, shipment and email.
- [Edostavka data-preservation checklist](../migration/edostavka-data-preservation-checklist.md) — initial contract, discrepancies corrected above.
- [Signature probe](../migration/signature-probe.md) — prior migration/API probe.
- [Framework architecture](../wiki/architecture.md) — subsystem and base-class reference.
- [V2 extension-point pattern](../wiki/v2-extension-point-pattern.md) — plugin loading and extension pattern.
- [Local rig](../wiki/local-rig.md) — current dev/test setup.
- [Clean-break policy](../adr/005-platform-v2-clean-break-policy.md) — internal API and installed-site contract policy.
- [Loader gotcha](../gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md) — load ordering.
- [CDEK credential gotcha](../gotchas/the-cdek-fixture-credentials-are-not-the-option-they-look-like.md) — fixture values are not API credentials.
- [Mock provider gotcha](../gotchas/a-mocked-provider-proves-the-mock-not-the-contract.md) — validate locality against carrier data.
