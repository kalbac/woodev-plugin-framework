---
title: Licensing
description: Learn how a plugin declares licence support through the public API.
---

In this page you will learn the four things a plugin author needs to know about licensing: where the product id goes, where customers enter their key, how to mark a plugin that is sold without a key, and what a shipping plugin does while the licence is not active.

The framework handles licences and updates. Your plugin contains no licensing code.

## 1. The product id is `download_id`

The `download_id` field of the [loader definition](../../getting-started/loader-definition-reference/#download_id) is the plugin's product id in the woodev.ru store. It is a positive integer, unique per plugin, written as a literal:

```php
<?php
defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'woodev/loader.php';

Woodev_Loader::register(
	__FILE__,
	[
		'plugin_id'         => 'my-carrier',
		'download_id'       => 1234,
		'plugin_name'       => 'My Carrier',
		'plugin_version'    => '1.0.0',
		'framework_version' => '%%FRAMEWORK_VERSION%%',
		'platform'          => 'woocommerce',
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.6',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'My_Carrier_Plugin',
	]
);
```

## 2. Customers enter the key on the Licences page

Customers enter and manage their keys on one framework page, **«Woodev → Лицензии»** (Licences). It lists every Woodev plugin on the site. Your plugin writes no licence form and registers no licence screen.

## 3. A plugin sold without a key says so

A plugin is assumed to need a licence key. If yours is sold without one, override `is_need_license()` in the main class to return `false`:

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the constructor, instance(), get_file() and the rest from "Your first plugin"...

	/**
	 * Tells the framework that this plugin is sold without a licence key.
	 *
	 * @return bool
	 */
	public function is_need_license(): bool {
		return false;
	}
}
```

The Licences page then shows «Лицензия не требуется» (No licence needed) for the plugin, and the plugin does not ask customers for a key.

## 4. Shipping plugins: while the licence is not active

For a shipping plugin, while the licence is not active, **shipping rates and carrier actions are unavailable**. An action that only reads from the carrier, such as refreshing a status, can be marked `'read_only' => true` so that it keeps working.

You declare an order action in the `woodev_shipping_order_actions` filter and carry it out in the `woodev_shipping_perform_order_action` filter. Add `'read_only' => true` to the declaration:

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Order\Action_Result;

/**
 * Declares a read-only order action.
 *
 * @param array $actions The actions offered for the order.
 * @return array
 */
function my_carrier_declare_order_actions( $actions ) {
	$actions[] = [
		'action'    => 'my-carrier-refresh-status',
		'label'     => 'Обновить статус',
		'title'     => 'Запросить у перевозчика текущий статус',
		'read_only' => true,
	];

	return $actions;
}
add_filter( 'woodev_shipping_order_actions', 'my_carrier_declare_order_actions' );

/**
 * Carries out the action.
 *
 * @param Action_Result $result The outcome so far.
 * @param string        $action The action id.
 * @return Action_Result
 */
function my_carrier_perform_order_action( $result, $action ) {
	if ( 'my-carrier-refresh-status' !== $action ) {
		return $result;
	}

	// Ask the carrier for the status here. This sketch only reports success.
	return Action_Result::success( '', 'Статус обновлён.' );
}
add_filter( 'woodev_shipping_perform_order_action', 'my_carrier_perform_order_action', 10, 2 );
```

Only an explicit `true` counts. An action without the flag is treated as a new carrier operation, so mark an action `read_only` only if it never creates, changes or cancels anything at the carrier.

## Where to go next

- [Loader definition reference](../../getting-started/loader-definition-reference/) explains every field of the definition, including `download_id`.
- [Shipping plugin anatomy](../../shipping/plugin-anatomy/) shows how a carrier plugin is put together.
- [Orders, export, documents & tracking](../../shipping/orders-export-documents-and-tracking/) covers order actions in full.
