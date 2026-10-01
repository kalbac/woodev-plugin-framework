<?php
/**
 * Shipment cancellation marker
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Shipment_Cancellation' ) ) :

	/**
	 * The framework's OWN record that an order's shipment was cancelled with the carrier (#1037).
	 *
	 * The framework does not own any carrier's status vocabulary, so a successful cancellation
	 * cannot be written as a raw carrier status — a fake `CANCELLED` in a carrier's own meta would
	 * be a lie about that carrier's data. It records the fact in a meta of its own instead: the
	 * unix time of the cancellation. Every reader of the canonical delivery status — the orders
	 * page row, its status filter, the row actions, the order-edit metabox, the background cancel —
	 * reads the status through {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::resolve_delivery_status()},
	 * which resolves {@see Delivery_Status::CANCELLED} while this marker is present, whatever the
	 * carrier's raw status still says (the raw status and its label survive beside it).
	 *
	 * **Lifetime.** Written by {@see Abstract_Shipment_Handler::cancel()} after the carrier accepted
	 * the cancellation; deleted by {@see Abstract_Shipment_Handler::export()} when the order is
	 * exported again — a new shipment starts a new life. The framework records no time for the
	 * carrier's raw status, so there is nothing to compare the marker against: the rule is «the
	 * marker wins until it is cleared». A carrier plugin that re-activates a cancelled shipment
	 * through a path of its own calls {@see self::clear()}.
	 *
	 * Installed-site data contract (a meta key of the framework's own): keep byte-for-byte.
	 *
	 * @since 2.0.2
	 */
	final class Shipment_Cancellation {

		/**
		 * The order meta that says «the shipment of this order was cancelled with the carrier»: the
		 * unix time of the cancellation. Sits beside
		 * {@see Abstract_Shipment_Handler::EXPORT_UNKNOWN_META} and
		 * {@see Carrier_Cancel::FAILED_META}.
		 *
		 * @var string
		 */
		public const CANCELLED_AT_META = '_woodev_shipment_cancelled_at';

		/**
		 * When the order's shipment was cancelled with the carrier (unix time), or 0 when it was not.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to read.
		 * @return int
		 */
		public static function cancelled_at( \WC_Order $order ): int {
			// The same accessor every other reader of an order's carrier meta uses (HPOS or post meta).
			return max( 0, (int) \Woodev_Order_Compatibility::get_order_meta( $order, self::CANCELLED_AT_META ) );
		}

		/**
		 * Whether the order's shipment was cancelled with the carrier and not exported since.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to read.
		 * @return bool
		 */
		public static function is_cancelled( \WC_Order $order ): bool {
			return self::cancelled_at( $order ) > 0;
		}

		/**
		 * Records that the order's shipment was cancelled with the carrier, now.
		 *
		 * HPOS-safe: goes through the order's own meta API, like the other framework-owned order
		 * metas ({@see Abstract_Shipment_Handler::EXPORT_UNKNOWN_META}).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order whose shipment was cancelled.
		 * @return void
		 */
		public static function mark( \WC_Order $order ): void {
			$order->update_meta_data( self::CANCELLED_AT_META, time() );
			$order->save_meta_data();
		}

		/**
		 * Forgets the cancellation — the order has a live shipment again.
		 *
		 * A no-op, with no datastore write, when nothing was recorded: the happy path of an export
		 * pays nothing. The marker is deleted on `$fresh` (the order as the datastore holds it NOW —
		 * `delete_meta_data()` on a copy that never saw the meta is a no-op) and on the caller's
		 * `$order`, so a later save of that copy cannot bring it back.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $fresh the order as the datastore holds it NOW.
		 * @param \WC_Order $order the caller's copy, kept in step.
		 * @return void
		 */
		public static function clear( \WC_Order $fresh, \WC_Order $order ): void {
			self::forget( $fresh );

			if ( $fresh !== $order ) {
				self::forget( $order );
			}
		}

		/**
		 * Deletes the marker off one order object.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return void
		 */
		private static function forget( \WC_Order $order ): void {

			if ( '' === (string) $order->get_meta( self::CANCELLED_AT_META ) ) {
				return;
			}

			$order->delete_meta_data( self::CANCELLED_AT_META );
			$order->save_meta_data();
		}
	}

endif;
