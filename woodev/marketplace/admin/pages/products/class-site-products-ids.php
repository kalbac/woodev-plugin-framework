<?php
/**
 * Row ids of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

defined( 'ABSPATH' ) || exit;

/**
 * A row id names an account and a product: `{account id}:{product id}`. The product id alone would name the same product in every
 * account, so a row shown under one account could be sent to another by editing a request.
 *
 * @since 2.1.3
 */
final class Site_Products_Ids {
	/**
	 * Builds the id of a row.
	 *
	 * @since 2.1.3
	 *
	 * @param int $account_id Account id.
	 * @param int $product_id Product or variation id.
	 * @return string
	 */
	public static function row_id( int $account_id, int $product_id ): string {
		return $account_id . ':' . $product_id;
	}

	/**
	 * Reads the product out of a row id of the given account.
	 *
	 * @since 2.1.3
	 *
	 * @param string $row_id     Row id.
	 * @param int    $account_id Account the table is open for.
	 * @return int|null The product id; null for another account's id or a malformed one.
	 */
	public static function product_id( string $row_id, int $account_id ): ?int {
		if ( 1 !== preg_match( '/^([1-9]\d{0,11}):([1-9]\d{0,11})$/D', $row_id, $parts ) ) {
			return null;
		}

		return (int) $parts[1] === $account_id ? (int) $parts[2] : null;
	}
}
