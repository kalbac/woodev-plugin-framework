# Hook inventory: Cdek

Refs: CDEK `v1` branch and `v2.3.0.9` (`a111aec3b4e850e248eaf4823c75d2cc72de7cc3`). The extraction covers plugin-owned action/filter calls and dynamic name patterns; WordPress/WooCommerce core hooks and bundled framework implementation hooks are excluded unless noted as a replacement or relevant generic API. Argument lists below are the arguments dispatched after the hook name. File paths/lines are relative to each referenced plugin tree.

## v1 extraction

| Hook name/pattern | Type | Arguments after hook | Call site |
|---|---|---|---|
| `woodev_yandex_map_api_key` | filter | `($value)` | `includes/class-wc-edostavka-checkout.php:63,236` (v1 fact-sheet call-site check) |
| `edostavka_update_order_review_address_args` | filter | `($billing_address_args, $chosen_rate)` | `includes/class-wc-edostavka-checkout.php:622` |
| `edostavka_update_order_review_postcode_args` | filter | `($billing_postcode_args, $chosen_rate)` | `includes/class-wc-edostavka-checkout.php:627` |
| `edostavka_update_order_review_state_args` | filter | `($billing_state_args, $chosen_rate)` | `includes/class-wc-edostavka-checkout.php:617` |
| `wc_cdek_shipping_integration_form_fields` | filter | `($value)` | `includes/class-wc-edostavka-integration.php:723` |
| `wc_cdek_shipping_rates_calc_params` | filter | `($calc_params, $package)` | `includes/class-wc-edostavka-shipping-method.php:861` |
| `wc_edostavka_after_details_order_table` | action | `($order)` | `includes/class-wc-edostavka-checkout.php:1043` |
| `wc_edostavka_after_order_preview_table` | action | `—` | `templates/views/html-order-preview-modal.php:69` |
| `wc_edostavka_after_order_update` | action | `($this)` | `includes/class-wc-edostavka-order.php:555` |
| `wc_edostavka_after_send_email_{email_id}` | action | `($order_id, $this)` | `includes/emails/abstract-wc-edostavka-email.php:59` |
| `wc_edostavka_ajax_rate_limit` | filter | `($defaults, $endpoint)` | `includes/class-wc-edostavka-ajax.php:102` |
| `wc_edostavka_allowed_countries_for_state` | filter | `($value)` | `includes/functions.php:147` |
| `wc_edostavka_api_base_url` | filter | `($url, $environment)` | `includes/functions-api.php:46` |
| `wc_edostavka_before_order_preview_table` | action | `—` | `templates/views/html-order-preview-modal.php:16` |
| `wc_edostavka_before_order_update` | action | `($this)` | `includes/class-wc-edostavka-order.php:514` |
| `wc_edostavka_before_order_update_save_meta` | action | `($this)` | `includes/class-wc-edostavka-order.php:551` |
| `wc_edostavka_carton_boxes` | filter | `($value)` | `includes/functions.php:667` |
| `wc_edostavka_checkout_hidden_or_remove_postcode_field` | filter | `($value, $disable_postcode_field, $fields)` | `includes/class-wc-edostavka-checkout.php:398` |
| `wc_edostavka_checkout_hidden_or_remove_state_field` | filter | `($value, $disable_state_field, $fields)` | `includes/class-wc-edostavka-checkout.php:388` |
| `wc_edostavka_checkout_params` | filter | `($value)` | `includes/class-wc-edostavka-checkout.php:158` |
| `wc_edostavka_china_tariffs_data` | filter | `($value)` | `includes/class-wc-edostavka-tariffs-data.php:574` |
| `wc_edostavka_cod_payment_methods` | filter | `($value)` | `includes/functions.php:1449` |
| `wc_edostavka_create_order_barcode_params` | filter | `($value)` | `api/class-wc-edostavka-api-request.php:123` |
| `wc_edostavka_create_order_waybill_params` | filter | `($value)` | `api/class-wc-edostavka-api-request.php:113` |
| `wc_edostavka_currencies` | filter | `($value)` | `includes/functions.php:349` |
| `wc_edostavka_customer_allow_geo_location` | filter | `($value, $this)` | `includes/class-wc-edostavka-customer-location-data.php:80` |
| `wc_edostavka_customer_default_city_code` | filter | `($value, $this)` | `includes/class-wc-edostavka-customer-location-data.php:84` |
| `wc_edostavka_customer_location_data` | filter | `($location)` | `includes/class-wc-edostavka-customer-location-data.php:108` |
| `wc_edostavka_customer_location_fields` | filter | `($value)` | `includes/functions.php:544` |
| `wc_edostavka_customer_set_default_destination` | filter | `($value, $location, $arg3 (expression))` | `includes/functions.php:517` |
| `wc_edostavka_delete_tracking_code` | action | `($order->get_id(), $notice_id)` | `includes/functions.php:1073` |
| `wc_edostavka_delivery_photo_ready` | action | `($arg1 (expression), $url, $attributes)` | `includes/webhooks/class-wc-edostavka-download-photo-webhook.php:78` |
| `wc_edostavka_delivery_rate_calculate_shipping` | action | `($rate, $package, $this)` | `includes/class-wc-edostavka-shipping-method.php:766` |
| `wc_edostavka_delivery_rates_item` | filter | `($value, $rate, $package, $this)` | `includes/class-wc-edostavka-shipping-method.php:753` |
| `wc_edostavka_delivery_rates_item_total_cost` | filter | `($value, $rate, $package)` | `includes/class-wc-edostavka-shipping-method.php:734` |
| `wc_edostavka_delivery_tariffs_data` | filter | `($value)` | `includes/class-wc-edostavka-tariffs-data.php:273` |
| `wc_edostavka_disabled_statuses_for_export` | filter | `($value)` | `includes/class-wc-edostavka-integration.php:512` |
| `wc_edostavka_enable_admin_help_tab` | filter | `($value)` | `includes/admin/class-wc-edostavka-admin.php:57` |
| `wc_edostavka_excluded_location_region_ids` | filter | `($value)` | `includes/functions.php:18` |
| `wc_edostavka_export_order_api_params` | filter | `($order_params, $this)` | `includes/class-wc-edostavka-order.php:489` |
| `wc_edostavka_extra_services_list` | filter | `($value)` | `includes/functions.php:632` |
| `wc_edostavka_generate_delivery_point_button_args` | filter | `($value)` | `includes/functions.php:1305` |
| `wc_edostavka_integration_params` | filter | `($params, $this)` | `includes/class-wc-edostavka-integration.php:99` |
| `wc_edostavka_is_admin` | filter | `($value)` | `includes/functions.php:399` |
| `wc_edostavka_locale` | filter | `($value)` | `includes/functions.php:340` |
| `wc_edostavka_location_cities_sort_results` | filter | `($value, $params)` | `includes/functions-api.php:162` |
| `wc_edostavka_location_regions_list` | filter | `($options)` | `includes/functions.php:23` |
| `wc_edostavka_method_additional_info_strings` | filter | `($additional_info_strings, $method, $method_instance, $this)` | `includes/class-wc-edostavka-checkout.php:541` |
| `wc_edostavka_method_instruction_text` | filter | `($value, $method, $method_instance, $this)` | `includes/class-wc-edostavka-checkout.php:496` |
| `wc_edostavka_native_widget_map_params` | filter | `($value)` | `includes/class-wc-edostavka-checkout.php:235` |
| `wc_edostavka_order_is_delivered` | filter | `($value, $this)` | `includes/class-wc-edostavka-order.php:647` |
| `wc_edostavka_order_is_editable` | filter | `($value, $this)` | `includes/class-wc-edostavka-order.php:643` |
| `wc_edostavka_order_mark_as_delivered` | action | `($this->get_id(), $status_slug)` | `includes/class-wc-edostavka-order.php:530` |
| `wc_edostavka_order_need_create_notice` | filter | `($value, $this)` | `includes/class-wc-edostavka-order.php:66` |
| `wc_edostavka_order_package` | filter | `($value, $package)` | `includes/order/class-wc-edostavka-order-packages.php:70` |
| `wc_edostavka_order_package_item` | filter | `($value, $item, $arg3 (expression), $this)` | `includes/order/class-wc-edostavka-order-packages.php:55` |
| `wc_edostavka_order_preview_details` | filter | `($value, $order)` | `includes/class-wc-edostavka-ajax.php:750` |
| `wc_edostavka_order_status_changed` | action | `($old_status, $status, $order_id)` | `includes/class-wc-edostavka-order.php:83` |
| `wc_edostavka_order_status_changed_from_{old}_to_{new}` | action | `($this->get_id())` | `includes/class-wc-edostavka-order.php:73` |
| `wc_edostavka_order_status_changed_to_{new}` | action | `($this->get_id())` | `includes/class-wc-edostavka-order.php:80` |
| `wc_edostavka_order_statuses` | filter | `($value)` | `includes/functions.php:773` |
| `wc_edostavka_order_statuses_to_create_notice` | filter | `($value, $status)` | `includes/class-wc-edostavka-order.php:66` |
| `wc_edostavka_order_{status}_notification` | action | `($this->get_id(), $this)` | `includes/class-wc-edostavka-order.php:533` |
| `wc_edostavka_preloaded_data_locations` | filter | `($value)` | `includes/functions.php:223` |
| `wc_edostavka_print_form_ready` | action | `($print_form_uuid, $form_type, $url)` | `includes/webhooks/class-wc-edostavka-print-form-webhook.php:55` |
| `wc_edostavka_set_customer_location` | action | `($location_data)` | `includes/class-wc-edostavka-ajax.php:385` |
| `wc_edostavka_shipping_method_delivery_points_request_params` | filter | `($value, $this)` | `includes/class-wc-edostavka-shipping-method.php:410` |
| `wc_edostavka_shop_tariffs_data` | filter | `($value)` | `includes/class-wc-edostavka-tariffs-data.php:68` |
| `wc_edostavka_status_keys_for_show` | filter | `($value)` | `includes/functions.php:822` |
| `wc_edostavka_suggestions_plugin_default_params` | filter | `($value)` | `includes/class-wc-edostavka-checkout.php:328` |
| `wc_edostavka_update_tracking_code` | action | `($tracking_code, $order, $notice_id)` | `includes/functions.php:1059` |
| `wc_edostavka_widget_map_params` | filter | `($value)` | `includes/class-wc-edostavka-checkout.php:202` |
| `wc_edostavka_yandex_map_apikey` | filter | `($value)` | `includes/class-wc-edostavka-checkout.php:63`, `includes/class-wc-edostavka-checkout.php:236` |
| `woocommerce_edostavka_cart_delivery_points_template` | filter | `($value, $out)` | `includes/class-wc-edostavka-checkout.php:682` |
| `woocommerce_edostavka_disallow_city_ids` | filter | `($value)` | `includes/functions.php:209` |
| `woocommerce_edostavka_email_tracking_core_url` | filter | `($url, $tracking_number, $arg3 (expression))` | `includes/emails/class-wc-edostavka-tracking-email.php:62` |
| `woocommerce_edostavka_order_number` | filter | `($value, $this)` | `includes/class-wc-edostavka-order.php:243` |
| `woocommerce_edostavka_shipping_id` | filter | `($value)` | `woocommerce-edostavka.php:449` |

