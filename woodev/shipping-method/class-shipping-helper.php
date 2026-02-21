<?php
/**
 * Woodev Shipping Helper
 *
 * Utility methods for the shipping module.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Helper' ) ) :

	class Shipping_Helper {

		/**
		 * Converts weight to grams.
		 *
		 * @since 1.5.0
		 *
		 * @param float  $weight weight value
		 * @param string $from_unit source unit (g, kg, lbs, oz), defaults to WC store unit
		 * @return float weight in grams
		 */
		public static function convert_weight_to_grams( float $weight, string $from_unit = '' ): float {

			if ( '' === $from_unit ) {
				$from_unit = get_option( 'woocommerce_weight_unit', 'kg' );
			}

			switch ( strtolower( $from_unit ) ) {
				case 'kg':
					return $weight * 1000;
				case 'lbs':
					return $weight * 453.592;
				case 'oz':
					return $weight * 28.3495;
				case 'g':
				default:
					return $weight;
			}
		}

		/**
		 * Converts dimensions to centimeters.
		 *
		 * @since 1.5.0
		 *
		 * @param float  $dimension dimension value
		 * @param string $from_unit source unit (cm, m, mm, in, yd), defaults to WC store unit
		 * @return float dimension in centimeters
		 */
		public static function convert_dimension_to_cm( float $dimension, string $from_unit = '' ): float {

			if ( '' === $from_unit ) {
				$from_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
			}

			switch ( strtolower( $from_unit ) ) {
				case 'm':
					return $dimension * 100;
				case 'mm':
					return $dimension / 10;
				case 'in':
					return $dimension * 2.54;
				case 'yd':
					return $dimension * 91.44;
				case 'cm':
				default:
					return $dimension;
			}
		}

		/**
		 * Gets the total weight of a shipping package in grams.
		 *
		 * @since 1.5.0
		 *
		 * @param array $package WC shipping package
		 * @return float total weight in grams
		 */
		public static function get_package_weight( array $package ): float {

			$weight = 0;

			if ( ! empty( $package['contents'] ) ) {
				foreach ( $package['contents'] as $item ) {
					/** @var \WC_Product $product */
					$product = $item['data'];
					if ( $product && $product->get_weight() ) {
						$weight += (float) $product->get_weight() * $item['quantity'];
					}
				}
			}

			return self::convert_weight_to_grams( $weight );
		}

		/**
		 * Gets the total value of a shipping package.
		 *
		 * @since 1.5.0
		 *
		 * @param array $package WC shipping package
		 * @return float total package value
		 */
		public static function get_package_total( array $package ): float {

			$total = 0;

			if ( ! empty( $package['contents'] ) ) {
				foreach ( $package['contents'] as $item ) {
					$total += (float) $item['line_total'];
				}
			}

			return $total;
		}

		/**
		 * Gets the shipping destination city from the package.
		 *
		 * @since 1.5.0
		 *
		 * @param array $package WC shipping package
		 * @return string destination city
		 */
		public static function get_package_city( array $package ): string {
			return ! empty( $package['destination']['city'] ) ? $package['destination']['city'] : '';
		}

		/**
		 * Gets the shipping destination postcode from the package.
		 *
		 * @since 1.5.0
		 *
		 * @param array $package WC shipping package
		 * @return string destination postcode
		 */
		public static function get_package_postcode( array $package ): string {
			return ! empty( $package['destination']['postcode'] ) ? $package['destination']['postcode'] : '';
		}

		/**
		 * Gets the shipping destination country code from the package.
		 *
		 * @since 1.5.0
		 *
		 * @param array $package WC shipping package
		 * @return string destination country code
		 */
		public static function get_package_country( array $package ): string {
			return ! empty( $package['destination']['country'] ) ? $package['destination']['country'] : '';
		}

		/**
		 * Rounds the shipping cost based on the given mode.
		 *
		 * @since 1.5.0
		 *
		 * @param float  $cost shipping cost
		 * @param string $mode rounding mode: none|ceil|floor|round
		 * @param int    $precision rounding precision
		 * @return float rounded cost
		 */
		public static function round_cost( float $cost, string $mode = 'none', int $precision = 0 ): float {

			switch ( $mode ) {
				case 'ceil':
					$multiplier = pow( 10, $precision );
					return ceil( $cost * $multiplier ) / $multiplier;
				case 'floor':
					$multiplier = pow( 10, $precision );
					return floor( $cost * $multiplier ) / $multiplier;
				case 'round':
					return round( $cost, $precision );
				case 'none':
				default:
					return $cost;
			}
		}

		/**
		 * Applies a fee to the shipping cost.
		 *
		 * @since 1.5.0
		 *
		 * @param float  $cost base shipping cost
		 * @param string $fee fee value (e.g., '250' or '5%')
		 * @param float  $base_for_percent base amount for percentage calculation (order total or shipping cost)
		 * @return float cost with fee applied
		 */
		public static function apply_fee( float $cost, string $fee, float $base_for_percent = 0 ): float {

			$fee = trim( $fee );

			if ( '' === $fee || '0' === $fee ) {
				return $cost;
			}

			if ( \Woodev_Helper::str_ends_with( $fee, '%' ) ) {
				$percent = (float) rtrim( $fee, '%' );
				$base    = $base_for_percent > 0 ? $base_for_percent : $cost;
				return $cost + ( $base * $percent / 100 );
			}

			return $cost + (float) $fee;
		}

		/**
		 * Formats delivery time for display.
		 *
		 * @since 1.5.0
		 *
		 * @param int $min_days minimum delivery days
		 * @param int $max_days maximum delivery days (0 = same as min)
		 * @return string formatted delivery time string
		 */
		public static function format_delivery_time( int $min_days, int $max_days = 0 ): string {

			if ( $max_days <= 0 || $max_days === $min_days ) {
				/* translators: %d - number of days */
				return sprintf( _n( '%d day', '%d days', $min_days, 'woodev-plugin-framework' ), $min_days );
			}

			/* translators: %1$d - minimum days, %2$d - maximum days */
			return sprintf( __( '%1$d-%2$d days', 'woodev-plugin-framework' ), $min_days, $max_days );
		}

		/**
		 * Checks if a shipping method ID belongs to a given plugin.
		 *
		 * @since 1.5.0
		 *
		 * @param string $method_id shipping method ID (can include instance suffix like 'method_id:1')
		 * @param string $plugin_method_id base method ID to check against
		 * @return bool
		 */
		public static function is_method_of( string $method_id, string $plugin_method_id ): bool {

			$base_id = strstr( $method_id, ':', true );

			if ( false === $base_id ) {
				$base_id = $method_id;
			}

			return $base_id === $plugin_method_id;
		}

		/**
		 * Gets the WC_Order shipping method item for a given plugin.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order WC order
		 * @param string    $method_id method ID to find
		 * @return \WC_Order_Item_Shipping|null shipping item or null
		 */
		public static function get_order_shipping_item( \WC_Order $order, string $method_id ): ?\WC_Order_Item_Shipping {

			foreach ( $order->get_shipping_methods() as $item ) {
				/** @var \WC_Order_Item_Shipping $item */
				if ( self::is_method_of( $item->get_method_id(), $method_id ) ) {
					return $item;
				}
			}

			return null;
		}
	}

endif;
