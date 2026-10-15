---
title: "Location & checkout fields"
description: Let shoppers pick their city, translate it into your carrier's own city code, and add managed fields to the classic and block checkout.
---

In this page you will learn how a shipping plugin gets the shopper's city and region from the framework, turns it into the carrier's own identifier, adds a carrier dictionary as a source of suggestions, and declares extra checkout fields that work in both the classic and the block checkout.

Covers framework release `%%FRAMEWORK_VERSION%%`. It assumes a plugin built as in [Shipping plugin anatomy](../plugin-anatomy/).

## How the pieces fit

Two framework layers do the work, and your plugin only fills in the parts that belong to your carrier:

- **The location layer** shows the region and city inputs, with suggestions, on the checkout. When the shopper picks a place, the layer keeps it as a neutral `Location_Record`. Your plugin supplies one small class, the **adapter**, that turns that record into the carrier's own identity, such as a city code.
- **The checkout field layer** injects and validates the extra fields your carrier needs, for example the pickup point. You describe the fields; the framework renders, validates and saves them.

Both layers cover the classic checkout and the block checkout. Do not build a city dropdown of your own, and do not rely on classic-checkout hooks for behaviour the block checkout needs too.

## Opt in to the location layer

The layer is off until a plugin asks for it. Override two methods of your `Shipping_Plugin` subclass:

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Location\Location_Adapter;

final class My_Carrier_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin {

	/**
	 * The adapter, built once.
	 *
	 * @var Location_Adapter|null
	 */
	private $location_adapter;

	/**
	 * Asks the framework for its shared city and region inputs.
	 *
	 * @return bool
	 */
	public function needs_location_provider(): bool {
		return true;
	}

	/**
	 * Gets the class that maps a location to this carrier's city code.
	 *
	 * @return Location_Adapter|null
	 */
	public function get_location_adapter(): ?Location_Adapter {
		if ( null === $this->location_adapter ) {
			$this->location_adapter = new My_Carrier_Location_Adapter( $this );
		}

		return $this->location_adapter;
	}

	// The remaining methods of the plugin class are shown in "Shipping plugin anatomy".
}
```

:::caution
A plugin that returns `true` from `needs_location_provider()` **must** also return an adapter. Without one the framework reports a developer notice (`_doing_it_wrong()`) the first time it tries to resolve a location, and your carrier never receives its city code.
:::

### Write the adapter

The adapter has one method, `resolve()`. It receives the shopper's `Location_Record` and returns whatever your carrier's API needs: a string, an integer, or an array of several values. The framework never looks inside the value.

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Location\Location_Adapter;
use Woodev\Framework\Shipping\Location\Location_Provider_Exception;
use Woodev\Framework\Shipping\Location\Location_Record;

/**
 * Maps a shopper's location to My Carrier's city code.
 */
final class My_Carrier_Location_Adapter implements Location_Adapter {

	/**
	 * The owning plugin.
	 *
	 * @var My_Carrier_Plugin
	 */
	private $plugin;

	/**
	 * Sets up the adapter.
	 *
	 * @param My_Carrier_Plugin $plugin The owning plugin.
	 */
	public function __construct( My_Carrier_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Resolves a location to the carrier's city code.
	 *
	 * @param Location_Record $record The shopper's location.
	 * @return string|null The city code, or null when the carrier does not serve the place.
	 *
	 * @throws Location_Provider_Exception On a failure that may succeed on a retry.
	 */
	public function resolve( Location_Record $record ) {
		$settlement = $record->settlement();
		$region     = $record->region();

		if ( null === $settlement ) {
			return null;
		}

		// 1. Ask the carrier's own city lookup first. It is the source of truth.
		$code = $this->plugin->get_my_carrier_api()->find_city_code(
			(string) $settlement['name'],
			(string) ( $region['name'] ?? '' )
		);

		if ( null !== $code ) {
			return $code;
		}

		// 2. Fall back to the framework's shared lookup, and check the id with the carrier.
		$ids = $this->plugin->get_location_service()->get_delivery_ids( $record );

		// DaData names each carrier it knows, for example `cdek_id`. Use the key of your carrier.
		if ( isset( $ids['cdek_id'] ) && $this->plugin->get_my_carrier_api()->city_exists( $ids['cdek_id'] ) ) {
			return $ids['cdek_id'];
		}

		return null;
	}
}
```

