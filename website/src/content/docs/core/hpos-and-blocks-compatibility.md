---
title: HPOS & Blocks compatibility
description: Learn how to declare HPOS and Cart/Checkout Blocks support, and how to read and write order data that works on both order stores.
---

In this page you will learn where to declare HPOS and Cart/Checkout Blocks support, how to check it in code, how to read and write order data on either order store, and why `wc_get_orders()` needs care with meta queries.

Covers framework release `%%FRAMEWORK_VERSION%%`.

## Two things to know first

- **HPOS** (High-Performance Order Storage) keeps orders in their own database tables. A store can use it or the older posts-based store, and a plugin has to work with both.
- **Cart/Checkout Blocks** are the block-based Cart and Checkout pages, which replace the classic shortcode pages.

The framework handles the declaration to WooCommerce and the merchant warnings. You supply two things: the declaration, and order code that does not assume a store.

## Declare support

Declare both features in `supported_features` of the loader definition. This is the **one** place:

```php
<?php
defined( 'ABSPATH' ) || exit;

defined( 'MY_CARRIER_FILE' ) || define( 'MY_CARRIER_FILE', __FILE__ );
defined( 'MY_CARRIER_VERSION' ) || define( 'MY_CARRIER_VERSION', '1.0.0' );

require_once plugin_dir_path( __FILE__ ) . 'woodev/loader.php';

/**
 * Builds the plugin once the framework is ready.
 *
 * @return void
 */
function my_carrier_start(): void {
	require_once plugin_dir_path( MY_CARRIER_FILE ) . 'includes/class-my-carrier-plugin.php';

	My_Carrier_Plugin::instance();
}

Woodev_Loader::register(
	MY_CARRIER_FILE,
	[
		'plugin_id'            => 'my-carrier',
		'download_id'          => 1234,
		'plugin_name'          => 'My Carrier',
		'plugin_version'       => MY_CARRIER_VERSION,
		'framework_version'    => '%%FRAMEWORK_VERSION%%',
		'backwards_compatible' => '2.0.0',
		'platform'             => 'woocommerce',
		'requirements'         => [
			'php'         => '7.4',
			'wordpress'   => '6.6',
			'woocommerce' => '7.0',
		],
		'supported_features'   => [
			'hpos'   => true,
			'blocks' => [
				'cart'     => true,
				'checkout' => true,
			],
		],
		'main_class'           => 'My_Carrier_Plugin',
		'callback'             => 'my_carrier_start',
	]
);
```

| Key | Default | Meaning |
|---|---|---|
| `hpos` | `false` | The plugin works with High-Performance Order Storage. |
| `blocks.cart` | `false` | The plugin works with the Cart block. |
| `blocks.checkout` | `false` | The plugin works with the Checkout block. |

