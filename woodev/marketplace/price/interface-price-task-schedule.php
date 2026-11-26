<?php
/**
 * How long and how often a price task is read.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * Optional. A {@see Price_Pusher} whose marketplace decides on prices later implements it to say how long a result is waited for
 * and how often it is asked. Without it a task is read every minute and expires after an hour.
 *
 * @since 2.1.24
 */
interface Price_Task_Schedule {
	/**
	 * Returns how long after the submit a result is waited for; what is still undecided then expires.
	 *
	 * @since 2.1.24
	 *
	 * @return int Seconds.
	 */
	public function price_task_deadline(): int;

	/**
	 * Returns the time between two reads of an open task; the first read follows the submit by the same time.
	 *
	 * @since 2.1.24
	 *
	 * @return int Seconds.
	 */
	public function price_poll_interval(): int;
}