`get_my_carrier_api()` stands for your own API client (see [Calling external APIs](../../core/calling-external-apis/)). What matters is the contract of `resolve()`:

- **Return `null` when the carrier does not serve the place.** `null` is a normal answer. The framework remembers it for the session, so the carrier is not asked again.
- **Throw only for a failure that may succeed on the next request**, such as a timeout. A thrown exception is logged and is not remembered, so the next calculation tries again. Never throw for "not served": that would call your carrier's API again on every rate calculation.
- **Keep it cheap.** `resolve()` runs on any request that calculates rates or loads pickup points, not only at checkout. The first call for a new place always reaches your code, so use a local dictionary or cache the API answer.
- **Never use a display name as an identifier.** Names change with the shopper's language. Match on the record's key or on the carrier's own code.

### The shared lookup of carrier ids

`$this->get_location_service()` returns the framework's `Location_Service`. Its `get_delivery_ids( $record )` returns the ids that the bundled DaData provider knows for Russian settlements, as an array keyed by carrier, for example `[ 'cdek_id' => '44' ]`. Treat it as a **second source only**: the ids come from a third party and may be out of date, so always try the carrier's own lookup first and verify any id you take from here.

It returns an empty array when the record did not come from DaData, the country is not Russia, DaData is not configured, or DaData has no answer. It throws `Location_Provider_Exception` when the request itself fails; let that exception leave `resolve()` so the framework retries.

Another helper is `get_record_in_language( $record, 'ru' )`. When a shop runs in a non-Russian language, DaData answers with transliterated names that never match a Cyrillic carrier dictionary, so ask for the Russian record before you compare names.

## What the shopper and the merchant get

The framework bundles the **DaData** provider. The shared layer owns the region and city inputs, the suggestions, the list of popular settlements, and the cascade from region to settlement to address. The merchant configures it under «Woodev → Настройки» (Settings), on the «Доставка» (Shipping) tab, in the «Локация» (Location) section. Your plugin writes no field or script for it.

## Add your carrier's own dictionary

A carrier that publishes its own list of regions and cities can offer it as a **provider**. This gives shops without a DaData key working suggestions, and gives you exact carrier codes.

Extend `Abstract_Location_Provider` and register an instance with the `woodev_location_providers` filter. The abstract class works out which optional abilities you implement by looking at the methods you override.

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Exception;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;

/**
 * The My Carrier city dictionary as a location provider.
 */
final class My_Carrier_Location_Provider extends Abstract_Location_Provider {

	/**
	 * Gets the provider id. It prefixes every locality key this provider produces.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'my-carrier';
	}

	/**
	 * Gets the name shown to the merchant.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'My Carrier dictionary', 'my-carrier' );
	}

	/**
	 * Gets the countries the dictionary covers.
	 *
	 * @return string[]
	 */
	public function get_countries(): array {
		return [ 'RU', 'BY', 'KZ' ];
	}

