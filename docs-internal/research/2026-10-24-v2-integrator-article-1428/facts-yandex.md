# Facts: Yandex Delivery v1 (1.3.7) → v2 for developers/integrators (framework #1428)

Evidence notation: `v2:<path>:<line>` = tag `v2.0.8` of `~/Projects/woocommerce-yandex-delivery` (card says v2.0.5; v2.0.8 is the latest tag).
**There is no v1 branch or v1 tag in that repo** (history starts at the 2.0.0 skeleton) and no v1 source on this Mac.
The v1 side therefore rests on: framework `docs-internal/migration/yandex-data-preservation-checklist.md` (survey of the real v1 code, with file:line),
`docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md` (hook inventory),
framework `tests/_fixtures/legacy-v1/woocommerce-yandex-delivery/` (literal excerpts of v1 1.3.7), and the plugin's own v2 docs/code that handle v1 data.
Rows whose v1 side comes only from the survey docs are marked [survey].

## 1. Shipping method ids

| v1 id | v2 id | Evidence |
|---|---|---|
| `yandex_delivery_other_day` | `yandex_delivery_courier` when the instance's v1 `tariff` = `time_interval`; `yandex_delivery_pickup` when `tariff` = `self_pickup`; missing/unknown tariff → courier (journalled) | v2:includes/lifecycle/class-yandex-v1-planner.php:22-37 (constants), docs/sessions/s9.md:8, class-yandex-v1-migration.php:31 |
| `yandex_delivery_express` | **dropped**: the zone row is deleted (zone, enabled flag and order journalled for manual rollback); no v2 equivalent | class-yandex-v1-migration.php:31; planner `gateway_methods()` :181-209 |

Instance ids are kept for the switched rows (`other_day` → courier/pickup keep `instance_id`/zone). Instance settings are copied (never over existing v2 settings) from
v1 option `woocommerce_yandex_delivery_other_day_<id>_settings` to `woocommerce_yandex_delivery_courier_<id>_settings` / `…_pickup_<id>_settings`; the v1 option stays
(v2:class-yandex-v1-planner.php:219-221 `instance_option()`; class-yandex-v1-migration.php:431-470 `carry_instance_settings()`).
Replaced instance keys: `tariff`, `warehouse_id`, `show_time_interval`, `description_rate`, `show_commission` (planner :40; translation rules in docblock :118-122).

COD gateway restriction `woocommerce_cod_settings` → `enable_for_methods`: bare `yandex_delivery_other_day` → both new ids; `other_day:<N>` → `<courier|pickup>:<N>`;
express entries removed unless that would empty the list (empty = "all methods"); filter `woodev_yandex_delivery_upgrade_gateway_options` extends the option list
(planner `gateway_methods()` :181-209; class-yandex-v1-migration.php:101,410).

## 2. OLD orders' shipping lines

**Not rewritten.** Old orders keep `method_id = yandex_delivery_other_day` with the old instance id. Express orders keep `yandex_delivery_express`.
Evidence: docs/gotchas/v1-order-lines-keep-the-other-day-method-id.md ("The migration rewrites zone rows … and never touches order data … Do not register a legacy checkout method and do not rewrite the stored lines");
the plugin resolves such a line at export time through its instance (`Yandex_Order_Request::method_id()`, v2:includes/orders/class-yandex-order-request.php:31-50);
`Yandex_Order_Actions::register()` declares the historical id to the framework orders page (v2:includes/orders/class-yandex-order-actions.php:56).
Express lines and `other_day` lines whose instance was deleted: export refuses with «Способ доставки заказа больше не доступен.» (same gotcha file).
An integration that filters orders by `method_id` must therefore match: `yandex_delivery_other_day` (old), `yandex_delivery_courier`, `yandex_delivery_pickup` (new).

## 3. Order meta / options

