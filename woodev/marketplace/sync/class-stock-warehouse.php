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
 * sent. An oversize product takes its link's warehouse, else the account's oversize warehouse
 * (`settings.oversize_warehouse_id`): falling back to the default warehouse is guesswork that sends oversize goods to the wrong
 * place, so without an oversize warehouse the product has none.
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
			return self::positive( $account->setting( 'oversize_warehouse_id', 0 ) );
		}

		return self::positive( $account->setting( 'warehouse_id', 0 ) );
	}

	/**
	 * Turns a stored warehouse id into an id or null.
	 *
	 * @param mixed $stored Stored value.
	 * @return int|null
	 */
	private static function positive( $stored ): ?int {
		$id = (int) $stored;

		return $id > 0 ? $id : null;
	}
}
