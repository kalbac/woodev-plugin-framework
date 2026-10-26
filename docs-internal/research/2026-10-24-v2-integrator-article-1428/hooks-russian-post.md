# Hook inventory: Russian Post

Refs: Russian Post `v1` branch and `v2.0.9` (`2a6a7bc56b2604831ed01592016bfdd1bdaa37af`). The extraction covers plugin-owned action/filter calls and dynamic name patterns; WordPress/WooCommerce core hooks and bundled framework implementation hooks are excluded unless noted as a replacement or relevant generic API. Argument lists below are the arguments dispatched after the hook name. File paths/lines are relative to each referenced plugin tree.

## v1 extraction

| Hook name/pattern | Type | Arguments after hook | Call site |
|---|---|---|---|
| `wc_russian_mail_category` | filter | `($mail_category, $this)` | `includes/abstracts/abstract-export-order.php:177` |
| `wc_russian_post_additional_info_strings` | filter | `($additional_info, $method, $method_instance, $this)` | `includes/classes/checkout.php:384` |
| `wc_russian_post_after_order_update` | action | `($this)` | `includes/classes/order.php:348` |
| `wc_russian_post_allow_country_codes` | filter | `($value)` | `includes/functions.php:182` |
| `wc_russian_post_cart_content_total` | filter | `($content_total, $arg2 (expression))` | `includes/classes/checkout.php:181` |
| `wc_russian_post_checkout_script_params` | filter | `($value)` | `includes/classes/checkout.php:138` |
| `wc_russian_post_clear_address_part` | filter | `($value, $original_address, $type)` | `includes/functions.php:155` |
| `wc_russian_post_clear_address_part_patterns` | filter | `($value, $address, $type)` | `includes/functions.php:146` |
| `wc_russian_post_disabled_statuses_for_canceled` | filter | `($value)` | `includes/admin/setup-wizard.php:559`, `includes/classes/integration.php:326` |
| `wc_russian_post_disabled_statuses_for_complete` | filter | `($value)` | `includes/admin/setup-wizard.php:510`, `includes/classes/integration.php:295` |
| `wc_russian_post_disabled_statuses_for_delivering` | filter | `($value)` | `includes/admin/setup-wizard.php:534`, `includes/classes/integration.php:310` |
| `wc_russian_post_disabled_statuses_for_export` | filter | `($value)` | `includes/admin/setup-wizard.php:388`, `includes/classes/integration.php:223` |
| `wc_russian_post_formatted_address` | filter | `($value, $destination)` | `includes/abstracts/abstract-shipping-methods.php:489` |
| `wc_russian_post_integration_form_fields` | filter | `($value)` | `includes/classes/integration.php:335` |
| `wc_russian_post_max_orders_per_update` | filter | `($value)` | `includes/functions.php:560` |
| `wc_russian_post_method_title` | filter | `($title, $parcel_type, $this)` | `includes/abstracts/abstract-shipping-methods.php:578` |
| `wc_russian_post_need_reload_shipping_methods` | filter | `($value)` | `includes/classes/checkout.php:90` |
| `wc_russian_post_order_needs_insurance_cost` | filter | `($value, $this)` | `includes/abstracts/abstract-export-order.php:94` |
| `wc_russian_post_order_otpravka_export_data` | filter | `($value, $this->get_russian_post_order(), $this)` | `includes/classes/export-order/otpravka.php:384` |
| `wc_russian_post_order_otpravka_export_item_data` | filter | `($value, $item, $this)` | `includes/classes/export-order/otpravka.php:19` |
| `wc_russian_post_order_otpravka_translate_fields` | filter | `($value)` | `includes/classes/export-order/otpravka.php:267` |
| `wc_russian_post_order_status_changed` | action | `($old_status, $status, $order_id)` | `includes/classes/order.php:193` |
| `wc_russian_post_order_status_changed_from_{old}_to{new}` | action | `($this->get_id())` | `includes/classes/order.php:187` |
| `wc_russian_post_order_status_changed_to_{new}` | action | `($this->get_id())` | `includes/classes/order.php:190` |
| `wc_russian_post_order_statuses` | filter | `($value)` | `includes/functions.php:159` |
| `wc_russian_post_order_tracking_number_changed` | action | `($arg1 (expression), $this->get_id())` | `includes/classes/order.php:149` |
| `wc_russian_post_order_widget_export_data` | filter | `($value, $this->get_russian_post_order(), $this)` | `includes/classes/export-order/widget.php:184` |
| `wc_russian_post_parcel_types` | filter | `($value)` | `includes/functions.php:15` |
| `wc_russian_post_rate_calculate_shipping` | action | `($rate, $package, $this)` | `includes/abstracts/abstract-shipping-methods.php:539` |
| `wc_russian_post_rate_cost` | filter | `($cost, $package, $this)` | `includes/abstracts/abstract-shipping-methods.php:568` |
| `wc_russian_post_rates_item` | filter | `($value, $rate, $package, $this)` | `includes/abstracts/abstract-shipping-methods.php:523` |
| `wc_russian_post_shipping_rate_cart_total` | filter | `($value, $package)` | `includes/abstracts/abstract-shipping-methods.php:685` |
| `wc_russian_post_success_exported_order` | action | `($request, $export_type, $this)` | `includes/classes/order.php:280` |
| `wc_russian_post_suggestions_plugin_default_params` | filter | `($value)` | `includes/classes/checkout.php:122` |
| `wc_russian_post_tracking_update_interval` | filter | `($value)` | `includes/classes/order.php:424` |
| `wc_russian_post_tracking_url` | filter | `($value, $tracking_number)` | `includes/classes/order.php:125` |

## released v2 extraction