## released v2 extraction

| Hook name/pattern | Type | Arguments after hook | Call site |
|---|---|---|---|
| `woodev_yandex_map_api_key` | filter | `($value)` | `includes/class-plugin.php:1065` |
| `wc_edostavka_yandex_map_apikey` | filter | `($value)` | `includes/class-plugin.php:1065` |
| `woodev_customer_location_saved` | action | `($record, $implicit, $user_id)` | `shared framework hook: `woodev/shipping-method/location/class-customer-location-store.php:110`; declared replacement at plugin `includes/class-plugin.php:1254` |
| `woodev_edostavka_carrier_cost_changed` | action | `($order, $arg2 (expression), $arg3 (expression), $arg4 (expression))` | `includes/tracking/class-cdek-shipment-facts-bridge.php:187` |
| `woodev_edostavka_delivery_date_changed` | action | `($order, $arg2 (expression), $arg3 (expression), $arg4 (expression), $arg5 (expression))` | `includes/tracking/class-cdek-shipment-facts-bridge.php:216` |
| `woodev_edostavka_delivery_issue` | action | `($order, $arg2 (expression), $arg3 (expression), $arg4 (expression))` | `includes/tracking/class-cdek-shipment-facts-bridge.php:243` |
| `woodev_edostavka_document_ready` | action | `($type)` | `includes/documents/class-cdek-document-source.php:282` |
| `woodev_edostavka_export_confirmed` | action | `($order, $carrier_order_id)` | `includes/orders/class-cdek-shipment-handler.php:361,644` |
| `woodev_edostavka_intake_confirmed` | action | `($order, $uuid)` | `includes/orders/class-cdek-intake-service.php:464,1265,1446` |
| `woodev_edostavka_shipment_export_failed` | action | `($order, $exception)` | `includes/orders/class-cdek-shipment-handler.php:666`, `includes/orders/class-cdek-shipment-handler.php:666 (resolved hook helper)` |
| `woodev_edostavka_status_sync_failed` | action | `($arg1 (expression), $exception)` | `includes/tracking/class-cdek-webhook-handler.php:404` |
| `woodev_edostavka_status_sync_failed` | action | `($order->get_id(), $exception)` | `includes/tracking/class-cdek-status-poller.php:256` |
| `woodev_edostavka_tariff_choices` | filter | `($options, $allowed_modes)` | `includes/shipping/class-cdek-tariff-catalog.php:504` |
| `woodev_edostavka_upgrade_gateway_options` | filter | `($value)` | `includes/lifecycle/class-cdek-v1-migration.php:1062` |
| `woodev_shipping_delivery_status_changed` | action | `($order, $previous_status, $current_status, $provider)` | `shared framework hook: `woodev/shipping-method/order/class-delivery-status-events.php:73`; declared replacement at plugin `includes/class-plugin.php:1244` |
| `woodev_shipping_delivery_status_resolved` | filter | `($canonical, $raw, $status_map)` | `shared framework hook: `woodev/shipping-method/order/class-delivery-status.php:268`; declared replacement at plugin `includes/class-plugin.php:1251` |
| `woodev_shipping_method_after_calculate_shipping` | action | `($package, $rate, $method)` | `shared framework hook: `woodev/shipping-method/class-shipping-method.php:814`; declared replacement at plugin `includes/class-plugin.php:1239` |
| `woodev_shipping_method_calculated_rate` | filter | `($rate, $package, $method)` | `shared framework hook: `woodev/shipping-method/class-shipping-method.php:765`; declared replacement at plugin `includes/class-plugin.php:1238` |
| `woodev_shipping_order_export_requested` | action | `($order)` | `shared framework hook: `woodev/shipping-method/order/abstract-shipment-handler.php:319`; declared replacement at plugin `includes/class-plugin.php:1241` |
| `woodev_shipping_order_exported` | action | `($order, $carrier_order_id)` | `shared framework hook: `woodev/shipping-method/order/abstract-shipment-handler.php:397`; declared replacement at plugin `includes/class-plugin.php:1246` |
| `woodev_shipping_rate_additional_info` | filter | `($blocks, $rate, $method, $index, $plugin)` | `shared framework hook: `woodev/shipping-method/class-shipping-plugin.php:918`; declared replacement at plugin `includes/class-plugin.php:1240` |

