<?php
/**
 * A marketplace that keeps one price for several links.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * An **optional** part of a {@see Price_Pusher}, for a marketplace whose price belongs to something larger than one link: one
 * price for all sizes of a card, say. A pusher that does not implement it keeps the plain rule: every link is its own group.
 *
 * A **price group** is the links of one account that hold the same value of one remote identifier. For such a pusher:
 *
 * - {@see Price_Pusher::submit()} gets whole groups. When one price of a group is due, the prices of its other links go in the
 *   same call, also those the marketplace already holds. A call may carry several groups, and a group larger than the usual
 *   request limit arrives whole, so the pusher splits it itself if the marketplace needs that. The pusher answers every line.
 * - A group that does not allow different prices, and whose computed prices differ, is **not sent**: its links carry the
 *   reason {@see \Woodev\Framework\Marketplace\Diagnostics\Sync_Reason::PRICE_GROUP_DIFFERS} until the prices agree.
 * - A group is sent only when each of its prices may be sent: while one waits (for a price task, because the marketplace
 *   asked for a pause, or because the least time between two sends has not passed), the whole group waits.
 * - A price the marketplace refused for good (a refusal or a quarantine it decided on later, the second expiry of a price
 *   task) is not sent again until it changes. Where a group may carry different prices, that link is left out of the call
 *   and the others go. Where it keeps one price, nothing is sent and the links that were due are turned down with the same
 *   reason.
 * - A link whose sends failed as often as the price cadence allows is left out where prices may differ; where the group
 *   keeps one price, it goes with the group when another link makes the group due.
 *
 * Only links whose price the shop sends take part: a created card with price synchronisation on and a calculated price. A link
 * outside that set is not compared and not sent, although a price the marketplace keeps for the whole group reaches it too.
 *
 * Both methods are asked while a price run prepares a request: they read stored facts and send nothing to the marketplace.
 *
 * @since 2.1.26
 */
interface Price_Grouping {
	/**
	 * Names the remote identifier whose value ties links into one price group.
	 *
	 * @since 2.1.26
	 *
	 * @return string Name in the links' `remote_ids`; a link that holds no value for it is its own group.
	 */
	public function price_group_id_name(): string;

	/**
	 * Tells whether the links of one group may carry different prices.
	 *
	 * Asked only about a group whose computed prices differ, or one that holds a refused or repeatedly failed price.
	 *
	 * @since 2.1.26
	 *
	 * @param Account        $account Account.
	 * @param Product_Link[] $members The links of the group that take part, two or more.
	 * @return bool False when the marketplace keeps one price for the whole group.
	 */
	public function allows_member_prices( Account $account, array $members ): bool;
}
