<?php
/**
 * Row ids of the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * A row id names an account and a WooCommerce order: `{account id}:{order id}`. The order id alone would let a row shown under one
 * account be sent to another by editing a request. The row of one unit of a grouped order adds a digest of the unit id, which is
 * the marketplace's and stays out of the page: `{account id}:{order id}:{digest}`.
 *
 * @since 2.1.5
 */
final class Site_Orders_Ids {
	/**
	 * Builds the id of a row.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @param int $order_id   WooCommerce order id.
	 * @return string
	 */
	public static function row_id( int $account_id, int $order_id ): string {
		return $account_id . ':' . $order_id;
	}

	/**
	 * Reads the order out of a row id of the given account.
	 *
	 * @since 2.1.5
	 *
	 * @param string $row_id     Row id.
	 * @param int    $account_id Account the table is open for.
	 * @return int|null The order id; null for another account's id or a malformed one.
	 */
	public static function order_id( string $row_id, int $account_id ): ?int {
		if ( 1 !== preg_match( '/^([1-9]\d{0,11}):([1-9]\d{0,11})$/D', $row_id, $parts ) ) {
			return null;
		}

		return (int) $parts[1] === $account_id ? (int) $parts[2] : null;
	}

	/**
	 * Builds the id of the row of one unit of an order.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account id.
	 * @param int    $order_id   WooCommerce order id.
	 * @param string $unit_id    The marketplace's id of the unit.
	 * @return string
	 */
	public static function unit_row_id( int $account_id, int $order_id, string $unit_id ): string {
		return self::row_id( $account_id, $order_id ) . ':' . self::unit_digest( $unit_id );
	}

	/**
	 * Reads a unit row id of the given account.
	 *
	 * @since 2.1.28
	 *
	 * @param string $row_id     Row id.
	 * @param int    $account_id Account the table is open for.
	 * @return array{order_id:int,digest:string}|null Null for an order's row id, another account's id or a malformed one.
	 */
	public static function unit( string $row_id, int $account_id ): ?array {
		if ( 1 !== preg_match( '/^([1-9]\d{0,11}):([1-9]\d{0,11}):([0-9a-f]{16})$/D', $row_id, $parts ) || (int) $parts[1] !== $account_id ) {
			return null;
		}

		return [
			'order_id' => (int) $parts[2],
			'digest'   => $parts[3],
		];
	}

	/**
	 * Returns the digest a unit's row id carries.
	 *
	 * @since 2.1.28
	 *
	 * @param string $unit_id The marketplace's id of the unit.
	 * @return string
	 */
	public static function unit_digest( string $unit_id ): string {
		return substr( md5( $unit_id ), 0, 16 );
	}
}
