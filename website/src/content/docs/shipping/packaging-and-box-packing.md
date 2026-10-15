---
title: "Packaging & box packing"
description: Pack cart items into boxes to get parcel dimensions and weight.
---

In this page you will learn how the framework turns a cart into parcels: where the store's boxes and the carrier's boxes come from, the three packing modes, and how to read the parcels in `rate_package()` and again when you export an order.

A carrier prices a parcel by its size and weight, and a cart is a list of products. Packing is the step between the two. A method that declares `FEATURE_BOX_PACKING` gets the packing done for it, and receives the result as the second argument of `rate_package()`.

## Switch packing on

Declare the feature in the method's constructor, before `parent::__construct()`:

```php
<?php
// In the constructor of your method, before parent::__construct():
$this->supports = [ self::FEATURE_BOX_PACKING ];
```

The merchant then sees two fields in the shipping zone: «Способ упаковки» (Packing mode) and «Непоместившиеся товары» (Items that did not fit). The second one appears only for the box mode. A method that does not declare the feature gets `null` instead of parcels.

## Two lists of boxes

Boxes come from two places, and they are never merged into one list.

**The store's boxes** belong to the merchant. They are kept in «Woodev → Настройки → Доставка → Упаковка» (Settings → Shipping → Packaging), one list for the whole site, and every carrier can use them. A box has a name, inside length, width and height, a maximum weight, the box's own weight, an optional cost and an on/off switch.

- Enter sizes and weights in the units WooCommerce is set to. The framework converts them to centimetres and kilograms before it packs.
- A maximum weight of zero, or an empty one, means no limit. The maximum includes the weight of the box itself.
- The cost is a fixed amount or a percentage of the goods in that box, such as `2%`.

Never copy this list into your plugin. If a merchant already has boxes from an earlier version of your plugin, migrate them into this list.

**The carrier's boxes** belong to the carrier: standard cartons it sells, locker cells, envelopes. You declare them in code by overriding `get_box_presets()` in the plugin class. Sizes are fixed in **centimetres** and weights in **kilograms**, whatever units the store uses.

```php
<?php
use Woodev\Framework\Shipping\Shipping_Plugin;

abstract class My_Carrier_Plugin_With_Boxes extends Shipping_Plugin {

	/**
	 * Declares the carrier's own boxes, in centimetres and kilograms.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_box_presets(): array {
		return [
			[
				'id'         => 'CARTON_M',
				'name'       => 'Carton M',
				'length'     => 30,
				'width'      => 20,
				'height'     => 15,
				'max_weight' => 20,
				'box_weight' => 0.2,
				'cost_mode'  => 'carrier',
			],
			[
				'id'        => 'CARTON_L',
				'name'      => 'Carton L',
				'length'    => 40,
				'width'     => 30,
				'height'    => 20,
				'cost_mode' => 'fixed',
				'cost'      => 50,
			],
		];
	}
}
```

| Key | Meaning |
|---|---|
| `id` | A stable string id: a letter first, then letters, digits, dashes or underscores. It must be unique, and you never change it after release. The merchant's choices are stored under it. |
| `name` | The name the merchant sees. Must not be empty. |
| `length`, `width`, `height` | Inside size in centimetres. Must be above zero. |
| `max_weight` | The most the box holds, in kilograms, including the box. `0`, or no key, means no limit. |
| `box_weight` | The empty box's weight in kilograms. Defaults to `0`. |
| `cost_mode` | Who charges for the box: `carrier`, `fixed` or `merchant` (below). |
| `cost` | Required for `fixed`, a non-negative number. Optional for `merchant`, an amount or a percentage, as the starting value. |

A preset with a missing key or a bad value is rejected with an `\InvalidArgumentException` when the settings are built.

The cost mode decides who sets the price of the box:

| `cost_mode` | The merchant sees | What you do |
|---|---|---|
| `carrier` | A «Учитывать стоимость» (Include the cost) switch. | Pass the packed boxes to the carrier's quote with `Packaging::get_carrier_boxes()`. The framework adds nothing. |
| `fixed` | The cost, read-only, in «Стоимость упаковки» (Packaging cost). | Nothing. The framework adds your cost once per box. |
| `merchant` | An editable «Стоимость упаковки», an amount or a percentage. | Nothing. The framework adds the merchant's cost once per box. |

A percentage is taken from the goods in that one parcel, after line discounts and before tax. The box costs of the store's boxes, `fixed` boxes and `merchant` boxes are added to your rate after `rate_package()` returns, even when the carrier made the delivery free.

The carrier's boxes appear as switches on the plugin's own settings tab, and they start **off**. The merchant chooses which ones to use.

`uses_boxes()` is `true` as soon as `get_box_presets()` returns something. A carrier that has no boxes of its own but uses the store's list overrides `uses_boxes()` and returns `true`. A carrier that does neither has no packaging settings, and its methods offer only explicit packing modes, without «Как в настройках плагина» (As in the plugin settings).

