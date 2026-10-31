---
title: "Methods & rates"
description: Define shipping methods and calculate rates for a cart.
---

In this page you will learn how to write a shipping method: which base class to extend, how `rate_package()` turns a cart into a rate, what the rate object carries, and which hooks you can use around it.

A shipping method is a class that WooCommerce shows in a shipping zone. The merchant adds it to a zone, sets its instance fields, and WooCommerce asks it for a rate every time the cart changes. The framework's `Shipping_Method` is a template around that call; you fill in the part that is specific to your carrier.

## Pick a base class

Extend one of the three delivery-type classes in `\Woodev\Framework\Shipping`:

| Class | Delivery type | What it adds |
|---|---|---|
| `Shipping_Method_Courier` | `courier` | Nothing else. Delivery to the customer's door. |
| `Shipping_Method_Pickup` | `pickup` | An abstract `get_point_source()`, which returns the carrier's pickup points. See [Pickup points & maps](../pickup-points-and-maps/). |
| `Shipping_Method_Postal` | `postal` | The method is unavailable until the customer has entered a postcode. |

All three set `get_delivery_type()` for you, and it is `final`. If you extend `Shipping_Method` itself, you implement `get_delivery_type()` and return `Shipping_Method::TYPE_COURIER`, `TYPE_PICKUP` or `TYPE_POSTAL`.

A postal method also has a helper, `get_extra_form_fields()`, with a "Require postal code" checkbox that is on by default. Add it to your own form fields if the merchant should be able to switch the requirement off.

:::note
Give every delivery type its **own method id**. The framework tells a pickup method from a courier method by its id, so one id cannot serve both. A carrier that offers courier delivery and pickup registers two method classes with two ids.
:::

## What you implement

| Member | Purpose |
|---|---|
| `public static function get_method_id(): string` | The id WooCommerce stores in the shipping zone. Never change it after release: a changed id orphans every zone the merchant has set up. |
| `protected function get_plugin(): Shipping_Plugin` | Returns your plugin object. |
| `protected function get_method_form_fields(): array` | The instance fields of your method, in WooCommerce's settings-field format. |
| `protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate` | Quotes one package. |

The framework adds a `title` field and a `description` field to every method before yours. The merchant's title becomes the rate label in the cart, and the description is shown under it.

### Name the method before `parent::__construct()`

Set the admin-facing name and description in your constructor **before** you call the parent constructor, in the same place where you set `$this->supports`:

```php
<?php
$this->method_title       = __( 'My Carrier: courier', 'my-carrier' );
$this->method_description = __( 'Delivery to the customer\'s door.', 'my-carrier' );
```

WooCommerce shows them in the «Add shipping method» window and in the zone's method list. Without them the card is blank and the merchant cannot tell what they are adding. The framework falls back to a generic name for the delivery type and raises a developer notice under `WP_DEBUG`, but a fallback is a safety net, not a name.

### Declare features

A feature is an optional capability, such as cost limits or box packing. [Method features](../method-features/) lists them all. There are two ways to declare one:

- Assign `$this->supports` **before** `parent::__construct()`. The framework keeps what you assigned and adds `shipping-zones` and `instance-settings` itself.
- Call `$this->add_support( $feature )` **after** `parent::__construct()`. The framework rebuilds the instance form, so the feature's field appears.

:::caution
Do not call `add_support()` before `parent::__construct()`. The method id is not set yet, so the hook that announces the feature is fired under a wrong name, and `WP_DEBUG` reports it. Use `$this->supports` there instead.
:::

## What `rate_package()` returns

`rate_package()` is the one place where your carrier logic runs. It receives the WooCommerce shipping package and, when the method uses [box packing](../packaging-and-box-packing/), the packed parcels. Otherwise `$packed` is `null`.

It answers in one of three ways:

| You | The framework |
|---|---|
| Return a `Shipping_Rate` | Adds the rate to the cart. |
| Return `null` | Adds nothing. The carrier legitimately does not serve this case: the destination, a parcel too heavy, a tariff that does not apply. |
| Throw a `\Woodev_Plugin_Exception` | Treats the carrier as failed. It hides the method for this package, writes the failure to the log at the ERROR level, and never lets the exception reach the customer. |

`\Woodev_API_Exception` and `\Woodev_Packer_Exception` are both `\Woodev_Plugin_Exception`, so a failed API call or a failed packing can simply propagate.

The text of a carrier's exception is never shown on a screen, because it can contain the carrier's own wording or a request URL. If a site needs a fallback, such as a flat rate, it can hook the failure:

```php
<?php
add_action(
	'woodev_shipping_method_rate_calculation_failed',
	static function ( \Woodev_Plugin_Exception $exception, array $package, $method ): void {
		// The method is already hidden. Add your own fallback rate here if the site needs one.
	},
	10,
	3
);
```

