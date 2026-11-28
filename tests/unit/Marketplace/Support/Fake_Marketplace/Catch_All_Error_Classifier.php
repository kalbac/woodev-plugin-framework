<?php
/**
 * A classifier that breaks its contract and answers for any exception.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Jobs\Error_Classifier;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;

/**
 * Calls everything a failure worth a retry, as an adapter with a catch-all branch would.
 */
final class Catch_All_Error_Classifier implements Error_Classifier {
	/**
	 * What it was asked about, in order.
	 *
	 * @var \Throwable[]
	 */
	public $asked = [];

	/**
	 * Calls anything a failure to retry.
	 *
	 * @param \Throwable $thrown What was thrown.
	 * @return Remote_Error
	 */
	public function classify( \Throwable $thrown ): ?Remote_Error {
		$this->asked[] = $thrown;

		return new Remote_Error( Remote_Error::RETRY, 'transport', $thrown->getMessage() );
	}

	/**
	 * Returns the health of an account whose credentials were refused.
	 *
	 * @param Remote_Error $error   The refusal.
	 * @param Account      $account Account.
	 * @return Health_Result
	 */
	public function health_for( Remote_Error $error, Account $account ): Health_Result {
		return new Health_Result( Health_Result::AUTH_FAILED, $error->message() );
	}
}