## v1 → v2 set diff

| v1 hook/pattern | Classification | shop code plausibly hooked it? | v2 outcome |
|---|---|---|---|
| `edostavka_update_order_review_address_args` | removed | yes — public |
| `edostavka_update_order_review_postcode_args` | removed | yes — public |
| `edostavka_update_order_review_state_args` | removed | yes — public |
| `wc_cdek_shipping_integration_form_fields` | removed | yes — public |
| `wc_cdek_shipping_rates_calc_params` | removed | yes — public |
| `wc_edostavka_after_details_order_table` | removed | yes — public |
| `wc_edostavka_after_order_preview_table` | removed | yes — public |
| `wc_edostavka_after_order_update` | renamed → `woodev_shipping_order_exported` | yes — public |
| `wc_edostavka_after_send_email_{email_id}` | removed | yes — public |
| `wc_edostavka_ajax_rate_limit` | removed | no — internal |
| `wc_edostavka_allowed_countries_for_state` | removed | yes — public |
| `wc_edostavka_api_base_url` | removed | yes — public |
| `wc_edostavka_before_order_preview_table` | removed | yes — public |
| `wc_edostavka_before_order_update` | renamed → `woodev_shipping_order_exported` | yes — public |
| `wc_edostavka_before_order_update_save_meta` | removed | no — internal |
| `wc_edostavka_carton_boxes` | removed | yes — public |
| `wc_edostavka_checkout_hidden_or_remove_postcode_field` | removed | yes — public |
| `wc_edostavka_checkout_hidden_or_remove_state_field` | removed | yes — public |
| `wc_edostavka_checkout_params` | removed | yes — public |
| `wc_edostavka_china_tariffs_data` | renamed → `woodev_edostavka_tariff_choices` | yes — public |
| `wc_edostavka_cod_payment_methods` | removed | yes — public |
| `wc_edostavka_create_order_barcode_params` | removed | yes — public |
| `wc_edostavka_create_order_waybill_params` | removed | yes — public |
| `wc_edostavka_currencies` | removed | yes — public |
| `wc_edostavka_customer_allow_geo_location` | removed | yes — public |
| `wc_edostavka_customer_default_city_code` | removed | yes — public |
| `wc_edostavka_customer_location_data` | renamed → `woodev_customer_location_saved` | yes — public |
| `wc_edostavka_customer_location_fields` | removed | yes — public |
| `wc_edostavka_customer_set_default_destination` | removed | yes — public |
| `wc_edostavka_delete_tracking_code` | removed | yes — public |
| `wc_edostavka_delivery_photo_ready` | removed | yes — public |
| `wc_edostavka_delivery_rate_calculate_shipping` | renamed → `woodev_shipping_method_after_calculate_shipping` | yes — public |
| `wc_edostavka_delivery_rates_item` | renamed → `woodev_shipping_method_calculated_rate` | yes — public |
| `wc_edostavka_delivery_rates_item_total_cost` | renamed → `woodev_shipping_method_calculated_rate` | yes — public |
| `wc_edostavka_delivery_tariffs_data` | renamed → `woodev_edostavka_tariff_choices` | yes — public |
| `wc_edostavka_disabled_statuses_for_export` | removed | yes — public |
| `wc_edostavka_enable_admin_help_tab` | removed | yes — public |
| `wc_edostavka_excluded_location_region_ids` | removed | yes — public |
| `wc_edostavka_export_order_api_params` | renamed → `woodev_shipping_order_export_requested` | yes — public |
| `wc_edostavka_extra_services_list` | removed | yes — public |
| `wc_edostavka_generate_delivery_point_button_args` | removed | yes — public |
| `wc_edostavka_integration_params` | removed | yes — public |
| `wc_edostavka_is_admin` | removed | yes — public |
| `wc_edostavka_locale` | removed | yes — public |
| `wc_edostavka_location_cities_sort_results` | removed | yes — public |
| `wc_edostavka_location_regions_list` | removed | yes — public |
| `wc_edostavka_method_additional_info_strings` | renamed → `woodev_shipping_rate_additional_info` | yes — public |
| `wc_edostavka_method_instruction_text` | renamed → `woodev_shipping_rate_additional_info` | yes — public |
| `wc_edostavka_native_widget_map_params` | removed | yes — public |
| `wc_edostavka_order_is_delivered` | removed | yes — public |
| `wc_edostavka_order_is_editable` | removed | yes — public |
| `wc_edostavka_order_mark_as_delivered` | renamed → `woodev_shipping_delivery_status_changed` | yes — public |
| `wc_edostavka_order_need_create_notice` | removed | yes — public |
| `wc_edostavka_order_package` | removed | yes — public |
| `wc_edostavka_order_package_item` | removed | yes — public |
| `wc_edostavka_order_preview_details` | removed | yes — public |
| `wc_edostavka_order_status_changed` | renamed → `woodev_shipping_delivery_status_changed` | yes — public |
| `wc_edostavka_order_status_changed_from_{old}_to_{new}` | removed | yes — public |
| `wc_edostavka_order_status_changed_to_{new}` | removed | yes — public |
| `wc_edostavka_order_statuses` | renamed → `woodev_shipping_delivery_status_resolved` | yes — public |
| `wc_edostavka_order_statuses_to_create_notice` | removed | yes — public |
| `wc_edostavka_order_{status}_notification` | removed | yes — public |
| `wc_edostavka_preloaded_data_locations` | removed | yes — public |
| `wc_edostavka_print_form_ready` | removed | yes — public |
| `wc_edostavka_set_customer_location` | renamed → `woodev_customer_location_saved` | yes — public |
| `wc_edostavka_shipping_method_delivery_points_request_params` | removed | yes — public |
| `wc_edostavka_shop_tariffs_data` | renamed → `woodev_edostavka_tariff_choices` | yes — public |
| `wc_edostavka_status_keys_for_show` | removed | yes — public |
| `wc_edostavka_suggestions_plugin_default_params` | removed | yes — public |
| `wc_edostavka_update_tracking_code` | removed | no — internal |
| `wc_edostavka_widget_map_params` | removed | yes — public |
| `wc_edostavka_yandex_map_apikey` | kept | yes — public |
| `woocommerce_edostavka_cart_delivery_points_template` | removed | yes — public |
| `woocommerce_edostavka_disallow_city_ids` | removed | yes — public |
| `woocommerce_edostavka_email_tracking_core_url` | removed | yes — public |
| `woocommerce_edostavka_order_number` | removed | yes — public |
| `woocommerce_edostavka_shipping_id` | removed | yes — public |
| `woodev_customer_location_saved` | new in v2 | — |
| `woodev_edostavka_carrier_cost_changed` | new in v2 | — |
| `woodev_edostavka_delivery_date_changed` | new in v2 | — |
| `woodev_edostavka_delivery_issue` | new in v2 | — |
| `woodev_edostavka_document_ready` | new in v2 | — |
| `woodev_edostavka_export_confirmed` | new in v2 | — |
| `woodev_edostavka_intake_confirmed` | new in v2 | — |
| `woodev_edostavka_shipment_export_failed` | new in v2 | — |
| `woodev_edostavka_status_sync_failed` | new in v2 | — |
| `woodev_edostavka_tariff_choices` | new in v2 | — |
| `woodev_edostavka_upgrade_gateway_options` | new in v2 | — |
| `woodev_shipping_delivery_status_changed` | new in v2 | — |
| `woodev_shipping_delivery_status_resolved` | new in v2 | — |
| `woodev_shipping_method_after_calculate_shipping` | new in v2 | — |
| `woodev_shipping_method_calculated_rate` | new in v2 | — |
| `woodev_shipping_order_export_requested` | new in v2 | — |
| `woodev_shipping_order_exported` | new in v2 | — |
| `woodev_shipping_rate_additional_info` | new in v2 | — |

