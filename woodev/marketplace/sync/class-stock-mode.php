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
 * `site` «Сайт — главный» (the default), `marketplace` «Маркетплейс — главный» or `off` «Не синхронизировать», read from the
 * account's `stock_mode`. In `off` nothing is pushed, nothing is mirrored into WooCommerce and the stock notification type is not
 * subscribed ({@see self::is_off()}).
 *
 * @since 2.1.0
 */
final class Stock_Mode {
	public const SITE        = Wpdb_Account_Store::STOCK_SITE;
	public const MARKETPLACE = Wpdb_Account_Store::STOCK_MARKETPLACE;
	public const OFF         = Wpdb_Account_Store::STOCK_OFF;

	/**
	 * Tells whether the shop's stock is the truth for an account (anything but `marketplace` and `off` is `site`).
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public static function is_site( Account $account ): bool {
		return ! in_array( $account->setting( 'stock_mode', self::SITE ), [ self::MARKETPLACE, self::OFF ], true );
	}

	/**
	 * Tells whether an account does not sync stock at all: no push, no mirroring, no stock notifications.
	 *
	 * @since 2.1.2
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public static function is_off( Account $account ): bool {
		return self::OFF === $account->setting( 'stock_mode', self::SITE );
	}
}
