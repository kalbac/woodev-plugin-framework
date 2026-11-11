<?php
/**
 * The adapter's classification of its own errors.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the jobs what a failed marketplace call means. Every marketplace has its own exception and its own codes; the runners
 * know neither and ask this ({@see \Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter::error_classifier()}).
 *
 * @since 2.0.30
 */
interface Error_Classifier {
	/**
	 * Classifies what a marketplace call threw.
	 *
	 * @since 2.0.30
	 *
	 * @param \Throwable $thrown What the pusher, the reader or the source threw.
	 * @return Remote_Error|null Null when it is not a marketplace answer (a bug, a database error): the runner rethrows it.
	 */
	public function classify( \Throwable $thrown ): ?Remote_Error;

	/**
	 * Returns the health an account gets when the marketplace refused its credentials: the status that pauses its jobs and the
	 * sentence the merchant reads on the account.
	 *
	 * @since 2.0.30
	 *
	 * @param Remote_Error $error   A `PAUSE_ACCOUNT` error.
	 * @param Account      $account The account whose credentials were refused.
	 * @return Health_Result
	 */
	public function health_for( Remote_Error $error, Account $account ): Health_Result;
}
