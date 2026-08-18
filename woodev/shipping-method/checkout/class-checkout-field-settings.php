<?php
/**
 * Woodev Checkout Field Settings
 *
 * Store-level settings handler owning the «Поля» section of the «Доставка» tab
 * (design S1/S9). Registered with the `checkout_fields` option namespace
 * (`woodev_checkout_fields_*`) so it never collides with `Location_Settings`'s
 * `woodev_location_*` options.
 *
 * Deliberately empty — Task 5 fills in the checkout field policy fields. Kept
 * present (rather than deferred) so {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab}
 * always has three composite children to route to, and the «Поля» section
 * renders (empty) from the moment any plugin declares a shipping plugin.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Field_Settings' ) ) :

	/**
	 * Settings handler for the checkout field policy («Поля» section). Empty until Task 5.
	 *
	 * @since 2.0.2
	 */
	class Checkout_Field_Settings extends \Woodev_Abstract_Settings {

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 */
		public function __construct() {
			parent::__construct( 'checkout_fields' );
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
		 * Optional note shown under the «Поля» section heading.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_section_note(): string {
			return '';
		}

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		protected function register_settings() {
			// Task 5 fills this in.
		}
	}

endif;
