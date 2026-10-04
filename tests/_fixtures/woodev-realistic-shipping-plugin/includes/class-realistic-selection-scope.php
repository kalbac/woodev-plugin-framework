<?php
/**
 * Realistic shipping fixture — its own pickup-selection scope (SP-11 C-2b, #1089).
 *
 * WHY THIS EXISTS. The Store API pickup transport — and with it the Checkout block's pickup
 * button — rests on the plugin's {@see \Woodev\Framework\Shipping\Pickup\Selection_Scope}: the
 * scope is what tells the server that a rate is this carrier's pickup rate
 * (`Pickup_Handler::owns_store_api_rate()`) and where a confirmed point is remembered. This
 * fixture built its `Pickup_Handler` WITHOUT one, which the classic checkout tolerates (no
 * remembered point, nothing else) and the block checkout does not: the cart's
 * `woodev-shipping` extension answered `owner: null` for this carrier's own rate, so the block
 * rendered no button while the order still demanded a point. Measured on the rig in #1089's
 * browser acceptance.
 *
 * Same shape, and same reasoning, as the sibling fixture's
 * `Woodev_Test_Provider_Selection_Scope`: this carrier's `Point_Source` is addressed by
 * locality, so a point confirmed through the picker belongs to the customer's current
 * settlement — {@see self::locality_for_point()} answers from the SAME Location Provider layer
 * {@see \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope::current_locality()} reads,
 * never from the point's own city name.
 *
 * In its own file, `require_once`'d from `woodev_realistic_shipping_plugin_init()`: a class
 * extending a `Woodev\Framework\*` symbol can only be declared once the bootstrap has selected
 * the framework copy (gotcha `fixture-classes-must-live-inside-plugin-init`).
 *
 * @package Woodev_Realistic_Shipping_Fixture
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Realistic_Selection_Scope' ) ) {

	/**
	 * Pickup-selection scope of the realistic shipping fixture.
	 */
	class Woodev_Realistic_Selection_Scope extends \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope {

		/**
		 * @inheritDoc
		 */
		public function session_key(): string {
			// This plugin's own installed-site key — never the sibling carrier's, so the two
			// carriers' remembered points cannot answer for one another.
			return 'woodev_realistic_pickup_selection';
		}

		/**
		 * @inheritDoc
		 */
		public function locality_for_point( \Woodev\Framework\Shipping\Pickup\Pickup_Point $point ): string {
			return $this->current_locality();
		}

		/**
		 * @inheritDoc
		 */
		public function type_for_method( string $method_id ): ?string {
			// Literal method id (= Woodev_Realistic_Pickup_Shipping_Method's own): the courier
			// method of this same plugin carries no pickup type and gets `null`.
			if ( 'woodev_realistic_pickup_shipping' !== $method_id ) {
				return null;
			}

			return \Woodev\Framework\Shipping\Pickup\Selection_Scope::TYPE_ANY;
		}
	}
}
