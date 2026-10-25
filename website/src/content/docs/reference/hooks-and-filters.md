---
title: Hooks & filters
description: Look up the actions and filters the framework fires for plugin authors, with their arguments and the page that explains each one.
---

In this page you will learn which actions and filters the framework fires, what each one receives and which page explains it, so that you can find the right extension point without reading the framework source.

Every row has the hook name, its type, the arguments in the order they are passed, and a short purpose. Hooks that are specific to a plugin carry a placeholder in their name. Replace it with your own value.

## Name placeholders

| Placeholder | Replace it with | Example |
|---|---|---|
| `{id}` | The plugin ID, as written in the loader definition. | `my-carrier` |
| `{id_underscored}` | The plugin ID with dashes turned into underscores. | `my_carrier` |
| `{api_id}` | The ID of the API client. By default this is the plugin ID. | `my-carrier` |
| `{identifier}` | The identifier of a background job handler: its `$prefix`, an underscore and its `$action`. | `my_carrier_export_jobs` |
| `{prefix}` | The `$hook_prefix` given to a shipping handler's constructor, followed by an underscore. Leave it out, with its underscore, when the prefix is empty. | `my-carrier_` |
| `{method_id}` | The ID of a shipping method class. | `my_carrier_courier` |

A few rules apply to all hooks in this page:

- **Return the type the filter expects.** Many filters check what you return and keep the original value when it is the wrong type, but not all do: some cast the result (a `bool` filter treats the string `'false'` as `true`). Never rely on a wrong-type return being ignored. Return the same type you were given.
- **Add your callbacks early.** Register them from `init_plugin()` or from `plugins_loaded`, not from a later action. Registries such as the tools and location providers read their filter once.
- **Keep hot-path callbacks cheap.** Filters that run for every order row, every rate or every pickup point on a map must not call a remote service.

## Two examples

A filter receives a value and must return one. This one shortens the rate cache for every method of the shop to two minutes:

```php
<?php
add_filter(
	'woodev_shipping_rate_cache_ttl',
	static function ( $ttl, $method, $package ) {
		return 120;
	},
	10,
	3
);
```

An action receives data and returns nothing. This one adds an order note after a shipment of the plugin with the hook prefix `my-carrier_` is exported:

```php
<?php
add_action(
	'woodev_shipping_my-carrier_shipment_exported',
	static function ( $order, $carrier_order_id ) {
		$order->add_order_note(
			sprintf(
				/* translators: %s: the carrier's order number. */
				__( 'Sent to the carrier as order %s.', 'my-carrier' ),
				$carrier_order_id
			)
		);
	},
	10,
	2
);
```

Pass the number of arguments as the fourth argument of `add_filter()` or `add_action()`. WordPress passes only one argument unless you ask for more.

## Plugin and lifecycle