A definition with `'type' => 'shipping'` defaults both block keys to `true`. See the [Loader definition reference](../../getting-started/loader-definition-reference/#supported_features).

What the framework does with the declaration:

- It tells WooCommerce early, before WooCommerce finishes starting, so the Plugins screen shows no incompatibility warning.
- HPOS is declared compatible only if `hpos` is `true` **and** WooCommerce is 7.6 or newer.
- Cart/Checkout Blocks are declared compatible only if **both** `blocks.cart` and `blocks.checkout` are `true`.

:::caution
Never declare these features in the plugin constructor. WooCommerce reads the declaration before your class is built, so only the loader definition is early enough. If a constructor value disagrees with the definition, the definition wins and WordPress logs a developer notice.
:::

Declare a feature only if the code supports it. A plugin that declares `hpos` and then reads orders with `get_post_meta()` loses data on stores that use HPOS.

## Check support in code

Three methods answer three different questions:

| Method | Question |
|---|---|
| `$plugin->is_hpos_compatible()` | Did **this plugin** declare HPOS, and is WooCommerce 7.6 or newer? |
| `$plugin->get_supported_features()` | What did the loader definition declare? Returns `[ 'hpos' => bool, 'blocks' => [ 'cart' => bool, 'checkout' => bool ] ]`. |
| `Woodev_Plugin_Compatibility::is_hpos_enabled()` | Is this **store** using HPOS tables right now? |

Do not confuse the first and the last. `is_hpos_compatible()` is about your plugin's promise. `is_hpos_enabled()` is about the store's setting, and it is the one that decides which storage code path runs.

## Cart and Checkout Blocks

If a merchant's Cart or Checkout page uses a block that your declaration does not cover, wp-admin shows an error notice: «My Carrier is not yet compatible with the Checkout block.» It links to the guide for reverting to the shortcode and has an «Edit the Checkout Page» button. The notice disappears when the page is changed or when you declare support.

The notices come from the blocks handler. A few things about it:

- `get_blocks_handler()` returns the handler, or `null` for a pure `Woodev_Plugin`. Check for `null` before you use it.
- `Woodev_Blocks_Handler::is_cart_block_in_use()` and `is_checkout_block_in_use()` are static. They tell you whether the store's page uses the block.
- To register your own block integration, override `init_blocks_handler()` in the plugin class and assign a subclass of `Woodev_Blocks_Handler` to `$this->blocks_handler`. Call the parent constructor from the subclass.

## Read and write order data

On a store with HPOS, order meta lives in an orders meta table. `get_post_meta()` and `update_post_meta()` read and write the posts table instead, so they silently miss it. Use one of these:

- The `WC_Order` object, when you already hold it: `$order->get_meta()`, `$order->update_meta_data()` followed by `$order->save()`.
- `Woodev_Order_Compatibility`, when you only have an order ID or object and want one call that picks the right store.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stores a tracking number on an order, on either order store.
 *
 * @param int|\WC_Order $order           The order, or its ID.
 * @param string        $tracking_number The tracking number.
 * @return void
 */
function my_carrier_save_tracking_number( $order, string $tracking_number ): void {
	Woodev_Order_Compatibility::update_order_meta( $order, '_my_carrier_tracking_number', $tracking_number );
}

/**
 * Reads the tracking number of an order, or an empty string.
 *
 * @param int|\WC_Order $order The order, or its ID.
 * @return string
 */
function my_carrier_get_tracking_number( $order ): string {
	if ( ! Woodev_Order_Compatibility::order_meta_exists( $order, '_my_carrier_tracking_number' ) ) {
		return '';
	}

	return (string) Woodev_Order_Compatibility::get_order_meta( $order, '_my_carrier_tracking_number' );
}
```

`update_order_meta()`, `add_order_meta()` and `delete_order_meta()` save immediately. You do not call `$order->save()` afterwards.

### Order meta helpers

All methods on `Woodev_Order_Compatibility` are static.

| Method | Does |
|---|---|
| `get_order_meta( $order, $meta_key, $single = true )` | Reads meta. |
| `update_order_meta( $order, $meta_key, $meta_value )` | Writes meta, replacing a value that exists. |
| `add_order_meta( $order, $meta_key, $meta_value, $unique = false )` | Adds meta, optionally only if the key is not there. |
| `delete_order_meta( $order, $meta_key, $meta_value = '' )` | Deletes meta. |
| `order_meta_exists( $order, $meta_key )` | Whether the key exists. |
| `is_order( $id_or_object )`, `is_refund( $id_or_object )` | Whether an ID is an order, or a refund. |

### Order screens and tables

Order screens differ between stores: a posts list and edit screen, or the `wc-orders` page. These helpers hide the difference:

| Method | Does |
|---|---|
| `get_orders_screen_url()` | The URL of the orders list. |
| `get_edit_order_url( $order )` | The URL of an order's edit screen. |
| `is_orders_screen()` | Whether the current screen is the orders list. |
| `is_orders_screen_for_status( $status )` | The same, filtered to one or more statuses. |
| `is_order_edit_screen()` | Whether the current screen adds or edits an order. |
| `is_order_screen()` | Whether it is either of the two. |
| `get_order_id_for_order_edit_screen()` | The ID of the order being edited, or `null`. |
| `get_order_screen_id()` | The admin screen ID of orders. |
| `get_orders_table()`, `get_orders_meta_table()` | The table names, for the rare raw query. |
| `get_order_post_types()` | The order post types. |

Use them to load assets only where they are needed:

```php
<?php
defined( 'ABSPATH' ) || exit;

add_action(
	'admin_enqueue_scripts',
	static function (): void {
		if ( ! Woodev_Order_Compatibility::is_order_edit_screen() ) {
			return;
		}

		wp_enqueue_script(
			'my-carrier-order',
			plugins_url( 'assets/js/order.js', MY_CARRIER_FILE ),
			[ 'jquery' ],
			MY_CARRIER_VERSION,
			true
		);
	}
);
```

## Check the WooCommerce version

`Woodev_Plugin_Compatibility` has the other compatibility checks, all static:

| Method | Does |
|---|---|
| `get_wc_version()` | The WooCommerce version, or `null`. |
| `is_wc_version( $version )` | Whether the version equals the one you pass. |
| `is_wc_version_gte( $version )`, `is_wc_version_gt( $version )`, `is_wc_version_lt( $version )` | Compare with `>=`, `>` and `<`. |
| `is_hpos_enabled()` | Whether the store uses HPOS tables. |
| `normalize_wc_screen_id( $slug )` | The WooCommerce screen ID for a slug such as `wc-settings`, which depends on the site's language. |

```php
<?php
if ( Woodev_Plugin_Compatibility::is_wc_version_gte( '9.0' ) ) {
	// Use a feature that WooCommerce 9.0 introduced.
}
```

A WooCommerce version below your declared minimum never reaches your code. Use these checks for features that are newer than that minimum.

## Find orders by meta

:::caution
`wc_get_orders()` silently **ignores** a `meta_query` argument on the posts-based order store. It does not raise an error. It returns every order on the site, as if you had passed no filter. HPOS honours the argument, so the code looks correct on a development site that uses HPOS and fails on a store that does not.
:::

On WooCommerce 9.2 or newer, a developer notice (`Order query argument (meta_query) is not supported on the current order datastore.`) is the only symptom. On older versions there is none.

Two ways to stay safe:

1. **One exact key and value.** Use the top-level `meta_key`, `meta_value` and `meta_compare` arguments. Both stores support them:

   ```php
   <?php
   $orders = wc_get_orders(
   	[
   		'limit'      => 20,
   		'meta_key'   => '_my_carrier_tracking_number',
   		'meta_value' => $tracking_number,
   	]
   );
   ```

2. **Anything richer**, such as OR groups, `NOT EXISTS` or numeric comparison. Branch on the store. On HPOS, pass `meta_query`. On the posts store, carry the query under a private query variable, and translate it to a real `meta_query` in the `woocommerce_order_data_store_cpt_get_orders_query` filter. Never put a `meta_query` key in the arguments on that path, because its presence is what raises the notice.

This class does both and is safe to call from anywhere:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Finds orders by meta on both order stores.
 */
final class My_Carrier_Order_Query {

	/**
	 * The private query variable that carries the meta query on the posts store.
	 */
	private const QUERY_VAR = 'my_carrier_meta_query';

	/**
	 * How many calls to get_orders() are running at once.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Gets orders that match a meta query.
	 *
	 * @param array<string, mixed> $args       Arguments for wc_get_orders(), without "meta_query".
	 * @param array<int|string, mixed> $meta_query A complete meta query, as WP_Meta_Query accepts it.
	 * @return array<int, \WC_Order|int>
	 */
	public static function get_orders( array $args, array $meta_query ): array {
		if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
			$args['meta_query'] = $meta_query;

			return wc_get_orders( $args );
		}

		$args[ self::QUERY_VAR ] = $meta_query;

		// Hook on the way in and unhook on the way out of the outermost call only.
		if ( 0 === self::$depth ) {
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ self::class, 'translate_query_var' ], 10, 2 );
		}

		++self::$depth;

		try {
			return wc_get_orders( $args );
		} finally {
			--self::$depth;

			if ( 0 === self::$depth ) {
				remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ self::class, 'translate_query_var' ], 10 );
			}
		}
	}

	/**
	 * Turns the private query variable into a real meta query on the posts store.
	 *
	 * @param array<string, mixed> $wp_query_args The WP_Query arguments WooCommerce built.
	 * @param array<string, mixed> $query_vars    The arguments passed to wc_get_orders().
	 * @return array<string, mixed>
	 */
	public static function translate_query_var( array $wp_query_args, array $query_vars ): array {
		if ( empty( $query_vars[ self::QUERY_VAR ] ) ) {
			return $wp_query_args;
		}

		$existing = $wp_query_args['meta_query'] ?? [];

		$wp_query_args['meta_query'] = [] === $existing
			? $query_vars[ self::QUERY_VAR ]
			: [
				'relation' => 'AND',
				$existing,
				$query_vars[ self::QUERY_VAR ],
			];

		return $wp_query_args;
	}
}
```

Call it with the same meta query on either store:

```php
<?php
$orders = My_Carrier_Order_Query::get_orders(
	[
		'limit'  => 50,
		'status' => 'processing',
	],
	[
		'relation' => 'OR',
		[
			'key'     => '_my_carrier_tracking_number',
			'compare' => 'EXISTS',
		],
		[
			'key'     => '_my_carrier_pickup_point',
			'compare' => 'EXISTS',
		],
	]
);
```

Three more rules for order queries:

- **A "matches nothing" filter is not an empty filter.** Every layer between you and the database may read an empty or unknown filter as "no filter". If you need an empty result, return an empty array yourself and skip the query.
- **`NOT IN` drops orders that have no such meta at all.** Use `NOT EXISTS` when you mean "does not have the key".
- **Test every order query on both stores.** A result that looks right on one store proves nothing about the other.

## Next steps

- [Loader definition reference](../../getting-started/loader-definition-reference/) lists every field of the definition.
- [The plugin class](../plugin-class/) covers the other override points.
