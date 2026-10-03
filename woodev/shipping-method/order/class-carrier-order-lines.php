<?php
/**
 * Builds carrier order lines from WooCommerce orders.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Carrier_Order_Lines' ) ) :

	/**
	 * Converts WooCommerce products into carrier-ready lines and reconciles totals.
	 */
	final class Carrier_Order_Lines {

		/**
		 * Builds product lines from an order; fees and shipping remain separate.
		 *
		 * Refunded quantities are removed before the line is built. When reconciliation is
		 * enabled, any indivisible per-unit remainder is assigned to one unit by splitting
		 * the line into a quantity n-1 line and a quantity 1 line.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order           Order being exported.
		 * @param bool      $inc_tax         Whether to use tax-inclusive item prices.
		 * @param bool      $reconcile_total Whether to reconcile to the order total less shipping.
		 * @return Carrier_Order_Line[]
		 */
		public static function from_order( \WC_Order $order, bool $inc_tax = false, bool $reconcile_total = false ): array {
			$lines    = [];
			$decimals = wc_get_price_decimals();

			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$quantity = (int) $item->get_quantity();

				if ( $quantity <= 0 ) {
					continue;
				}

				$quantity -= abs( (int) $order->get_qty_refunded_for_item( $item_id ) );

				if ( $quantity <= 0 ) {
					continue;
				}

				$unit_price = self::to_minor_units( (float) $order->get_item_total( $item, $inc_tax ), $decimals );
				$product    = $item->get_product();
				$tax_info   = [
					'tax_class'   => $item->get_tax_class(),
					'tax_status'  => $product ? $product->get_tax_status() : '',
					'taxes'       => $item->get_taxes(),
					'includes_tax' => $inc_tax,
				];

				$lines[] = new Carrier_Order_Line(
					(string) $item->get_name(),
					$product ? (string) $product->get_sku() : '',
					$quantity,
					$unit_price,
					$unit_price * $quantity,
					$tax_info
				);
			}

			if ( $reconcile_total ) {
				$lines = self::reconcile( $lines, self::order_lines_target( $order, $inc_tax, $decimals ) );
			}

			return $lines;
		}

		/**
		 * Reconciles line totals to a target, distributing minor units by largest remainder.
		 *
		 * Negative amounts, a negative target, or a positive target with no priced lines are
		 * rejected as a no-op. Split quantities retain line metadata and keep all unit prices
		 * integral in minor currency units.
		 *
		 * @since 2.0.2
		 *
		 * @param Carrier_Order_Line[] $lines        Lines to reconcile.
		 * @param int                  $target_minor Target line total in minor units.
		 * @return Carrier_Order_Line[]
		 */
		public static function reconcile( array $lines, int $target_minor ): array {
			if ( $target_minor < 0 || [] === $lines ) {
				return $lines;
			}

			$source_total = 0;
			$unit_total   = 0;

			foreach ( $lines as $line ) {
				if ( $line->get_quantity() <= 0 || $line->get_unit_price_minor() < 0 || $line->get_total_minor() < 0 ) {
					return $lines;
				}

				$source_total += $line->get_total_minor();
				$unit_total   += $line->get_unit_price_minor() * $line->get_quantity();
			}

			if ( $unit_total === $target_minor ) {
				return $lines;
			}

			if ( 0 === $source_total ) {
				return $lines;
			}

			$allocations = [];
			$remainders  = [];
			$allocated   = 0;

			foreach ( $lines as $index => $line ) {
				$numerator              = $line->get_total_minor() * $target_minor;
				$allocations[ $index ]   = intdiv( $numerator, $source_total );
				$remainders[ $index ]    = $numerator % $source_total;
				$allocated              += $allocations[ $index ];
			}

			$indices = array_keys( $lines );
			usort(
				$indices,
				static function ( int $left, int $right ) use ( $remainders ): int {
					return $remainders[ $right ] <=> $remainders[ $left ] ?: $left <=> $right;
				}
			);

			for ( $i = 0; $i < $target_minor - $allocated; $i++ ) {
				++$allocations[ $indices[ $i % count( $indices ) ] ];
			}

			$result = [];

			foreach ( $lines as $index => $line ) {
				$line_total = $allocations[ $index ];
				$quantity   = $line->get_quantity();
				$unit_price = intdiv( $line_total, $quantity );
				$remainder  = $line_total % $quantity;

				if ( 0 === $remainder ) {
					$result[] = $line->with_pricing( $quantity, $unit_price, $line_total );
					continue;
				}

				if ( $quantity > 1 ) {
					$result[] = $line->with_pricing( $quantity - 1, $unit_price, $unit_price * ( $quantity - 1 ) );
				}

				$result[] = $line->with_pricing( 1, $unit_price + $remainder, $unit_price + $remainder );
			}

			return $result;
		}

		/**
		 * Converts a decimal amount into the store's minor currency units using half-up rounding.
		 *
		 * @since 2.0.2
		 *
		 * @param float $amount   Decimal amount.
		 * @param int   $decimals WooCommerce price decimal count.
		 * @return int
		 */
		public static function to_minor_units( float $amount, int $decimals ): int {
			return (int) round( $amount * ( 10 ** $decimals ), 0, PHP_ROUND_HALF_UP );
		}

		/**
		 * Gets the product-line target from the order total, keeping shipping separate.
		 *
		 * Fees remain excluded from the generated lines by default; when reconciliation is
		 * requested their value is part of the order-total target and is apportioned to products.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order    WooCommerce order.
		 * @param bool      $inc_tax  Whether target prices include tax.
		 * @param int       $decimals Store price decimal count.
		 * @return int
		 */
		private static function order_lines_target( \WC_Order $order, bool $inc_tax, int $decimals ): int {
			$total          = (float) $order->get_total();
			$shipping_total = (float) $order->get_shipping_total();

			if ( $inc_tax ) {
				$shipping_total += (float) $order->get_shipping_tax();
			} else {
				$total -= (float) $order->get_total_tax();
			}

			return self::to_minor_units( $total - $shipping_total, $decimals );
		}
	}

endif;
