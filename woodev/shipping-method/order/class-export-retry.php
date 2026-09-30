<?php
/**
 * Woodev Export Retry
 *
 * The delayed, capped retry of a shipment export (#954): the schedule (1 min, 5 min, 30 min, 2 h),
 * the 5-attempt cap, the attempt counter kept on the order, and the WooCommerce Action Scheduler
 * action that carries an attempt out.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Export_Retry' ) ) :

	/**
	 * Schedules export attempts with a delay, counts them, and stops at a cap.
	 *
	 * Replaces the plugin-supplied background-job queue the shipment handler used to retry
	 * through: that queue dispatches at once, so a carrier that was down was hammered in a
	 * tight chain «fail → re-queue → dispatch» with no pause and no end. An attempt is now an
	 * Action Scheduler action (WooCommerce always ships it) at a chosen time, and the framework
	 * — not the carrier plugin — owns the hook that runs it
	 * ({@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::run_export_retry()}), so it
	 * finds the order's shipment handler through the registry that already maps an order to its
	 * carrier.
	 *
	 * {@see self::enqueue()} is the reusable seam: anything that needs an order exported in the
	 * background later (the auto-export of #1007) schedules it through the same hook and group.
	 *
	 * Installed-site data contracts (keep byte-for-byte): {@see self::HOOK}, {@see self::GROUP},
	 * {@see self::ATTEMPTS_META}, {@see self::DEFERRALS_META} — a scheduled action outlives the release
	 * that scheduled it.
	 *
	 * A due attempt that meets a BUSY order (a native edit lock, a concurrent export) is put back,
	 * uncounted ({@see self::defer()}); the chain ends only for a terminal reason — exported, refused,
	 * cancelled, the carrier's plugin gone, or the cap.
	 *
	 * @since 2.0.2
	 */
	final class Export_Retry {

		/** @var string the Action Scheduler hook that performs one export attempt; its one argument is the order id */
		public const HOOK = 'woodev_shipping_export_retry';

		/** @var string the Action Scheduler group of the framework's shipping actions */
		public const GROUP = 'woodev-shipping';

		/**
		 * The order meta counting the export attempts that FAILED in a row: present from the first
		 * retryable failure until an export succeeds, a failure that is not retried ends the chain, or
		 * the cap is reached.
		 *
		 * @var string
		 */
		public const ATTEMPTS_META = '_woodev_shipment_export_attempts';

		/**
		 * The order meta counting how many times a due attempt was put back because the order was busy
		 * ({@see self::defer()}). Kept apart from {@see self::ATTEMPTS_META}: a busy order is not a carrier
		 * failure and must not eat into the five attempts. Cleared with the attempt counter.
		 *
		 * @var string
		 */
		public const DEFERRALS_META = '_woodev_shipment_export_deferrals';

		/** @var string {@see self::after_failure()}: the next attempt is waiting in the queue */
		public const SCHEDULED = 'scheduled';

		/** @var string {@see self::defer()}: the due attempt was put back in the queue, uncounted */
		public const DEFERRED = 'deferred';

		/** @var string {@see self::after_failure()}: the attempts ran out — the note is written and the chain is over */
		public const GAVE_UP = 'gave_up';

		/** @var string {@see self::after_failure()}: a next attempt was due but could not be queued (Action Scheduler is unavailable) */
		public const NOT_SCHEDULED = 'not_scheduled';

		/** @var int attempts in all — the first export plus four retries — before the framework gives up */
		public const MAX_ATTEMPTS = 5;

		/** @var int how many times one chain may put a due attempt back because the order was busy — at {@see self::DEFER_DELAY} each, about two hours — before a busy order counts as a failed attempt */
		public const MAX_DEFERRALS = 24;

		/** @var int seconds a due attempt that met a busy order waits before it tries again */
		private const DEFER_DELAY = 5 * MINUTE_IN_SECONDS;

		/** @var string the Action Scheduler status of an action that is waiting its turn — NOT `in-progress`, which is the action now being carried out (`ActionScheduler_Store::STATUS_PENDING`) */
		private const PENDING_STATUS = 'pending';

		/** @var array<int,int> seconds to wait after the Nth failed attempt, before attempt N + 1 */
		private const DELAYS = [
			1 => MINUTE_IN_SECONDS,
			2 => 5 * MINUTE_IN_SECONDS,
			3 => 30 * MINUTE_IN_SECONDS,
			4 => 2 * HOUR_IN_SECONDS,
		];

		/**
		 * The number of export attempts of this order that have failed in a row.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to read.
		 * @return int
		 */
		public static function attempts( \WC_Order $order ): int {
			return max( 0, (int) $order->get_meta( self::ATTEMPTS_META ) );
		}

		/**
		 * The wait before the next attempt, after the given number of failed ones.
		 *
		 * @since 2.0.2
		 *
		 * @param int $failed_attempts how many attempts have failed so far (at least 1).
		 * @return int|null seconds, or null when the cap is reached and there is no next attempt.
		 */
		public static function delay_after( int $failed_attempts ): ?int {
			return self::DELAYS[ $failed_attempts ] ?? null;
		}

		/**
		 * Books a failed attempt that may be retried: counts it, and either schedules the next attempt
		 * or — at the cap — stops, leaves a note on the order and clears the counter.
		 *
		 * One attempt waits per order. A manual export that fails while an attempt is already queued
		 * REPLACES it — at the time this failure asks for, not the older one — instead of starting a
		 * second chain; it counts as an attempt because the carrier was really called. At the cap the
		 * queued attempt is cancelled, so it cannot come back later and start a fresh chain. An attempt
		 * that could not be queued is not counted: there is nothing waiting for the count to lead to.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $fresh       the order as the datastore holds it NOW; the counter is written here.
		 * @param int|null  $retry_after seconds a 429 asked us to wait ({@see \Woodev_API_Rate_Limit_Exception::get_retry_after()}), or null for the standard schedule.
		 * @param string    $last_error  the carrier's text of this failure, for the note written when the attempts run out; already redacted.
		 * @return string {@see self::SCHEDULED}, {@see self::GAVE_UP} (the cap is reached, the note is written) or {@see self::NOT_SCHEDULED}.
		 */
		public static function after_failure( \WC_Order $fresh, ?int $retry_after, string $last_error ): string {

			$attempts = self::attempts( $fresh ) + 1;
			$delay    = self::delay_after( $attempts );

			if ( null === $delay ) {
				self::cancel_pending( $fresh->get_id() );
				$fresh->add_order_note( self::give_up_text( $last_error ) );
				self::forget( $fresh );

				return self::GAVE_UP;
			}

			self::cancel_pending( $fresh->get_id() );

			if ( ! self::enqueue( $fresh->get_id(), null !== $retry_after ? max( 1, $retry_after ) : $delay ) ) {
				return self::NOT_SCHEDULED;
			}

			$fresh->update_meta_data( self::ATTEMPTS_META, $attempts );
			$fresh->save_meta_data();

			return self::SCHEDULED;
		}

		/**
		 * Puts a due attempt back in the queue because the order was busy — WITHOUT counting an attempt.
		 *
		 * A native edit lock (a manager has the order open; in a WP-Cron run every lock is another
		 * manager's) and the export lock of a concurrent click are both transient, so they are no
		 * reason to abandon the chain: the carrier was never called. The attempt comes back
		 * {@see self::DEFER_DELAY} later, at most {@see self::MAX_DEFERRALS} times per chain — a lock
		 * that never lets go would otherwise keep the chain alive forever — after which the busy order
		 * is booked as one failed attempt ({@see self::after_failure()}).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $fresh  the order as the datastore holds it NOW; the deferral counter is written here.
		 * @param string    $reason why the order was busy, for the note written if the attempts run out; already redacted.
		 * @return string {@see self::DEFERRED}; or, once the deferrals are used up, what {@see self::after_failure()} answers; or {@see self::NOT_SCHEDULED} when the attempt could not be queued.
		 */
		public static function defer( \WC_Order $fresh, string $reason ): string {

			$deferrals = self::deferrals( $fresh );

			if ( $deferrals >= self::MAX_DEFERRALS ) {
				return self::after_failure( $fresh, null, $reason );
			}

			if ( ! self::enqueue( $fresh->get_id(), self::DEFER_DELAY ) ) {
				return self::NOT_SCHEDULED;
			}

			$fresh->update_meta_data( self::DEFERRALS_META, $deferrals + 1 );
			$fresh->save_meta_data();

			return self::DEFERRED;
		}

		/**
		 * Ends the chain of attempts: the counters are cleared, so the next failure starts again at 1.
		 *
		 * Called on a success and on a failure that is not retried. A no-op, with no datastore write,
		 * when nothing was ever counted — the happy path pays nothing.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $fresh the order as the datastore holds it NOW.
		 * @param \WC_Order $order the caller's copy, kept in step so a later save of it cannot bring the counters back.
		 * @return void
		 */
		public static function reset( \WC_Order $fresh, \WC_Order $order ): void {

			self::forget( $fresh );

			if ( $fresh !== $order ) {
				self::forget( $order );
			}
		}

		/**
		 * The sentence that says the attempts ran out — the order note and the merchant's message.
		 *
		 * @since 2.0.2
		 *
		 * @param string $last_error the carrier's text of the last failure.
		 * @return string
		 */
		public static function give_up_text( string $last_error ): string {
			return sprintf(
				/* translators: 1: number of attempts, 2: the carrier's last error */
				__( 'Не удалось выгрузить заказ перевозчику за %1$d попыток: %2$s', 'woodev-plugin-framework' ),
				self::MAX_ATTEMPTS,
				$last_error
			);
		}

		/**
		 * Schedules one export attempt of an order, `$delay` seconds from now.
		 *
		 * Idempotent per order: an attempt already WAITING for this order is not doubled. «Waiting» is
		 * the pending status only — the attempt being carried out right now is `in-progress`, and a
		 * failure inside it must be able to queue the next one ({@see \as_has_scheduled_action()}
		 * would answer yes for it too, and the chain would die after the first automatic retry). The
		 * action's single argument is the order id; it runs
		 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::run_export_retry()}.
		 *
		 * @since 2.0.2
		 *
		 * @param int $order_id the order to export.
		 * @param int $delay    seconds from now; 0 runs it on the next queue pass.
		 * @return bool whether an attempt is now waiting for the order.
		 */
		public static function enqueue( int $order_id, int $delay = 0 ): bool {

			if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
				return false; // WooCommerce ships Action Scheduler, so this is a site that broke it: no retry, and the failure is still reported.
			}

			if ( [] !== self::pending_ids( $order_id ) ) {
				return true;
			}

			return (int) as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, [ $order_id ], self::GROUP ) > 0;
		}

		/**
		 * The attempts of an order that are waiting in the queue — never the one running now.
		 *
		 * @since 2.0.2
		 *
		 * @param int $order_id the order.
		 * @return int[] Action Scheduler action ids, at most one is asked for.
		 */
		private static function pending_ids( int $order_id ): array {
			return array_map(
				'intval',
				(array) as_get_scheduled_actions(
					[
						'hook'     => self::HOOK,
						'args'     => [ $order_id ],
						'group'    => self::GROUP,
						'status'   => self::PENDING_STATUS,
						'per_page' => 1,
					],
					'ids'
				)
			);
		}

		/**
		 * Cancels the attempt waiting for an order, if any. The running one is not touched.
		 *
		 * @since 2.0.2
		 *
		 * @param int $order_id the order.
		 * @return void
		 */
		private static function cancel_pending( int $order_id ): void {

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, [ $order_id ], self::GROUP );
			}
		}

		/**
		 * How many times the attempts of this order's chain were put back because the order was busy.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to read.
		 * @return int
		 */
		private static function deferrals( \WC_Order $order ): int {
			return max( 0, (int) $order->get_meta( self::DEFERRALS_META ) );
		}

		/**
		 * Deletes the attempt and deferral counters off one order object.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return void
		 */
		private static function forget( \WC_Order $order ): void {

			$has_attempts  = self::attempts( $order ) > 0;
			$has_deferrals = self::deferrals( $order ) > 0;

			if ( ! $has_attempts && ! $has_deferrals ) {
				return;
			}

			if ( $has_attempts ) {
				$order->delete_meta_data( self::ATTEMPTS_META );
			}

			if ( $has_deferrals ) {
				$order->delete_meta_data( self::DEFERRALS_META );
			}

			$order->save_meta_data();
		}
	}

endif;