## CDEK deprecated-hook map audit

Compared the v1 extraction with `DEPRECATED_HOOK_REPLACEMENTS` in v2.3.0.9 `includes/class-plugin.php:1229-1270`. The map declares 38 entries, including 16 old hook names mapped to v2 equivalents, removed-hook notices, and three concrete after-email hooks (represented by the dynamic v1 pattern above). The following 40 extracted v1 patterns have no map entry and no same-name v2 call site:

`edostavka_update_order_review_address_args`, `edostavka_update_order_review_postcode_args`, `edostavka_update_order_review_state_args`, `wc_edostavka_after_details_order_table`, `wc_edostavka_after_order_preview_table`, `wc_edostavka_ajax_rate_limit`, `wc_edostavka_allowed_countries_for_state`, `wc_edostavka_api_base_url`, `wc_edostavka_before_order_preview_table`, `wc_edostavka_before_order_update_save_meta`, `wc_edostavka_cod_payment_methods`, `wc_edostavka_create_order_barcode_params`, `wc_edostavka_create_order_waybill_params`, `wc_edostavka_currencies`, `wc_edostavka_customer_allow_geo_location`, `wc_edostavka_customer_location_fields`, `wc_edostavka_customer_set_default_destination`, `wc_edostavka_delivery_photo_ready`, `wc_edostavka_enable_admin_help_tab`, `wc_edostavka_excluded_location_region_ids`, `wc_edostavka_generate_delivery_point_button_args`, `wc_edostavka_integration_params`, `wc_edostavka_locale`, `wc_edostavka_location_cities_sort_results`, `wc_edostavka_location_regions_list`, `wc_edostavka_order_is_delivered`, `wc_edostavka_order_is_editable`, `wc_edostavka_order_need_create_notice`, `wc_edostavka_order_preview_details`, `wc_edostavka_order_status_changed_from_{old}_to_{new}`, `wc_edostavka_order_status_changed_to_{new}`, `wc_edostavka_order_statuses_to_create_notice`, `wc_edostavka_order_{status}_notification`, `wc_edostavka_preloaded_data_locations`, `wc_edostavka_print_form_ready`, `wc_edostavka_status_keys_for_show`, `woocommerce_edostavka_cart_delivery_points_template`, `woocommerce_edostavka_disallow_city_ids`, `woocommerce_edostavka_order_number`, `woocommerce_edostavka_shipping_id`.

These include public filters/actions plausibly used by shop code. The intentionally curated deprecation map therefore does not notify every removed v1 extension point.


## WooCommerce-generated availability filter

WooCommerce builds `woocommerce_shipping_{method_id}_is_available` from the registered shipping method id. The v1 `edostavka` id produces the old filter; the v2 method ids `edostavka_courier`, `edostavka_pickup`, and `edostavka_postamat` produce new names. This is a WooCommerce core filter family, not a plugin `do_action`/`apply_filters` call site. The v2 plugin explicitly accepts the historical `edostavka` order-line id (`includes/class-plugin.php:72`).
