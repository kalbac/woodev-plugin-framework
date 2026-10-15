---
title: "Pickup points & maps"
description: Let shoppers choose a pickup point or parcel locker on a map, and save the choice with the order.
---

In this page you will learn how a pickup method gets its points from your carrier, how the framework shows them on a map in the classic and the block checkout, how a shopper's choice is checked and saved, and which hooks let you adjust the result.

Covers framework release `%%FRAMEWORK_VERSION%%`. It builds on [Location & checkout fields](../location-and-checkout-fields/), because the pickup field and the shopper's city come from there.

## The parts

You supply the data and the wiring. The framework supplies the map, the list, the search, the point card and the checks.

| Part | Who writes it | What it does |
|---|---|---|
| `Point_Source` | You | Fetches points from the carrier and returns them as `Pickup_Point` values. |
| `Pickup_Point` | Framework | One normalised point: address, type, hours, payment, weight limit, lockers' cells. |
| `Map_Provider` | Framework (two built in) | Draws the map: Yandex Maps or a carrier's own embedded widget. |
| `Selection_Scope` | You | Tells the framework how to remember a shopper's choice for a place. |
| `Pickup_Handler` | You build it, the framework runs it | Wires it all into the checkout: the button, the routes, the checks, the saved choice. |
| `Pickup_Field` | Framework preset | The checkout field that holds the chosen point. |

## 1. Supply the points

A pickup method extends `Shipping_Method_Pickup` and returns its `Point_Source` from `get_point_source()`. The framework exposes that source to its own routes and rate code.

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Pickup\Point_Source;

/**
 * The My Carrier pickup method.
 *
 * The rate, the form fields and the method id are described in "Methods & rates".
 */
final class My_Carrier_Pickup_Method extends \Woodev\Framework\Shipping\Shipping_Method_Pickup {

	/**
	 * Gets the carrier's pickup point source.
	 *
	 * @return Point_Source
	 */
	protected function get_point_source(): Point_Source {
		return new My_Carrier_Point_Source( My_Carrier_Plugin::instance()->get_my_carrier_api() );
	}