`calculate_shipping()` is `final`, so you cannot override it. It runs the hooks, the availability checks, the optional rate cache, and calls `rate_package()`. Implement `rate_package()` instead.

### One total, not a sum of parcels

When the cart is packed into several parcels, ask the carrier for **one** quote for the whole shipment and return that total. The framework does not add up per-parcel prices.

## The rate object

`Shipping_Rate` is an immutable value object. Its constructor takes seven arguments, in this order: `new Shipping_Rate( $method_id, $id, $label, $cost, $package, $meta_data, $args )`. Only the first three are required.

| Argument | Meaning |
|---|---|
| `$method_id` | The WooCommerce method id, `$this->id`. Must not be empty. |
| `$id` | The rate id, usually `$this->get_rate_id()`. Must not be empty. |
| `$label` | What the customer reads. An empty label is replaced with the method's title. |
| `$cost` | A number, a numeric string, or an array of costs. Defaults to `'0'`. |
| `$package` | A flag or package data, almost always `null`. |
| `$meta_data` | Key-value pairs saved on the order's shipping line. |
| `$args` | Further WooCommerce rate attributes (below). |

An empty method id or rate id throws an `\InvalidArgumentException`, because WooCommerce cannot show such a rate. An empty label or a cost that is not a number does not throw: it is reported under `WP_DEBUG` and the rate degrades, so a customer is never stopped at checkout.

`$meta_data` is saved flat on the order's shipping line. Keys that start with an underscore are hidden from the customer. To hide other keys from the admin order screen, override the static `get_hidden_order_item_meta_keys()` and merge the framework's list:

```php
<?php
use Woodev\Framework\Shipping\Shipping_Method_Courier;

final class My_Carrier_Courier_Method extends Shipping_Method_Courier {

	/**
	 * Lists the order item meta keys that the admin order screen should hide.
	 *
	 * @return string[]
	 */
	public static function get_hidden_order_item_meta_keys(): array {
		return array_merge( parent::get_hidden_order_item_meta_keys(), [ 'my_carrier_tariff' ] );
	}
}
```

`$args` accepts three keys that go straight to WooCommerce's `add_rate()`: `taxes`, `calc_tax` and `price_decimals`. It also accepts two attributes that the framework applies to the finished rate:

