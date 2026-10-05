<?php
/**
 * Checkout Blocks pickup-point picker — registration (SP-11 C-2b, #1089).
 *
 * @package Woodev\Framework\Shipping\Checkout\Blocks
 */

namespace Woodev\Framework\Shipping\Checkout\Blocks;

use Woodev\Framework\Shipping\Pickup\Pickup_Handler;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Pickup_Blocks' ) ) :

	/**
	 * Wires the WooCommerce Checkout block's pickup-point button (SP-11 D-2 A / D-3 B / D-4 A).
	 *
	 * The button is OUR OWN inner block, forced into the shipping-methods block: WooCommerce renders
	 * it without the merchant touching the page. It shows for the framework's pickup rates only —
	 * which rate that is comes from the SERVER, in the cart's `woodev-shipping` extension data
	 * ({@see \Woodev\Framework\Shipping\Pickup\Store_Api_Pickup::cart_data()}), never from a label
	 * or a method-id list guessed in the browser.
	 *
	 * Fleet-wide, like {@see Locality_Blocks}: every pickup handler adds itself here, and ONE
	 * integration publishes all of them, keyed by plugin and field — the same keys the Store API
	 * transport uses, so two active carriers cannot answer for one another. The picker itself is the
	 * storefront map session the classic checkout uses (`pickup-session.js`); the handler that owns a
	 * field enqueues it, with the field's config, from its own
	 * {@see Pickup_Handler::enqueue_assets()}.
	 *
	 * @since 2.0.2
	 */
	final class Pickup_Blocks {

		/** WooCommerce integration name; the data key WooCommerce publishes is `{name}_data`. */
		public const INTEGRATION_NAME = 'woodev-shipping-pickup';

		/** The forced inner block that carries the button under the shipping methods. */
		public const BLOCK = 'woodev/shipping-pickup';

		/** @var bool whether this request already wired the hooks */
		private static bool $registered = false;

		/** @var array<string, Pickup_Handler> active pickup handlers, keyed by `plugin|field` */
		private static array $handlers = [];

		/**
		 * Adds a carrier's pickup field; the hooks are wired once per request.
		 *
		 * @since 2.0.2
		 *
		 * @param Pickup_Handler $handler   The carrier's handler.
		 * @param string         $plugin_id Owning plugin identity (the Store API transport key).
		 * @param string         $field_id  Checkout field identity (the Store API transport key).
		 *
		 * @return void
		 */
		public static function add_handler( Pickup_Handler $handler, string $plugin_id, string $field_id ): void {
			self::$handlers[ $plugin_id . '|' . $field_id ] = $handler;

			if ( self::$registered ) {
				return;
			}

			self::$registered = true;

			add_action( 'init', [ self::class, 'register_block_types' ] );
			add_action( 'woocommerce_blocks_checkout_block_registration', [ self::class, 'register_integration' ] );
		}

		/**
		 * Registers the server side of the forced inner block from its `block.json`.
		 *
		 * WooCommerce reads the SERVER-registered `parent` to attach a block's frontend component; the
		 * client registration alone is not enough. No render callback — the block is never saved into
		 * page content, it is forced in on the frontend.
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

			if ( class_exists( '\WP_Block_Type_Registry' ) && \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
				return;
			}

			register_block_type( __DIR__ . '/shipping-pickup' );
		}

		/**
		 * Adds the integration to the Checkout block's registry.
		 *
		 * Feature-detected (SP-11 D-5): without `IntegrationInterface` the store gets no button, and
		 * the server's pre-payment validation still refuses an order without a point.
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
			if ( [] === self::$handlers || ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) || ! is_callable( [ $registry, 'register' ] ) ) {
				return;
			}

			$registry->register( new Pickup_Blocks_Integration() );
		}

		/**
		 * What the bundle needs to find each active pickup field: the transport keys and the name of
		 * the JS global its picker config is localized under.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{pluginId: string, fieldId: string, configKey: string}>
		 */
		public static function field_descriptors(): array {
			$descriptors = [];

			foreach ( self::$handlers as $handler ) {
				$descriptors[] = $handler->blocks_descriptor();
			}

			return $descriptors;
		}

		/**
		 * The shopper-facing strings of the block itself.
		 *
		 * Storefront strings: the msgid is ENGLISH and the Russian arrives from the catalogue
		 * (AGENTS.md). The button's and the dialog's own labels are not here — they are the picker
		 * config's, the very strings the classic checkout shows.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string>
		 */
		public static function i18n_strings(): array {
			return [
				'required'       => __( 'Please choose a pickup point.', 'woodev-plugin-framework' ),
				// The hint under the button while the cart holds no resolved locality (#1110); the
				// same msgid as the server's refusal (`Store_Api_Pickup::locality_message()`).
				'chooseLocality' => __( 'Choose your locality from the suggestions to see pickup points.', 'woodev-plugin-framework' ),
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
			self::$handlers   = [];
		}
	}

endif;