	// get_method_id(), get_plugin(), get_method_form_fields() and rate_package() go here.
}
```

### A source that loads a whole city at once

Carriers that return every point of a city in one call use the **bulk** strategy: `get_strategy()` returns `Point_Source::STRATEGY_BULK`, and the framework only calls `fetch_points()` with a place, never with a map area. Here the carrier also has an endpoint that looks one point up by its id, so the source implements `Point_Source` directly.

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;

/**
 * Loads My Carrier's pickup points for a city.
 */
final class My_Carrier_Point_Source implements Point_Source {

	/**
	 * The carrier API client.
	 *
	 * @var My_Carrier_Api
	 */
	private $api;

	/**
	 * Sets up the source.
	 *
	 * @param My_Carrier_Api $api The carrier API client.
	 */
	public function __construct( My_Carrier_Api $api ) {
		$this->api = $api;
	}

	/**
	 * Gets the loading strategy.
	 *
	 * @return string
	 */
	public function get_strategy(): string {
		return self::STRATEGY_BULK;
	}

	/**
	 * Fetches the points of the shopper's city.
	 *
	 * @param Point_Query $query What to fetch.
	 * @return Pickup_Point[]
	 *
	 * @throws \Woodev_API_Exception When the carrier cannot be reached.
	 */
	public function fetch_points( Point_Query $query ): array {
		// This is the carrier city code your location adapter produced for the shopper's place.
		$city_code = $query->get_resolved_identity();

		// A shopper without a known place gets no points. It is never a request for all of them.
		if ( null === $city_code ) {
			return [];
		}

		$points = [];

		foreach ( $this->api->get_points_for_city( (string) $city_code ) as $row ) {
			$point = $this->to_point( $row );

			// One bad point must not empty the map.
			if ( null !== $point ) {
				$points[] = $point;
			}
		}

		return $points;
	}

	/**
	 * Fetches one point by its carrier id.
	 *
	 * @param string $point_id The carrier's point id.
	 * @return Pickup_Point|null Null when the carrier does not know the point.
	 *
	 * @throws \Woodev_API_Exception When the carrier cannot be reached.
	 */
	public function fetch_details( string $point_id ): ?Pickup_Point {
		$row = $this->api->get_point( $point_id );

		return is_array( $row ) ? $this->to_point( $row ) : null;
	}

	/**
	 * Turns one carrier row into a framework point.
	 *
	 * @param array<string, mixed> $row One row from the carrier.
	 * @return Pickup_Point|null Null when the row is not usable.
	 */
	private function to_point( array $row ): ?Pickup_Point {
		$is_locker = 'LOCKER' === ( $row['kind'] ?? '' );
		$payment   = (array) ( $row['payment'] ?? [] );

		return Pickup_Point::from_array(
			[
				'id'              => (string) ( $row['id'] ?? '' ),
				'name'            => (string) ( $row['name'] ?? '' ),
				'lat'             => $row['lat'] ?? null,
				'lng'             => $row['lng'] ?? null,
				'address'         => (string) ( $row['address'] ?? '' ),
				'locality'        => (string) ( $row['city'] ?? '' ),
				'postal_code'     => (string) ( $row['postcode'] ?? '' ),
				'phone'           => (string) ( $row['phone'] ?? '' ),
				'type'            => [
					'code'  => $is_locker ? 'locker' : 'office',
					// The shopper reads this label as written, so translate it.
					'label' => $is_locker ? __( 'Parcel locker', 'my-carrier' ) : __( 'Pickup office', 'my-carrier' ),
				],
				'payment_methods' => $this->payment_labels( $payment ),
				'accepts_cod'     => in_array( 'CASH', $payment, true ),
				// In grams.
				'max_weight'      => isset( $row['max_weight_kg'] ) ? (int) round( $row['max_weight_kg'] * 1000 ) : null,
			]
		);
	}

	/**
	 * Turns carrier payment codes into labels a shopper understands.
	 *
	 * @param string[] $codes Carrier codes.
	 * @return string[]
	 */
	private function payment_labels( array $codes ): array {
		$labels = [];

		foreach ( $codes as $code ) {
			if ( 'CASH' === $code ) {
				$labels[] = __( 'Cash', 'my-carrier' );
			} elseif ( 'CARD' === $code ) {
				$labels[] = __( 'Bank card', 'my-carrier' );
			}

			// A code that means nothing to a buyer, such as "already paid", is left out.
		}

		return $labels;
	}
}
```

If the carrier has only a list endpoint, extend `Abstract_Bulk_Point_Source` instead. It supplies `get_strategy()` and `fetch_details()` (a scan over the raw points), and you write `fetch_points()` and `raw_bulk_points()`, which returns the raw rows the scan looks through. If a raw row is not already in the `Pickup_Point::from_array()` shape, override `normalize_bulk_point()`, and if its id is not under the `id` key, override `bulk_point_id()`.

Points to remember:

- **A failed request throws, an empty city returns `[]`.** `fetch_points()` and `fetch_details()` throw `\Woodev_API_Exception` when the carrier cannot answer. The route turns that into an error with a retry, which the shopper sees differently from "no points here". `fetch_details()` returns `null` only for a point the carrier does not know.
- **Skip a bad row, do not abort.** `Pickup_Point::from_array()` returns `null` for a point without an id, name, address, type or valid coordinates. Collect the good ones.
- **The shopper reads the strings as you wrote them.** The type label, `payment_methods` and `services` are shown on the map card as given. Map the carrier's codes to translated labels and write the labels as English msgids (`__( 'Cash', 'my-carrier' )`), then put the Russian text in your `.po` file. A raw carrier code such as `card_on_receipt` would be shown to the shopper. Leave out codes that mean nothing to a buyer.
- **Give `max_weight` in grams.**

### Optional point data

`from_array()` accepts these keys besides the ones above:

| Key | Meaning |
|---|---|
| `short_address` | A shortened address. Defaults to `address`. |
| `work_time` | A text such as "Mon–Fri 9:00–18:00". Built from `schedule` when empty. |
| `schedule`, `time_zone` | Structured hours: day keys `mon` … `sun`, each a list of `[ 'HH:MM', 'HH:MM' ]` pairs; an empty list means closed that day. |
| `instruction`, `phone`, `photos`, `icons` | Extra card content. |
| `point_short_name` | A tab name when a place has several types of point. |
| `cells` | Parcel-locker compartments, see below. |

### Parcel lockers and their cells

