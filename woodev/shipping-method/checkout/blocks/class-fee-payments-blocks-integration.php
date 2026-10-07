<?php
/**
 * Checkout Blocks «payment method changed» trigger — WooCommerce Blocks integration (#1144).
 *
 * @package Woodev\Framework\Shipping\Checkout\Blocks
 */

namespace Woodev\Framework\Shipping\Checkout\Blocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Woodev\Framework\Shipping\Fee_Payments;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Fee_Payments_Blocks_Integration' ) ) :

	/**
	 * Hands the shared `checkout-blocks` bundle the namespace it sends the chosen payment method to.
	 *
	 * Registers no script of its own: it names the shared handle, which WooCommerce de-duplicates.
	 * While no shipping method instance limits its fee to payment methods it names nothing and
	 * publishes `enabled: false`, so the bundle never watches the payment store.
	 *
	 * Only ever instantiated from the `woocommerce_blocks_checkout_block_registration` hook, i.e. when
	 * WooCommerce Blocks — and so {@see IntegrationInterface} — exists.
	 *
	 * @since 2.0.2
	 */
	class Fee_Payments_Blocks_Integration implements IntegrationInterface {

		/**
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_name(): string {
			return Fee_Payments::INTEGRATION_NAME;
		}

		/**
		 * Registers the shared bundle when it has been built.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function initialize(): void {
			Locality_Blocks_Integration::register_bundle();
		}

		/**
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_script_handles(): array {
			return $this->is_enabled() && wp_script_is( Locality_Blocks_Integration::SCRIPT_HANDLE, 'registered' ) ? [ Locality_Blocks_Integration::SCRIPT_HANDLE ] : [];
		}

		/**
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_editor_script_handles(): array {
			return [];
		}

		/**
		 * The data the bundle reads through `getSetting( 'woodev-shipping-fee-payments_data' )`.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>
		 */
		public function get_script_data(): array {

			if ( ! $this->is_enabled() ) {
				return [ 'enabled' => false ];
			}

			return [
				'enabled'   => true,
				'namespace' => Fee_Payments::EXTENSION_NAMESPACE,
			];
		}

		/**
		 * The block checkout is only worth watching on the storefront, and only while the option is in use.
		 *
		 * @return bool
		 */
		private function is_enabled(): bool {
			return ! is_admin() && Fee_Payments::is_used();
		}
	}

endif;
