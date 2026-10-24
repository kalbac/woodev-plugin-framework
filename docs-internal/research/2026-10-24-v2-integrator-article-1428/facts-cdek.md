# Facts: CDEK v1 → v2 for developers/integrators (framework #1428)

Evidence notation: `v1:<path>:<line>` = branch `v1` (plugin 2.3.2, last v1) of `~/Projects/woocommerce-edostavka`;
`v2:<path>:<line>` = tag `v2.3.0.7` (latest tag; the card's v2.3.0.4 behaves the same for everything below);
`docs/...` = files in the same repo (read in the working tree on `main`).

## 1. Shipping method ids

| v1 id | v2 id | Evidence |
|---|---|---|
| `edostavka` (one WC method for all tariffs; `$method_id = 'edostavka'`) | `edostavka_courier` (tariff modes other than below), `edostavka_pickup` (modes 2, 4), `edostavka_postamat` (modes 6, 7) | v1:woocommerce-edostavka.php:58; v2:includes/lifecycle/class-cdek-v1-migration.php:65-78 (constants), :322-323 (`PICKUP_MODES=[2,4]`, `POSTAMAT_MODES=[6,7]`), `split_instances()` :971-1000 (target chosen by the instance's stored `tariff` → tariff mode; unknown/missing tariff → courier) |
| pre-2.2 only: `edostavka-<tariff>` (hyphenated rows) | not migrated; row left as is, journal `unsupported_origin` | v2:class-cdek-v1-migration.php (class docblock "MINIMUM_VERSION 2.2.0"); docs/migration-v1-table.md A61 |

Zone instances: the **same `instance_id` and zone are kept**; only the `method_id` column of `woocommerce_shipping_zone_methods` changes
(v2:class-cdek-v1-migration.php:971-1010, `$this->zones->change_method( $id, 'edostavka', $target )`). Instance settings array is copied to
the new option name: v1 `woocommerce_edostavka_<instance>_settings` → v2 `woocommerce_edostavka_courier_<instance>_settings` (or `_pickup_`/`_postamat_`);
v1 option stays for rollback (v2:class-cdek-v1-migration.php:2260 `instance_option()`: `'woocommerce_' . $method_id . '_' . $instance_id . '_settings'`; docs/migration-v1-table.md section B).

COD gateway restriction: WooCommerce setting `woocommerce_cod_settings` → `enable_for_methods` is rewritten: bare `edostavka` → the three new ids; `edostavka:<N>` → `<new id>:<N>`;
the filter `woodev_edostavka_upgrade_gateway_options` lists further gateway option names (v2:class-cdek-v1-migration.php:330, :1062-1100).

## 2. OLD orders' shipping lines

**Not rewritten.** The migration never writes `woocommerce_order_items` / `order_itemmeta` (only a read-only `SELECT … LIMIT 1` to detect that v1 orders exist):
`grep -n "order_items" class-cdek-v1-migration.php` finds nothing; the lone SQL is v2:includes/lifecycle/class-cdek-lifecycle.php:222-250 (`has_v1_cdek_shipping_order()`, SELECT only).
Old orders keep `method_id = edostavka` (and the old `instance_id`); new orders carry `edostavka_courier|pickup|postamat`.
The plugin itself accepts both: `Plugin::SHIPPING_METHOD_IDS = ['edostavka','edostavka_courier','edostavka_pickup','edostavka_postamat']` (v2:includes/class-plugin.php:72).
Rate snapshot meta on the shipping line, `edostavka_rate`, is unchanged in name (v1:includes/class-wc-edostavka-shipping-method.php:704; docs/migration-v1-table.md C1 row 14).
WC core filter `woocommerce_shipping_<id>_is_available` follows the id (v1:class-wc-edostavka-shipping-method.php:695 builds it from `$this->id`), so a third-party filter on `woocommerce_shipping_edostavka_is_available` no longer matches.

## 3. Order meta / options an integration could read

Everything below is "kept" unless stated; source: docs/migration-v1-table.md section C1, spot-checked in v2 code.

| Key | Fate | Evidence |
|---|---|---|
| `_wc_edostavka_shipping` (marker), `_wc_edostavka_status`, `_wc_edostavka_cdek_order_id`, `_wc_edostavka_tracking_code` | same key, same meaning | v2:includes/class-plugin.php:386-401, :649-651; migration-v1-table C1 rows 1-4 |
| `_wc_edostavka_chosen_delivery_point` (array per package) | v1 orders: still read as fallback. **New orders** write `_wc_edostavka_pickup_point_data` (normalized point) instead | v2:includes/class-plugin.php:75; v2:includes/orders/class-cdek-order-request.php:613-658 |
| `_wc_edostavka_customer_location` | read as legacy fallback; v2 resolves the city from the framework location | v2:includes/orders/class-cdek-order-city.php:110 |
| `_wc_edostavka_history_statuses`, `_wc_edostavka_latest_order_update_time` | v2 no longer reads/writes them (old values stay on old orders) | migration-v1-table C1 rows 7-8 (grep evidence) |
| `_wc_edostavka_can_courier_call`, `_wc_edostavka_courier_already_called` | not carried; courier calls ("вызов курьера") now through intake service | migration-v1-table C1 rows 9-10; v2:includes/orders/class-cdek-intake-service.php |
| `_wc_edostavka_tracking_number_email_send` | no v2 reader (tracking e-mail absent) — "unverified" in the table | migration-v1-table C1 row 13 → **не подтверждено** |
| Option `woocommerce_edostavka_settings` | same option, same keys for API login/password/sender/seller/order_prefix etc. (62 keys; 35 auto, 8 merchant decision, 19 dropped) | migration-v1-table section A |
| Option `wc_edostavka_webhook_ids` | kept in place | migration-v1-table A59 |
| Licence option `cdek_woocommerce_shipping_method_license_key` → `woodev_edostavka_license_key` | copied once (not an integration matter; internal) | v2:class-cdek-v1-migration.php `carry_license()` |

## 4. Public hooks: v1 → v2

Method: all v1 `do_action/apply_filters` with prefixes `wc_edostavka`, `wc_cdek`, `edostavka` listed with
`git grep -nE "(do_action|apply_filters)\(" v1 -- includes api templates` (71 names incl. dynamic families); each name searched in v2 with
`git grep -n -F "'<name>" v2.3.0.7 -- includes woocommerce-edostavka.php`.

**The plugin itself declares the map** (curated with the operator 12.10.2026): v2:includes/class-plugin.php `DEPRECATED_HOOK_REPLACEMENTS` (:1229-1270),
fed to the framework `get_deprecated_hooks()` (:1272-1290) with `removed=true`, `map=false` (arguments differ, so no automatic forwarding). With `WP_DEBUG` on, a
notice appears for each listed hook that still has a listener.

### Kept (same name)
| Hook | Type | v2 evidence |
|---|---|---|
| `wc_edostavka_yandex_map_apikey` | filter | v1:includes/class-wc-edostavka-checkout.php:63,236 → v2:includes/class-plugin.php:1063 (`apply_filters( 'woodev_yandex_map_api_key', apply_filters( 'wc_edostavka_yandex_map_apikey', $settings->get_map_fallback_key() ) )`); v1 default arg was the key string, v2 default arg is the fallback key from settings — same one-argument shape |
| `woodev_yandex_map_api_key` | filter | same line; unchanged |

### Replaced (declared replacement; arguments differ)
| v1 hook (signature) | v2 replacement (signature) | Evidence |
|---|---|---|
| `wc_edostavka_delivery_rates_item` filter `($rate_atts[id,label,cost,package,meta_data], $cdek_rate, $package, $method)` and `wc_edostavka_delivery_rates_item_total_cost` filter `($total, $cdek_rate, $package)` | `woodev_shipping_method_calculated_rate` filter `($rate, $package, $method)`; `$rate` is the framework `Shipping_Rate` object (return `null` = no rate), not an attributes array | v1:class-wc-edostavka-shipping-method.php:734,753; v2:class-plugin.php DEPRECATED map; website hooks-and-filters.md row `woodev_shipping_method_calculated_rate` |
| `wc_edostavka_delivery_rate_calculate_shipping` action `($cdek_rate, $package, $method)` | `woodev_shipping_method_after_calculate_shipping` action `($package, $rate, $method)` — argument order changed | v1:…shipping-method.php:766; hooks-and-filters.md |
| `wc_edostavka_method_instruction_text`, `wc_edostavka_method_additional_info_strings` | `woodev_shipping_rate_additional_info` filter `($blocks, $rate, $method, $index, $plugin)` | v1:class-wc-edostavka-checkout.php:496,541 |
| `wc_edostavka_shop_tariffs_data`, `…delivery_tariffs_data`, `…china_tariffs_data` | `woodev_edostavka_tariff_choices` filter `($options, $allowed_modes)` | v2:includes/shipping/class-cdek-tariff-catalog.php:504 |
| `wc_edostavka_export_order_api_params` filter `($params, $order_wrapper)` | `woodev_shipping_order_export_requested` **action** `($order)` — fires just before the create call; it cannot change the payload any more (no payload filter found) | plugin map; hooks-and-filters.md |
| `wc_edostavka_order_status_changed` action `($old_lower, $new_lower, $order_id)`; `wc_edostavka_order_mark_as_delivered` `($order_id, $status_slug)` | `woodev_shipping_delivery_status_changed` action `($order, $previous_status, $current_status, $provider)`; statuses are the framework canonical set (`pending, created, in_transit, ready_for_pickup, delivered, returning, returned, failed, cancelled, unknown`), not raw CDEK codes; `$order` is `WC_Order`; fires for every provider — check `$provider` | v1:class-wc-edostavka-order.php:83,530; v2:class-plugin.php:1242-1243; framework woodev/shipping-method/order/class-delivery-status.php:40-75 |
| `wc_edostavka_before_order_update`, `wc_edostavka_after_order_update` | `woodev_shipping_order_exported` action `($order, $carrier_order_id)` (different event: export, not a status poll) | plugin map |
| `wc_edostavka_order_statuses` filter | `woodev_shipping_delivery_status_resolved` filter `($canonical, $raw, $status_map)` | plugin map |
| `wc_edostavka_customer_location_data`, `wc_edostavka_set_customer_location` | `woodev_customer_location_saved` action `($record, $implicit, $user_id)` | plugin map |

### Removed (no replacement; the plugin map lists them with `null`)
`wc_edostavka_checkout_params`, `…widget_map_params`, `…native_widget_map_params`, `…suggestions_plugin_default_params`, `wc_edostavka_is_admin`,
`…checkout_hidden_or_remove_state_field`, `…checkout_hidden_or_remove_postcode_field`, `wc_cdek_shipping_rates_calc_params`,
`…shipping_method_delivery_points_request_params`, `wc_edostavka_disabled_statuses_for_export`, `wc_edostavka_order_package`, `…order_package_item`,
`wc_edostavka_update_tracking_code`, `…delete_tracking_code`, `wc_edostavka_after_send_email_*`, `wc_edostavka_extra_services_list`,
`wc_edostavka_carton_boxes`, `wc_edostavka_customer_default_city_code`, `wc_cdek_shipping_integration_form_fields`.
(For the form-fields filter the framework has `woodev_shipping_plugin_settings_{id}_form_fields` — `{id}` = `edostavka`; the plugin map says "no replacement",
so treat that as a possible equivalent, **не подтверждено** that field names match.)

### Removed and NOT in the plugin's map (no deprecation notice will fire) — grep shows no v2 occurrence (`git grep -F` on v2.3.0.7 = NONE)
`wc_edostavka_after_details_order_table`, `…before_order_preview_table`, `…after_order_preview_table`, `…order_preview_details`, `…enable_admin_help_tab`,
`…ajax_rate_limit`, `…allowed_countries_for_state`, `…api_base_url`, `…before_order_update_save_meta`, `…cod_payment_methods`, `…create_order_waybill_params`,
`…create_order_barcode_params`, `…currencies`, `…customer_allow_geo_location`, `…customer_location_fields`, `…customer_set_default_destination`,
`…excluded_location_region_ids`, `…generate_delivery_point_button_args`, `…integration_params`, `…locale`, `…location_cities_sort_results`, `…location_regions_list`,
`…order_is_delivered`, `…order_is_editable`, `…order_need_create_notice`, `…order_statuses_to_create_notice`, `…preloaded_data_locations`, `…print_form_ready`
(its sibling `woodev_edostavka_document_ready($type)` exists, v2:includes/documents/class-cdek-document-source.php:60 — a different signature, `($type)` only),
`…status_keys_for_show`, `edostavka_update_order_review_{state,address,postcode}_args`, and the dynamic families
`wc_edostavka_order_status_changed_from_{old}_to_{new}`, `…_changed_to_{status}`, `wc_edostavka_order_{status}_notification` (v1:class-wc-edostavka-order.php:73-80,533).
Note: v1 had a typo fixed in s15 (`…_to_` now with underscore) — irrelevant for v2.

### New in v2 (not v1-related, listed so the article does not call them replacements)
`woodev_edostavka_carrier_cost_changed`, `…delivery_date_changed`, `…delivery_issue`, `…status_sync_failed`, `…tariff_choices`, `…upgrade_gateway_options`,
`woodev_edostavka_export_confirmed($order,$uuid)`, `woodev_edostavka_intake_confirmed($order,$uuid)` (v2 only; v2:includes/tracking/class-cdek-shipment-facts-bridge.php,
includes/orders/class-cdek-shipment-handler.php:42, class-cdek-intake-service.php:49).

## 5. Endpoints / AJAX / cron

| Item | v1 | v2 | Evidence |
|---|---|---|---|
| Frontend AJAX (`wp_ajax[_nopriv]_`) | `edostavka_get_location_cities`, `get_related_location_cities`, `edostavka_get_deliverypoints`, `edostavka_set_customer_location[_model|_by_id]`, `edostavka_set_delivery_point`, `edostavka_get_tariff_by_code` | removed (checkout uses framework REST/blocks) | v1:includes/class-wc-edostavka-ajax.php:18-52; v2 grep finds only `woodev_edostavka_search_sender_city`, `…_sender_office` (admin) in class-plugin.php:244-245 |
| Admin AJAX | `edostavka_order_action`, `edostavka_create_courier_call`, `edostavka_courier_call_status`, `edostavka_get_order_details` | removed (framework orders page) | v1 :54-57; v2 grep NONE |
| Webhook endpoints | `?wc-api=wc_edostavka_order_status`, `…_print_form`, `…_download_photo` | `wc_edostavka_order_status` and `wc_edostavka_orders` still accepted by a legacy handler; `print_form`/`download_photo` **не подтверждено** (not found in v2.3.0.7) | v1:includes/webhooks/class-wc-edostavka-webhook-handler.php:200; v2:includes/tracking/class-cdek-webhook-handler.php:86-87 |
| Cron | WP-Cron `wc_edostavka_orders_update`, schedule `wc_edostavka_orders`; AS hook `wc_edostavka_run_courier_call` (group `wc_edostavka_courier_actions`) | AS recurring poller 900 s (WP-Cron fallback keeps the same hook name + schedule); migration clears the v1 cron hook and cancels pending v1 per-order actions | v2:includes/tracking/class-cdek-status-poller.php:25,67,140-143; class-cdek-v1-migration.php:123,1665-1692 |
| Action Scheduler group | `wc_edostavka_location_cities` | gone (v1 already deleted) | migration-v1-table C5 |

## 6. Gaps / not verified
- v1 side taken from branch `v1` (2.3.2); the `docs/migration-v1-table.md` figures speak of v1 = 2.2.5.5 — the branch is a later v1 maintenance build; hook list diffed only against `v1`.
- Dynamic hook names built with `sprintf` not enumerated beyond the 4 found.
- Framework-level (`woodev/` inside v1) hooks of the old bundled framework (`wc_edostavka_*` from `Woodev_Plugin`) were not inventoried.
