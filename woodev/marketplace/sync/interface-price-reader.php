<?php
/**
 * Reads the prices a marketplace holds.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Price\Price_Pair;

defined( 'ABSPATH' ) || exit;

/**
 * The read half of the `from_marketplace` price direction
 * ({@see \Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter::price_reader()}): what the marketplace now shows for
 * some articles. {@see Price_Refresher} turns the answer into shop prices.
 *
 * @since 2.1.0
 */
interface Price_Reader {
	/**
	 * Reads the prices of some articles.
	 *
	 * @since 2.1.0
	 *
	 * @param Account  $account   Account.
	 * @param string[] $offer_ids Articles (one job batch at most).
	 * @return array<string,Price_Pair> By article; an article the marketplace did not answer for is absent.
	 * @throws \UnexpectedValueException When the marketplace answers with an article that was not asked for.
	 * @throws \Throwable When the read fails; the job classifies it.
	 */
	public function read( Account $account, array $offer_ids ): array;
}