- `description` is the text under the rate. When you leave it out, the merchant's `description` field is used.
- `delivery_time` is the delivery time that WooCommerce shows next to the rate. `Shipping_Helper::format_delivery_time( 2, 3 )` returns «2-3 days». WooCommerce shows it from version 9.2. To let the merchant add extra days or hide the period, report the days with `$rate->with_delivery_days( 2, 3 )` instead and declare [`FEATURE_DELIVERY_TIME`](../method-features/#delivery-time).

Use `with_cost()`, `with_label()`, `with_meta_data()` and `with_args()` to change a rate. Each returns a new rate.

## Say why a method is hidden

A method that returns `null` or fails is invisible to everyone, including the manager who is trying to find out why. Call `report_hidden()` on every refusal path:

```php
<?php
$this->report_hidden( 'too_heavy', __( 'the parcel is heavier than the carrier accepts', 'my-carrier' ) );
```

- The first argument is a stable reason code. The second is the reason, written for a shop manager.
- Write the reason only. Do not put the method's name in it: the framework records the method's title beside it and groups methods with the same reason into one line.
- Never pass a carrier's exception text.
- Report the reason in the same helper that writes your log line, so the log and the hint cannot disagree.
- When you report your own reason before you rethrow a failure, the framework skips its generic «rate calculation failed» entry.

The manager sees the hint on the classic checkout and on the block checkout. It appears only while the plugin's «Логирование» (Logging) switch is on, and only for a user who can `manage_woocommerce`. For a guest the call does nothing. The framework itself reports two reasons: an inactive licence, and a failed rate calculation.

## Cache rates

Asking a carrier's API on every cart change is slow. A method can opt in to a short-lived rate cache by declaring `FEATURE_RATE_CACHE`. A rate is then kept for 10 minutes. Only a successful rate is stored: a failure and a `null` answer never are.

Declaring the feature is a promise: **`get_rate_cache_context()` names every input that your price depends on.** Two calculations with an equal context get the same cached rate. The framework's default context already covers the package contents and destination, the instance settings, the packing mode, the payment method and the selected pickup point. Anything else that `rate_package()` reads, such as credentials or a plugin-wide setting, you add yourself:

```php
<?php
use Woodev\Framework\Shipping\Shipping_Method_Courier;

final class My_Carrier_Courier_Method extends Shipping_Method_Courier {

	/**
	 * Adds what the price depends on beyond the framework's default cache key.
	 *
	 * @param array<string,mixed> $package The WooCommerce shipping package.
	 * @return array<string,mixed>
	 */
	public function get_rate_cache_context( array $package ): array {
		$context                = parent::get_rate_cache_context( $package );
		$context['sender_city'] = (string) My_Carrier_Plugin::instance()->get_my_settings()->get_value( 'sender_city' );

		return $context;
	}
}
```

Keep the method public. The values must be scalars, `null` or arrays of them. Anything else turns the cache off for that call. A plugin-wide setting is not an instance setting, so without this line a change to it would keep serving the old price.

A site can change the cache with three filters:

| Filter | Arguments | Effect |
|---|---|---|
| `woodev_shipping_rate_cache_enabled` | `$enabled`, `$method`, `$package` | Return `false` to turn the cache off. It can never turn it on for a method that did not declare the feature. |
| `woodev_shipping_rate_cache_ttl` | `$seconds`, `$method`, `$package` | Change the 10-minute lifetime. Zero or less turns the cache off. |
| `woodev_shipping_rate_cache_key_parts` | `$parts`, `$method`, `$package` | Add key parts that the framework cannot see. |

## Hooks around a rate

These hooks fire for every method, in this order. The `$method` argument is the `Shipping_Method` object.

| Hook | Type | Arguments | Use it to |
|---|---|---|---|
| `woodev_shipping_method_before_calculate_shipping` | action | `$package`, `$method` | Prepare something before the calculation. |
| `woodev_shipping_{method_id}_is_available` | filter | `$is_available`, `$package`, `$method` | Hide a method for a package. |
| `woodev_shipping_method_pre_calculate_rate` | filter | `null`, `$package`, `$method` | Supply a rate yourself and skip `rate_package()`. Return a `Shipping_Rate`. |
| `woodev_shipping_method_calculated_rate` | filter | `$rate`, `$package`, `$method` | Change the finished rate or drop it with `null`. |
| `woodev_shipping_method_before_add_rate` | action | `$rate`, `$package`, `$method` | Act just before the rate is added. |
| `woodev_shipping_method_after_add_rate` | action | `$rate`, `$package`, `$method` | Act just after. |
| `woodev_shipping_method_after_calculate_shipping` | action | `$package`, `$rate`, `$method` | Act at the end, with or without a rate. |
| `woodev_shipping_method_rate_calculation_failed` | action | `$exception`, `$package`, `$method` | React to a carrier failure (above). |
| `woodev_shipping_method_{method_id}_form_fields` | filter | `$fields`, `$method` | Change the method's instance form fields. |

A filter that returns the wrong type is ignored, and the value from before the filter is kept. Inside your own class you can override two protected methods instead of using the actions: `before_calculate( array $package )` and `after_calculate( array $package, ?Shipping_Rate $rate )`.

## A full method

This method quotes a courier delivery from a base cost and a price per kilogram. It uses a cheaper base cost inside the sender's city, becomes free above a threshold the merchant sets, reports why it is hidden, and caches its rates. Load it from the entry file of [Shipping plugin anatomy](../plugin-anatomy/).

`my-carrier/includes/shipping/class-my-carrier-courier-method.php`

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\{Shipping_Helper, Shipping_Method_Courier, Shipping_Plugin, Shipping_Rate};

/**
 * Courier delivery by My Carrier.
 */
final class My_Carrier_Courier_Method extends Shipping_Method_Courier {

	/** The method id, stored in every shipping zone that uses the method. */
	const METHOD_ID = 'my_carrier_courier';

	/** The heaviest parcel the carrier accepts, in kilograms. */
	const MAX_WEIGHT_KG = 30;

	/**
	 * Sets up the method.
	 *
	 * @param int $instance_id The shipping zone instance id, or 0 when the method is not in a zone.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->method_title       = __( 'My Carrier: courier', 'my-carrier' );
		$this->method_description = __( 'Delivery to the customer\'s door by a My Carrier courier.', 'my-carrier' );
		$this->supports           = [ self::FEATURE_RATE_CACHE ];

		parent::__construct( $instance_id );
	}

	/**
	 * Gets the method id.
	 *
	 * @return string
	 */
	public static function get_method_id(): string {
		return self::METHOD_ID;
	}

	/**
	 * Gets the plugin that owns the method.
	 *
	 * @return Shipping_Plugin
	 */
	protected function get_plugin(): Shipping_Plugin {
		return My_Carrier_Plugin::instance();
	}

	/**
	 * Gets the instance fields the merchant edits in the shipping zone.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_method_form_fields(): array {
		return [
			'base_cost'   => [
				'title'   => __( 'Base cost', 'my-carrier' ),
				'type'    => 'price',
				'default' => '300',
			],
			'local_cost'  => [
				'title'    => __( 'Base cost in the sender city', 'my-carrier' ),
				'type'     => 'price',
				'default'  => '150',
				'desc_tip' => __( 'Used when the customer lives in the same city as the sender.', 'my-carrier' ),
			],
			'cost_per_kg' => [
				'title'   => __( 'Cost per kilogram', 'my-carrier' ),
				'type'    => 'price',
				'default' => '20',
			],
			'free_from'   => [
				'title'       => __( 'Free delivery from', 'my-carrier' ),
				'type'        => 'price',
				'default'     => '',
				'placeholder' => __( 'Never', 'my-carrier' ),
			],
		];
	}

	/**
	 * Quotes one package.
	 *
	 * @param array<string,mixed>        $package The WooCommerce shipping package.
	 * @param \Woodev_Packer_Result|null $packed  The packed parcels, or null.
	 * @return Shipping_Rate|null A rate, or null when the carrier does not serve the package.
	 *
	 * @throws \Woodev_Plugin_Exception When the carrier cannot be asked.
	 */
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
		$city = Shipping_Helper::get_package_city( $package );

		if ( '' === $city ) {
			return $this->hide( 'no_city', __( 'the customer has not entered a city yet', 'my-carrier' ) );
		}

		$weight_kg = Shipping_Helper::get_package_weight( $package ) / 1000;

		if ( $weight_kg > self::MAX_WEIGHT_KG ) {
			return $this->hide( 'too_heavy', __( 'the order is heavier than the carrier accepts', 'my-carrier' ) );
		}

		$cost      = $this->ask_carrier( $weight_kg, $city );
		$free_from = (string) $this->get_option( 'free_from', '' );

		if ( '' !== $free_from && Shipping_Helper::get_package_total( $package ) >= (float) $free_from ) {
			$cost = 0.0;
		}

		return new Shipping_Rate(
			$this->id,
			$this->get_rate_id(),
			$this->get_title(),
			$cost,
			null,
			[],
			[ 'delivery_time' => Shipping_Helper::format_delivery_time( 2, 3 ) ]
		);
	}

	/**
	 * Adds what the price depends on beyond the framework's default cache key.
	 *
	 * @param array<string,mixed> $package The WooCommerce shipping package.
	 * @return array<string,mixed>
	 */
	public function get_rate_cache_context( array $package ): array {
		$context                = parent::get_rate_cache_context( $package );
		$context['sender_city'] = $this->get_sender_city();

		return $context;
	}

	/**
	 * Gets the price from the carrier.
	 *
	 * A real method sends a request to the carrier here, and throws a Woodev_Plugin_Exception when the
	 * call fails. This example computes the price from the instance fields.
	 *
	 * @param float  $weight_kg The parcel weight in kilograms.
	 * @param string $city      The destination city.
	 * @return float
	 *
	 * @throws \Woodev_Plugin_Exception When the plugin has no API key.
	 */
	private function ask_carrier( float $weight_kg, string $city ): float {
		if ( '' === (string) My_Carrier_Plugin::instance()->get_my_settings()->get_value( 'api_key' ) ) {
			throw new \Woodev_Plugin_Exception( 'The My Carrier API key is missing.' );
		}

		$is_local = 0 === strcasecmp( $city, $this->get_sender_city() );
		$base     = (float) $this->get_option( $is_local ? 'local_cost' : 'base_cost', 0 );

		return $base + $weight_kg * (float) $this->get_option( 'cost_per_kg', 0 );
	}

	/**
	 * Gets the sender city from the plugin-wide settings.
	 *
	 * @return string
	 */
	private function get_sender_city(): string {
		return (string) My_Carrier_Plugin::instance()->get_my_settings()->get_value( 'sender_city' );
	}

	/**
	 * Hides the method: writes the log line and tells the store manager why, with one reason code.
	 *
	 * @param string $code   A stable reason code.
	 * @param string $reason Why, for a store manager, without the method's name.
	 * @return null
	 */
	private function hide( string $code, string $reason ) {
		$this->get_plugin()->log_debug( sprintf( 'Method hidden (%s).', $code ), $this->get_plugin()->get_id() . '_' . $this->get_id() );
		$this->report_hidden( $code, $reason );

		return null;
	}
}
```

The `hide()` helper returns `null` so each refusal is one line: `return $this->hide( ... );`. A merchant now sees the method in their zone, edits the four fields, and sees a rate such as «My Carrier: courier», 2-3 days, in the cart.

## Next steps

- [Method features](../method-features/) adds cost limits, insurance, fees by payment method, a city limit, extra services and box packing.
- [Packaging & box packing](../packaging-and-box-packing/) explains the `$packed` argument.
- [Pickup points & maps](../pickup-points-and-maps/) covers the pickup base class.
- [Logging & debugging](../../core/logging-and-debugging/) covers the «Логирование» switch that controls the hidden-method hint.
