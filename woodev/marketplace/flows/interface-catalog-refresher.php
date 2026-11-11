<?php
/**
 * The marketplace's side of the catalogue refresh.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Re-reads the marketplace's category tree and the attributes of the categories that mappings use ({@see Mapped_Pairs}) into the
 * catalogue cache. The refresh job ({@see \Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Runner}) owns the schedule, the licence and
 * pause checks and the retries; this is only the part that talks to the marketplace.
 *
 * A pass has a time budget. When it runs out the result says the pass is incomplete and the job continues it, with the same `$since`,
 * so what was refreshed already (cache rows fresher than `$since`) is not read again.
 *
 * @since 2.0.30
 */
interface Catalog_Refresher {
	/**
	 * Refreshes the tree and the attributes of the mapped categories.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account  Account that pays for the calls.
	 * @param string  $context  Job hook, for the client's context.
	 * @param int     $since    Freshness limit: cache rows fetched at or after it are current.
	 * @param float   $deadline Unix time the pass must stop at.
	 * @return Refresh_Result
	 */
	public function refresh( Account $account, string $context, int $since, float $deadline ): Refresh_Result;
}
