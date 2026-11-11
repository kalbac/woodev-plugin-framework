<?php
/**
 * Reads what became of the cards that were sent.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * The remote half of the `poll_task` job: the result of an import task, and the state of each card until it exists. The job
 * owns the schedule, the claim and the ledger; this contract owns the requests and what the marketplace's words mean.
 *
 * @since 2.1.0
 */
interface Card_Lifecycle {
	/**
	 * Returns the name of the identifier (in {@see Link_Record::remote_ids()}) the marketplace names a card by when it is read
	 * back, learned from the import result.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function card_id_name(): string;

	/**
	 * Returns the names of the identifiers that may reach a card after it was created; the job keeps reading a created card until
	 * every one of them is known.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public function late_id_names(): array;

	/**
	 * Reads the result of an import task.
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account        Account.
	 * @param string  $remote_task_id The marketplace's number of the task.
	 * @param string  $context        Name of the calling job.
	 * @return array<string,Import_Outcome> The items the marketplace reports, by seller's article. An item it does not report yet is absent.
	 * @throws \Throwable When the request fails.
	 */
	public function read_import( Account $account, string $remote_task_id, string $context ): array;

	/**
	 * Reads the state of cards that were imported.
	 *
	 * @since 2.1.0
	 *
	 * @param Account                   $account Account.
	 * @param array<string,Link_Record> $links   The links, by their {@see self::card_id_name()} identifier.
	 * @param string                    $context Name of the calling job.
	 * @return array<string,Card_Outcome> The cards the marketplace reports, by the same identifier.
	 * @throws \Throwable When the request fails.
	 */
	public function read_cards( Account $account, array $links, string $context ): array;
}
