<?php
/**
 * The marketplace's label calls.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * The two remote calls of the label flow and the one limit that goes with them. The adapter answers it through
 * `label_provider()` (`Capability::GET_LABEL`); {@see Label_Service} owns everything else — the task rows, the polling window,
 * the documents, the licence.
 *
 * A provider throws {@see Label_Exception} for a refusal whose sentence the merchant can read (an order not ready, a list the
 * marketplace rejects) and lets any other failure of the call out: the adapter's error classifier decides what that means.
 *
 * @since 2.0.30
 */
interface Label_Provider {
	/**
	 * Returns how many orders one set of labels may cover.
	 *
	 * @since 2.0.30
	 *
	 * @return int
	 */
	public function max_keys(): int;

	/**
	 * Asks the marketplace for the labels of remote orders.
	 *
	 * @since 2.0.30
	 *
	 * @param Account  $account       Account the orders belong to.
	 * @param string[] $external_keys Remote-order keys, cleaned and within {@see self::max_keys()}.
	 * @return string The marketplace's task id; empty when it gave none.
	 * @throws Label_Exception With a sentence for the merchant.
	 */
	public function request( Account $account, array $external_keys ): string;

	/**
	 * Polls a label task once.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account        Account of the task.
	 * @param string  $remote_task_id The marketplace's task id.
	 * @return Label_Answer
	 * @throws Label_Exception With a sentence for the merchant.
	 */
	public function fetch( Account $account, string $remote_task_id ): Label_Answer;
}
