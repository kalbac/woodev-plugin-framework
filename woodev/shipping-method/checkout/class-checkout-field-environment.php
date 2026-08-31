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

	class Checkout_Field_Environment {

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
		 * The store's shipping countries, code => name
		 * ({@see \WC_Countries::get_shipping_countries()}). Card #503's
		 * `phone_field_format` option needs the actual list (not just the count) to
		 * build one select option per country. Optional, defaulting to `[]`, so
		 * every existing 2-arg call site (this class predates card #503) keeps
		 * working unchanged — clean-break policy allows a signature change here,
		 * but there is no reason to force a mechanical edit on every call site
		 * that has no use for the new fact.
		 *
		 * @var array<string,string>
		 */
		public $shipping_countries;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param bool                 $block_checkout          whether the block checkout is in use.
		 * @param int                  $shipping_country_count  how many countries the store ships to.
		 * @param array<string,string> $shipping_countries      the store's shipping countries, code => name.
		 */
		public function __construct( bool $block_checkout, int $shipping_country_count, array $shipping_countries = [] ) {
			$this->block_checkout         = $block_checkout;
			$this->shipping_country_count = $shipping_country_count;
			$this->shipping_countries     = $shipping_countries;
		}

		/**
		 * Builds the environment from the live WordPress/WooCommerce runtime.
		 *
		 * Block-checkout detection reuses {@see \Woodev_Blocks_Handler::is_checkout_block_in_use()}
		 * (`woodev/handlers/blocks-handler.php`) rather than duplicating its
		 * `CartCheckoutUtils` probe — that method is `public static` (not instance-bound
		 * to a plugin object as the plan assumed), so it is directly reusable here.
		 *
		 * Card #147 audit: the `WC()->countries` guard below is not reachable as a
		 * live REST/GET degradation — see
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::wc_country_codes()}'s
		 * own docblock for the proof that `WC()->countries` is set unconditionally in
		 * `WooCommerce::init()`, independent of request type. It only fires when
		 * WooCommerce itself is inactive.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Also resolves `$shipping_countries` (card #503).
		 *
		 * @return self
		 */
		public static function from_wc(): self {
			$block     = \Woodev_Blocks_Handler::is_checkout_block_in_use();
			$countries = function_exists( 'WC' ) && WC()->countries
				? WC()->countries->get_shipping_countries()
				: [];

			return new self( $block, count( $countries ), $countries );
		}
	}

endif;
