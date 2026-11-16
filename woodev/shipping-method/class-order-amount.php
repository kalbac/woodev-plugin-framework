<?php
/**
 * The order amount after the coupon discounts, carried on the shipping package (#1722).
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Order_Amount' ) ) :

	/**
	 * Puts «the cart subtotal less the coupon discounts» on every shipping package of a cart that has a coupon.
	 *
	 * WooCommerce keeps a package's rates in the customer's session under the package's hash and serves them while
	 * the hash is unchanged (`WC_Shipping::calculate_shipping_for_package()`), without calling the shipping method.
	 * A package carries the lines it ships, the whole-cart subtotal and the coupon CODES — not what the coupons
	 * took off. A change of quantity that moves the discount but keeps the subtotal and the codes (a coupon that
	 * excludes some of the products) would therefore keep serving the rate calculated for the OLD discount, and a
	 * threshold or an order-amount limit measured on the amount after the discounts would be answered from a stale
	 * rate. So the amount itself rides on the package, on `woocommerce_cart_shipping_packages`, which the classic
	 * cart and the block checkout's Store API both go through (the same shape as {@see Fee_Payments} and
	 * {@see License_Gate}).
	 *
	 * Nothing is added to a package without a coupon: there the amount is the subtotal the package already carries,
	 * and a shop without coupons keeps its package hashes exactly as they were.
	 *
	 * The amount is measured the way WooCommerce's own Free shipping measures its minimum: the displayed subtotal less
	 * the discount total, and less the tax on the discount while prices are shown with tax, rounded to the shop's
	 * price decimals.
	 *
	 * @since 2.1.9
	 */
	final class Order_Amount {

		/**
		 * The package key the amount after the discounts travels under.
		 */
		public const PACKAGE_KEY = 'woodev_order_amount';

		/**
		 * @var bool whether this request already wired the filter
		 */
		private static bool $registered = false;

		/**
		 * @var object|null a stand-in for `WC()->cart`. Tests only: `WC` is never defined in the unit-test process.
		 */
		private static ?object $cart = null;

		/**
		 * @var int|null the price decimals a test fixes. Tests only.
		 */
		private static ?int $decimals = null;

		/**
		 * Wires the filter once per request; later plugins are no-ops.
		 *
		 * @since 2.1.9
		 *
		 * @return void
		 */
		public static function register(): void {

			if ( self::$registered ) {
				return;
			}

			self::$registered = true;

			add_filter( 'woocommerce_cart_shipping_packages', [ self::class, 'add_amount_to_packages' ] );
		}

		/**
		 * Forgets the per-request wiring and the test stand-ins. Tests only.
		 *
		 * @internal
		 *
		 * @since 2.1.9
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$registered = false;
			self::$cart       = null;
			self::$decimals   = null;
		}

		/**
		 * Answers the cart and the price decimals from stand-ins instead of WooCommerce. Tests only.
		 *
		 * @internal
		 *
		 * @since 2.1.9
		 *
		 * @param object|null $cart     the stand-in for `WC()->cart`, `null` to read WooCommerce again.
		 * @param int|null    $decimals the price decimals, `null` to read WooCommerce again.
		 * @return void
		 */
		public static function use_runtime_for_tests( ?object $cart, ?int $decimals = null ): void {
			self::$cart     = $cart;
			self::$decimals = $decimals;
		}

		/**
		 * Adds {@see self::amount_after_discounts()} to every shipping package that carries a coupon.
		 *
		 * @internal Hooked on `woocommerce_cart_shipping_packages`; not for direct calls.
		 *
		 * @since 2.1.9
		 *
		 * @param mixed $packages the cart's shipping packages.
		 * @return mixed
		 */
		public static function add_amount_to_packages( $packages ) {

			if ( ! is_array( $packages ) || [] === $packages ) {
				return $packages;
			}

			foreach ( $packages as $key => $package ) {

				if ( ! is_array( $package ) ) {
					continue;
				}

				$amount = self::amount_after_discounts( $package );

				if ( null !== $amount ) {
					// a string: the same text on every request, so the package hash only moves with the amount
					$packages[ $key ][ self::PACKAGE_KEY ] = (string) $amount;
				}
			}

			return $packages;
		}

		/**
		 * The package's cart subtotal less what the applied coupons took off.
		 *
		 * @since 2.1.9
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return float|null `null` when the package has no coupon, no numeric subtotal, or there is no cart to ask.
		 */
		public static function amount_after_discounts( array $package ): ?float {

			$subtotal = $package['cart_subtotal'] ?? null;
			$cart     = self::cart();

			if ( empty( $package['applied_coupons'] ) || ! is_numeric( $subtotal ) || null === $cart ) {
				return null;
			}

			$discount = (float) $cart->get_discount_total();

			if ( $cart->display_prices_including_tax() ) {
				$discount += (float) $cart->get_discount_tax();
			}

			return self::normalize( (float) $subtotal - $discount );
		}

		/**
		 * An order amount at the shop's price decimals — the one place amounts are rounded, so a sum that picked up float
		 * noise (`119.80000000000001`) is compared with a threshold or a limit as the money it is.
		 *
		 * @since 2.1.9
		 *
		 * @param float $amount the amount.
		 * @return float
		 */
		public static function normalize( float $amount ): float {
			return round( $amount, self::decimals() );
		}

		/**
		 * @return object|null the cart, `null` outside a cart request.
		 */
		private static function cart(): ?object {

			if ( null !== self::$cart ) {
				return self::$cart;
			}

			$woo  = function_exists( 'WC' ) ? WC() : null;
			$cart = is_object( $woo ) && isset( $woo->cart ) ? $woo->cart : null;

			return is_object( $cart ) ? $cart : null;
		}

		/**
		 * @return int the shop's price decimals.
		 */
		private static function decimals(): int {

			if ( null !== self::$decimals ) {
				return self::$decimals;
			}

			return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		}
	}

endif;
