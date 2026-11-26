<?php
/**
 * Labels made by a task, in a format the merchant chooses.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Label_Answer;
use Woodev\Framework\Marketplace\Flows\Selectable_Label_Format;

/**
 * Hands out the task `777`; `fetch()` gives `$answer`. The format is `$format`: the setting a test changes.
 */
final class Fake_Formatted_Task_Label_Provider implements Selectable_Label_Format {
	/**
	 * The format of the account.
	 *
	 * @var string
	 */
	public $format = 'png-58x40';

	/**
	 * Answer of `fetch()`.
	 *
	 * @var Label_Answer
	 */
	public $answer;

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
	 * Takes a request.
	 *
	 * @param Account  $account       Account.
	 * @param string[] $external_keys Keys.
	 * @return string
	 */
	public function request( Account $account, array $external_keys ): string {
		return '777';
	}

	/**
	 * Answers a poll.
	 *
	 * @param Account $account        Account.
	 * @param string  $remote_task_id Task id.
	 * @return Label_Answer
	 */
	public function fetch( Account $account, string $remote_task_id ): Label_Answer {
		return $this->answer;
	}

	/**
	 * Returns the format.
	 *
	 * @param Account $account Account.
	 * @return string
	 */
	public function label_format( Account $account ): string {
		return $this->format;
	}
}