	/**
	 * Declares what the dictionary can suggest. It has regions and settlements, but no streets.
	 *
	 * @return string[]
	 */
	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ];
	}

	/**
	 * The provider reuses the carrier's API credentials, so it declares no fields of its own and
	 * answers from the carrier's settings instead.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return My_Carrier_Plugin::instance()->get_my_carrier_api()->has_credentials();
	}

	/**
	 * Suggests places that match what the shopper typed.
	 *
	 * @param string         $query What the shopper typed.
	 * @param Location_Scope $scope The country, the level and an optional parent place.
	 * @return Location_Record[]
	 *
	 * @throws Location_Provider_Exception When the dictionary cannot be read.
	 */
	public function suggest( string $query, Location_Scope $scope ): array {
		if ( '' === trim( $query ) ) {
			return [];
		}

		try {
			$rows = My_Carrier_Plugin::instance()->get_my_carrier_api()->search_cities( $query, $scope->country() );
		} catch ( \Exception $exception ) {
			// A failure is not "no match". An empty list reads as "no such city" and would
			// silently remove the carrier from the checkout.
			throw new Location_Provider_Exception( $exception->getMessage(), 0, $exception );
		}

		$records = [];

		foreach ( $rows as $row ) {
			$records[] = Location_Record::from_array(
				[
					'key'         => 'my-carrier:' . $row['code'],
					'provider_id' => 'my-carrier',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => $scope->country(),
					'region'      => [
						'name' => $row['region'],
						'type' => '',
					],
					'settlement'  => [
						'name' => $row['name'],
						'type' => '',
					],
					'raw'         => $row,
				]
			);
		}

		return $records;
	}
}

add_filter(
	'woodev_location_providers',
	static function ( $providers ) {
		$providers   = (array) $providers;
		$providers[] = new My_Carrier_Location_Provider();

		return $providers;
	}
);
```

Rules for a provider:

- **The key is yours and namespaced.** A `Location_Record` key reads `provider_id:native_id`, and the prefix must equal `provider_id`. Use the carrier's own city code as the native id. The adapter can then read the code straight off the key without another lookup.
- **Failure throws, "found nothing" returns `[]`.** Wrong credentials or a network error must throw `Location_Provider_Exception`, never return an empty list.
- **Serve the settlement level for every country you list.** A provider that cannot is rejected at registration.
- **Choose a unique id.** If two providers use the same id, the first one wins and the second is reported with `_doing_it_wrong()`. An entry in the filter that is not a `Location_Provider` is dropped the same way.
- **Declare no fields when the carrier's credentials live elsewhere**, and override `is_configured()` as above. The default answer is built from the declared fields, so a provider with none would otherwise claim to be configured.
- **Add optional abilities by overriding the method.** `list_localities()` lets the merchant choose the "ready list" field mode, where the shopper picks from a full list per region. `locate()` supports a default place from the shopper's IP address, `normalize()` turns a typed address into a record, and `resolve_key()` re-checks a stored key. Each of them must be answerable without a guess, and `resolve_key()` returns `null` only when the carrier confirms the place is gone.
- **Keep a cached list small.** A list of a few thousand settlements per region is fine as one cached value, but check that your cache write succeeds and never store a half-loaded list as if it were complete.

## Add checkout fields

Override `get_checkout_handler()` to return a `Checkout_Handler`. The handler takes a `Checkout_Fields` collection and a **hook prefix**, normally your plugin id.

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field;

final class My_Carrier_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin {

	/**
	 * The checkout handler, built once.
	 *
	 * @var Checkout_Handler|null
	 */
	private $checkout_handler;

	/**
	 * Gets the checkout handler. The framework registers it for you.
	 *
	 * @return Checkout_Handler|null
	 */
	public function get_checkout_handler(): ?Checkout_Handler {
		if ( null === $this->checkout_handler ) {
			$fields = Checkout_Fields::from_array(
				[
					// No label: the shopper sees a button and a map, not an input.
					Pickup_Field::create( 'my_carrier_pickup_point' ),
				]
			);

			$this->checkout_handler = new Checkout_Handler( $fields, 'my_carrier' );
		}

		return $this->checkout_handler;
	}

	// The remaining methods of the plugin class are shown in "Shipping plugin anatomy".
}
```

`Pickup_Field::create()` takes the method ids that need a pickup point as an optional second argument. Left out, the field is required exactly when the chosen method is a pickup method, which the framework works out when it is needed. Pass a list, as in `Pickup_Field::create( 'my_carrier_pickup_point', [ 'my_carrier_pickup' ] )`, when the field must follow a narrower set. `Checkout_Handler::set_requires_pickup_methods()` does the same for the final check that a pickup method is never ordered without a point.