| Key | Fate | Evidence |
|---|---|---|
| `_yandex_delivery_request_id` | kept | v2:includes/orders/class-yandex-shipment-handler.php:36; checklist "Order meta keys" [survey] |
| `_yandex_delivery_state_status` | kept (raw Yandex status) | v2:includes/tracking/class-yandex-delivery-status.php:17 |
| `_yandex_delivery_sharing_url` | kept (tracking link) | v2:includes/tracking/class-yandex-tracking-link.php:15 |
| `_yandex_delivery_destination_station_id`, `…_interval_from`, `…_interval_to` | v1 meta still read by the v2 export for old orders; `…_destination_station_address` not seen in v2 (**не подтверждено** whether read) | docs/gotchas/v1-order-lines-keep-the-other-day-method-id.md; v2:class-yandex-order-actions.php:62 (`pickup_point_meta_key`) |
| New v2 meta: `_yandex_delivery_carrier_price`, `_yandex_delivery_delivery_cost`, `_yandex_delivery_confirm_pending`, `_yandex_delivery_last_document_skip_note`; shipping-line meta `yandex_delivery_intervals` | new | v2:includes/shipping/class-yandex-pricing.php:29,41; class-yandex-shipment-handler.php:27; class-yandex-document-source.php:28; class-yandex-delivery-interval.php:35 |
| Option `woocommerce_yandex_delivery_settings` | same option; shared keys are copied to framework options, keys with no counterpart are reported: `disable_state_field`, `widget_type`, `map_type`, `widget_position`, `replace_address_fields`, `region_suggestions`, `city_suggestions`, `address_suggestions`, `autocomplete_customer_address` | v2:includes/lifecycle/class-yandex-v1-global-planner.php:91-101 |
| Warehouse table `{prefix}wc_yandex_delivery_warehouses` | left untouched, read once to copy `station_id`; no longer used | v2:includes/lifecycle/class-yandex-wpdb-warehouse-source.php:49; checklist D10 |
| Order `EXPORTING_PROCESS` | orders caught in this state are reported, not changed | class-yandex-v1-migration.php:31 (step 7) |

## 4. Public hooks: v1 → v2

v1 inventory [survey]: checklist "Public Hooks" and phase6a draft lines 119-120. v2 search: `git grep -n -F "<name>" v2.0.8 -- includes woocommerce-yandex-delivery.php`.
**The v2 plugin declares no deprecated-hook map** (`git grep get_deprecated_hooks v2.0.8` = nothing), so no `WP_DEBUG` notice appears for removed hooks.

### Kept
| Hook | Evidence |
|---|---|
| `woodev_yandex_map_api_key` (filter, 1 arg: key) | v2:includes/class-plugin.php:294-303 (kept on purpose "so a shop's customisation keeps working") |
| `woodev_yandex_delivery_http_request_args` (filter `($args, $api)`) | NOT a plugin hook: framework API client `apply_filters( 'woodev_' . $this->get_api_id() . '_http_request_args', $args, $this )` (framework woodev/api/class-api-base.php:2329) and `get_api_id()` returns the plugin id `yandex_delivery` (v2:includes/api/class-yandex-api-client.php:424, class-plugin.php:46). Present since the framework's first commit (`01dfbe76`). Whether the v1 plugin's API client used the same id: the card asserts so [survey/card] — **не подтверждено** by v1 source |

### Replaced (equivalent event exists in framework; names/args differ)
| v1 hook | v2 replacement | Evidence |
|---|---|---|
| `wc_yandex_order_status_changed($old, $new, $order_id)`, `…_changed_to_{status}`, `…_changed_from_{old}_to{new}`, `wc_yandex_order_mark_as_delivered`, `wc_yandex_order_mark_as_cancelled` | `woodev_shipping_delivery_status_changed($order, $previous, $current, $provider)` with canonical statuses (`delivered`, `cancelled`, `in_transit`, …) — the plugin extends `Abstract_Tracking_Handler` and maps raw Yandex statuses to canonical | v2:includes/tracking/class-yandex-tracking-handler.php:16, class-yandex-delivery-status.php:25-60; hooks-and-filters.md. That the poller path fires the action for each change is **не подтверждено** by a direct grep (the poller records through `Shipment_Facts`; only checked imports, class-yandex-status-poller.php:12) |
| `wc_yandex_delivery_rate_cost`, `wc_yandex_delivery_{id}_fallback_calculate_shipping` | `woodev_shipping_method_calculated_rate($rate,$package,$method)`, `woodev_shipping_method_rate_calculation_failed($exception,$package,$method)` | framework hooks-and-filters.md; v1 names [survey] |
| `wc_yandex_delivery_update_sharing_url`, `…delete_sharing_url` | none; the link is order meta `_yandex_delivery_sharing_url` | class-yandex-tracking-link.php:15 |