## The packing modes

The merchant chooses a default mode for the plugin on its tab, and each method can override it. The method's own field also offers «Как в настройках плагина» (As in the plugin settings) when the carrier uses boxes.

| Merchant sees | Constant of `Woodev_Packer_Dispatcher` | Result |
|---|---|---|
| «Каждый товар отдельно» (Each item separately) | `ALGORITHM_SEPARATELY` (`separately`) | Every unit is its own parcel. |
| «Всё в одну коробку» (Everything in one box) | `ALGORITHM_VIRTUAL` (`virtual`) | One virtual box for everything. |
| «Упаковывать в коробки» (Pack into boxes) | `ALGORITHM_BOXES` (`boxes`) | The smallest set of the store's boxes and the carrier's enabled boxes that holds the cart. |

An older plugin might have stored a fourth mode, `single`. It is retired, and a stored `single` is read as `virtual`. You need no special case for it.

- **`virtual`** tries several box bases, places the units into each one by one, largest first and in any rotation, so that small items fill the gaps, and keeps the box with the smallest volume. The size of the box is the result of a real placement, not of adding up sides. The work is bounded by the number of units, never by the clock, so the same cart always gives the same box. Above 120 units it falls back to a simpler box.
- **`boxes`** accepts a set of boxes only if the items can really be placed in them, in any of the six orientations. At most 120 placements are attempted per parcel; the rest of the cart waits for the next parcel. When two sets fill equally, the store's boxes win over the carrier's, then the smaller volume wins.
- **Items that fit no box** are never dropped. They travel as a parcel of their own, or, if the merchant chose «Всё в одну коробку» for «Непоместившиеся товары», together in one virtual box. With no boxes defined, every unit is such a parcel.

A product without a weight or dimensions takes the store-wide defaults from «Woodev → Настройки → Доставка → Вес и габариты» (Weight and dimensions). Each of the four values is decided on its own, so a product with a weight and no size keeps its weight.

## Read the parcels while quoting

`rate_package()` receives a `\Woodev_Packer_Result`, or `null` when the method did not declare the feature, when the cart has nothing physical to pack, or when the packer is unavailable. Handle `null` by rating without dimensions.

| Call on `Woodev_Packer_Result` | Returns |
|---|---|
| `get_packages()` | The list of parcels, each a `Woodev_Packer_Package_Result`. |
| `get_package_count()` | The number of parcels. |
| `get_total_weight()` | The summed weight, in kilograms. |
| `get_total_volume()` | The summed volume, in cubic centimetres. |
| `get_algorithm()` | The mode that produced the result. |
| `to_array()` | Everything above as a plain array. |

A parcel answers these questions:

| Call on `Woodev_Packer_Package_Result` | Returns |
|---|---|
| `get_length()`, `get_width()`, `get_height()` | The outer size, in centimetres. |
| `get_weight()` | The weight in kilograms, including the box's own weight when the parcel is a box. |
| `get_volume()` | Length × width × height. |
| `get_item_count()` | The number of units in the parcel. |
| `get_items()` | Which cart lines are in the parcel: a list of `key`, `product_id` and `quantity`. |
| `get_box_id()`, `get_box_name()` | The box, or an empty string for a parcel that is not a box. |
| `get_box_origin()` | `store`, `carrier`, or an empty string for an unboxed parcel. |

The quantities of one `key` across all parcels add up to the line's quantity. No unit is dropped, whatever the mode.

:::caution
Package sizes and weights are centimetres and kilograms. `Shipping_Helper::get_package_weight()`, which reads a WooCommerce package directly, returns **grams**. Convert before you hand either number to the carrier.
:::

This method sends the carrier one request for the whole shipment, listing the parcels and the carrier's chargeable boxes. Return the carrier's total as it is. The framework does not sum parcel prices, and it adds the cost of the store's, fixed and merchant boxes itself.

```php
<?php
use Woodev\Framework\Shipping\{Packaging, Shipping_Method_Courier, Shipping_Rate};

final class My_Carrier_Parcel_Method extends Shipping_Method_Courier {

	/**
	 * Quotes the packed shipment.
	 *
	 * @param array<string,mixed>        $package The WooCommerce shipping package.
	 * @param \Woodev_Packer_Result|null $packed  The packed parcels, or null.
	 * @return Shipping_Rate|null
	 */
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
		$places = [];

		foreach ( null === $packed ? [] : $packed->get_packages() as $parcel ) {
			$places[] = [
				'length_cm' => $parcel->get_length(),
				'width_cm'  => $parcel->get_width(),
				'height_cm' => $parcel->get_height(),
				'weight_kg' => $parcel->get_weight(),
			];
		}

		$request = [
			'places' => $places,
			// Carrier boxes that the merchant asked to be charged by the carrier: [ [ 'id' => ..., 'count' => ... ] ].
			'boxes'  => Packaging::get_carrier_boxes( $packed ),
		];

		$total = $this->ask_carrier( $request );

		return new Shipping_Rate( $this->id, $this->get_rate_id(), $this->get_title(), $total );
	}

	/**
	 * Sends the request to the carrier and returns the total price.
	 *
	 * @param array<string,mixed> $request The parcels and the chargeable boxes.
	 * @return float
	 *
	 * @throws \Woodev_Plugin_Exception When the carrier cannot be asked.
	 */
	private function ask_carrier( array $request ): float {
		throw new \Woodev_Plugin_Exception( 'Replace this with a call to the carrier.' );
	}
}
```