A locker has compartments of fixed sizes. If the carrier publishes them, pass them in `cells` as a list of `[ 'length' => cm, 'width' => cm, 'height' => cm, 'max_weight' => grams or null ]`:

```php
<?php
defined( 'ABSPATH' ) || exit;

$cells = [
	[ 'length' => 40, 'width' => 30, 'height' => 20, 'max_weight' => 15000 ],
	[ 'length' => 60, 'width' => 40, 'height' => 40, 'max_weight' => null ],
];
```

The framework then places the order's items into each cell in turn. A locker that cannot hold the order cannot be chosen, so you do not write a fit check. Pass cells only when the carrier really published them:

- One malformed cell, or more than 32 different cells, drops the **whole** list. A dropped cell could have been the one that fits, so the point is then simply not size-checked.
- The three sides are an unordered box; which side you call `length` does not matter.
- Cells stay on the server and are not sent to the browser.

### A source that loads the map area

A carrier that returns points for the visible part of the map (the **viewport** strategy) implements `Point_Source` directly instead. `get_strategy()` returns `Point_Source::STRATEGY_VIEWPORT`, and `fetch_points()` receives a query with `get_bounds()` (`[ min_lat, min_lng, max_lat, max_lng ]`) and `get_types()`. It must honour `get_types()`, where an empty list means all types, because the whole point of that strategy is to fetch only what is shown. The framework caps the size of one box. Search text arrives in `get_search()`.

## 2. Remember the shopper's choice

A `Selection_Scope` tells the framework what a "place" and a "type" mean for your carrier, so the choice comes back when the shopper returns to the same city. If your plugin uses the location layer, extend `Provider_Selection_Scope`: it already answers `current_locality()` from the shopper's current place, and you add the rest.

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope;

/**
 * Remembers which point a shopper chose, per place.
 */
final class My_Carrier_Selection_Scope extends Provider_Selection_Scope {

	/**
	 * Gets the session key the choices are stored under. Keep it stable once shops use it.
	 *
	 * @return string
	 */
	public function session_key(): string {
		return 'my_carrier_pickup_selection';
	}

	/**
	 * Gets the place a confirmed point belongs to.
	 *
	 * It must use the same kind of key as current_locality(), so the point is read back
	 * under the key it was written with.
	 *
	 * @param Pickup_Point $point The point that was just confirmed.
	 * @return string
	 */
	public function locality_for_point( Pickup_Point $point ): string {
		return $this->current_locality();
	}

	/**
	 * Gets the point type a method restores a choice for.
	 *
	 * @param string $method_id The bare shipping method id, or an empty string when it is not known yet.
	 * @return string|null A type code, self::TYPE_ANY, or null for no restored choice.
	 */
	public function type_for_method( string $method_id ): ?string {
		return 'my_carrier_pickup' === $method_id ? self::TYPE_ANY : null;
	}
}
```

:::caution
Compare the method id **positively**, as above. An empty string means "WooCommerce does not know the chosen method yet", never "no method". A rule such as "anything except courier gets a point" would restore a point for a method nobody chose.
:::

Without a scope the block checkout has no way to carry the choice, so its pickup button does not appear. The classic checkout still works, and the framework reports the missing scope.

## 3. Pick a map

Two providers are built in, and both are used the same way: build one and give it to the handler.

| Provider | Id | Use it when |
|---|---|---|
| `Yandex_Map_Provider` | `yandex` | You want the framework's own map: list panel, point card, search, clustering and filters around a Yandex map. |
| `Embedded_Map_Provider` | `embedded` | The carrier ships its own widget, which already has a list and a selection. It is shown in a sandboxed frame. |

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Map\Embedded_Map_Provider;
use Woodev\Framework\Shipping\Map\Yandex_Map_Provider;

// The Yandex provider. The key is your own, from the Yandex developer console.
$yandex = new Yandex_Map_Provider( 'YOUR-FALLBACK-KEY' );

// The carrier's widget: its address, and the origin a selection message is trusted from.
$widget = new Embedded_Map_Provider( 'https://widget.my-carrier.example/map', 'https://widget.my-carrier.example' );
```