### Removed (v1 names from the survey; each is absent from v2.0.8 includes — grep NONE)
Actions: `wc_yandex_order_status_changed*`, `wc_yandex_order_mark_as_delivered`, `wc_yandex_order_mark_as_cancelled`, `wc_yandex_delivery_update_sharing_url`,
`wc_yandex_delivery_delete_sharing_url`, `wc_yandex_delivery_{id}_fallback_calculate_shipping`.
Filters: `wc_yandex_suggestions_plugin_default_params`, `wc_yandex_delivery_checkout_script_params`, `wc_yandex_delivery_after_shipping_rate_outputs`,
`wc_yandex_delivery_time_interval_params`, `wc_yandex_delivery_formatted_address`, `wc_yandex_delivery_clear_address_part_patterns`, `wc_yandex_delivery_clear_address_part`,
`wc_yandex_delivery_order_statuses`, `wc_yandex_delivery_cancel_reasons`, `wc_yandex_delivery_shipping_method_pricing_calculator_params`,
`wc_yandex_delivery_prepare_order_data`, `wc_yandex_delivery_rate_cost`, `wc_yandex_delivery_order_places`, `wc_yandex_disabled_statuses_for_export`,
`wc_yandex_delivery_integration_form_fields`, `wc_yandex_delivery_abort_cached_response`.
Search: loop of `git grep -c <name> v2.0.8 -- includes woocommerce-yandex-delivery.php` returned 0 files for each; the only `'wc_yandex_` strings left are migration option names and the three v1 queue hooks.
`wc_yandex_delivery_prepare_order_data` (the payload filter integrators used) has no equivalent; the framework only has the action `woodev_shipping_order_export_requested($order)`.

### New in v2 (not replacements)
`woodev_yandex_delivery_cod_payment_methods` (filter, class-yandex-rate-rules.php:70), `woodev_yandex_delivery_status_sync_failed` (poller), `woodev_yandex_delivery_upgrade_gateway_options` (migration).

## 5. Endpoints / AJAX / cron

| Item | v1 [survey] | v2 | Evidence |
|---|---|---|---|
| Cron: recurring `wc_yandex_orders_update` (AS, interval option `order_status_update_interval`), single `wc_yandex_update_order` payload `['order_id','slim']`, `wc_yandex_export_order` | as listed | all three are cancelled by the migration (step 7); replaced by AS poller hook `woodev_yandex_delivery_poll_status` | v2:class-yandex-v1-migration.php:94; tracking/class-yandex-status-poller.php:22 |
| Frontend AJAX `get_yandex_delivery_location_detect`, `get_yandex_delivery_shipment_points`, `set_yandex_delivery_pickup_point`, `set_yandex_delivery_time_interval` | present | absent (no `wp_ajax_` in v2 except the admin sender search `class-yandex-sender-ajax.php:60-62`); checkout state lives in framework | v2 grep |
| Admin AJAX `yandex_delivery_export_order`, `…update_order`, `…cancel_order`, `…print_label_order`, `…print_act`, `wc_yandex_delivery_get_order_offers`, `…confirm_order_offer` | present | absent | v2 grep |
| REST namespace `yandex-delivery`, routes `/warehouses`, `/warehouses/(?P<id>[\w-]+)` | present | warehouse routes absent (no `register_rest_route` in v2 includes; warehouses dropped, D10). The string `yandex-delivery` remains only as a Store API checkout namespace (class-yandex-interval-checkout.php:31) | v2 grep |
| Admin page slug `wc-yandex-orders` | present | kept as `legacy_page_slug` of the framework orders page | v2:class-yandex-order-actions.php:65 |
| Webhook | none (outbound only) | none | checklist; v2 spec §9.1 |

## 6. Gaps
- No v1 source: v1 signatures of `wc_yandex_*` hooks are not verified, only names. I did not guess argument lists; the draft states argument changes only where the framework side is known.
- Whether the card's "v2.0.5 tag" vs v2.0.8 changes anything here: no hook/id changes found between them in the migration files (not diffed).
