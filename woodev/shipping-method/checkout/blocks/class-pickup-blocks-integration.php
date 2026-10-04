<?php
/**
 * Checkout Blocks pickup-point picker — WooCommerce Blocks integration (SP-11 C-2b, #1089).
 *
 * @package Woodev\Framework\Shipping\Checkout\Blocks
 */

namespace Woodev\Framework\Shipping\Checkout\Blocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Woodev\Framework\Shipping\Pickup\Store_Api_Pickup;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Pickup_Blocks_Integration' ) ) :

	/**
	 * Hands the pickup button's data to the Checkout block.
	 *
	 * The block lives in the SAME bundle as the locality chooser (`checkout-blocks`), so this
	 * integration registers no script of its own: it names the shared handle, which WooCommerce
	 * de-duplicates, and makes sure it is registered even on a store where the locality integration
	 * is not.
	 *
	 * Only ever instantiated from the `woocommerce_blocks_checkout_block_registration` hook, i.e. when
	 * WooCommerce Blocks — and so {@see IntegrationInterface} — exists.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Blocks_Integration implements IntegrationInterface {

		/**
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_name(): string {
			return Pickup_Blocks::INTEGRATION_NAME;
		}

		/**
		 * Registers the shared bundle when it has been built.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function initialize(): void {
			static::register_bundle();
		}

		/**
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_script_handles(): array {
			return wp_script_is( Locality_Blocks_Integration::SCRIPT_HANDLE, 'registered' ) ? [ Locality_Blocks_Integration::SCRIPT_HANDLE ] : [];
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
		 * The data the bundle reads through `getSetting( 'woodev-shipping-pickup_data' )`.
		 *
		 * Carries no nonce and no customer selection: the picker's own config (with its REST nonce)
		 * is localized by the owning handler on the checkout page only, and the confirmed point
		 * arrives in the cart's extension data. WooCommerce also asks for this data while enqueueing
		 * editor assets — the editor gets a disabled block.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>
		 */
		public function get_script_data(): array {
			if ( is_admin() ) {
				return [ 'enabled' => false ];
			}

			$fields = Pickup_Blocks::field_descriptors();

			if ( [] === $fields ) {
				return [ 'enabled' => false ];
			}

			if ( wp_style_is( Locality_Blocks_Integration::SCRIPT_HANDLE, 'registered' ) ) {
				wp_enqueue_style( Locality_Blocks_Integration::SCRIPT_HANDLE );
			}

			return [
				'enabled'   => true,
				'namespace' => Store_Api_Pickup::EXTENSION_NAMESPACE,
				'fields'    => $fields,
				'i18n'      => Pickup_Blocks::i18n_strings(),
			];
		}

		/**
		 * Registers the `checkout-blocks` bundle. A seam: tests replace it.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected static function register_bundle(): void {
			Locality_Blocks_Integration::register_bundle();
		}
	}

endif;
