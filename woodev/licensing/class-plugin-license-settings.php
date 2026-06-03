<?php
/**
 * Deprecated shim for Woodev_License_Settings.
 *
 * The licensing settings page admin handler moved to
 * Woodev_Woocommerce_License_Settings in 2.0.0 because the class
 * registers a woocommerce_screen_ids filter and is only relevant for
 * plugins running on WooCommerce. The framework loader
 * (Woodev_Plugin::load_license_settings_fields) now requires the new
 * class on demand, gated on Woodev_Helper::is_woocommerce_active().
 *
 * This stub is retained only for backward compat: dependent plugins
 * that do `class_exists( 'Woodev_License_Settings' )` or `instanceof`
 * checks against the class name will still resolve. Direct
 * instantiation is flagged via _doing_it_wrong() and does nothing.
 *
 * @since 1.0.0
 * @deprecated 2.0.0 Use Woodev_Woocommerce_License_Settings instead.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Woodev_License_Settings::class, false ) ) :

	class Woodev_License_Settings {

		/**
		 * Stored only to mirror the original constructor signature; the shim
		 * does not act on it. PHPStan requires every typed parameter to be
		 * used — assigning to a private property satisfies the check
		 * without adding behavior to the deprecated stub.
		 *
		 * @var Woodev_Plugin
		 */
		private $plugin;

		/**
		 * Deprecated since 2.0.0.
		 *
		 * The licensing settings admin handler moved to
		 * Woodev_Woocommerce_License_Settings. Direct instantiation of
		 * this class is a no-op and emits _doing_it_wrong().
		 *
		 * @since 1.0.0
		 * @deprecated 2.0.0 Use Woodev_Woocommerce_License_Settings instead.
		 *
		 * @param Woodev_Plugin $plugin Unused (preserved for signature BC).
		 */
		public function __construct( Woodev_Plugin $plugin ) {
			$this->plugin = $plugin;
			_deprecated_function( __METHOD__, '2.0.0', 'Woodev_Woocommerce_License_Settings::__construct' );
			_doing_it_wrong(
				__METHOD__,
				'Woodev_License_Settings has moved to Woodev_Woocommerce_License_Settings in 2.0.0. The licensing settings page is now loaded only when WooCommerce is active. Do not instantiate this class directly — the framework loads the WC-coupled replacement from Woodev_Plugin::load_license_settings_fields().',
				'2.0.0'
			);
		}
	}

endif;
