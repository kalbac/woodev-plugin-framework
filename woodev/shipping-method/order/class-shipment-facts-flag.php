<?php
/**
 * The «attention» row flag of shipment facts.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Shipment_Facts_Flag' ) ) :
	/**
	 * Puts ONE «attention» badge on an order's row for what {@see Shipment_Facts_Events} found — never one per event,
	 * because a row carries at most {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Row_Flags::MAX_FLAGS} badges
	 * and a carrier's own flags (a courier call, say) compete for the same slots.
	 *
	 * Which badge, in order:
	 *
	 * 1. «Проблема доставки» (tone `error`) while a delivery issue stands — it is settled once the canonical delivery
	 *    status is one the CARRIER declares final ({@see Shipment_Facts_Events::get_final_states()}: delivered /
	 *    returned / cancelled by default, a carrier adds `failed` if its failure is terminal).
	 * 2. «Стоимость изменена перевозчиком» (tone `warn`) after a cost change. It stays: a changed price still wants
	 *    the merchant's review, even after the shipment is delivered.
	 *
	 * A date or courier change asks for no attention. The filter callback runs for EVERY row of the orders page, so
	 * it reads order meta only and never calls a carrier. A carrier that never hands in facts has no attention meta,
	 * and nothing happens.
	 *
	 * @since 2.0.2
	 */
	final class Shipment_Facts_Flag {
		/**
		 * Hooks the badge. Idempotent: WordPress de-duplicates the same static callback.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public static function register(): void {
			add_filter( 'woodev_shipping_order_row_flags', [ self::class, 'add_flags' ], 20, 3 );
		}

		/**
		 * Adds the «attention» badge to a row's flags.
		 *
		 * @since 2.0.2
		 * @param mixed $flags    Flags built so far.
		 * @param mixed $order    The order.
		 * @param mixed $provider The matched carrier.
		 * @return mixed
		 */
		public static function add_flags( $flags, $order, $provider ) {
			if ( ! is_array( $flags ) || ! $order instanceof \WC_Order || ! $provider instanceof Orders_Provider ) {
				return $flags;
			}

			$flag = self::build( $order, $provider );

			if ( null !== $flag ) {
				$flags[] = $flag;
			}

			return $flags;
		}

		/**
		 * The badge for one order, or null when nothing needs attention. Meta only.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order    Order.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @return array{label:string,tone:string,title:string}|null
		 */
		public static function build( \WC_Order $order, Orders_Provider $provider ): ?array {
			$attention = Shipment_Facts_Events::get_attention( $order, $provider );

			if ( [] === $attention ) {
				return null;
			}

			// A final status settles a delivery issue; a changed price still wants the merchant's review.
			if ( isset( $attention['issue'] ) && is_array( $attention['issue'] ) && ! self::is_final( $order, $provider ) ) {
				return [
					'label' => __( 'Проблема доставки', 'woodev-plugin-framework' ),
					'tone'  => 'error',
					'title' => Shipment_Facts_Events::issue_label( (string) ( $attention['issue']['code'] ?? '' ), (string) ( $attention['issue']['label'] ?? '' ) ),
				];
			}

			if ( isset( $attention['cost'] ) && is_array( $attention['cost'] ) ) {
				return [
					'label' => __( 'Стоимость изменена перевозчиком', 'woodev-plugin-framework' ),
					'tone'  => 'warn',
					'title' => self::cost_title( $attention['cost'] ),
				];
			}

			return null;
		}

		/**
		 * Whether the order's canonical delivery status is final.
		 *
		 * @param \WC_Order       $order    Order.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @return bool
		 */
		private static function is_final( \WC_Order $order, Orders_Provider $provider ): bool {
			return in_array( Order_Actions::resolve_canonical_status( $order, $provider ), Shipment_Facts_Events::get_final_states( $provider ), true );
		}

		/**
		 * The tooltip of the cost badge: which figure moved and from what to what.
		 *
		 * @param array<string,mixed> $cost The attention's cost entry.
		 * @return string «стоимость доставки: 465 → 520 ₽»; «стоимость доставки: 520 ₽» when the carrier had reported none.
		 */
		private static function cost_title( array $cost ): string {
			$currency = Shipment_Facts_Events::format_currency( (string) ( $cost['currency'] ?? Shipment_Facts::DEFAULT_CURRENCY ) );
			$to       = Shipment_Facts_Events::format_money( (float) ( $cost['to'] ?? 0 ) );
			$figures  = isset( $cost['from'] ) && is_numeric( $cost['from'] )
				? Shipment_Facts_Events::format_money( (float) $cost['from'] ) . ' → ' . $to . ' ' . $currency
				: $to . ' ' . $currency;

			return Shipment_Facts_Events::cost_label( (string) ( $cost['component'] ?? Shipment_Facts::COST_DELIVERY ) ) . ': ' . $figures;
		}
	}
endif;
