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
		 * Plugin compatibility flags for WooCommerce runtime features.
		 *
		 * @since 2.0.0
		 *
		 * @var array{ hpos?: bool, blocks?: array{ cart?: bool, checkout?: bool }}
		 */
		protected $supported_features = [];

		/**
		 * Initialize the WooCommerce plugin.
		 *
		 * @since 2.0.0
		 *
		 * @param string $id Plugin ID.
		 * @param string $version Plugin version number.
		 * @param array{
		 *     supported_features?: array{
		 *          hpos?: bool,
		 *          blocks?: array{
		 *               cart?: bool,
		 *               checkout?: bool
		 *          }
		 *     }
		 * } $args Plugin arguments.
		 */
		public function __construct( string $id, string $version, array $args = [] ) {
			$args = wp_parse_args(
				$args,
				[
					'supported_features' => [
						'hpos'   => false,
						'blocks' => [
							'cart'     => false,
							'checkout' => false,
						],
					],
				]
			);

			$this->supported_features = $args['supported_features'];

			parent::__construct( $id, $version, $args );

			// Build the WooCommerce Blocks handler only for WooCommerce plugins.
			$this->init_blocks_handler();
		}

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

			foreach ( [ 'shipping', 'checkout', 'integration' ] as $tab ) {
				add_action( 'woocommerce_before_settings_' . $tab, [ $this, 'add_class_form_wrap_start' ] );
				add_action( 'woocommerce_after_settings_' . $tab, [ $this, 'add_class_form_wrap_end' ] );
			}

			// add any PHP incompatibilities to the system status report
			add_filter(
				'woocommerce_system_status_environment_rows',
				[
					$this,
					'add_system_status_php_information',
				]
			);
		}

		/**
		 * Gets a list of the plugin's WooCommerce compatibility flags.
		 *
		 * @since 2.0.0
		 *
		 * @return array{ hpos?: bool, blocks?: array{ cart?: bool, checkout?: bool }}
		 */
		public function get_supported_features(): array {
			return $this->supported_features;
		}
	}

endif;
