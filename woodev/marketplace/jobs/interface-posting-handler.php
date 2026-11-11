<?php
/**
 * What the posting jobs ask of the marketplace's order flow.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * The two pieces of the order flow that stay with the marketplace's plugin — it reads its own order format, maps its statuses and
 * knows how far back its listing reaches — behind the two calls {@see Posting_Jobs} makes. The adapter answers it through
 * `posting_handler()`.
 *
 * @since 2.0.30
 */
interface Posting_Handler {
	/**
	 * Reads one remote order and writes it into WooCommerce.
	 *
	 * @since 2.0.30
	 *
	 * @param int    $account_id  Account id.
	 * @param string $external_id The remote order's number.
	 * @return void
	 * @throws \Woodev\Framework\Marketplace\Orders\Order_Claim_Exception When another writer holds the order (the job tries again).
	 */
	public function refresh( int $account_id, string $external_id ): void;

	/**
	 * Reads the remote orders changed since the last run and writes them, keeping its own watermark.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account Account.
	 * @return void
	 */
	public function catch_up( Account $account ): void;
}