The Yandex provider needs a key to load the map, and it picks one in this order: the key the merchant entered in «Ключ API Яндекс Карт» (Yandex Maps API key) on the settings page described below, then the optional second constructor argument, then the fallback key you passed first. A site can replace the fallback with the `woodev_shipping_map_fallback_api_key` filter. The fallback is shared by every shop that runs your plugin, so a heavily used shop should enter its own key.

A plugin that offers more than one keeps them in `$this->get_map_provider_registry()`: `register( $provider )` stores a provider by its id, and `get( 'yandex' )` returns it or `null`. Registering a second provider under the same id replaces the first.

A provider describes itself to the framework through the `Map_Provider` interface (`get_id()`, `get_label()`, `get_script_handle()`, `get_settings_fields()`, `get_js_config()` and `owns_chrome()`). You do not need to implement it unless you ship a map that is neither of the two.

## 4. Build and register the handler

The `Pickup_Handler` ties the source, the map and the scope into the checkout. Build it once and call `register()` yourself.

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field;
use Woodev\Framework\Shipping\Map\Yandex_Map_Provider;
use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;

final class My_Carrier_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin {

	/**
	 * The pickup handler, built once.
	 *
	 * @var Pickup_Handler|null
	 */
	private $pickup_handler;

	/**
	 * The checkout handler, built once.
	 *
	 * @var Checkout_Handler|null
	 */
	private $checkout_handler;

	/**
	 * Gets the pickup handler. The framework does not register it for you, so the first call does.
	 *
	 * @return Pickup_Handler|null
	 */
	public function get_pickup_handler(): ?Pickup_Handler {
		if ( null !== $this->pickup_handler ) {
			return $this->pickup_handler;
		}

		$this->pickup_handler = new Pickup_Handler(
			'my-carrier',                                    // 1. Plugin id: the route and script name.
			'my_carrier_pickup_point',                       // 2. The checkout field that holds the point.
			new My_Carrier_Point_Source( $this->get_my_carrier_api() ), // 3. The point source.
			new Yandex_Map_Provider( 'YOUR-FALLBACK-KEY' ),  // 4. The map.
			[                                                // 5. Where the map opens.
				'center' => [ 55.76, 37.64 ],
				'zoom'   => 10,
			],
			new Shipping_Order_Handler(                      // 6. Where the full point is saved on the order.
				[ 'pickup_point' => '_my_carrier_pickup_point' ]
			),
			'pickup_point',                                  // 7. The key of that order meta, as named above.
			[],                                              // 8. Icons per point type.
			'#06aedd',                                       // 9. Accent colour.
			'',                                              // 10. The merchant's accent colour setting.
			true,                                            // 11. Offer an address search.
			false,                                           // 12. Refresh the checkout after a choice.
			new My_Carrier_Selection_Scope(),                // 13. The selection scope.
			$this                                            // 14. This plugin.
		);

		$this->pickup_handler->register();

		return $this->pickup_handler;
	}

	/**
	 * Gets the checkout handler. It also makes sure the pickup handler exists on every request.
	 *
	 * @return Checkout_Handler|null
	 */
	public function get_checkout_handler(): ?Checkout_Handler {
		if ( null === $this->checkout_handler ) {
			$this->get_pickup_handler();

			$this->checkout_handler = new Checkout_Handler(
				Checkout_Fields::from_array( [ Pickup_Field::create( 'my_carrier_pickup_point' ) ] ),
				'my_carrier'
			);
		}

		return $this->checkout_handler;
	}

