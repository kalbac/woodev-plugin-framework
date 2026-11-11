<?php
/**
 * The stock ownership mode of an account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * `site` «Сайт — главный» (the default) or `marketplace` «Маркетплейс — главный», read from the account's `stock_mode`.
 *
 * @since 2.0.30
 */
final class Stock_Mode {
	public const SITE        = Wpdb_Account_Store::STOCK_SITE;
	public const MARKETPLACE = Wpdb_Account_Store::STOCK_MARKETPLACE;

	/**
	 * Tells whether the shop's stock is the truth for an account (anything but `marketplace` is `site`).
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public static function is_site( Account $account ): bool {
		return self::MARKETPLACE !== $account->setting( 'stock_mode', self::SITE );
	}
}
