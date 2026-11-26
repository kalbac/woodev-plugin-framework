<?php
/**
 * The lifecycle of a marketplace that gives no task number, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Article_Keyed_Lifecycle;
use Woodev\Framework\Marketplace\Flows\Import_Deadlines;
use Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner;

/**
 * Reads the import by articles from {@see Scripted_Card_Lifecycle::$import}, answers the card list from {@see self::$existing} and
 * has its own deadlines.
 */
final class Scripted_Article_Lifecycle extends Scripted_Card_Lifecycle implements Article_Keyed_Lifecycle, Import_Deadlines {
	/**
	 * Cards the card list holds: article => identifiers.
	 *
	 * @var array<string,array<string,string>>
	 */
	public $existing = [];

	/**
	 * Seconds the import result is waited for.
	 *
	 * @var int
	 */
	public $task_seconds = Poll_Task_Runner::TASK_DEADLINE;

	/**
	 * Seconds a card is waited for.
	 *
	 * @var int
	 */
	public $card_seconds = Poll_Task_Runner::CARD_DEADLINE;

	/**
	 * Reads the import result by articles.
	 *
	 * @param Account  $account   Account.
	 * @param string[] $offer_ids Articles.
	 * @param string   $context   Job.
	 * @return array
	 * @throws \Throwable The scripted failure.
	 */
	public function read_import_by_articles( Account $account, array $offer_ids, string $context ): array {
		$this->reads[] = [ 'by_articles', $offer_ids ];
		$this->before_answer();

		return array_intersect_key( $this->import, array_flip( $offer_ids ) );
	}

	/**
	 * Looks the articles up in the card list.
	 *
	 * @param Account  $account   Account.
	 * @param string[] $offer_ids Articles.
	 * @param string   $context   Job.
	 * @return array
	 * @throws \Throwable The scripted failure.
	 */
	public function find_cards( Account $account, array $offer_ids, string $context ): array {
		$this->reads[] = [ 'find', $offer_ids ];
		$this->before_answer();

		return array_intersect_key( $this->existing, array_flip( $offer_ids ) );
	}

	/**
	 * Returns the task deadline.
	 *
	 * @return int
	 */
	public function task_deadline(): int {
		return $this->task_seconds;
	}

	/**
	 * Returns the card deadline.
	 *
	 * @return int
	 */
	public function card_deadline(): int {
		return $this->card_seconds;
	}
}
