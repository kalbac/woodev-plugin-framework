<?php
/**
 * Woodev Pickup Map Settings
 *
 * Store-level settings handler owning the «Карта» section of the «Доставка» tab
 * (design S1/S9). Registered with the `pickup_map` option namespace
 * (`woodev_pickup_map_*`) so it never collides with `Location_Settings`'s
 * `woodev_location_*` options.
 *
 * Deliberately empty — Task 8 fills in the pickup map behaviour fields.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Map_Settings' ) ) :

	/**
	 * Settings handler for the pickup map behaviour («Карта» section). Empty until Task 8.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Map_Settings extends \Woodev_Abstract_Settings {

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 */
		public function __construct() {
			parent::__construct( 'pickup_map' );
		}

		/**
		 * Gets the settings ids this handler owns, in registration order. Used by
		 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab} to build the
		 * `Settings_Section` without duplicating this handler's own field list.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return [];
		}

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		protected function register_settings() {
			// Task 8 fills this in.
		}
	}

endif;
