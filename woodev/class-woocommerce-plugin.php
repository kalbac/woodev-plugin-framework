<?php
/**
 * WooCommerce platform plugin base.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Woocommerce_Plugin::class, false ) ) :

	/**
	 * Base class for plugins that require WooCommerce runtime behavior.
	 *
	 * The class is intentionally thin at this step. Runtime WooCommerce ownership
	 * moves here in later Platform v2 phases after resolver and loader contracts
	 * are covered by tests.
	 *
	 * @since 2.0.0
	 */
	abstract class Woocommerce_Plugin extends \Woodev_Plugin {

		/**
		 * Adds WooCommerce runtime action and filter hooks.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		protected function add_woocommerce_hooks(): void {

			// handle WooCommerce features compatibility (such as HPOS, WC Cart & Checkout Blocks support...)
			add_action( 'before_woocommerce_init', [ $this, 'handle_features_compatibility' ] );

			foreach ( array( 'shipping', 'checkout', 'integration' ) as $tab ) {
				add_action( 'woocommerce_before_settings_' . $tab, array( $this, 'add_class_form_wrap_start' ) );
				add_action( 'woocommerce_after_settings_' . $tab, array( $this, 'add_class_form_wrap_end' ) );
			}

			// add any PHP incompatibilities to the system status report
			add_filter(
				'woocommerce_system_status_environment_rows',
				array(
					$this,
					'add_system_status_php_information',
				)
			);
		}
	}

endif;
