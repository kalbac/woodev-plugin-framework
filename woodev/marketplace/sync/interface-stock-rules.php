<?php
/**
 * What the stock flusher asks the marketplace beyond the push itself.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace's rules of a stock send. The flusher owns the queue, the claim and the budget; it knows no marketplace code,
 * so it asks here what a refused line means and how a card is archived
 * ({@see \Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter::stock_rules()}).
 *
 * @since 2.1.0
 */
interface Stock_Rules {
	/**
	 * The marketplace asked to wait (a rate limit on the line): not a failure, nothing to tell the merchant.
	 */
	public const WAIT = 'wait';

	/**
	 * The marketplace does not know the card yet: the line waits and keeps the marketplace's text, not counted as a failure.
	 */
	public const NOT_READY = 'not_ready';

	/**
	 * The card is archived on the marketplace: the link is retired and the pending value dropped.
	 */
	public const ARCHIVED = 'archived';

	/**
	 * Anything else: a counted failure with the marketplace's text.
	 */
	public const FAILED = 'failed';

	/**
	 * Tells what a refused line means.
	 *
	 * @since 2.1.0
	 *
	 * @param Item_Result $answer The marketplace's answer for one line, not accepted.
	 * @return string One of the constants above.
	 */
	public function verdict( Item_Result $answer ): string;

	/**
	 * Archives the cards of some links, after their stock was set to zero (a removed product).
	 *
	 * A marketplace that has no archive answers true: the zero stock is all it can do, and the links are retired.
	 *
	 * @since 2.1.0
	 *
	 * @param Account        $account Account.
	 * @param Product_Link[] $links   Links whose cards go to the archive.
	 * @return bool False when the marketplace did not confirm.
	 * @throws \Throwable When the call failed; the flusher classifies it.
	 */
	public function archive( Account $account, array $links ): bool;
}