`Packaging::get_carrier_boxes( $packed )` returns only the carrier's boxes whose cost mode is `carrier` and that the merchant asked to be charged. It returns an empty list for `null`. The inherited `get_rate_cache_context()` already includes the box list, the switches, the costs and the line values, so add to it only what your carrier reads beyond that. See [Methods & rates](../methods-and-rates/#cache-rates).

## Read the parcels when you export

The packing of an order is worked out again when you export it, with the same rule as at checkout. `pack_order()` returns the same kind of result for an order. The call path is: find the order's shipping line, find the method instance from the line, and ask it to pack.

```php
<?php
use Woodev\Framework\Shipping\{Shipping_Helper, Shipping_Method};

/**
 * Lists the parcels of an order for the carrier's order request.
 *
 * @param WC_Order $order     The order about to be exported.
 * @param string   $method_id The id of the My Carrier method the customer chose.
 * @return array<int,array<string,mixed>> One entry per parcel, empty when nothing can be packed.
 */
function my_carrier_parcels_for_order( WC_Order $order, string $method_id ): array {
	$line = Shipping_Helper::get_order_shipping_item( $order, $method_id );

	if ( null === $line ) {
		return [];
	}

	$method = WC_Shipping_Zones::get_shipping_method( $line->get_instance_id() );

	// The zone may no longer have the method.
	if ( ! $method instanceof Shipping_Method ) {
		return [];
	}

	$packed = $method->pack_order( $order );

	if ( null === $packed ) {
		return [];
	}

	$parcels = [];

	foreach ( $packed->get_packages() as $parcel ) {
		$parcels[] = [
			'length_cm' => $parcel->get_length(),
			'width_cm'  => $parcel->get_width(),
			'height_cm' => $parcel->get_height(),
			'weight_kg' => $parcel->get_weight(),
			'box'       => $parcel->get_box_id(),
			'items'     => $parcel->get_items(),
		];
	}

	return $parcels;
}
```

The order is turned into a package the way a cart is: lines that need no shipping, deleted products and fully refunded lines are left out, and refunded units are not counted. A method that did not declare `FEATURE_BOX_PACKING` gets `null`.

Do not compare the `items` of a quote with the `items` of an export. A cart line key and an order item id are different things. Compare the sizes and weights of the parcels instead.

## Pack without a method

To pack a list of products outside a shipping method, use the WooCommerce-aware dispatcher. It converts the products' sizes and weights from the store's units to centimetres and kilograms, and it applies the store-wide defaults.

```php
<?php
/**
 * Packs the items of a WooCommerce shipping package into one virtual box.
 *
 * @param array<string,mixed> $package A WooCommerce shipping package.
 * @return Woodev_Packer_Result|null The result, or null when there is nothing to pack.
 */
function my_carrier_pack_virtual( array $package ): ?Woodev_Packer_Result {
	$items = Woodev_WC_Packer_Dispatcher::from_cart_items( (array) ( $package['contents'] ?? [] ) );

	if ( [] === $items ) {
		return null;
	}

	return Woodev_WC_Packer_Dispatcher::pack( Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL, $items );
}
```

`pack()` throws a `Woodev_Packer_Exception` for an empty list or an unknown mode. For the `boxes` mode, pass the boxes as the third argument and the mode for items that fit none as the fourth: `Woodev_WC_Packer_Dispatcher::pack( 'boxes', $items, $boxes, 'virtual' )`. Leave the boxes out and it reads the store's boxes. `Woodev_Packer_Dispatcher::get_algorithms()` lists the modes that a merchant can choose.

## Parcel lockers

Whether an order fits into the cell of a parcel locker is checked by the framework, not by your plugin, when the customer picks a point. Give the point its cells only when the carrier publishes them. See [Pickup points & maps](../pickup-points-and-maps/).

## Next steps

- [Method features](../method-features/) lists the other optional features of a method.
- [Methods & rates](../methods-and-rates/) explains `rate_package()` and the rate cache.
- [Orders, export, documents & tracking](../orders-export-documents-and-tracking/) covers the export that reads the parcels.
