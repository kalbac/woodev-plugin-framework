<?php
/**
 * Woodev Export Queue
 *
 * How many orders are being exported to a carrier in the background RIGHT NOW (#1007): the number
 * behind the «Сейчас выгружаются N заказов перевозчику» notice on the shipping orders page and on the
 * WooCommerce orders list.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Export_Queue' ) ) :

	/**
	 * Reads the export actions out of Action Scheduler and words the count.
	 *
	 * «Being exported» is an export attempt that is RUNNING, or that is due and waits for the queue
	 * runner to pick it up: the auto-export of a status change, a retry whose wait is over, a busy attempt
	 * that was put back. An attempt scheduled for LATER — the wait after a failure, up to two hours — is
	 * not counted: nothing is being exported then, and a notice that says so for two hours would be untrue.
	 * The cancellations of {@see Carrier_Cancel} are not exports and are not counted either.
	 *
	 * @since 2.0.2
	 */
	final class Export_Queue {

		/** @var string the Action Scheduler status of an action being carried out now (`ActionScheduler_Store::STATUS_RUNNING`) */
		private const RUNNING_STATUS = 'in-progress';

		/** @var string the Action Scheduler status of an action waiting for its turn (`ActionScheduler_Store::STATUS_PENDING`) */
		private const PENDING_STATUS = 'pending';

		/**
		 * How many orders have an export running or due now.
		 *
		 * @since 2.0.2
		 *
		 * @return int 0 when Action Scheduler is not available.
		 */
		public static function count_in_progress(): int {

			if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
				return 0;
			}

			$scope = [
				'hook'     => Export_Retry::HOOK,
				'group'    => Export_Retry::GROUP,
				'per_page' => -1,
			];

			$running = (array) as_get_scheduled_actions( $scope + [ 'status' => self::RUNNING_STATUS ], 'ids' );
			$due     = (array) as_get_scheduled_actions(
				$scope + [
					'status'       => self::PENDING_STATUS,
					'date'         => time(),
					'date_compare' => '<=',
				],
				'ids'
			);

			return count( array_unique( array_merge( $running, $due ) ) );
		}

		/**
		 * The notice's sentence for a count, in the merchant's language and the right Russian form
		 * («1 заказ», «2 заказа», «5 заказов»); `''` for zero — nothing to say.
		 *
		 * @since 2.0.2
		 *
		 * @param int $count orders being exported.
		 * @return string
		 */
		public static function notice_text( int $count ): string {

			if ( $count < 1 ) {
				return '';
			}

			return sprintf(
				/* translators: %d: number of orders being exported to the carrier in the background */
				_n( 'Сейчас выгружается %d заказ перевозчику', 'Сейчас выгружаются %d заказов перевозчику', $count, 'woodev-plugin-framework' ),
				$count
			);
		}
	}

endif;