	// The remaining methods of the plugin class are shown in "Shipping plugin anatomy".
}
```

:::caution
The framework registers the **checkout** handler and the **webhook** handler for you, but **not** the pickup handler. Without `register()` there is no pickup button, no routes and no saved choice, and no error tells you so. Build the pickup handler from a method that runs on every request. The checkout handler getter above does that, because the framework calls it on every request. Do not call `register()` on the checkout handler.
:::

Things to get right in the arguments:

- **Pass the plugin as argument 14.** Without it the picker finds the shopper's place by the name shown on the page instead of by the location layer's key, and two settlements with one name can be mixed up.
- **Pass the scope as argument 13.** Without it the block checkout has no pickup button.
- **The field id must equal the id of the `Pickup_Field`.** The same id is the order-meta key used when the point is saved, so it has to be unique among your carrier plugins.
- **Arguments 6 and 7 go together.** Give both to save the full chosen point (not only its id) on the order, or neither. `Shipping_Order_Handler` takes a map from a logical name to the real order-meta key; it has no default key of its own.
- **The default map position is required.** It must be a `center` with a latitude and longitude and a `zoom` from 8 to 18, and an invalid value throws `\InvalidArgumentException`.

### What the checkout does with a point

When a shopper confirms a point, the framework checks it before it is accepted, and again when the order is placed:

- **Weight.** The cart weight must fit the point's `max_weight`.
- **Cash on delivery.** A point that does not accept cash cannot be used with a cash-on-delivery payment method.
- **Locker cells.** A locker whose cells cannot hold the order is not selectable.

A point that fails cannot be chosen, and the verdict carries a reason the shopper can read. If the carrier cannot be reached for the re-check at order time, the order is allowed by default, because a transient failure should not cost the sale; the filter below can reverse that.

By default a chosen point also fills the address and postcode fields, when the point has them. A merchant controls this under «Woodev → Настройки» (Settings), on the «Доставка» (Shipping) tab, in the «Карта» (Map) section, together with the other map options: «Подстановка адреса» (address fill), «Закрывать карту» (close the map after a choice), «Расположение кнопки» (button position) and «Акцентный цвет» (accent colour). Only the values the point really has are written. Do not make up a missing city or postcode.

## Hooks

### Filters and actions about the choice

| Hook | Type | Arguments |
|---|---|---|
| `woodev_shipping_pickup_point_selectable` | filter | The verdict `[ 'allowed' => bool, 'reason' => string\|null ]`, the `Pickup_Point`, the payment method id, the cart weight in grams and an array of method context. |
| `woodev_shipping_pickup_point_selection` | filter | The result for a confirmed point, the `Pickup_Point` and a context array with `field_id`, `method_id`, `payment_method` and `cart_weight`. |
| `woodev_shipping_pickup_point_selected` | action | The confirmed `Pickup_Point` and the same context array. |
| `woodev_shipping_pickup_recheck_outage_allows_checkout` | filter | `true`, the exception, the plugin id and the point id. Return `false` to refuse the order when the re-check cannot reach the carrier. |

`woodev_shipping_pickup_point_selectable` runs while the list is drawn, once for each point. Keep it to constants and stored data and never call the carrier from it.

```php
<?php
defined( 'ABSPATH' ) || exit;

add_filter(
	'woodev_shipping_pickup_point_selectable',
	static function ( $verdict, $point, $payment_method, $cart_weight ) {
		$type = $point->to_array()['type']['code'] ?? '';

		if ( 'locker' === $type && $cart_weight > 15000 ) {
			$verdict['allowed'] = false;
			$verdict['reason']  = __( 'Parcel lockers take parcels up to 15 kg.', 'my-carrier' );
		}

		return $verdict;
	},
	10,
	4
);
```

Use `woodev_shipping_pickup_point_selection` to change the answer for a point that was just confirmed, for example to refuse it for a reason you can only see then. Use `woodev_shipping_pickup_point_selected` to react after it is accepted, for example to log it.

### Appearance and behaviour of the map

Each of these takes the value to change, and most also take the plugin id so one carrier's setting does not affect another.

| Hook | Value and default |
|---|---|
| `woodev_pickup_accent_color` | The accent colour (a hex string). |
| `woodev_pickup_accent_fill_color`, `woodev_pickup_accent_contrast_color` | The derived fill and text colours; the accent is the second argument. |
| `woodev_pickup_map_i18n` | An array of the map's interface strings, and the plugin id. |
| `woodev_pickup_map_search_enabled` | Whether the address search is offered. |
| `woodev_pickup_max_accumulated_points` | `0` for unlimited. Bounds the points kept while the map is panned, for the viewport strategy. |
| `woodev_pickup_distance_unit_system` | `metric` or `imperial`. |
| `woodev_pickup_map_point_glyphs` | An array of marker glyphs per point type. |
| `woodev_pickup_max_remembered_selections` | How many remembered selections are kept per shopper. |

## Next steps

- [Orders, export, documents & tracking](../orders-export-documents-and-tracking/) shows how the saved point reaches the carrier when the order is exported.
- [Location & checkout fields](../location-and-checkout-fields/) covers the adapter that gives `fetch_points()` its city code.
