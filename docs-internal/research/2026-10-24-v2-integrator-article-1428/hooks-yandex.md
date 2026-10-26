# Hook inventory: Yandex

Refs: Yandex `v2.0.10` (`9eec67ff130682040bfcc35e9406b46cadc10c5c`); v1 survey sources listed per row. The extraction covers plugin-owned action/filter calls and dynamic name patterns; WordPress/WooCommerce core hooks and bundled framework implementation hooks are excluded unless noted as a replacement or relevant generic API. Argument lists below are the arguments dispatched after the hook name. File paths/lines are relative to each referenced plugin tree.

## v1 extraction

| Hook name/pattern | Type | Arguments after hook | Call site |
|---|---|---|---|
| `wc_yandex_delivery_abort_cached_response` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_after_shipping_rate_outputs` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_cancel_reasons` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_checkout_script_params` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_clear_address_part` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_clear_address_part_patterns` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_delete_sharing_url` | action | `($arg1 (expression))` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:119` |
| `wc_yandex_delivery_formatted_address` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_integration_form_fields` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_order_places` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_order_statuses` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_prepare_order_data` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_rate_cost` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_shipping_method_pricing_calculator_params` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_time_interval_params` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_delivery_update_sharing_url` | action | `($arg1 (expression))` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:119` |
| `wc_yandex_delivery_{id}_fallback_calculate_shipping` | action | `($arg1 (expression))` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:119` |
| `wc_yandex_disabled_statuses_for_export` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `wc_yandex_order_mark_as_cancelled` | action | `($arg1 (expression))` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:119` |
| `wc_yandex_order_mark_as_delivered` | action | `($arg1 (expression))` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:119` |
| `wc_yandex_order_status_changed` | action | `($arg1 (expression))` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:119` |
| `wc_yandex_order_status_changed_from_{old}_to_{new}` | action | `($arg1 (expression))` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:119` |
| `wc_yandex_order_status_changed_to_{status}` | action | `($arg1 (expression))` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:119` |
| `wc_yandex_suggestions_plugin_default_params` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |
| `woodev_yandex_delivery_http_request_args` | filter | `($args, $arg2 (expression))` | `docs-internal/migration/yandex-data-preservation-checklist.md:113 (generic API hook; plugin id supplies yandex_delivery)` |
| `woodev_yandex_map_api_key` | filter | `($value)` | `docs-internal/archive/platform-v2-phase6a-yandex-reference-contract-draft.md:120` |

## released v2 extraction

| Hook name/pattern | Type | Arguments after hook | Call site |
|---|---|---|---|
| `woodev_yandex_delivery_cod_payment_methods` | filter | `($value)` | `includes/shipping/class-yandex-rate-rules.php:70` |
| `woodev_yandex_delivery_http_request_args` | filter | `($args, $api)` | `shared framework source: `woodev/api/class-api-base.php:2329`; API id from released plugin `includes/api/class-yandex-api-client.php:424`` |
| `woodev_yandex_delivery_status_sync_failed` | action | `($order->get_id(), $error)` | `includes/tracking/class-yandex-status-poller.php:251` |
| `woodev_yandex_delivery_upgrade_gateway_options` | filter | `($value)` | `includes/lifecycle/class-yandex-v1-migration.php:410` |
| `woodev_yandex_map_api_key` | filter | `($value)` | `includes/class-plugin.php:303` |

| `woodev_shipping_method_calculated_rate` | filter | `($rate, $package, $method)` | shared framework API used by Yandex methods: `woodev/shipping-method/class-shipping-method.php:765` |
| `woodev_shipping_method_rate_calculation_failed` | action | `($exception, $package, $method)` | shared framework API used by Yandex methods: `woodev/shipping-method/class-shipping-method.php:1775` |
| `woodev_shipping_delivery_status_changed` | action | `($order, $previous_status, $current_status, $provider)` | shared framework event hook: `woodev/shipping-method/order/class-delivery-status-events.php:73` |

## v1 → v2 set diff

v1 entries are reconstructed from the required survey/reference sources; every row is marked `[survey]` or `[fixture]` (no original plugin v1 checkout is available). The legacy v1 fixtures do not contain hook call sites, so v1 callback argument signatures remain unknown.

| v1 hook/pattern | Classification | shop code plausibly hooked it? | v2 outcome |
|---|---|---|---|
| `wc_yandex_order_status_changed` [survey] | action | yes — public | removed; possible semantic replacement: `woodev_shipping_delivery_status_changed` (not a declared plugin map) |
| `wc_yandex_order_status_changed_to_{status}` [survey] | action | yes — public | removed; possible semantic replacement: `woodev_shipping_delivery_status_changed` (not a declared plugin map) |
| `wc_yandex_order_status_changed_from_{old}_to_{new}` [survey] | action | yes — public | removed; possible semantic replacement: `woodev_shipping_delivery_status_changed` (not a declared plugin map) |
| `wc_yandex_order_mark_as_delivered` [survey] | action | yes — public | removed; possible semantic replacement: `woodev_shipping_delivery_status_changed` (not a declared plugin map) |
| `wc_yandex_order_mark_as_cancelled` [survey] | action | yes — public | removed; possible semantic replacement: `woodev_shipping_delivery_status_changed` (not a declared plugin map) |
| `wc_yandex_delivery_update_sharing_url` [survey] | action | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_delete_sharing_url` [survey] | action | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_{id}_fallback_calculate_shipping` [survey] | action | yes — public | removed (no v2 occurrence) |
| `woodev_yandex_map_api_key` [survey] | filter | yes — public | kept |
| `wc_yandex_suggestions_plugin_default_params` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_checkout_script_params` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_after_shipping_rate_outputs` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_time_interval_params` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_formatted_address` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_clear_address_part_patterns` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_clear_address_part` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_order_statuses` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_cancel_reasons` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_shipping_method_pricing_calculator_params` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_prepare_order_data` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_rate_cost` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_order_places` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_disabled_statuses_for_export` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_integration_form_fields` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `wc_yandex_delivery_abort_cached_response` [survey] | filter | yes — public | removed (no v2 occurrence) |
| `woodev_yandex_delivery_http_request_args` [survey] | filter | yes — public | possible kept in v2 shared framework API; v1 continuity unverified |

New in v2: `woodev_yandex_delivery_cod_payment_methods` (filter), `woodev_yandex_delivery_upgrade_gateway_options` (filter), and `woodev_yandex_delivery_status_sync_failed` (action).


## WooCommerce-generated availability filter

WooCommerce builds `woocommerce_shipping_{method_id}_is_available` from the registered shipping method id. In v1 the ids were `yandex_delivery_other_day` / `yandex_delivery_express`; in v2 they are `yandex_delivery_courier` / `yandex_delivery_pickup`. This is a WooCommerce core filter family, not a plugin `do_action`/`apply_filters` call site.
