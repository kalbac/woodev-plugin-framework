<?php
/**
 * Error classification of the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Jobs\Error_Classifier;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;

/**
 * Knows one exception, {@see Fake_Remote_Exception}; anything else is not a marketplace answer, which is exactly what a real
 * adapter says about a database error or a bug.
 */
final class Fake_Error_Classifier implements Error_Classifier {
	/**
	 * Accounts whose credentials were reported refused, in order.
	 *
	 * @var int[]
	 */
	public $refused = [];

	/**
	 * Classifies what a call threw.
	 *
	 * @param \Throwable $thrown What was thrown.
	 * @return Remote_Error|null
	 */
	public function classify( \Throwable $thrown ): ?Remote_Error {
		return $thrown instanceof Fake_Remote_Exception ? $thrown->error() : null;
	}

	/**
	 * Returns the health of an account whose credentials were refused.
	 *
	 * @param Remote_Error $error   The refusal.
	 * @param Account      $account Account.
	 * @return Health_Result
	 */
	public function health_for( Remote_Error $error, Account $account ): Health_Result {
		$this->refused[] = $account->id();

		return new Health_Result( Health_Result::AUTH_FAILED, 'Маркетплейс отклонил ключ: ' . $error->message() );
	}
}
