<?php
/**
 * Order ingress contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls orders from a marketplace. The poll path is mandatory for every marketplace: Wildberries has no push at all, and
 * Ozon's pushes can be late, so its poller is the catch-up that guarantees no order is lost.
 *
 * @since 2.0.29
 */
interface Order_Source {
	/**
	 * Lists orders changed since a moment, one page at a time.
	 *
	 * @since 2.0.29
	 *
	 * @param Account            $account Account.
	 * @param \DateTimeImmutable $since   Start of the window.
	 * @param string|null        $cursor  Cursor from the previous page, null for the first page.
	 * @return Pull_Result
	 */
	public function pull_changed( Account $account, \DateTimeImmutable $since, ?string $cursor ): Pull_Result;

	/**
	 * Fetches one order by its marketplace id (refresh after a push, or a registry entry that is not terminal yet).
	 *
	 * @since 2.0.29
	 *
	 * @param Account $account     Account.
	 * @param string  $external_id Marketplace order id.
	 * @return Order_DTO|null Null when the marketplace does not know it.
	 */
	public function pull_one( Account $account, string $external_id ): ?Order_DTO;
}
