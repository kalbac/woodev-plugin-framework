# Facts: Russian Post v1 (1.2.6.0) → v2 for developers/integrators (framework #1428)

Evidence notation: `v1:<path>:<line>` = branch `v1` (commit 8cae17b, 1.2.6.0 as shipped) of `~/Projects/woodev-russian-post`;
`v2:<path>:<line>` = tag `v2.0.7`; `docs/…` = the repo's `docs/migration/russian-post-data-preservation-checklist.md` ("checklist") and `tier-a-state.md`.

## 1. Shipping method ids

| v1 id | v2 id | Evidence |
|---|---|---|
| `wc_russian_post_courier` | `russian_post_courier` (zone rows AND old order lines rewritten) | checklist "Shipping methods and zones"; v2:includes/order/class-russian-post-order-migration-writer.php:108 |
| `wc_russian_post_postal` | zone row → `russian_post_pickup` (one-to-one). Old order line → `russian_post_postamat` if the stored point type is `postamat`; `russian_post_international` if the destination country is not RU and no point is stored; otherwise `russian_post_pickup` | same writer :108 (nested ternary) ; docs/tier-a-state.md "Zone mapping is one-to-one" |
| (none) | `russian_post_postamat`, `russian_post_international` are new, no v1 zone row; migration screen offers to add the postamat method | checklist "New method ids"; v2:includes/shipping/class-russian-post-international-method.php:42 |

v1 ids: `$this->id = 'wc_russian_post_courier'` (v1:includes/classes/shipping-method-courier.php:12) and `'wc_russian_post_postal'` (v1:includes/classes/shipping-method-postal.php:12); the plugin id is `wc_russian_post` (v1:woodev-russian-post.php:66).
Instance ids and zone membership are kept; only `method_id` changes. Instance options are **copied**: `woocommerce_wc_russian_post_courier_<id>_settings` → `woocommerce_russian_post_courier_<id>_settings`, `woocommerce_wc_russian_post_postal_<id>_settings` → `woocommerce_russian_post_pickup_<id>_settings`; old options stay (checklist).
COD gateway `enable_for_methods` is rewritten (bare ids and `id:instance` forms), originals in journal option `wc_russian_post_upgrade_2_0_0` (docs/tier-a-state.md).

## 2. OLD orders' shipping lines — REWRITTEN (unlike CDEK/Yandex)

A background "Tier B" migration (Action Scheduler hook `woodev_wc_russian_post_migrate_orders_batch`, group `wc-russian-post-migration`; v2:includes/lifecycle/class-russian-post-order-migration-runner.php:33,40) converts every shipping line whose `method_id` is `wc_russian_post_courier|postal`:
- `$line->set_method_id( $method )` and adds line meta `_wc_russian_post_v1_method_id` = the original id (rollback marker) — v2:includes/order/class-russian-post-order-migration-writer.php:126-127;
- from the line's `wc_russian_post_rate` meta (serialized v1 object) it writes order meta `_wc_russian_post_pickup_point` and `_wc_russian_post_product_key` and converts the rate record to a plain array (writer :95-124 `convert()`/`persist_rate()`; checklist "Shipping-item, user and product meta");
- it runs in batches after the update, not instantly: for a period after the update, part of the orders may still carry the old ids (the runner state option `woodev_wc_russian_post_order_migration`, checklist "Options"). How long it takes depends on order count: **не подтверждено** (no timing measured).
So an integration that matches `wc_russian_post_courier|postal` loses old orders once the batch reaches them; match `russian_post_*` and, for old orders, optionally read `_wc_russian_post_v1_method_id`.
Refunds' shipping lines are converted too (writer :102,:140 handle `WC_Order_Refund`).

## 3. Order meta / options

| Key | Fate | Evidence |
|---|---|---|
| `_wc_russian_post_is_russian_post`, `_wc_russian_post_status`, `_wc_russian_post_export_type`, `_wc_russian_post_tracking_number`, `_wc_russian_post_tracking_number_email_sent`, `_wc_russian_post_russian_post_id`, `_wc_russian_post_latest_order_update_time`, `_wc_russian_post_tracking_history` | kept, same names. Stored status values `new, exported, accepted, dispatched, failed, delivered, canceled` unchanged; v2 adds `ready, returning, returned` | checklist "Order meta"; v2:includes/tracking/class-russian-post-tracking-status.php:18 |
| New: `_wc_russian_post_address_doubtful`, `_group_name`, `_pickup_point`, `_pickup_point_id`, `_pickup_point_address`, `_product_key`, `_dimension_type`; line meta `_wc_russian_post_v1_method_id` | new in v2 | checklist; writer :126 |
| Shipping line meta `wc_russian_post_rate` | rewritten in place (serialized object → plain array) | checklist |
| Option `woocommerce_wc_russian_post_settings` | same option, **all 26 field names kept**; `export_type`, `enable_tracking`, `tracking_login`, `tracking_password`, `hide_fields`, map/suggestion switches dropped as settings (values stay in the option); `vat_rate` and `tracking_delivering` stay but are not read by v2 | checklist "Main settings fields" |
| Widget-cabinet credentials `wc_russian_post_guid_id/key` | not read, not deleted | checklist |
| E-mail id `wc_russian_post_tracking_email` + template names `emails/tracking-number.php` | kept | checklist "E-mail" |

## 4. Public hooks: v1 → v2

v1 call sites listed with `git grep -nE "(do_action|apply_filters)\(" v1 -- includes woodev-russian-post.php ':!woodev'`; v2 with the same grep on `v2.0.7`.
v2 fires only these plugin hooks: `wc_russian_post_order_status_changed`, `…_status_changed_from_`, `…_status_changed_to_`, `wc_russian_post_order_tracking_number_changed`, `wc_russian_post_success_exported_order` (+ new `woodev_wc_russian_post_customs_declaration`, `…_no_declaration_countries`, `…_tracking_daily_cap`).
No deprecated-hook map exists (`git grep get_deprecated_hooks v2.0.7` = none) → no `WP_DEBUG` notice for removed hooks.

