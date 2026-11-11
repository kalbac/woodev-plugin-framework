<?php
/**
 * Scripted label calls of the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Label_Answer;
use Woodev\Framework\Marketplace\Flows\Label_Exception;
use Woodev\Framework\Marketplace\Flows\Label_Provider;

/**
 * Takes up to 20 orders per set. `request()` hands out the task id in `$remote_task_id` (or the throwable in `$request_fails`);
 * `fetch()` gives the answer in `$answer` (or throws the throwable). `$during_fetch` runs inside the next `fetch()`: the seam
 * for an interleaved poll.
 */
final class Fake_Label_Provider implements Label_Provider {
	/**
	 * Largest set.
	 *
	 * @var int
	 */
	public $max = 20;

	/**
	 * The task id the next request is answered with.
	 *
	 * @var string
	 */
	public $remote_task_id = '777';

	/**
	 * Thrown by the next `request()`.
	 *
	 * @var \Throwable|null
	 */
	public $request_fails;

	/**
	 * Answer of `fetch()`, or the throwable it throws.
	 *
	 * @var Label_Answer|\Throwable
	 */
	public $answer;

	/**
	 * Runs inside the next `fetch()`.
	 *
	 * @var callable|null
	 */
	public $during_fetch;

	/**
	 * Calls made: `[ 'request'|'fetch', key list or task id ]`.
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	public $calls = [];

	/**
	 * Sets the default answer.
	 */
	public function __construct() {
		$this->answer = Label_Answer::pending();
	}

	/**
	 * Returns the largest set.
	 *
	 * @return int
	 */
	public function max_keys(): int {
		return $this->max;
	}

	/**
	 * Takes a request.
	 *
	 * @param Account  $account       Account.
	 * @param string[] $external_keys Keys.
	 * @return string
	 * @throws \Throwable The scripted failure.
	 */
	public function request( Account $account, array $external_keys ): string {
		$this->calls[] = [ 'request', $external_keys ];

		if ( null !== $this->request_fails ) {
			throw $this->request_fails;
		}

		return $this->remote_task_id;
	}

	/**
	 * Answers a poll.
	 *
	 * @param Account $account        Account.
	 * @param string  $remote_task_id Task id.
	 * @return Label_Answer
	 * @throws Label_Exception|\Throwable The scripted failure.
	 */
	public function fetch( Account $account, string $remote_task_id ): Label_Answer {
		$this->calls[] = [ 'fetch', $remote_task_id ];

		if ( null !== $this->during_fetch ) {
			call_user_func( $this->during_fetch );
		}

		if ( $this->answer instanceof \Throwable ) {
			throw $this->answer;
		}

		return $this->answer;
	}
}