If you return no handler, the plugin has no managed fields and nothing is written to the order for them. Do not call `register()` on this handler: the framework registers the checkout handler of a shipping plugin for you.

:::caution
Give every field an id that **no other carrier plugin uses**. A shop may run two carriers, and fields are keyed by id, so two plugins sharing an id collapse into one field and one carrier's pickup button never appears. The field id is also the order-meta key the value is saved under, so choose an id you can keep for the life of the plugin.
:::

### Describe a field

`Field::create( 'id' )` returns a builder. The setters you will use most:

| Method | What it does |
|---|---|
| `set_type( 'text' )` | Input type, for example `text`, `hidden` or `select`. |
| `set_label( 'Label' )` | The visible label. WooCommerce keeps control of its own address labels, so this applies to your own fields only. |
| `set_error_label( 'Pickup point' )` | The name used in error messages, for a field whose control is not a plain input. |
| `set_required( true )` | `true`, `false`, or a condition array such as "required when this shipping method is chosen". |
| `set_required_message( '…' )` | The complete message shown when the value is missing. |
| `set_invalid_message( '…' )` | The complete message shown when your validate callback returns `false`. |
| `set_section( 'order' )` | `order` (default), `billing` or `shipping`. |
| `set_sanitize_callback( $callable )` | Cleans the posted value. |
| `set_validate_callback( $callable )` | Returns `true`, `false`, or a `WP_Error` that carries its own message. |
| `depends_on( 'parent_id' )` | Makes the field depend on the value of another field. |
| `set_source( $callable, 'options' )` | Supplies the options or suggestions of a field. |
| `source_location( 'settlement' )` | Backs the field with the location layer. Levels: `region`, `settlement`, `address`. |

A text field of your own, required only for one method, looks like this:

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field;

$fields = Checkout_Fields::from_array(
	[
		Pickup_Field::create( 'my_carrier_pickup_point' ),

		Field::create( 'my_carrier_door_code' )
			->set_type( 'text' )
			->set_label( __( 'Door code', 'my-carrier' ) )
			->set_section( 'shipping' )
			->set_required(
				[
					'state'    => 'chosen_shipping_method',
					'operator' => 'in',
					'value'    => [ 'my_carrier_courier' ],
				]
			)
			->set_required_message( __( 'Please enter the door code for the courier.', 'my-carrier' ) )
			->set_sanitize_callback( 'sanitize_text_field' ),
	]
);
```

A `required` condition uses the operators `=`, `!=`, `in`, `not_in` and, for pickup, `is_pickup_method`. Any other operator is reported with `_doing_it_wrong()` at registration, not at checkout.

For a select whose options depend on another field, the `Dependent_Select` preset saves a line:

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Checkout\Presets\Dependent_Select;

$tariff = Dependent_Select::create( 'my_carrier_tariff', 'my_carrier_zone' )
	->set_label( __( 'Tariff', 'my-carrier' ) )
	// A callable that returns the options for the chosen parent value.
	->set_source( 'my_carrier_tariff_options' );
```

To back the address fields with the location layer, declare them with `source_location()`. The framework decides the checkout section from the shop's shipping destination setting and repeats the field for both billing and shipping when needed, so declare the id once, using WooCommerce's own naming such as `shipping_city`:

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Checkout\Field;

