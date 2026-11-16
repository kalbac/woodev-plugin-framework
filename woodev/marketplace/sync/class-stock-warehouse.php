<?php
/**
 * Warehouse resolution of a stock pair.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;

defined( 'ABSPATH' ) || exit;

/**
 * The link's own warehouse, else the account's default; none means the product is reported «Не выбран склад» and nothing is
 * sent. An oversize product takes its link's warehouse, else the account's oversize warehouse
 * (`settings.oversize_warehouse_id`): falling back to the default warehouse is guesswork that sends oversize goods to the wrong
 * place, so without an oversize warehouse the product has none.
 *
 * A warehouse that the cached list of the account's available warehouses no longer contains (the marketplace switched it off or
 * archived it) resolves to none as well, because every send to it would fail; sends resume by themselves when the merchant picks
 * another warehouse or the warehouse is listed again. An empty cache (never refreshed, a failed refresh) blocks nothing.
 *
 * @since 2.1.0
 */
final class Stock_Warehouse {
	/**
	 * Resolves the warehouse of a link.
	 *
	 * @since 2.1.0
	 *
	 * @since 2.1.8 A warehouse missing from the known available list resolves to none.
	 *
	 * @param Account                $account    Account.
	 * @param Link_Record            $link       Link.
	 * @param Warehouse_Options|null $warehouses Cached warehouse lists; the account's own catalogue cache when null.
	 * @return int|null Marketplace warehouse id, or null when none is chosen or the chosen one is not available.
	 */
	public static function for_link( Account $account, Link_Record $link, ?Warehouse_Options $warehouses = null ): ?int {
		$id = self::chosen( $account, $link );

		if ( null === $id ) {
			return null;
		}

		$warehouses = $warehouses ?? self::options_of( $account );

		return null !== $warehouses && $warehouses->is_unavailable( $account->id(), $id ) ? null : $id;
	}

	/**
	 * The warehouse the merchant chose for a link, whether or not the marketplace still offers it.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link.
	 * @return int|null
	 */
	private static function chosen( Account $account, Link_Record $link ): ?int {
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
	 * Opens the account's cached warehouse lists, or null when there is no database to read them from.
	 *
	 * @param Account $account Account.
	 * @return Warehouse_Options|null
	 */
	private static function options_of( Account $account ): ?Warehouse_Options {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$table = ( new Schema( $account->adapter_id() ) )->table_name( 'catalog_cache', $wpdb->prefix );

		return new Warehouse_Options( new Wpdb_Catalog_Cache( $wpdb, $table ) );
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
