<?php
/**
 * Warehouse resolution of a stock pair.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * The link's own warehouse, else the account's default; none means the product is reported «Не выбран склад» and nothing is
 * sent. An oversize product takes only a warehouse chosen on its link: falling back to the default warehouse is guesswork that
 * sends oversize goods to the wrong place.
 *
 * @since 2.1.0
 */
final class Stock_Warehouse {
	/**
	 * Resolves the warehouse of a link.
	 *
	 * @since 2.1.0
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link.
	 * @return int|null Marketplace warehouse id, or null when none is chosen.
	 */
	public static function for_link( Account $account, Link_Record $link ): ?int {
		$own = (int) $link->warehouse_id();

		if ( $own > 0 ) {
			return $own;
		}

		if ( $link->is_oversize() ) {
			return null;
		}

		$default = (int) $account->setting( 'warehouse_id', 0 );

		return $default > 0 ? $default : null;
	}
}