$region = Field::create( 'shipping_state' )->set_type( 'text' )->source_location( 'region' );
$city   = Field::create( 'shipping_city' )->set_type( 'text' )->source_location( 'settlement' );
```

Several carrier plugins that ask for the same level on the same field share one cascade on the checkout; this is the supported way to run two carriers on one shop.

## Required fields are checked on the server

Checkout is built so a shopper cannot get stuck or place an order the carrier cannot deliver:

- **The server decides.** A field that JavaScript hides is still posted, and WooCommerce checks presence, not visibility. The framework validates the managed fields itself when the order is placed, in the classic checkout and through the Store API in the block checkout. A pickup method can never be ordered without a pickup point, whatever the field's own condition says.
- **A hidden address row is not required.** When the merchant hides the address or postcode for pickup methods, the framework also relaxes `required` for that row while a pickup method is chosen. The shopper is never rejected for a field they cannot see.
- **The settlement field cannot be lost.** If another plugin removes the city field or makes it optional, the framework puts it back as required and tells the merchant on the «Доставка» tab.
- **The place-order button waits.** By default the button stays disabled until the required delivery fields are filled. The merchant can turn that off with «Блокировать оформление заказа» (Block order placement); the server check stays on either way.
- **Messages are yours to word.** Use `set_error_label()`, `set_required_message()` and `set_invalid_message()` so a button-driven field never says "enter a value" about an input that is not on the page.

### What the merchant controls

The framework applies these choices for you through `Checkout_Field_Policy`, a store-wide object that adjusts WooCommerce's own fields late, after themes and field-manager plugins have had their say. Under «Woodev → Настройки» (Settings), «Доставка» (Shipping) tab, «Форма заказа» (Checkout form) section, the merchant can show or remove the region field, hide the address and postcode for pickup methods, hide the country when the shop ships to one country, and pick a phone mask. Write your code so that any of these choices still produces a valid order: for example, never assume the region field exists.

## Hooks

Every hook of the checkout handler carries your prefix, so two plugins never share one. With the prefix `my_carrier` the names are `woodev_shipping_my_carrier_{name}`:

| Hook | Type | Arguments |
|---|---|---|
| `woodev_shipping_my_carrier_checkout_fields` | filter | The checkout fields after the managed fields are added, and the section. |
| `woodev_shipping_my_carrier_checkout_field_saved` | action | The order, the field id (also the meta key) and the saved value. |
| `woodev_shipping_my_carrier_checkout_data_saved` | action | The order and all saved values, keyed by field id. |
| `woodev_shipping_my_carrier_checkout_processed` | action | The order and the values that were persisted for it. |
| `woodev_shipping_my_carrier_admin_order_saved` | action | The order, the values written and a context array, after the admin order editor creates or updates an order. |

A few hooks are shared by every carrier, so check the plugin or order before acting: `woodev_shipping_store_api_posted_data` (filter, block checkout posted values), `woodev_shipping_available_payment_gateways` (filter, payment methods for the chosen delivery) and `woodev_checkout_pickup_slot_fields` (filter, the pickup fields of a handler).

## Phone masks

The merchant can enable an input mask for the phone field. It is a convenience, not a validation: the shopper cannot type an extra character, and the carrier still checks the number. Masks exist for Russia, Kazakhstan, Belarus, Ukraine, Armenia, Azerbaijan, Georgia, Kyrgyzstan, Tajikistan, Turkmenistan, Uzbekistan and Moldova. The mask works in the classic checkout.

Add a country, or change one, with the filter `woodev_phone_mask_patterns`. A `#` is a digit and every other character is shown as it is:

```php
<?php
defined( 'ABSPATH' ) || exit;

add_filter(
	'woodev_phone_mask_patterns',
	static function ( $patterns ) {
		$patterns       = (array) $patterns;
		$patterns['PL'] = '+48 ### ### ###';

		return $patterns;
	}
);
```

Some countries dial a domestic number with a leading trunk digit instead of the calling code (`8` for Russia and Kazakhstan). The filter `woodev_phone_mask_trunk_prefixes` adds that digit for another country; its value maps a country code to a single digit.

## Next steps

- [Pickup points & maps](../pickup-points-and-maps/) adds the pickup button, the map and the saved choice that the `Pickup_Field` above shows.
- [Orders, export, documents & tracking](../orders-export-documents-and-tracking/) covers what happens after the order is placed.
- [HPOS & Blocks compatibility](../../core/hpos-and-blocks-compatibility/) explains the declaration that block checkout support rests on.
