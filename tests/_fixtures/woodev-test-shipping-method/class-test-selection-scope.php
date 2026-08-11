<?php
/**
 * Woodev_Test_Selection_Scope — the rig's {@see \Woodev\Framework\Shipping\Pickup\Selection_Scope}
 * fixture (SP-5 pickup-selection-persistence plan, Step 4, issue #176).
 *
 * Deliberately speaks a DIFFERENT locality vocabulary than {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point}
 * itself: a point's own `locality` field is the human city name ("Москва"), the same
 * string this fixture's `billing_state` field encodes as a numeric region code ("77").
 * Using the region code — not the city name — as this scope's locality key exercises the
 * framework's "opaque locality key, never derived or normalized by the framework itself"
 * contract for real: a hidden assumption that the locality key IS the human city string
 * would silently pass if this fixture reused that same string, and only show up against a
 * real carrier whose locality vocabulary genuinely differs (СДЭК's `city_id`, Яндекс's
 * `geo_id`). See {@see \Woodev\Framework\Shipping\Pickup\Selection_Scope}'s own docblock.
 *
 * Extracted to its own file, `require_once`'d from `woodev_test_shipping_method_plugin_init()`
 * (not declared inline there, and not at this file's top level) for the same reason
 * `Woodev_Test_Bulk_Point_Source` is: a class `implements`-ing a `Woodev\Framework\*` symbol
 * can only be DECLARED once the bootstrap has selected the framework copy and registered its
 * autoloader — see that file's own docblock and gotcha
 * `fixture-classes-must-live-inside-plugin-init`.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Selection_Scope' ) ) {

	/**
	 * Class Woodev_Test_Selection_Scope
	 *
	 * Locality is the `billing_state` region code ("77"/"78"/"23" — see the three
	 * regions wired in `woodev_test_shipping_method_plugin_init()`'s checkout-fields
	 * block), never the point's own `locality` string. The single fixture shipping
	 * method (`woodev_test_shipping`) is not itself type-specific, so it maps to
	 * {@see \Woodev\Framework\Shipping\Pickup\Selection_Scope::TYPE_ANY} — every other
	 * method id (a courier method a real plugin might also register) gets `null`,
	 * meaning no selection is ever restored for it.
	 */
	class Woodev_Test_Selection_Scope implements \Woodev\Framework\Shipping\Pickup\Selection_Scope {

		/**
		 * Maps a point's own human `locality` string to this scope's numeric region
		 * code — the SAME mapping `woodev_test_shipping_method_plugin_init()`'s
		 * `billing_state`/`billing_city` field wiring already encodes, restated here
		 * because a fixture Point_Source has no reason to know about checkout fields.
		 *
		 * @var array<string, string> region code, keyed by city name.
		 */
		private const REGION_CODE_BY_CITY = [
			'Москва'          => '77',
			'Санкт-Петербург' => '78',
			'Краснодар'       => '23',
		];

		/**
		 * @inheritDoc
		 */
		public function session_key(): string {
			// An installed-site data contract owned by THIS plugin (spec §3.2) — a real
			// plugin's own key, e.g. Yandex's `chosen_yandex_pickup_point`, not a
			// framework-coined one.
			return 'woodev_test_pickup_selection';
		}

		/**
		 * @inheritDoc
		 */
		public function locality_for_point( \Woodev\Framework\Shipping\Pickup\Pickup_Point $point ): string {
			return self::REGION_CODE_BY_CITY[ $point->get_locality() ] ?? '';
		}

		/**
		 * @inheritDoc
		 */
		public function current_locality(): string {
			if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
				return '';
			}

			return (string) WC()->customer->get_billing_state();
		}

		/**
		 * @inheritDoc
		 */
		public function type_for_method( string $method_id ): ?string {
			// Literal method id (= Woodev_Test_Shipping_Method::METHOD_ID) — same
			// reasoning as the checkout-fields block's own literal: that class is not
			// yet loaded when this scope is constructed.
			if ( 'woodev_test_shipping' !== $method_id ) {
				return null;
			}

			return \Woodev\Framework\Shipping\Pickup\Selection_Scope::TYPE_ANY;
		}
	}
}
