<?php
/**
 * Woodev Checkout Field Environment
 *
 * The facts {@see Checkout_Field_Settings}'s availability rules (design §3.2) need to
 * know about the current store, resolved ONCE by the caller into a plain value object
 * so those rules stay unit-testable without a WordPress/WooCommerce runtime.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Field_Environment' ) ) :

	/**
	 * Immutable value object carrying the two facts the «Поля» availability rules
	 * gate on: whether the store serves the block checkout, and how many countries
	 * it ships to.
	 *
	 * PHP 7.4 target — no constructor property promotion, so the two properties are
	 * declared and assigned long-hand.
	 *
	 * @since 2.0.2
	 */
	final class Checkout_Field_Environment {

		/**
		 * Whether WooCommerce currently serves the checkout BLOCK (as opposed to the
		 * classic checkout shortcode) as the default checkout experience.
		 *
		 * @var bool
		 */
		public $block_checkout;

		/**
		 * How many countries the store currently ships to
		 * ({@see \WC_Countries::get_shipping_countries()}).
		 *
		 * @var int
		 */
		public $shipping_country_count;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param bool $block_checkout          whether the block checkout is in use.
		 * @param int  $shipping_country_count  how many countries the store ships to.
		 */
		public function __construct( bool $block_checkout, int $shipping_country_count ) {
			$this->block_checkout         = $block_checkout;
			$this->shipping_country_count = $shipping_country_count;
		}

		/**
		 * Builds the environment from the live WordPress/WooCommerce runtime.
		 *
		 * Block-checkout detection reuses {@see \Woodev_Blocks_Handler::is_checkout_block_in_use()}
		 * (`woodev/handlers/blocks-handler.php`) rather than duplicating its
		 * `CartCheckoutUtils` probe — that method is `public static` (not instance-bound
		 * to a plugin object as the plan assumed), so it is directly reusable here.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function from_wc(): self {
			$block = \Woodev_Blocks_Handler::is_checkout_block_in_use();
			$count = function_exists( 'WC' ) && WC()->countries
				? count( WC()->countries->get_shipping_countries() )
				: 0;

			return new self( $block, $count );
		}
	}

endif;
