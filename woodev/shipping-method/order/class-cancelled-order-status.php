<?php
/**
 * Moves an order to the status the merchant chose once the carrier cancelled its shipment.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Cancelled_Order_Status' ) ) :
	/**
	 * Sets the carrier's «Статус отменённого заказа»
	 * ({@see \Woodev\Framework\Shipping\Settings\Export_Settings::get_cancelled_status()}) on an order the
	 * moment its canonical delivery status becomes «cancelled» (#1203) — the sibling of
	 * {@see Delivered_Order_Status}, listening to the same once-per-real-change event
	 * `woodev_shipping_delivery_status_changed` ({@see Delivery_Status_Events}), with no carrier code.
	 *
	 * The rule, in order:
	 *
	 * 1. Only the canonical state «cancelled» counts; «Не менять» (or a status WooCommerce no longer has) does nothing.
	 * 2. Only a cancellation the CARRIER reported. The framework's own cancellation of a shipment
	 *    ({@see Shipment_Cancellation}, the «Отменить» button or a cancelled order) also publishes
	 *    «cancelled», but it is the merchant's act: cancelling a shipment by hand must not cancel the order.
	 * 3. An order already in the chosen status is left alone.
	 * 4. NEVER touches an order that is cancelled, completed, refunded, failed or trashed
	 *    ({@see self::PROTECTED_STATUSES}): the carrier cancelling a shipment of an order the merchant has
	 *    already settled must not reopen or rewrite it.
	 *
	 * Unlike {@see Delivered_Order_Status} it keeps no «applied» marker: a shipment that is exported again
	 * and cancelled again is a new real change and the order should follow it; the event's own once-per-change
	 * baseline already stops a re-sync from repeating it.
	 *
	 * **No cancel loop.** A shop-side cancel makes {@see Order_Automation} cancel the shipment at the carrier. The
	 * status change made here is the carrier's news, not the merchant's decision, so while it is applied
	 * {@see self::is_applying()} is true for THAT order and {@see Order_Automation::handle_status_change()} stands
	 * aside for it — nothing is queued for the carrier. The guard is per order (and nests): another order cancelled
	 * inside the same status change still has its shipment cancelled.
	 *
	 * @since 2.0.2
	 */
	final class Cancelled_Order_Status {
		/**
		 * Order statuses (without `wc-`) a carrier-cancelled notice never overrides.
		 *
		 * @var string[]
		 */
		public const PROTECTED_STATUSES = [ 'cancelled', 'completed', 'refunded', 'failed', 'trash' ];

		/**
		 * The orders this class is moving to the chosen status right now, by order id.
		 *
		 * Scoped per order on purpose: a third-party callback that cancels ANOTHER order synchronously
		 * inside the same status change is the merchant's side of the shop, and its shipment must still be
		 * cancelled at the carrier.
		 *
		 * @var array<int,true>
		 */
		private static array $applying = [];

		/**
		 * Starts listening. Idempotent: WordPress de-duplicates the same static callback.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public static function register(): void {
			add_action( 'woodev_shipping_delivery_status_changed', [ self::class, 'apply' ], 20, 4 );
		}

		/**
		 * Whether this class is moving THIS order to the chosen status right now — the status change in progress
		 * is the one it makes for a carrier-cancelled shipment. {@see Order_Automation::handle_status_change()}
		 * asks it so that change is not sent back to the carrier. Any other order is not affected.
		 *
		 * @since 2.0.2
		 * @param int $order_id The order.
		 * @return bool
		 */
		public static function is_applying( int $order_id ): bool {
			return isset( self::$applying[ $order_id ] );
		}

		/**
		 * Applies the cancelled status to an order whose canonical state just changed.
		 *
		 * @since 2.0.2
		 * @param mixed $order    Shipment order.
		 * @param mixed $previous Previous canonical state.
		 * @param mixed $current  Current canonical state.
		 * @param mixed $provider Matched carrier.
		 * @return void
		 */
		public static function apply( $order, $previous, $current, $provider ): void {
			if ( Delivery_Status::CANCELLED !== $current || ! $order instanceof \WC_Order || ! $provider instanceof Orders_Provider ) {
				return;
			}

			if ( Shipment_Cancellation::is_cancelled( $order ) ) {
				return;
			}

			$plugin = Orders_Registry::instance()->get_provider_plugin( $provider->get_id() );
			$target = null === $plugin ? null : $plugin->get_export_settings()->get_cancelled_status();

			if ( null === $target || ! wc_is_order_status( 'wc-' . $target ) ) {
				return;
			}

			$status = $order->get_status();

			if ( $status === $target || in_array( $status, self::PROTECTED_STATUSES, true ) ) {
				return;
			}

			// Remember whether an outer call already guards this very order, so a nested call restores what it found.
			$order_id = (int) $order->get_id();
			$was      = self::is_applying( $order_id );

			self::$applying[ $order_id ] = true;

			try {
				$order->update_status(
					$target,
					__( 'Перевозчик сообщил, что отправление отменено.', 'woodev-plugin-framework' )
				);
			} finally {
				if ( ! $was ) {
					unset( self::$applying[ $order_id ] );
				}
			}
		}
	}
endif;