These hooks follow the life of your plugin on a site. See [Lifecycle & upgrades](../../core/lifecycle-and-upgrades/) and [The plugin class](../../core/plugin-class/).

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_plugins_loaded` | action | none | Run code once, after every Woodev plugin on the site has started. | [The plugin class](../../core/plugin-class/) |
| `woodev_{id}_installed` | action | none | React to the first install of your plugin on a site. | [Lifecycle & upgrades](../../core/lifecycle-and-upgrades/) |
| `woodev_{id}_updated` | action | `$installed_version` | React to an upgrade. The argument is the version the site had before. | [Lifecycle & upgrades](../../core/lifecycle-and-upgrades/) |
| `woodev_{id}_activated` | action | none | Run when the plugin is activated. | [Lifecycle & upgrades](../../core/lifecycle-and-upgrades/) |
| `woodev_{id}_deactivated` | action | none | Run when the plugin is deactivated. | [Lifecycle & upgrades](../../core/lifecycle-and-upgrades/) |
| `woodev_{id}_milestone_reached` | action | `$id`, `$message`, `$since` | Trigger a milestone notice by firing the action, instead of calling the lifecycle handler. You fire this one; the framework listens. | [Lifecycle & upgrades](../../core/lifecycle-and-upgrades/#milestone-notices) |
| `woodev_{id}_milestone_message` | filter | `$message`, `$id` | Reword or suppress a milestone notice. Return an empty string to hide it. | [Lifecycle & upgrades](../../core/lifecycle-and-upgrades/#milestone-notices) |
| `woodev_{id}_scripts_optimization_plugins` | filter | `$plugins` | Change the list of script-optimisation plugins that the environment check looks for. Keys are plugin files, values are names. | [Dependencies & environment checks](../../core/dependencies-and-environment-checks/) |
| `woodev_plugin_framework_show_deprecated_hook_notices` | filter | `$show` | Turn off the debug notices about deprecated hooks. They show only when `WP_DEBUG` is on. | [Migrating from v1](../../core/migrating-from-v1/) |
| `wc_{id}_rest_api_system_status_data` | filter | `$data`, `$response`, `$request` | Add your own data to the WooCommerce REST system status response. | [REST endpoints](../../settings/rest-endpoints/) |

## Settings and wizard

These hooks shape the settings screens and the setup wizard. See [Typed settings](../../settings/typed-settings/), [Setup wizard](../../settings/setup-wizard/) and [Admin notices & messages](../../settings/admin-notices-and-messages/).

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_{id}_settings_api_setting_types` | filter | `$setting_types`, `$settings` | Add your own setting type to the list of valid ones. | [Typed settings](../../settings/typed-settings/) |
| `woodev_{id}_settings_api_control_types` | filter | `$control_types`, `$settings` | Add your own control type to the list of valid ones. | [Typed settings](../../settings/typed-settings/) |
| `woodev_{id}_settings_api_setting_control_types` | filter | `$control_types`, `$setting_type`, `$setting`, `$settings` | Say which control types a setting type accepts. Starts as an empty array. | [Typed settings](../../settings/typed-settings/) |
| `woodev_{id}_setup_wizard_steps` | filter | `$steps`, `$wizard` | Add, remove or reorder wizard steps. The steps are keyed by ID. | [Setup wizard](../../settings/setup-wizard/) |
| `woodev_{id}_dismiss_notice` | action | `$message_id`, `$user_id` | React when a user dismisses one of your admin notices. | [Admin notices & messages](../../settings/admin-notices-and-messages/) |
| `woodev_shipping_tools` | filter | `$tools` | Add a `Shipping_Tool` to the tools section of the shipping settings. Register before `init` priority 25; the list is read once. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_plugin_settings_{id}_form_fields` | filter | `$form_fields`, `$integration` | Change the fields of the shared shipping settings page. `{id}` is the integration ID. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_integration_{id}_supports_{feature}` | action | `$integration`, `$name` | React when the shared settings integration declares a feature. Dashes in the feature name become underscores. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_integration_{id}_removed_support_{feature}` | action | `$integration`, `$name` | React when the integration drops a feature. | [Plugin anatomy](../../shipping/plugin-anatomy/) |

## API client

These hooks wrap every request an API client sends. See [Calling external APIs](../../core/calling-external-apis/) and [Logging & debugging](../../core/logging-and-debugging/).

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_{api_id}_api_request_performed` | action | `$request_data`, `$response_data`, `$api` | Log or inspect a finished call. The data is already redacted. | [Calling external APIs](../../core/calling-external-apis/#hooks-around-a-request) |
| `woodev_{api_id}_api_request_uri` | filter | `$uri`, `$api` | Change the address of a request. | [Calling external APIs](../../core/calling-external-apis/#hooks-around-a-request) |
| `woodev_{api_id}_http_request_args` | filter | `$args`, `$api` | Change the arguments passed to WordPress: headers, body, timeout. | [Calling external APIs](../../core/calling-external-apis/#hooks-around-a-request) |
| `woodev_{api_id}_request_timeout` | filter | `$timeout`, `$purpose`, `$api` | Change the timeout in seconds. `$purpose` is `rates`, `reference`, `export` or `default`. | [Calling external APIs](../../core/calling-external-apis/#timeouts-and-request-purpose) |
| `woodev_plugin_{id}_api_request_is_cacheable` | filter | `$cacheable`, `$request` | Turn the response cache off for one request. | [Calling external APIs](../../core/calling-external-apis/#caching-responses) |
| `woodev_plugin_{id}_api_request_cache_lifetime` | filter | `$lifetime`, `$request` | Change how long a response stays cached. | [Calling external APIs](../../core/calling-external-apis/#caching-responses) |
| `woodev_plugin_{id}_api_request_cache_max_bytes` | filter | `$max_bytes`, `$request` | Change the largest response that is cached. Zero or less removes the cap. | [Calling external APIs](../../core/calling-external-apis/#caching-responses) |
| `woodev_{id}_api_is_tls_1_2_available` | filter | `$available`, `$api` | Override the TLS 1.2 check that some carriers require. | [Calling external APIs](../../core/calling-external-apis/) |
| `woodev_api_log_text_secret_param_names` | filter | `$secret_names`, `$text` | Add the names of credentials that are masked in logged text. Use it when your carrier names a parameter in an uncommon way. | [Calling external APIs](../../core/calling-external-apis/#request-logging-and-secrets) |

:::note
The cache hooks use `woodev_plugin_{id}_…` while the others use `woodev_{api_id}_…`. Both names are correct, and both use the plugin ID by default.
:::

## Background jobs

A job handler fires these hooks under its own `{identifier}`, so two handlers never share a hook. See [Background processing](../../core/background-processing/).

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `{identifier}_job_created` | action | `$job` | React when a job is created. | [Background processing](../../core/background-processing/) |
| `{identifier}_job_updated` | action | `$job` | React when a job is saved. | [Background processing](../../core/background-processing/) |
| `{identifier}_job_complete` | action | `$job` | React when a job finishes. | [Background processing](../../core/background-processing/) |
| `{identifier}_job_failed` | action | `$job` | React when a job is marked failed. | [Background processing](../../core/background-processing/) |
| `{identifier}_job_deleted` | action | `$job` | React after a job is deleted. | [Background processing](../../core/background-processing/) |
| `{identifier}_new_job_attrs` | filter | `$attrs`, `$job_id` | Change the attributes of a new job. | [Background processing](../../core/background-processing/) |
| `{identifier}_returned_job` | filter | `$job` | Change a job as it is read from the database. | [Background processing](../../core/background-processing/) |
| `{identifier}_default_time_limit` | filter | `$seconds` | Change the time limit of one run. The default is 20. | [Background processing](../../core/background-processing/) |
| `{identifier}_time_exceeded` | filter | `$exceeded` | Override the time check. | [Background processing](../../core/background-processing/) |
| `{identifier}_memory_exceeded` | filter | `$exceeded` | Override the memory check. | [Background processing](../../core/background-processing/) |
| `{identifier}_queue_lock_time` | filter | `$seconds` | Change how long a run holds the lock. | [Background processing](../../core/background-processing/) |
| `{identifier}_cron_interval` | filter | `$minutes` | Change the health-check interval. | [Background processing](../../core/background-processing/) |
| `{identifier}_batch_handler_items_per_batch` | filter | `$items_per_batch` | Change how many items one browser-driven request processes. | [Background processing](../../core/background-processing/) |
| `{identifier}_batch_handler_js_args` | filter | `$args`, `$handler` | Change the arguments passed to the batch handler's JavaScript. | [Background processing](../../core/background-processing/) |

## Shipping: methods and rates

These hooks run while a shipping method prices a package and while a plugin registers its methods. See [Methods & rates](../../shipping/methods-and-rates/) and [Method features](../../shipping/method-features/).

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_shipping_method_before_calculate_shipping` | action | `$package`, `$method` | Run code before a method starts to price a package. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_method_pre_calculate_rate` | filter | `$rate`, `$package`, `$method` | Answer with a stored `Shipping_Rate` and skip the carrier call. Start from `null`. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_method_calculated_rate` | filter | `$rate`, `$package`, `$method` | Change the rate before it is added. Return `null` to add no rate. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_method_before_add_rate` | action | `$rate`, `$package`, `$method` | Run code just before a rate is added. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_method_after_add_rate` | action | `$rate`, `$package`, `$method` | Run code just after a rate is added. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_method_after_calculate_shipping` | action | `$package`, `$rate`, `$method` | Run code after pricing ends. `$rate` is `null` when no rate was added. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_method_rate_calculation_failed` | action | `$exception`, `$package`, `$method` | Add your own fallback, such as a flat rate, when the carrier call fails. The method is already hidden for that package. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_{method_id}_is_available` | filter | `$is_available`, `$package`, `$method` | Change whether the method is offered for a package. Return a `bool`. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_method_{method_id}_form_fields` | filter | `$form_fields`, `$method` | Change the instance settings fields of a method. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_method_{method_id}_supports_{feature}` | action | `$method`, `$name` | React when the method declares a feature. Dashes in the feature name become underscores. | [Method features](../../shipping/method-features/) |
| `woodev_shipping_resolved_services` | filter | `$services`, `$method`, `$subject` | Add or drop additional services before they reach the carrier. An entry is `code`, `name` and `parameter`; an entry without a code is dropped. | [Method features](../../shipping/method-features/) |
| `woodev_shipping_insurance_cod_gateways` | filter | `$gateway_ids`, `$method` | Say which payment gateway IDs count as cash on delivery for insurance. The default is `cod`. | [Method features](../../shipping/method-features/) |
| `woodev_shipping_rate_cache_enabled` | filter | `$enabled`, `$method`, `$package` | Turn the rate cache off for a method or package. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_rate_cache_ttl` | filter | `$ttl`, `$method`, `$package` | Change how long a rate is cached. The default is 600 seconds; zero or less turns the cache off. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_rate_cache_key_parts` | filter | `$parts`, `$method`, `$package` | Add what changes the answer but is not in the cache key. Parts must be plain data. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_rate_additional_info` | filter | `$blocks`, `$rate`, `$method`, `$index`, `$plugin` | Add or remove the blocks drawn under a rate on the order form. The blocks are keyed by name and echoed as HTML, so escape your own. | [Methods & rates](../../shipping/methods-and-rates/) |
| `woodev_shipping_plugin_before_register_method` | action | `$method_id`, `$class`, `$plugin` | Run code before a method class is registered. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_plugin_after_register_method` | action | `$method_id`, `$class`, `$plugin` | Run code after a method class is registered. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_plugin_method_classes` | filter | `$method_classes`, `$plugin` | Change the list of method classes before registration. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_plugin_registered_methods` | filter | `$methods`, `$plugin` | Change the final list of registered methods. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_plugin_{id_underscored}_accepted_currencies` | filter | `$currencies`, `$plugin` | Change the currency codes the plugin accepts. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_plugin_{id_underscored}_accepted_countries` | filter | `$countries`, `$plugin` | Change the country codes the plugin accepts. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_method_{method_id}_system_status_start` | action | `$method` | Add rows at the start of the method's block on the WooCommerce status page. | [Plugin anatomy](../../shipping/plugin-anatomy/) |
| `woodev_shipping_method_{method_id}_system_status_end` | action | `$method` | Add rows at the end of that block. | [Plugin anatomy](../../shipping/plugin-anatomy/) |

## Location

These hooks belong to the location layer: the typeahead at checkout and the providers behind it. See [Location & checkout fields](../../shipping/location-and-checkout-fields/).

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_location_providers` | filter | `$providers` | Add your own `Location_Provider` objects. Entries that do not implement the interface are rejected. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_active_provider` | filter | `$provider`, `$active_id` | Replace the provider chosen for a request. Return a `Location_Provider` or `null`. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_provider_for_level` | filter | `$provider`, `$level`, `$country` | Choose the provider that answers one suggestion level, such as city or street. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_default_country` | filter | `$country` | Change the country used when the shopper has not chosen one. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_geoip_country_mismatch` | filter | `$result`, `$record`, `$country` | Override what happens when a located record is in a different country than the shopper's. `$result` starts as `null`; return a `Location_Record` to use it, or leave `null` to refuse the record. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_region_ancestor_cache_ttl` | filter | `$ttl` | Change how long region lookups are cached. The default is one day. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_resolution_cache_ttl` | filter | `$ttl` | Make cached resolutions expire after this many seconds. The default `0` never expires them. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_i18n` | filter | `$strings` | Reword the typeahead's text, for example when your carrier calls a locality something else. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_customer_location_saved` | action | `$record`, `$implicit`, `$user_id` | React when a shopper's location is stored. `$implicit` is `true` for a guess, and `$user_id` is `0` for a guest. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_customer_location_forgotten` | action | `$user_id` | React when a stored location is erased. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_customer_location_ttl_seconds` | filter | `$ttl` | Change how long a stored location lives. Return `null` to keep it for good, or a positive number of seconds. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_customer_location_export_raw` | filter | `$raw`, `$record`, `$user_id` | Clean the provider's raw answer before it goes into a WordPress privacy export. Return `null` to leave it out. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_popular_settlement_list_cap` | filter | `$cap` | Change how many popular localities are kept per provider. The default is 30. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_popular_settlement_ttl_seconds` | filter | `$seconds` | Change how long a locality stays on the popular list after its last use. The default is two months. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_popular_settlement_verify_ttl_seconds` | filter | `$seconds` | Change how long a locality is trusted before it is checked again. The default is two weeks. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_provider_operation_failed` | action | `$provider_id`, `$operation`, `$exception` | Log a provider failure that the REST route turned into a 502. `$operation` is `suggest` or `list`. The exception is raw, so redact it before you log it. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_provider_countries` | filter | `$countries` | Widen or narrow the countries the bundled DaData provider reports. The default is RU, BY, KZ, UZ, AM, AZ, KG, TJ and TM. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_dadata_language` | filter | `$language`, `$locale` | Choose `ru` or `en` for DaData answers. The default follows the site locale. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_dadata_delivery_ids_cache_ttl` | filter | `$ttl`, `$found` | Change how long a DaData carrier-ID answer is cached. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_dadata_record_in_language_cache_ttl` | filter | `$ttl`, `$found` | Change how long a translated DaData record is cached. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_location_dadata_operation_failed` | action | `$operation`, `$exception` | Log a DaData call that failed and was handled. `$operation` is `suggest`, `locate`, `normalize` or `map`. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_rest_rate_limit_client_ip` | filter | `$address`, `$edge`, `$forwarded` | Tell the rate limiter of the public shipping REST routes (location, pickup, field sources) which client address to count. Return the verified address when your server sits behind a proxy you control. A value that is not a valid IP address is ignored. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |

## Pickup and maps

These hooks control the pickup-point map and the choice of a point. See [Pickup points & maps](../../shipping/pickup-points-and-maps/).

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_shipping_pickup_point_selectable` | filter | `$verdict`, `$point`, `$payment_method`, `$cart_weight`, `$method_context` | Change whether one point can be chosen. Return `['allowed' => bool, 'reason' => string or null]`. It runs for every point drawn, so keep it cheap. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_shipping_pickup_point_selection` | filter | `$result`, `$point`, `$context` | Confirm or correct a chosen point. It runs once per confirmation, so it is the place to call the carrier. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_shipping_pickup_point_selected` | action | `$point`, `$context` | React after a point is confirmed and allowed. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_shipping_pickup_recheck_outage_allows_checkout` | filter | `$allow`, `$exception`, `$plugin_id`, `$point_id` | Block checkout when the carrier is down during the final re-check. The default `true` lets the order through. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_accent_color` | filter | `$color` | Change the map's accent colour. Return a hex colour. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_accent_fill_color` | filter | `$fill_color`, `$accent` | Change the fill colour derived from the accent. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_accent_contrast_color` | filter | `$contrast_color`, `$accent` | Change the text colour drawn on that fill. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_map_point_glyphs` | filter | `$glyphs`, `$plugin_id` | Pick an icon per point type. The built-in values are `warehouse` and `package`; you can also return your own SVG markup. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_map_i18n` | filter | `$strings`, `$plugin_id` | Reword any text the map shows. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_map_search_enabled` | filter | `$enabled`, `$plugin_id` | Turn the address search on the map on or off. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_max_accumulated_points` | filter | `$max`, `$plugin_id` | Cap how many points the browser keeps while the shopper pans. The default `0` means no cap. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_distance_unit_system` | filter | `$unit_system`, `$plugin_id` | Show distances in `metric` or `imperial` units. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_pickup_max_remembered_selections` | filter | `$max` | Change how many chosen points the session remembers. The default is 20. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |
| `woodev_shipping_map_fallback_api_key` | filter | `$key` | Replace the fallback Yandex Maps key used when the merchant has not entered one. | [Pickup points & maps](../../shipping/pickup-points-and-maps/) |

## Checkout

These hooks cover the managed checkout fields, the block checkout and the payment methods offered with a shipping choice. See [Location & checkout fields](../../shipping/location-and-checkout-fields/).

Hooks marked with `{prefix}` are named `woodev_shipping_{prefix}…`. With the prefix `my-carrier_`, `checkout_fields` becomes `woodev_shipping_my-carrier_checkout_fields`.

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_shipping_{prefix}checkout_fields` | filter | `$checkout_fields`, `$section` | Change the checkout fields after the managed fields are added. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_shipping_{prefix}checkout_field_saved` | action | `$order`, `$id`, `$value` | React after one managed field is saved on the order. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_shipping_{prefix}checkout_data_saved` | action | `$order`, `$values` | React after all managed fields are saved. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_shipping_{prefix}checkout_processed` | action | `$order`, `$values` | React after posted checkout data is cleaned, checked and saved. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_shipping_{prefix}admin_order_saved` | action | `$order`, `$values`, `$context` | React after the admin order editor creates or updates an order of this carrier. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_shipping_store_api_posted_data` | filter | `$posted`, `$order` | Add the values a block-checkout order carries for your fields. Key each value by the field ID. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_checkout_pickup_slot_fields` | filter | `$slots`, `$fields` | Change which pickup-slot fields the checkout check enforces. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_pickup_slot_placements` | filter | `$placements`, `$field_id`, `$plugin_id` | Choose where the pickup button is placed on the checkout. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_checkout_field_policy_restore_invariants` | filter | `$restore`, `$fields` | Stop the framework from restoring the required address fields on a request. Use it only when your own field manager needs a field optional for one flow. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_shipping_field_policy_pickup_method_ids` | filter | `$ids` | Change which shipping methods hide the address fields. Remove `local_pickup` to keep the address for WooCommerce's own pickup. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_shipping_available_payment_gateways` | filter | `$gateways`, `$chosen_method`, `$pickup_point` | Remove payment gateways for the chosen method or point, for example cash on delivery at a point that takes no cash. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_phone_mask_patterns` | filter | `$patterns` | Change the phone mask per country. Keys are two-letter country codes, values use `#` for a digit. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |
| `woodev_phone_mask_trunk_prefixes` | filter | `$trunk_prefixes` | Change the national trunk digit per country. | [Location & checkout fields](../../shipping/location-and-checkout-fields/) |

## Orders, export and tracking

These hooks follow a shipment from the orders page to the carrier and back. The `{prefix}` hooks are named in the same way as in the checkout section. See [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/).

### Export, cancel, tracking and webhooks

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_shipping_order_export_requested` | action | `$order` | React just before any carrier's create call is sent. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_{prefix}shipment_exported` | action | `$order`, `$carrier_order_id` | React after an order is exported to your carrier. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_order_exported` | action | `$order`, `$carrier_order_id` | React after any carrier's export. Use it for work that must not depend on one plugin's prefix. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_{prefix}shipment_export_failed` | action | `$order`, `$exception` | React when an export fails. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_{prefix}shipment_cancelled` | action | `$order`, `$carrier_order_id` | React after a shipment is cancelled. The stored ID is already cleared, so use the argument. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_{prefix}shipment_cancel_failed` | action | `$order`, `$exception` | React when a cancellation fails. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_{prefix}tracking_admin_display` | action | `$history`, `$order` | Draw the tracking history on the admin order screen. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_{prefix}tracking_frontend_display` | action | `$history`, `$order` | Draw the tracking history for the shopper. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_{prefix}webhook_received` | action | `$payload`, `$request` | React to a verified carrier webhook. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_{prefix}webhook_touches_delivery_status` | filter | `$touches_status`, `$payload` | Say that a webhook payload is not a delivery-status update. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |

### Delivery status and shipment facts

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_shipping_delivery_status_changed` | action | `$order`, `$previous_status`, `$current_status`, `$provider` | React when a carrier's status change is saved. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_delivery_status_resolved` | filter | `$canonical`, `$raw`, `$status_map` | Change how a raw carrier status maps to the framework's status. A recorded cancellation cannot be overridden. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_delivery_sync_timestamp` | filter | `$timestamp`, `$carrier_id` | Change the time recorded as a carrier's last status sync. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_delivery_sync_recorded` | action | `$carrier_id`, `$timestamp` | React after a last-sync time is stored. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_carrier_cost_changed` | action | `$order`, `$old`, `$new`, `$currency`, `$provider`, `$component` | React when the carrier changes the delivery cost. WooCommerce totals are not changed. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_delivery_date_changed` | action | `$order`, `$date`, `$window`, `$kind`, `$previous`, `$provider` | React when the carrier sets or moves the delivery date. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_delivery_issue` | action | `$order`, `$code`, `$label`, `$at`, `$provider` | React when the carrier reports a delivery problem. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_courier_assigned` | action | `$order`, `$courier`, `$provider` | React when a courier is assigned or changed. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_shipment_fact_final_states` | filter | `$states`, `$provider` | Say which statuses close a delivery issue for a carrier. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_shipment_fact_cost_labels` | filter | `$labels` | Reword the cost components in order notes. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_shipment_fact_flag` | filter | `$flag`, `$kind`, `$order`, `$provider` | Decide whether a change puts the attention flag on an order row. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_shipment_fact_note` | filter | `$note`, `$kind`, `$order`, `$provider`, `$event` | Reword the order note for a change, or return an empty string for no note. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_delivery_email_placeholders` | filter | `$placeholders`, `$order`, `$provider` | Add carrier-specific placeholders to the delivery status email. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |

### The orders page

The orders page lists a carrier's orders and offers actions on them. A plugin that serves several carriers checks `$provider->get_id()` first.

| Hook | Type | Arguments | Use it to | Explained in |
|---|---|---|---|---|
| `woodev_shipping_orders_providers` | filter | `$providers` | Change the list of carriers shown on the orders page. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_query_args` | filter | `$args`, `$carrier` | Change the `wc_get_orders()` arguments before the query runs. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_cpt_query_args` | filter | `$query`, `$query_vars` | Change the query when orders are stored as posts. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_new_counts_ttl` | filter | `$ttl` | Change how long the menu badge counts are cached. The default is 60 seconds; zero or less turns the cache off. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_row` | filter | `$row`, `$order`, `$provider` | Change a finished row. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_row_destination` | filter | `$destination`, `$order`, `$provider` | Change the destination text of a row. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_order_row_flags` | filter | `$flags`, `$order`, `$provider` | Add badges under the tracking number. Each flag has a `label` and optional `tone`, `title` and `icon`. It runs for every row. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_preview` | filter | `$preview`, `$order`, `$provider` | Change the order preview. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_preview_fields` | filter | `$fields`, `$order`, `$provider` | Add lines to the preview. A line has `label`, `value` and optional `url` and `tone`. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_order_metabox_fields` | filter | `$fields`, `$order`, `$provider` | Add lines to the order-edit box of an exported order. Same line shape as the preview. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_exportable_statuses` | filter | `$statuses`, `$provider` | Change the order statuses an export may start from, without the `wc-` prefix. The list starts as `pending`, `on-hold`, `processing` plus every registered status that is not final (`completed`, `cancelled`, `refunded`, `failed`) or a draft, so a custom status is included. `$provider` is `null` where no carrier applies, such as the settings page. Every carrier plugin on the site shares the list unless you tell them apart by `$provider`. Since 2.0.14. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_order_actions` | filter | `$actions`, `$order`, `$provider` | Declare your own actions on an order row. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_perform_order_action` | filter | `$result`, `$action`, `$order`, `$provider`, `$payload` | Run one of your own actions. Return `Action_Result::success()` or `Action_Result::failure()`. Leave the value alone for actions that are not yours. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_order_action_field_options` | filter | `$options`, `$order`, `$action`, `$field_id`, `$provider` | Supply the options of a select field when its dialog opens. Return a list of `value` and `label`, or a `WP_Error`. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_toolbar_actions` | filter | `$actions` | Declare buttons above the orders table. Each needs an `id` and the `provider` that owns it. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_orders_toolbar_dialog` | filter | `$dialog`, `$action_id` | Supply the dialog of one toolbar action. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_perform_toolbar_action` | filter | `$result`, `$action_id`, `$order`, `$provider`, `$payload` | Run a toolbar action for one chosen order. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |
| `woodev_shipping_perform_toolbar_row_action` | filter | `$result`, `$action_id`, `$tab_id`, `$row_id`, `$row_action` | Run a button on a row of a dialog's list tab. | [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) |

## Payments (legacy)

The payment gateway layer is legacy and has no v2 reference plugin yet. Its hook names are built from the gateway ID, for example `wc_payment_gateway_my_gateway_payment_processed`. The most useful ones are below; see [Payment gateways](../../payments/payment-gateways/) for the classes behind them.

| Hook | Type | Arguments | Use it to |
|---|---|---|---|
| `wc_payment_gateway_{gateway_id}_form_fields` | filter | `$form_fields`, `$gateway` | Change the gateway's settings fields. |
| `wc_payment_gateway_{gateway_id}_order_button_text` | filter | `$text`, `$gateway` | Change the text of the checkout button. |
| `wc_gateway_{gateway_id}_is_available` | filter | `$is_available` | Change whether the gateway is offered. |
| `wc_payment_gateway_{gateway_id}_get_order` | filter | `$order`, `$gateway` | Add data to the order object a direct or hosted gateway works on. |
| `wc_payment_gateway_{gateway_id}_process_payment` | filter | `true`, `$order_id`, `$gateway` | Take over a direct payment by returning a result array. |
| `wc_payment_gateway_{gateway_id}_payment_processed` | action | `$order`, `$gateway` | React after a payment is approved. |
| `wc_payment_gateway_{gateway_id}_complete_payment` | action | `$order`, `$gateway` | React when an order is completed. |
| `wc_payment_gateway_{gateway_id}_refund_processed` | action | `$order`, `$gateway` | React after a refund succeeds. |
| `wc_payment_gateway_{gateway_id}_add_transaction_data` | action | `$order`, `$response`, `$gateway` | Save more data from a transaction response. |
| `wc_payment_gateway_{gateway_id}_held_order_status` | filter | `$status`, `$order`, `$response`, `$gateway` | Change the status given to a held order. The default is `on-hold`. |
| `wc_payment_gateway_{gateway_id}_payment_tokens_loaded` | action | `$tokens`, `$handler` | React when a customer's saved methods are loaded. |
| `wc_payment_gateway_{gateway_id}_payment_method_added` | action | `$token_id`, `$user_id`, `$response` | React when a customer saves a new method. |
| `wc_{gateway_id}_payment_form_start` | action | `$form` | Add markup at the start of the payment form. |
| `wc_{gateway_id}_payment_form_end` | action | `$form` | Add markup at the end of the payment form. |
| `wc_{id}_my_payment_methods_table_headers` | filter | `$headers`, `$table` | Change the columns of the saved-methods table on My Account. `{id}` is the plugin ID. |
| `wc_{handler_id}_js_args` | filter | `$args`, `$handler` | Change the arguments a script handler, such as the payment form, passes to its JavaScript. `{handler_id}` is the handler's `get_id()`. |

## Related

- [Calling external APIs](../../core/calling-external-apis/) and [Background processing](../../core/background-processing/) list the hooks of their own area next to the code that uses them.
- [Lifecycle & upgrades](../../core/lifecycle-and-upgrades/) explains the install, update and milestone hooks.
