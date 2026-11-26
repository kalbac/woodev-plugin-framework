<?php
/**
 * Scripted label calls of a marketplace that answers with the labels at once.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Immediate_Label_Provider;
use Woodev\Framework\Marketplace\Flows\Label_Answer;

/**
 * `labels_now()` gives the answer in `$answer` (or throws the throwable). The task calls are never expected.
 */
final class Fake_Immediate_Label_Provider implements Immediate_Label_Provider {
	/**
	 * Answer of `labels_now()`, or the throwable it throws.
	 *
	 * @var Label_Answer|\Throwable
	 */
	public $answer;

	/**
	 * Calls made: `[ method, key list or task id ]`.
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	public $calls = [];

	/**
	 * Sets the answer.
	 *
	 * @param Label_Answer $answer Answer.
	 */
	public function __construct( Label_Answer $answer ) {
		$this->answer = $answer;
	}

	/**
	 * Returns the largest set.
	 *
	 * @return int
	 */
	public function max_keys(): int {
		return 20;
	}

	/**
	 * Never called.
	 *
	 * @param Account  $account       Account.
	 * @param string[] $external_keys Keys.
	 * @return string
	 */
	public function request( Account $account, array $external_keys ): string {
		$this->calls[] = [ 'request', $external_keys ];

		return '';
	}

	/**
	 * Never called.
	 *
	 * @param Account $account        Account.
	 * @param string  $remote_task_id Task id.
	 * @return Label_Answer
	 */
	public function fetch( Account $account, string $remote_task_id ): Label_Answer {
		$this->calls[] = [ 'fetch', $remote_task_id ];

		return Label_Answer::pending();
	}

	/**
	 * Answers at once.
	 *
	 * @param Account  $account       Account.
	 * @param string[] $external_keys Keys.
	 * @return Label_Answer
	 * @throws \Throwable The scripted one.
	 */
	public function labels_now( Account $account, array $external_keys ): Label_Answer {
		$this->calls[] = [ 'labels_now', $external_keys ];

		if ( $this->answer instanceof \Throwable ) {
			throw $this->answer; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A scripted double.
		}

		return $this->answer;
	}
}
