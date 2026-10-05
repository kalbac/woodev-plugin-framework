<?php
/**
 * Checkout Blocks locality chooser — WooCommerce Blocks integration (SP-11 C-1, #1087).
 *
 * @package Woodev\Framework\Shipping\Checkout\Blocks
 */

namespace Woodev\Framework\Shipping\Checkout\Blocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Locality_Blocks_Integration' ) ) :

	/**
	 * Hands the locality chooser's bundle and data to the Checkout block.
	 *
	 * Only ever instantiated from the `woocommerce_blocks_checkout_block_registration` hook, i.e. when
	 * WooCommerce Blocks — and so {@see IntegrationInterface} — exists.
	 *
	 * @since 2.0.2
	 */
	class Locality_Blocks_Integration implements IntegrationInterface {

		/** Script handle of the built `checkout-blocks` bundle. */
		public const SCRIPT_HANDLE = 'woodev-checkout-blocks';

		/**
		 * WooCommerce script handles the bundle reads off `window.wc` / `wcSettings`. The bundle imports
		 * none of `@woocommerce/*` (the packages are not a dependency here), so the dependency extraction
		 * plugin cannot name them — they are declared by hand. `wc-blocks-registry` is the public payment
		 * registry the pickup button resolves the active gateway's id through (#1089).
		 */
		private const WC_SCRIPT_DEPENDENCIES = [ 'wc-blocks-checkout', 'wc-blocks-checkout-events', 'wc-blocks-data-store', 'wc-blocks-registry', 'wc-settings' ];

		/** @var Checkout_Handler the handler answering for the fleet */
		private Checkout_Handler $handler;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Checkout_Handler $handler The handler whose location layer feeds the chooser.
		 */
		public function __construct( Checkout_Handler $handler ) {
			$this->handler = $handler;
		}

		/**
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_name(): string {
			return Locality_Blocks::INTEGRATION_NAME;
		}

		/**
		 * Registers the bundle (and its stylesheet) when it has been built.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function initialize(): void {
			static::register_bundle();
		}

		/**
		 * Registers the `checkout-blocks` bundle and its stylesheet under {@see self::SCRIPT_HANDLE}.
		 *
		 * Static and public because the bundle is shared: the pickup button
		 * ({@see Pickup_Blocks_Integration}, SP-11 C-2b #1089) is another block of the same bundle and
		 * must find the handle registered on a store where this integration is not. Registering a
		 * handle WordPress already knows is a no-op.
		 *
		 * A checkout where the bundle is missing — a source checkout that never ran `npm run build` —
		 * gets no handle, never a 404 and never a dependency on nothing.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function register_bundle(): void {
			$asset_file = static::build_path() . '/index.asset.php';

			if ( ! is_readable( $asset_file ) ) {
				return;
			}

			$asset = require $asset_file;
			$deps  = is_array( $asset ) && isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : [];
			$ver   = is_array( $asset ) && isset( $asset['version'] ) ? (string) $asset['version'] : (string) \Woodev_Plugin::VERSION;

			wp_register_script(
				self::SCRIPT_HANDLE,
				static::build_url() . '/index.js',
				array_values( array_unique( array_merge( $deps, self::WC_SCRIPT_DEPENDENCIES ) ) ),
				$ver,
				true
			);

			// Only REGISTERED here: `initialize()` runs on every request that registers the Checkout block,
			// checkout page or not. The stylesheet is enqueued from `get_script_data()`, which WooCommerce
			// reads only when it renders the block.
			if ( is_readable( static::build_path() . '/style-index.css' ) ) {
				wp_register_style( self::SCRIPT_HANDLE, static::build_url() . '/style-index.css', [], $ver );
			}
		}

		/**
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_script_handles(): array {
			return wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ? [ self::SCRIPT_HANDLE ] : [];
		}

		/**
		 * No editor script: the block is never placed by a merchant, it is forced in on the frontend.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_editor_script_handles(): array {
			return [];
		}

		/**
		 * The data the bundle reads through `getSetting( 'woodev-shipping-locality_data' )`.
		 *
		 * Never carries the visitor's nonce or customer selection into the EDITOR: WooCommerce also asks for
		 * this data while enqueueing editor assets, and the editor page is not the shopper's.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>
		 */
		public function get_script_data(): array {
			if ( is_admin() ) {
				return [ 'enabled' => false ];
			}

			$location = $this->handler->locality_blocks_config();

			if ( null === $location ) {
				return [ 'enabled' => false ];
			}

			if ( wp_style_is( self::SCRIPT_HANDLE, 'registered' ) ) {
				wp_enqueue_style( self::SCRIPT_HANDLE );
			}

			$location['i18n'] = array_merge( (array) ( $location['i18n'] ?? [] ), Locality_Blocks::i18n_strings() );

			return [
				'enabled'  => true,
				'location' => $location,
			];
		}

		/**
		 * The built bundle's directory. A seam: tests point it at a fixture.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected static function build_path(): string {
			return dirname( __DIR__, 3 ) . '/assets/build/checkout-blocks';
		}

		/**
		 * The built bundle's URL, without a trailing slash.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected static function build_url(): string {
			return dirname( plugins_url( 'index.js', static::build_path() . '/index.js' ) );
		}
	}

endif;
