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
	 * Converts WooCommerce order products and fees into carrier-ready lines.
	 */
	final class Carrier_Order_Lines {

		/**
		 * Builds product and fee lines from an order; shipping remains separate.
		 *
		 * Refunded quantities are removed before the line is built. Fractional remaining
		 * quantities are represented as one unit carrying the whole line total. When
		 * reconciliation is enabled, a line remainder is spread across units whose prices
		 * differ by at most one minor unit.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order           Order being exported.
		 * @param bool      $inc_tax         Whether to use tax-inclusive item prices.
		 * @param bool      $reconcile_total Whether to reconcile to the remaining order total.
		 * @param bool      $apportion_fees  Whether to fold fees into product prices instead of returning fee lines.
		 * @return Carrier_Order_Line[]
		 */
		public static function from_order( \WC_Order $order, bool $inc_tax = false, bool $reconcile_total = false, bool $apportion_fees = false ): array {
			$lines     = [];
			$fee_lines = [];
			$decimals  = wc_get_price_decimals();

			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$quantity = (float) $item->get_quantity();

				if ( $quantity <= 0 ) {
					continue;
				}

				$quantity -= abs( (float) $order->get_qty_refunded_for_item( $item_id ) );

				if ( $quantity <= 0 ) {
					continue;
				}

				$unit_price    = self::to_minor_units( (float) $order->get_item_total( $item, $inc_tax ), $decimals );
				$line_total    = self::to_minor_units( $unit_price / ( 10 ** $decimals ) * $quantity, $decimals );
				$line_quantity = floor( $quantity ) === $quantity ? (int) $quantity : 1;

				if ( 1 === $line_quantity && floor( $quantity ) !== $quantity ) {
					$unit_price = $line_total;
				}
				$product    = $item->get_product();
				$tax_info   = [
					'tax_class'    => $item->get_tax_class(),
					'tax_status'   => $product ? $product->get_tax_status() : '',
					'taxes'        => $item->get_taxes(),
					'includes_tax' => $inc_tax,
				];

				$lines[] = new Carrier_Order_Line(
					(string) $item->get_name(),
					$product ? (string) $product->get_sku() : '',
					$line_quantity,
					$unit_price,
					$line_total,
					$tax_info
				);
			}

			if ( ! $apportion_fees ) {
				foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
					if ( ! $item instanceof \WC_Order_Item_Fee ) {
						continue;
					}

					$fee_refunded = (float) $order->get_total_refunded_for_item( $item_id, 'fee' );
					$fee_total    = (float) $item->get_total() - $fee_refunded;

					if ( $inc_tax ) {
						$fee_refunded_tax = 0;

						foreach ( array_keys( $item->get_taxes()['total'] ?? [] ) as $tax_id ) {
							$fee_refunded_tax += (float) $order->get_tax_refunded_for_item( $item_id, $tax_id, 'fee' );
						}

						$fee_total       += (float) $item->get_total_tax() - $fee_refunded_tax;
					}

					$fee_minor = self::to_minor_units( $fee_total, $decimals );
					$fee_lines[]   = new Carrier_Order_Line(
						(string) $item->get_name(),
						'',
						1,
						$fee_minor,
						$fee_minor,
						[
							'tax_class'    => $item->get_tax_class(),
							'taxes'        => $item->get_taxes(),
							'includes_tax' => $inc_tax,
						]
					);
				}
			}

			if ( $reconcile_total ) {
				$target = self::order_lines_target( $order, $inc_tax, $decimals );

				if ( ! $apportion_fees ) {
					$target -= self::fee_lines_total( $fee_lines );
				}

				$lines = array_merge( self::reconcile( $lines, $target ), $apportion_fees ? [] : $fee_lines );
			} else {
				$lines = array_merge( $lines, $fee_lines );
			}

			return $lines;
		}

		/**
		 * Reconciles line totals to a target, distributing minor units by largest remainder.
		 *
		 * Invalid inputs and impossible targets throw UnexpectedValueException so callers
		 * cannot mistake an unreconciled result for a successful one. Split quantities retain
		 * line metadata and keep all unit prices integral in minor currency units.
		 *
		 * @since 2.0.2
		 *
		 * @param Carrier_Order_Line[] $lines        Lines to reconcile.
		 * @param int                  $target_minor Target line total in minor units.
		 * @return Carrier_Order_Line[]
		 * @throws \UnexpectedValueException When values or target cannot be reconciled.
		 */
		public static function reconcile( array $lines, int $target_minor ): array {
			$lines = array_values( $lines );

			if ( $target_minor < 0 ) {
				throw new \UnexpectedValueException( 'Cannot reconcile carrier lines to a negative target.' );
			}

			if ( [] === $lines ) {
				if ( 0 !== $target_minor ) {
					throw new \UnexpectedValueException( 'Cannot reconcile a positive target without priced carrier lines.' );
				}

				return [];
			}

			$source_total = 0;
			$unit_total   = 0;

			foreach ( $lines as $line ) {
				if ( $line->get_quantity() <= 0 || $line->get_unit_price_minor() < 0 || $line->get_total_minor() < 0 ) {
					throw new \UnexpectedValueException( 'Cannot reconcile negative carrier line values.' );
				}

				$source_total += $line->get_total_minor();
				$unit_total   += $line->get_unit_price_minor() * $line->get_quantity();
			}

			if ( $unit_total === $target_minor && $source_total === $target_minor ) {
				return $lines;
			}

			if ( 0 === $source_total ) {
				if ( 0 !== $target_minor ) {
					throw new \UnexpectedValueException( 'Cannot reconcile a positive target from zero-value carrier lines.' );
				}

				return $lines;
			}

			$allocations = [];
			$remainders  = [];
			$allocated   = 0;

			foreach ( $lines as $index => $line ) {
				$numerator              = $line->get_total_minor() * $target_minor;
				$allocations[ $index ] = intdiv( $numerator, $source_total );
				$remainders[ $index ]  = $numerator % $source_total;
				$allocated            += $allocations[ $index ];
			}

			$indices = array_values( array_keys( $lines ) );
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

				$higher_quantity = $remainder;
				$lower_quantity  = $quantity - $remainder;

				if ( $lower_quantity > 0 ) {
					$result[] = $line->with_pricing( $lower_quantity, $unit_price, $unit_price * $lower_quantity );
				}

				if ( $higher_quantity > 0 ) {
					$result[] = $line->with_pricing( $higher_quantity, $unit_price + 1, ( $unit_price + 1 ) * $higher_quantity );
				}
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
		 * Gets the remaining non-shipping target from the order, in the selected tax mode.
		 *
		 * The overall refund amount is subtracted once. Refunded quantities already reduce
		 * product source lines; reconciliation therefore corrects only their monetary drift.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order    WooCommerce order.
		 * @param bool      $inc_tax  Whether target prices include tax.
		 * @param int       $decimals Store price decimal count.
		 * @return int
		 */
		private static function order_lines_target( \WC_Order $order, bool $inc_tax, int $decimals ): int {
			$total                   = (float) $order->get_total() - (float) $order->get_total_refunded();
			$shipping_total          = (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded();
			$shipping_tax_refunded = self::shipping_tax_refunded( $order );
			$shipping_tax            = (float) $order->get_shipping_tax() - $shipping_tax_refunded;
			$remaining_tax            = (float) $order->get_total_tax() - (float) $order->get_total_tax_refunded();

			if ( $inc_tax ) {
				$shipping_total += $shipping_tax;
			} else {
				$total -= $remaining_tax;
			}

			return self::to_minor_units( $total - $shipping_total, $decimals );
		}

		/**
		 * Sums generated fee lines.
		 *
		 * @since 2.0.2
		 * @param Carrier_Order_Line[] $lines Carrier lines.
		 * @return int
		 */
		private static function fee_lines_total( array $lines ): int {
			$total = 0;

			foreach ( $lines as $line ) {
				$total += $line->get_total_minor();
			}

			return $total;
		}

		/**
		 * Gets refunded shipping tax across WooCommerce versions.
		 *
		 * @since 2.0.2
		 * @param \WC_Order $order Order.
		 * @return float
		 */
		private static function shipping_tax_refunded( \WC_Order $order ): float {
			if ( method_exists( $order, 'get_total_shipping_tax_refunded' ) ) {
				return (float) $order->get_total_shipping_tax_refunded();
			}

			if ( ! method_exists( $order, 'get_refunds' ) ) {
				return 0;
			}

			$refunded = 0;

			foreach ( $order->get_refunds() as $refund ) {
				foreach ( $refund->get_items( 'tax' ) as $tax_item ) {
					if ( ! $tax_item instanceof \WC_Order_Item_Tax ) {
						continue;
					}

					$refunded += abs( (float) $tax_item->get_shipping_tax_total() );
				}
			}

			return $refunded;
		}
	}

endif;
