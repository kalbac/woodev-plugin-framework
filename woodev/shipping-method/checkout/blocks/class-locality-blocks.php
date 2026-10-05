<?php
/**
 * Checkout Blocks locality chooser — registration (SP-11 C-1, #1087).
 *
 * @package Woodev\Framework\Shipping\Checkout\Blocks
 */

namespace Woodev\Framework\Shipping\Checkout\Blocks;

use Woodev\Framework\Shipping\Checkout\Checkout_Handler;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Locality_Blocks' ) ) :

	/**
	 * Wires the WooCommerce Checkout block's «locality» chooser (SP-11 D-1 A / D-2 A / D-5 A).
	 *
	 * The chooser is OUR OWN inner block, mounted next to the native address form. It never replaces or
	 * hides the core City/State inputs: choosing a locality writes the native values, and the native fields
	 * stay editable and authoritative. WooCommerce renders it without the merchant touching the page
	 * because the block is registered as a FORCED inner block of the shipping address block.
	 *
	 * The loading route is `IntegrationInterface` on `woocommerce_blocks_checkout_block_registration`:
	 * the Checkout block adds our script handle to its own dependencies and publishes
	 * {@see Locality_Blocks_Integration::get_script_data()} under `woodev-shipping-locality_data`. On a page
	 * that renders the classic checkout shortcode that hook never fires, so the classic path pays nothing.
	 *
	 * Fleet-wide, not per plugin: every shipping plugin that boots {@see Checkout_Handler} calls
	 * {@see self::register()}, but the location layer has exactly one active provider chain per store,
	 * so one integration (the first handler's) answers for all of them — registering a second integration
	 * under the same name would only make WooCommerce complain.
	 *
	 * @since 2.0.2
	 */
	final class Locality_Blocks {

		/** WooCommerce integration name; the data key WooCommerce publishes is `{name}_data`. */
		public const INTEGRATION_NAME = 'woodev-shipping-locality';

		/** The forced inner block that carries the chooser under the shipping address. */
		public const BLOCK_SHIPPING = 'woodev/shipping-locality';
		/** The forced inner block that carries the chooser under billing when it is the destination. */
		public const BLOCK_BILLING = 'woodev/shipping-locality-billing';

		/** @var bool whether this request already wired the hooks */
		private static bool $registered = false;

		/** @var Checkout_Handler|null the handler that answers for the fleet */
		private static ?Checkout_Handler $handler = null;

		/**
		 * Wires the hooks once per request; later handlers are no-ops.
		 *
		 * @since 2.0.2
		 *
		 * @param Checkout_Handler $handler The registering handler.
		 *
		 * @return void
		 */
		public static function register( Checkout_Handler $handler ): void {
			if ( self::$registered ) {
				return;
			}

			self::$registered = true;
			self::$handler    = $handler;

			add_action( 'init', [ self::class, 'register_block_types' ] );
			add_action( 'woocommerce_blocks_checkout_block_registration', [ self::class, 'register_integration' ] );
		}

		/**
		 * Registers the server side of the forced inner block from its `block.json`.
		 *
		 * WooCommerce reads the SERVER-registered `parent` to attach a block's frontend component; the
		 * client registration alone is not enough. The block has no render callback — it is never saved
		 * into page content, it is forced in on the frontend.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function register_block_types(): void {
			if ( ! function_exists( 'register_block_type' ) ) {
				return;
			}

			if ( ! class_exists( '\WP_Block_Type_Registry' ) || ! \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_SHIPPING ) ) {
				register_block_type( __DIR__ . '/shipping-locality' );
			}

			if ( 'billing_only' === get_option( 'woocommerce_ship_to_destination', 'shipping' ) && ( ! class_exists( '\WP_Block_Type_Registry' ) || ! \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_BILLING ) ) ) {
				register_block_type( __DIR__ . '/shipping-locality-billing' );
			}
		}

		/**
		 * Adds the integration to the Checkout block's registry.
		 *
		 * Feature-detected (SP-11 D-5): `IntegrationInterface` ships with WooCommerce Blocks, so a store
		 * without it — or one old enough not to have it — simply gets no chooser and a working native
		 * address form.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param object $registry The WooCommerce `IntegrationRegistry`.
		 *
		 * @return void
		 */
		public static function register_integration( object $registry ): void {
			if ( null === self::$handler || ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) || ! is_callable( [ $registry, 'register' ] ) ) {
				return;
			}

			$registry->register( new Locality_Blocks_Integration( self::$handler ) );
		}

		/**
		 * The shopper-facing strings the chooser renders.
		 *
		 * Storefront strings: the msgid is ENGLISH and the Russian arrives from the catalogue (AGENTS.md).
		 * They travel from PHP, next to the location layer's own strings, so the bundle carries no
		 * msgids of its own.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string>
		 */
		public static function i18n_strings(): array {
			return [
				'label'        => __( 'Find your locality', 'woodev-plugin-framework' ),
				'hint'         => __( 'Choose a locality to fill in the city and region.', 'woodev-plugin-framework' ),
				'searching'    => __( 'Searching…', 'woodev-plugin-framework' ),
				'listLabel'    => __( 'Locality suggestions', 'woodev-plugin-framework' ),
				'clear'        => __( 'Clear the chosen locality', 'woodev-plugin-framework' ),
				'regionNotSet' => __( 'The region could not be matched — choose it in the address form.', 'woodev-plugin-framework' ),
				'syncFailed'   => __( 'Your locality could not be updated.', 'woodev-plugin-framework' ),
				'retry'        => __( 'Try again', 'woodev-plugin-framework' ),
			];
		}

		/**
		 * Forgets the per-request wiring. Tests only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$registered = false;
			self::$handler    = null;
		}

		/**
		 * The handler answering for the fleet, or `null` before {@see self::register()}.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return Checkout_Handler|null
		 */
		public static function handler(): ?Checkout_Handler {
			return self::$handler;
		}
	}

endif;
