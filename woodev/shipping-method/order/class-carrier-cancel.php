<?php
/**
 * Woodev Carrier Cancel
 *
 * The background cancellation of a shipment at the carrier when its WooCommerce order is cancelled or
 * fully refunded (#1007, spec §12 A3): the WooCommerce Action Scheduler action that carries it out, the
 * «the carrier was not told» marker the orders page shows, and the short put-back of a cancellation that
 * met a busy order.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Carrier_Cancel' ) ) :

	/**
	 * Schedules the cancellation of an order's shipment and keeps the marker of a failed one.
	 *
	 * The sibling of {@see Export_Retry}: same Action Scheduler group, same shape — one waiting action
	 * per order, the order id as its only argument, and the framework (not the carrier plugin) owning the
	 * hook that runs it
	 * ({@see \Woodev\Framework\Shipping\Order\Order_Automation::run_cancel()}). There is no retry
	 * schedule: a cancellation the carrier refuses is reported on the order and on the orders page for the
	 * merchant to settle with the carrier, never repeated on its own.
	 *
	 * Installed-site data contracts (keep byte-for-byte): {@see self::HOOK}, {@see self::FAILED_META},
	 * {@see self::DEFERRALS_META} — a scheduled action outlives the release that scheduled it.
	 *
	 * @since 2.0.2
	 */
	final class Carrier_Cancel {

		/** @var string the Action Scheduler hook that performs one cancellation; its one argument is the order id */
		public const HOOK = 'woodev_shipping_cancel_at_carrier';

		/**
		 * The order meta that says «this order is cancelled in WooCommerce, but the carrier still has its
		 * shipment because the cancellation failed». Its value is the carrier order id that could not be
		 * cancelled, so the marker is read only while that very id is still stored
		 * ({@see self::has_failed()}): a shipment that was cancelled later by hand, or an order exported
		 * again, carries a different (or no) id and the stale marker is ignored without anyone clearing it.
		 *
		 * @var string
		 */
		public const FAILED_META = '_woodev_shipment_cancel_failed';

		/**
		 * The order meta counting how many times a due cancellation was put back because the order was
		 * busy (an export holding the per-order lock). Cleared when the cancellation is carried out.
		 *
		 * @var string
		 */
		public const DEFERRALS_META = '_woodev_shipment_cancel_deferrals';

		/** @var int how many times one cancellation may be put back before the busy order is reported as a failure */
		public const MAX_DEFERRALS = 12;

		/** @var int seconds a cancellation that met a busy order waits before it tries again */
		private const DEFER_DELAY = MINUTE_IN_SECONDS;

		/** @var string the Action Scheduler status of an action that is waiting its turn (`ActionScheduler_Store::STATUS_PENDING`) */
		private const PENDING_STATUS = 'pending';

		/**
		 * Schedules one cancellation of an order, `$delay` seconds from now.
		 *
		 * Idempotent per order: a cancellation already WAITING for this order is not doubled.
		 *
		 * @since 2.0.2
		 *
		 * @param int $order_id the order whose shipment to cancel.
		 * @param int $delay    seconds from now; 0 runs it on the next queue pass.
		 * @return bool whether a cancellation is now waiting for the order.
		 */
		public static function enqueue( int $order_id, int $delay = 0 ): bool {

			if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
				return false; // WooCommerce ships Action Scheduler, so this is a site that broke it.
			}

			$waiting = as_get_scheduled_actions(
				[
					'hook'     => self::HOOK,
					'args'     => [ $order_id ],
					'group'    => Export_Retry::GROUP,
					'status'   => self::PENDING_STATUS,
					'per_page' => 1,
				],
				'ids'
			);

			if ( [] !== (array) $waiting ) {
				return true;
			}

			return (int) as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, [ $order_id ], Export_Retry::GROUP ) > 0;
		}

		/**
		 * Puts a due cancellation back in the queue because the order was busy — an export is running.
		 *
		 * The running export may be about to store the carrier id of a shipment that must then be
		 * cancelled, so the answer «busy» is «look again shortly», not «nothing to cancel». At most
		 * {@see self::MAX_DEFERRALS} times, then the caller reports the order as not cancelled.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $fresh the order as the datastore holds it NOW; the counter is written here.
		 * @return bool whether the cancellation is waiting again.
		 */
		public static function defer( \WC_Order $fresh ): bool {

			$deferrals = max( 0, (int) $fresh->get_meta( self::DEFERRALS_META ) );

			if ( $deferrals >= self::MAX_DEFERRALS || ! self::enqueue( $fresh->get_id(), self::DEFER_DELAY ) ) {
				return false;
			}

			$fresh->update_meta_data( self::DEFERRALS_META, $deferrals + 1 );
			$fresh->save_meta_data();

			return true;
		}

		/**
		 * Clears the deferral counter: the cancellation ran (or was given up).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return void
		 */
		public static function forget_deferrals( \WC_Order $order ): void {

			if ( '' === (string) $order->get_meta( self::DEFERRALS_META ) ) {
				return;
			}

			$order->delete_meta_data( self::DEFERRALS_META );
			$order->save_meta_data();
		}

		/**
		 * Records that the carrier's shipment could not be cancelled.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order            the order.
		 * @param string    $carrier_order_id the carrier order id that is still alive at the carrier.
		 * @return void
		 */
		public static function mark_failed( \WC_Order $order, string $carrier_order_id ): void {
			$order->update_meta_data( self::FAILED_META, $carrier_order_id );
			$order->save_meta_data();
		}

		/**
		 * Clears the failed-cancellation marker.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return void
		 */
		public static function clear_failed( \WC_Order $order ): void {

			if ( '' === (string) $order->get_meta( self::FAILED_META ) ) {
				return;
			}

			$order->delete_meta_data( self::FAILED_META );
			$order->save_meta_data();
		}

		/**
		 * Whether the order's CURRENT carrier shipment is the one a cancellation failed for.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order                   the order.
		 * @param string    $current_carrier_order_id the carrier order id the order stores now; '' when it has none.
		 * @return bool
		 */
		public static function has_failed( \WC_Order $order, string $current_carrier_order_id ): bool {

			if ( '' === $current_carrier_order_id ) {
				return false;
			}

			// Read the way the row reads the carrier id itself, so both come from the same datastore path.
			return $current_carrier_order_id === (string) \Woodev_Order_Compatibility::get_order_meta( $order, self::FAILED_META );
		}
	}

endif;
