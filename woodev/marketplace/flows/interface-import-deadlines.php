<?php
/**
 * How long a marketplace may take over an export.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: a {@see Card_Lifecycle} implements it when the marketplace is slower than the defaults of the `poll_task` job
 * ({@see \Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner::TASK_DEADLINE} and
 * {@see \Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner::CARD_DEADLINE}), for example when it checks documents for days.
 *
 * Both are counted from the submit. A card deadline shorter than the task deadline fails a card whose import answers late.
 *
 * @since 2.1.21
 */
interface Import_Deadlines {
	/**
	 * Returns how long the import result is waited for; after it the open items fail.
	 *
	 * @since 2.1.21
	 *
	 * @return int Seconds.
	 */
	public function task_deadline(): int;

	/**
	 * Returns how long an imported card is waited for until it exists; after it the card fails.
	 *
	 * @since 2.1.21
	 *
	 * @return int Seconds.
	 */
	public function card_deadline(): int;
}
