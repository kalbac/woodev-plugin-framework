<?php
/**
 * The marketplace's side of changing the article of a card that is already there.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Optional contract of an adapter, like {@see \Woodev\Framework\Marketplace\Admin\Product\Product_Tab_Facts}: an adapter that implements it **and**
 * reports {@see \Woodev\Framework\Marketplace\Capabilities\Capability::RENAME_SKU} lets the merchant change the article of a card after the export;
 * an adapter without it keeps the article frozen once the card was sent. `Marketplace_Adapter` itself does not change.
 *
 * The `rename_offer_ids` job owns everything around the call (the gate, the claim, the retry ladder, what happens to the shop's row); this contract owns
 * the request and what the marketplace's answer means.
 *
 * @since 2.1.13
 */
interface Offer_Id_Renamer {
	/**
	 * Returns how many articles one request may change (Ozon: 25). The job never hands over more.
	 *
	 * @since 2.1.13
	 *
	 * @return int
	 */
	public function max_renames_per_request(): int;

	/**
	 * Asks the marketplace to change the articles of cards it already has.
	 *
	 * The adapter answers for **every** pair: a pair the marketplace refused carries its answer, any other is renamed. A call that fails as a whole
	 * (a refused key, a limit, a network error) throws instead; the adapter's error classifier decides what that means. A pair missing from the answer is
	 * treated as refused, so nothing is lost silently.
	 *
	 * @since 2.1.13
	 *
	 * @param Account              $account Account.
	 * @param array<string,string> $pairs   Current article => new article, at most {@see self::max_renames_per_request()}.
	 * @param string               $context Name of the calling job, for the governor and the error ring.
	 * @return array<string,Offer_Id_Rename_Outcome> By current article.
	 * @throws \Throwable When the request fails as a whole.
	 */
	public function rename_offer_ids( Account $account, array $pairs, string $context ): array;
}
