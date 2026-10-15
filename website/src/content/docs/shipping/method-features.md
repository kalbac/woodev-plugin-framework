---
title: Method features
description: Switch optional capabilities on for a shipping method.
---

In this page you will learn the optional features a shipping method can declare, what each one adds to the merchant's settings and to the cart, and the code that switches it on.

Every feature is **off** until the method declares it. Most add a field to the method's instance form, in the shipping zone, and the framework then does the work around that field, so your `rate_package()` stays short. Declare a feature the way [Methods & rates](../methods-and-rates/#declare-features) describes: assign `$this->supports` before `parent::__construct()`, or call `add_support()` after it.

| Feature | Merchant sees | You write |
|---|---|---|
| [`FEATURE_COST_LIMITS`](#cost-limits) | A minimum and a maximum delivery cost. | Nothing. The framework clamps the rate. |
| [`FEATURE_INSURANCE`](#insurance) | A choice of when to insure the shipment. | Read the insurance decision. |
| [`FEATURE_FEE_PAYMENTS`](#fee-by-payment-method) | A list of payment methods the fee applies to. | Ask whether the fee applies. |
| [`FEATURE_CITY_LIMIT`](#city-limit) | A list of cities where the method is available or unavailable. | Nothing. |
| [`FEATURE_BOX_PACKING`](#box-packing) | A packing mode. | Use the packed parcels. |
| [`FEATURE_SHIPPING_CLASSES`](#shipping-classes) | A shipping class the method is limited to. | Nothing. |
| [`declare_services()`](#additional-services) | A list of additional services to tick. | Return the services. |
| [`show_if`](#show-a-field-only-when) on a field | A field that appears only when another setting has a value. | Add a rule to the field. |

The examples below show the part of a method class that each feature touches. [Putting it together](#putting-it-together) shows them in one complete class.

## Cost limits

`FEATURE_COST_LIMITS` is for a carrier whose price is **calculated**, usually by its API.

- **The merchant sees** two fields: «Минимальная стоимость доставки» (Minimum delivery cost) and «Максимальная стоимость доставки» (Maximum delivery cost). An empty field means no limit.
- **The framework does** the clamping, as the last step of the calculation: after your fee, your free-shipping rule and the box cost. Equal limits make the price fixed, which is why there is no separate "fixed price" option. A rate of zero or less stays as it is, so a free rate is never raised to the minimum.

```php
<?php
// In the constructor of your method, before parent::__construct():
$this->supports = [ self::FEATURE_COST_LIMITS ];
```

The values are stored in the instance options `min_cost` and `max_cost`. Read them with `get_min_cost()` and `get_max_cost()`, which return a float or `null`. If you need the limited number earlier than the end of the calculation, call `apply_cost_limits( $cost )`. Calling it twice is harmless.

Do not declare this feature when the merchant types the price themselves. A limit on a price the merchant already controls only gets in the way.

## Insurance

`FEATURE_INSURANCE` lets the merchant decide when a shipment is insured, and gives you one decision to read for the quote and the export.

- **The merchant sees** «Учитывать страховку» (Include insurance) with three choices: «Нет» (No), «Всегда» (Always) and «Только при оплате при получении» (Only for payment on receipt).
- **You choose** the default for a method that has not been saved yet by overriding `get_default_insurance_mode()`. It returns one of `INSURANCE_NONE`, `INSURANCE_ALWAYS` or `INSURANCE_DELIVERY_PAYMENT`. Return `INSURANCE_ALWAYS` when the carrier charges for insurance anyway, so the price is honest.

Read the decision in two places, with the same rule on both ends:

```php
<?php
// While quoting, in rate_package():
$insurance = $this->resolve_insurance_for_package( $package );

// While exporting an order to the carrier:
$insurance = $this->resolve_insurance_for_order( $order, $items );

// Both return [ 'enabled' => bool, 'declared_value' => float ].
// The declared value is 0.0 when insurance is off.
```

The declared value is the value of the goods after discounts, without tax, shipping and fees, in the store currency. The carrier's API owns any conversion to its own currency. `$items` is optional: pass the shipment's product lines for a split order, or leave it out for the whole order.

"Payment on receipt" means the gateways returned by the filter `woodev_shipping_insurance_cod_gateways`, which defaults to `[ 'cod' ]`. Add the id of another cash-on-delivery gateway there:

```php
<?php
add_filter(
	'woodev_shipping_insurance_cod_gateways',
	static function ( array $gateways ): array {
		$gateways[] = 'my_cash_gateway';

		return $gateways;
	}
);
```

Before a customer has chosen a payment method, the conditional mode counts as off. The outcome of the decision is part of the default [rate cache](../methods-and-rates/#cache-rates) key.

## Fee by payment method

`FEATURE_FEE_PAYMENTS` lets a merchant charge a handling fee only when the customer pays in certain ways, such as cash on delivery.

- **The merchant sees** «Оплата с наценкой» (Fee for payment methods), a list of the store's payment gateways. An empty list means the fee always applies. With a list, the fee applies only when the customer has chosen one of those gateways.
- **The framework does** the hard part. It puts the chosen payment method into the shipping package, so WooCommerce cannot serve a stale rate, and it recalculates the rate when the customer changes the payment method, on both the classic and the block checkout.
- **You write** the fee field and ask the framework whether the fee applies.

The framework gives you no fee field, because the fee is yours: a fixed amount, a percentage, a tariff-dependent surcharge. Add your own field in `get_method_form_fields()` and use `apply_fee_for_package()`, which returns the cost unchanged when the fee does not apply:

```php
<?php
use Woodev\Framework\Shipping\Shipping_Method_Courier;

final class My_Carrier_Express_Method extends Shipping_Method_Courier {

	/**
	 * Gets the instance fields the merchant edits in the shipping zone.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_method_form_fields(): array {
		return [
			'fee' => [
				'title'    => __( 'Handling fee', 'my-carrier' ),
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( 'A fixed amount, or a percentage such as 5%.', 'my-carrier' ),
			],
		];
	}
}
```

Then, in `rate_package()`, after you know the carrier's price:

```php
<?php
$cost = $this->apply_fee_for_package( $cost, (string) $this->get_option( 'fee', '' ), $cost, $package );
```

`apply_fee_for_package( float $cost, string $fee, float $base_for_percent, array $package )` reads `150` as an amount and `5%` as a percentage of `$base_for_percent`. If you only need the yes/no answer, call `fee_applies_for_package( $package )`. When no payment method has been chosen yet, a restricted fee does not apply.

### A price that always depends on the payment method

Some carriers put the payment method into the quote itself: a cash-on-delivery commission is part of the price. Such a method has no `fee_payments` setting that could switch the recalculation on, so declare it on the method **class**:

```php
<?php
use Woodev\Framework\Shipping\Shipping_Method_Courier;

final class My_Carrier_Cod_Method extends Shipping_Method_Courier {

	/**
	 * Tells the framework that the price always depends on the payment method.
	 *
	 * @return bool
	 */
	public static function declares_payment_dependent_price(): bool {
		return true;
	}
}
```

This is a static method, not a feature in `$this->supports`. WooCommerce collects a cart's packages before it builds the method objects, so an instance setting would be read too late. Every instance of the method then recalculates when the customer changes the payment method.

To offer only some payment gateways with a delivery method, which is a different thing, filter `woodev_shipping_available_payment_gateways`. It receives the available gateways, the chosen shipping method (or `null`) and the chosen pickup point (or `null`), and returns the gateways to offer.

## City limit

`FEATURE_CITY_LIMIT` makes a method available only in some cities, or everywhere except some. It needs the shared location layer, so the plugin must return `true` from `needs_location_provider()`. See [Location & checkout fields](../location-and-checkout-fields/).

- **The merchant sees** «Ограничение по городам» (City limit) with three choices: «Без ограничений» (No limit), «Доступен только в городах» (Available only in these cities) and «Недоступен в городах» (Not available in these cities), and a list of cities to search and pick, up to 200. If the shipping zone names regions, the search covers only those regions.
- **The framework does** the rest: it hides the method from a customer whose city the limit excludes.

```php
<?php
// In the constructor of your method, before parent::__construct():
$this->supports = [ self::FEATURE_CITY_LIMIT ];
```

The limit never hides shipping because of missing data:

- While the customer has not chosen a city, the method stays available.
- A saved city that the current location provider no longer recognises is ignored. A list of only such cities does not make the method vanish for everyone, and the settings form warns the merchant.
- If the location layer fails, the method stays available.

The same limit is added to WooCommerce's own «Самовывоз» (Local pickup) method on every store that runs a Woodev shipping plugin.

The limit is built and tested on the classic checkout. The block checkout reads the same saved city, but it is not covered by acceptance tests in this framework version (%%FRAMEWORK_VERSION%%), so test it there before you promise it to a merchant.

## Box packing

`FEATURE_BOX_PACKING` packs the cart into parcels before your rate is calculated. The merchant sees a packing mode, and `rate_package()` receives the packed parcels as its second argument. [Packaging & box packing](../packaging-and-box-packing/) covers the modes, the boxes and the result.

```php
<?php
// In the constructor of your method, before parent::__construct():
$this->supports = [ self::FEATURE_BOX_PACKING ];
```

## Shipping classes

`FEATURE_SHIPPING_CLASSES` limits a method to one WooCommerce shipping class, for example "bulky goods".

- **The merchant sees** a «Shipping class» field with "Any shipping class", "No shipping class" and every class of the store.
- **The framework does** the check: with a class selected, the method is offered only when every item in the cart that needs shipping has that class. One other item hides the method, and the reason goes to the log while «Логирование» (Logging) is on.

```php
<?php
// In the constructor of your method, before parent::__construct():
$this->supports = [ self::FEATURE_SHIPPING_CLASSES ];
```

## Additional services

An additional service is an extra the carrier sells with the delivery: an SMS notice, a fitting room, a signature on delivery. **Declaring the list is declaring the support**, so there is no feature constant. Override `declare_services()` and return `Carrier_Service` objects.

- **The merchant sees** «Дополнительные услуги» (Additional services), a multiselect of the services that can be picked. Services the carrier adds on its own are not shown.
- **The framework does** the bookkeeping. The services in the quote are saved with the rate on the order's shipping line, so the export sends the services the customer was quoted, whatever the settings say by then.

```php
<?php
use Woodev\Framework\Shipping\{Carrier_Service, Shipping_Method_Courier};

final class My_Carrier_Express_Method extends Shipping_Method_Courier {

	/**
	 * Declares the services the carrier offers.
	 *
	 * @return Carrier_Service[]
	 */
	protected function declare_services(): array {
		return [
			// A plain service the merchant can tick.
			new Carrier_Service( 'SMS', __( 'SMS notification', 'my-carrier' ) ),

			// A service with a value: the goods value of the order.
			new Carrier_Service( 'COD', __( 'Cash on delivery', 'my-carrier' ), 'sum', Carrier_Service::SOURCE_DECLARED_VALUE ),

			// An automatic service: never offered, added whenever its value is not null.
			new Carrier_Service( 'PARCELS', __( 'Number of parcels', 'my-carrier' ), 'count', Carrier_Service::SOURCE_CUSTOM, '', false ),
		];
	}
}
```

`Carrier_Service` takes a code, a name for the merchant, an optional parameter name, the source of the parameter's value, a one-line description and a `selectable` flag. You can also return arrays with the keys `code`, `name`, `parameter`, `parameter_source`, `description` and `selectable`. An entry that cannot be used is dropped rather than breaking the settings screen.

The parameter's source decides where its value comes from:

- `Carrier_Service::SOURCE_DECLARED_VALUE` is the goods value of the package or order lines, the same number insurance uses.
- `Carrier_Service::SOURCE_CUSTOM` is a value you compute by overriding `resolve_service_parameter()`:

```php
<?php
use Woodev\Framework\Shipping\{Carrier_Service, Shipping_Method_Courier};

final class My_Carrier_Express_Method extends Shipping_Method_Courier {

	/**
	 * Computes the value of a service parameter.
	 *
	 * @param Carrier_Service     $service The service.
	 * @param array<string,mixed> $subject What is priced or exported.
	 * @return int|float|string|null
	 */
	protected function resolve_service_parameter( Carrier_Service $service, array $subject ) {
		if ( 'PARCELS' === $service->get_code() ) {
			return $subject['packed'] instanceof \Woodev_Packer_Result ? $subject['packed']->get_package_count() : null;
		}

		return parent::resolve_service_parameter( $service, $subject );
	}
}
```

`$subject` holds `context` (`package` or `order`), `package`, `order`, `items`, `packed` and `declared_value`. Returning `null` leaves a selectable service out. An automatic service needs a parameter, because without one nothing could say when it applies.

Read the services with the same resolver on both ends:

```php
<?php
// While quoting, in rate_package():
$services = $this->resolve_services_for_package( $package, $packed );

// While exporting an order:
$services = $this->resolve_services_for_order( $order, $items, $packed );

// Each entry is [ 'code' => string, 'name' => string, 'parameter' => int|float|string|null ].
```

The filter `woodev_shipping_resolved_services` receives the resolved list, the method and the subject, and lets a site add or drop an entry.

:::caution
The framework adds the chosen services to the rate-cache key. If `resolve_service_parameter()` reads anything else, such as a plugin-wide setting, add that input to your `get_rate_cache_context()` too, or a change to it keeps serving the cached quote.
:::

A carrier can refuse a service for an account and fail the whole quote because of it. The fallback is yours: retry without the refused service, remember the refusal for a while, and tell the merchant. The framework does not guess which services your carrier accepts together.

## Show a field only when

Any entry of your `get_method_form_fields()` can carry a `show_if` rule. The field then appears only while the rule holds:

```php
<?php
use Woodev\Framework\Shipping\Shipping_Method_Courier;

final class My_Carrier_Express_Method extends Shipping_Method_Courier {

	/**
	 * Gets the instance fields the merchant edits in the shipping zone.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_method_form_fields(): array {
		return [
			'pricing'    => [
				'title'   => __( 'Pricing', 'my-carrier' ),
				'type'    => 'select',
				'default' => 'tariff',
				'options' => [
					'tariff' => __( 'By carrier tariff', 'my-carrier' ),
					'fixed'  => __( 'Fixed cost', 'my-carrier' ),
				],
			],
			'fixed_cost' => [
				'title'   => __( 'Fixed cost', 'my-carrier' ),
				'type'    => 'price',
				'default' => '',
				'show_if' => [
					'setting'  => 'pricing',
					'operator' => '=',
					'value'    => 'fixed',
				],
			],
		];
	}
}
```

- `setting` is the **unprefixed** key of the controlling field, as you wrote it in `get_method_form_fields()`.
- `operator` is `=`, `!=`, `in` or `not_in`, and defaults to `=`. For `in` and `not_in`, `value` is a list.
- A checkbox compares as `yes` or `no`.
- Several rules go in a list, with an optional `relation` of `AND` (the default) or `OR`.
- The whole `show_if` can be a closure that returns the rule. It runs when the form is built.

The rule works on the method's own page and inside the WooCommerce window that edits a method in a zone. A rule that cannot be honoured, such as an unknown operator or a field that depends on itself, is dropped and the field stays visible: a broken rule never hides a setting the merchant needs. Hiding is presentation only. A hidden field is still submitted and keeps its saved value.

A `.wc-enhanced-select` or `.wc-product-search` field works in that window with no JavaScript of yours.

## Other filters and hooks

| Hook | Type | Arguments | Use it to |
|---|---|---|---|
| `woodev_shipping_method_{method_id}_form_fields` | filter | `$fields`, `$method` | Add, remove or change instance fields. |
| `woodev_shipping_method_{method_id}_supports_{feature}` | action | `$method`, `$feature` | React when a feature is declared. Dashes in the feature name become underscores. |
| `woodev_shipping_insurance_cod_gateways` | filter | `$gateways`, `$method` | Say which gateways count as payment on receipt. |
| `woodev_shipping_resolved_services` | filter | `$services`, `$method`, `$subject` | Change the resolved additional services. |
| `woodev_shipping_available_payment_gateways` | filter | `$gateways`, `$chosen_method`, `$pickup_point` | Offer only some payment gateways. |

Two more features, `FEATURE_COD` and `FEATURE_DECLARED_VALUE`, only record intent. `supports_cod()` and `supports_declared_value()` answer whether a method declared them, and the framework changes nothing because of the answer.

## Putting it together

This method declares every feature on the page. It is a second courier method for the plugin built in [Shipping plugin anatomy](../plugin-anatomy/): add it to `get_shipping_method_classes()` and load its file before the plugin is built.

`my-carrier/includes/shipping/class-my-carrier-express-method.php`

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\{Carrier_Service, Shipping_Method_Courier, Shipping_Plugin, Shipping_Rate};

/**
 * Express courier delivery by My Carrier, with every optional feature switched on.
 */
final class My_Carrier_Express_Method extends Shipping_Method_Courier {

	/** The method id, stored in every shipping zone that uses the method. */
	const METHOD_ID = 'my_carrier_express';

	/**
	 * Sets up the method.
	 *
	 * @param int $instance_id The shipping zone instance id, or 0 when the method is not in a zone.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->method_title       = __( 'My Carrier: express courier', 'my-carrier' );
		$this->method_description = __( 'Fast delivery to the customer\'s door.', 'my-carrier' );
		$this->supports           = [
			self::FEATURE_COST_LIMITS,
			self::FEATURE_INSURANCE,
			self::FEATURE_FEE_PAYMENTS,
			self::FEATURE_CITY_LIMIT,
			self::FEATURE_BOX_PACKING,
			self::FEATURE_SHIPPING_CLASSES,
		];

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
			'pricing'    => [
				'title'   => __( 'Pricing', 'my-carrier' ),
				'type'    => 'select',
				'default' => 'tariff',
				'options' => [
					'tariff' => __( 'By carrier tariff', 'my-carrier' ),
					'fixed'  => __( 'Fixed cost', 'my-carrier' ),
				],
			],
			'fixed_cost' => [
				'title'   => __( 'Fixed cost', 'my-carrier' ),
				'type'    => 'price',
				'default' => '',
				'show_if' => [
					'setting'  => 'pricing',
					'operator' => '=',
					'value'    => 'fixed',
				],
			],
			'fee'        => [
				'title'    => __( 'Handling fee', 'my-carrier' ),
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( 'A fixed amount, or a percentage such as 5%.', 'my-carrier' ),
			],
		];
	}

	/**
	 * Insures the shipment by default, because the carrier charges for it anyway.
	 *
	 * @return string
	 */
	protected function get_default_insurance_mode(): string {
		return self::INSURANCE_ALWAYS;
	}

	/**
	 * Declares the additional services the carrier offers.
	 *
	 * @return Carrier_Service[]
	 */
	protected function declare_services(): array {
		return [
			new Carrier_Service( 'SMS', __( 'SMS notification', 'my-carrier' ) ),
			new Carrier_Service( 'PARCELS', __( 'Number of parcels', 'my-carrier' ), 'count', Carrier_Service::SOURCE_CUSTOM, '', false ),
		];
	}

	/**
	 * Computes the value of a service parameter.
	 *
	 * @param Carrier_Service     $service The service.
	 * @param array<string,mixed> $subject What is priced or exported.
	 * @return int|float|string|null
	 */
	protected function resolve_service_parameter( Carrier_Service $service, array $subject ) {
		if ( 'PARCELS' === $service->get_code() ) {
			return $subject['packed'] instanceof \Woodev_Packer_Result ? $subject['packed']->get_package_count() : null;
		}

		return parent::resolve_service_parameter( $service, $subject );
	}

	/**
	 * Quotes one package.
	 *
	 * @param array<string,mixed>        $package The WooCommerce shipping package.
	 * @param \Woodev_Packer_Result|null $packed  The packed parcels, or null.
	 * @return Shipping_Rate|null
	 */
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
		$parcels   = null === $packed ? 1 : max( 1, $packed->get_package_count() );
		$insurance = $this->resolve_insurance_for_package( $package );
		$services  = $this->resolve_services_for_package( $package, $packed );

		if ( 'fixed' === $this->get_option( 'pricing', 'tariff' ) ) {
			$cost = (float) $this->get_option( 'fixed_cost', 0 );
		} else {
			$cost = 450.0 * $parcels;
		}

		if ( $insurance['enabled'] ) {
			$cost += $insurance['declared_value'] * 0.005;
		}

		$cost += 20.0 * count( $services );
		$cost  = $this->apply_fee_for_package( $cost, (string) $this->get_option( 'fee', '' ), $cost, $package );

		return new Shipping_Rate( $this->id, $this->get_rate_id(), $this->get_title(), $cost );
	}
}
```

A merchant who adds this method to a zone sees the pricing choice, the fee, the cost limits, the insurance choice, the payment methods for the fee, the city limit, the packing mode, the shipping class and the services, all on one form. The cost limits clamp the rate after your code returns it.

## Next steps

- [Methods & rates](../methods-and-rates/) explains `rate_package()`, the rate object and the rate cache.
- [Packaging & box packing](../packaging-and-box-packing/) explains the `$packed` argument.
- [Location & checkout fields](../location-and-checkout-fields/) covers the location layer that the city limit needs.