### Kept
| Hook | v1 → v2 | Evidence |
|---|---|---|
| `wc_russian_post_order_status_changed` action | `(old_lower, new_lower, order_id)` — same signature; fired by the tracking poller | v1:includes/classes/order.php:193; v2:includes/tracking/class-russian-post-tracking-poller.php:472 |
| `wc_russian_post_order_status_changed_from_{old}_to{new}` (v1 typo: no underscore before the new status — kept on purpose) and `…_changed_to_{status}` (only when there was no old status) | same names, `(order_id)` | v1:order.php:187,190; v2:poller :467,469 |
| `wc_russian_post_order_tracking_number_changed` | `(tracking_number, order_id)` — same | v1:order.php:149; v2:includes/order/class-russian-post-order-export.php:476 |
| `wc_russian_post_success_exported_order` | **name kept, args changed**: v1 `($request, $export_type, $russian_post_order_wrapper)`; v2 `($response /* Russian_Post_Api_Response */, 'otpravka', $order /* WC_Order */)`; `$export_type` is always `otpravka` | v1:order.php:280; v2:class-russian-post-order-export.php:138 and its docblock :129-136 |

Status vocabulary in these hooks is Russian Post's own stored one (see §3), not the framework canonical one.

### Replaced (framework equivalent, different signature)
| v1 | v2 | Evidence |
|---|---|---|
| `wc_russian_post_rates_item` filter, `wc_russian_post_rate_cost` filter, `wc_russian_post_rate_calculate_shipping` action | `woodev_shipping_method_calculated_rate($rate,$package,$method)` / `woodev_shipping_method_after_calculate_shipping($package,$rate,$method)` — by analogy with CDEK's declared map; the Russian Post plugin declares no map, **не подтверждено** that rate flows through these hooks for every RP method | v1:abstract-shipping-methods.php:523,539,568; hooks-and-filters.md |
| `wc_russian_post_integration_form_fields` | framework `woodev_shipping_plugin_settings_{id}_form_fields` (`{id}` = `wc_russian_post`) — **не подтверждено** | v1:integration.php:335 |
| `wc_russian_post_tracking_update_interval`, `wc_russian_post_max_orders_per_update` | none; v2 poller has `woodev_wc_russian_post_tracking_daily_cap` (new) | v1:order.php:424, functions.php:560; v2 hook list |

### Removed (no v2 occurrence)
`wc_russian_post_after_order_update`, `wc_russian_post_parcel_types`, `wc_russian_post_method_title`, `wc_russian_post_formatted_address`,
`wc_russian_post_shipping_rate_cart_total`, `wc_russian_post_cart_content_total`, `wc_russian_post_checkout_script_params`,
`wc_russian_post_suggestions_plugin_default_params`, `wc_russian_post_additional_info_strings`, `wc_russian_post_need_reload_shipping_methods`,
`wc_russian_post_order_statuses`, `wc_russian_post_allow_country_codes`, `wc_russian_post_clear_address_part[_patterns]`, `wc_russian_post_tracking_url`,
`wc_russian_post_order_needs_insurance_cost`, `wc_russian_post_order_otpravka_export_data` / `…_export_item_data` / `…_translate_fields`, `wc_russian_post_order_widget_export_data`,
`wc_russian_post_disabled_statuses_for_{export,complete,delivering,canceled}`, `wc_russian_mail_category` (v1 typo name).
Evidence: v1 call sites above vs. empty v2.0.7 result for the same names.
Also already gone before v2 (v1 1.2.x deprecator, v1:woodev-russian-post.php:197-235): `wc_russian_post_new_order_params`, `…get_rate_cost`, `…types_dimension_disallow`, `…allow_international_shipping`, `…order_defaults`, `…custom_order_meta_data_fields`, `…shipping_params`, `…order_exception`, `…order_exported`, `…update_orders_query_args`.
Note `wc_russian_post_order_otpravka_export_data` (the main payload filter) has no equivalent; only the action `woodev_shipping_order_export_requested($order)`.

## 5. Endpoints / AJAX / cron

| Item | v1 | v2 | Evidence |
|---|---|---|---|
| AJAX | `get_post_office`, `set_russian_post_point`, `russian_post_order_action` (+ `russian_post_set_customer_location` per checklist) | removed; the first was an open unauthenticated proxy | v1:includes/classes/ajax.php:13-19; checklist; v2 grep: no `wp_ajax_` in includes |
| `wc-api` | `?wc-api=russian_post_barcode`, `?wc-api=russian_post_status` | removed. The checklist says "a request returns 410"; **I found no handler at all in v2.0.7** (no `woocommerce_api_` / `wc-api` string), so WooCommerce answers as for any unknown endpoint — code wins | v1:includes/classes/webhooks.php:10-11; checklist; v2 grep NONE |
| Cron | AS recurring `wc_russian_post_orders_update` (12 h) | cancelled by migration; replaced by AS poller `woodev_wc_russian_post_poll_tracking` (group `woocommerce-russian-post`) | v1:woodev-russian-post.php:289-293; v2:includes/tracking/class-russian-post-tracking-poller.php:36,39 |
| Orders admin page | `admin.php?page=wc_russian_post_orders` | slug kept | checklist |
| Tracking | SOAP tracking with `tracking_login/password` | credentials NOT carried; new tracking token asked in migration screen | checklist |
| Core filter | `woocommerce_validate_postcode` RU override | removed | checklist |

## 6. Gaps
- Tier B timing and whether every shop finishes: not measured.