| Hook name/pattern | Type | Arguments after hook | Call site |
|---|---|---|---|
| `wc_russian_post_order_status_changed` | action | `($old, $new, $order->get_id())` | `includes/tracking/class-russian-post-tracking-poller.php:472` |
| `wc_russian_post_order_status_changed_from_{old}_to{new}` | action | `($order->get_id())` | `includes/tracking/class-russian-post-tracking-poller.php:467` |
| `wc_russian_post_order_status_changed_to_{new}` | action | `($order->get_id())` | `includes/tracking/class-russian-post-tracking-poller.php:469` |
| `wc_russian_post_order_tracking_number_changed` | action | `($arg1 (expression), $order->get_id())` | `includes/order/class-russian-post-order-export.php:476` |
| `wc_russian_post_success_exported_order` | action | `($response, 'otpravka', $order)` | `includes/order/class-russian-post-order-export.php:138` |
| `woodev_shipping_method_after_calculate_shipping` | action | `($package, $rate, $method)` | `shared framework API hook; source `woodev/shipping-method/class-shipping-method.php:814`` |
| `woodev_shipping_method_calculated_rate` | filter | `($rate, $package, $method)` | `shared framework API hook; source `woodev/shipping-method/class-shipping-method.php:814`` |
| `woodev_shipping_plugin_settings_{id}_form_fields` | filter | `($form_fields, $plugin)` | `framework settings hook; candidate only (no direct Russian Post call-site replacement)` |
| `woodev_wc_russian_post_customs_declaration` | filter | `($value, $order)` | `includes/international/class-russian-post-international-body.php:145` |
| `woodev_wc_russian_post_has_v1_data` | filter | `($found)` | `includes/setup/class-russian-post-setup-wizard.php:93` |
| `woodev_wc_russian_post_no_declaration_countries` | filter | `($value)` | `includes/international/class-russian-post-customs-declaration.php:63` |
| `woodev_wc_russian_post_tracking_daily_cap` | filter | `($value)` | `includes/class-plugin.php:309` |

## v1 → v2 set diff

| v1 hook/pattern | Classification | shop code plausibly hooked it? | v2 outcome |
|---|---|---|---|
| `wc_russian_mail_category` | removed | yes — public |
| `wc_russian_post_additional_info_strings` | removed | yes — public |
| `wc_russian_post_after_order_update` | removed | no — internal |
| `wc_russian_post_allow_country_codes` | removed | yes — public |
| `wc_russian_post_cart_content_total` | removed | yes — public |
| `wc_russian_post_checkout_script_params` | removed | yes — public |
| `wc_russian_post_clear_address_part` | removed | yes — public |
| `wc_russian_post_clear_address_part_patterns` | removed | yes — public |
| `wc_russian_post_disabled_statuses_for_canceled` | removed | yes — public |
| `wc_russian_post_disabled_statuses_for_complete` | removed | yes — public |
| `wc_russian_post_disabled_statuses_for_delivering` | removed | yes — public |
| `wc_russian_post_disabled_statuses_for_export` | removed | yes — public |
| `wc_russian_post_formatted_address` | removed | yes — public |
| `wc_russian_post_integration_form_fields` | removed | yes — public |
| `wc_russian_post_max_orders_per_update` | removed | yes — public |
| `wc_russian_post_method_title` | removed | yes — public |
| `wc_russian_post_need_reload_shipping_methods` | removed | yes — public |
| `wc_russian_post_order_needs_insurance_cost` | removed | yes — public |
| `wc_russian_post_order_otpravka_export_data` | removed | yes — public |
| `wc_russian_post_order_otpravka_export_item_data` | removed | yes — public |
| `wc_russian_post_order_otpravka_translate_fields` | removed | yes — public |
| `wc_russian_post_order_status_changed` | kept | yes — public |
| `wc_russian_post_order_status_changed_from_{old}_to{new}` | kept | yes — public |
| `wc_russian_post_order_status_changed_to_{new}` | kept | yes — public |
| `wc_russian_post_order_statuses` | removed | yes — public |
| `wc_russian_post_order_tracking_number_changed` | kept | yes — public |
| `wc_russian_post_order_widget_export_data` | removed | yes — public |
| `wc_russian_post_parcel_types` | removed | yes — public |
| `wc_russian_post_rate_calculate_shipping` | removed | yes — public |
| `wc_russian_post_rate_cost` | removed | yes — public |
| `wc_russian_post_rates_item` | removed | yes — public |
| `wc_russian_post_shipping_rate_cart_total` | removed | yes — public |
| `wc_russian_post_success_exported_order` | kept; arguments changed | yes — public |
| `wc_russian_post_suggestions_plugin_default_params` | removed | yes — public |
| `wc_russian_post_tracking_update_interval` | removed | yes — public |
| `wc_russian_post_tracking_url` | removed | yes — public |
| `woodev_shipping_method_after_calculate_shipping` | new in v2 | — |
| `woodev_shipping_method_calculated_rate` | new in v2 | — |
| `woodev_shipping_plugin_settings_{id}_form_fields` | new in v2 | — |
| `woodev_wc_russian_post_customs_declaration` | new in v2 | — |
| `woodev_wc_russian_post_has_v1_data` | new in v2 | — |
| `woodev_wc_russian_post_no_declaration_countries` | new in v2 | — |
| `woodev_wc_russian_post_tracking_daily_cap` | new in v2 | — |


## WooCommerce-generated availability filter

WooCommerce builds `woocommerce_shipping_{method_id}_is_available` from the registered shipping method id. The v1 ids were `wc_russian_post_courier` / `wc_russian_post_postal`; v2 ids are `russian_post_courier`, `russian_post_pickup`, `russian_post_postamat`, and `russian_post_international`. This is a WooCommerce core filter family, not a plugin `do_action`/`apply_filters` call site.
