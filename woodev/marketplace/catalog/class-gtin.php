<?php
/**
 * Which barcode is a real GTIN.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * A marketplace barcode is a free string; WooCommerce's GTIN field is not. Its setter strips what is not a digit (`12345Ejf5`
 * would be stored as `123455`), so only a value that **is** a GTIN may be written there: digits only, 8, 12, 13 or 14 of them,
 * with a correct GS1 check digit, and not a **restricted-circulation number** — one a shop, a company or a marketplace gives its
 * own goods, which names nothing outside it (https://www.gs1.org/prefixes), however correct its check digit. Every barcode is
 * kept byte for byte elsewhere ({@see \Woodev\Framework\Marketplace\Data\Wpdb_Link_Store}).
 *
 * @since 2.1.23
 */
final class Gtin {
	private const LENGTHS = [ 8, 12, 13, 14 ];

	/**
	 * Tells whether a barcode is a GTIN.
	 *
	 * @since 2.1.23
	 *
	 * @param string $barcode Barcode, as the marketplace gave it.
	 * @return bool
	 */
	public static function is_valid( string $barcode ): bool {
		if ( 1 !== preg_match( '/^\d+$/D', $barcode ) || ! in_array( strlen( $barcode ), self::LENGTHS, true ) ) {
			return false;
		}

		return (int) substr( $barcode, -1 ) === self::check_digit( substr( $barcode, 0, -1 ) ) && ! self::is_restricted( $barcode );
	}

	/**
	 * Tells whether a number of a GTIN length is one of restricted circulation: an 8-digit one starting with `0` or `2`; a longer
	 * one whose 13-digit form (a 12-digit number gets a leading `0`, a 14-digit one loses its first digit) starts with `02`,
	 * `04`, `2` or the seven zeros of the numbers a company keeps for itself.
	 *
	 * @param string $digits 8, 12, 13 or 14 digits.
	 * @return bool
	 */
	private static function is_restricted( string $digits ): bool {
		if ( 8 === strlen( $digits ) ) {
			return 1 === preg_match( '/^[02]/', $digits );
		}

		$thirteen = 14 === strlen( $digits ) ? substr( $digits, 1 ) : str_pad( $digits, 13, '0', STR_PAD_LEFT );

		return 1 === preg_match( '/^(02|04|2|0000000)/', $thirteen );
	}

	/**
	 * Returns the first barcode of a list that is a GTIN.
	 *
	 * @since 2.1.23
	 *
	 * @param array<int,mixed> $barcodes Barcodes, in the marketplace's order.
	 * @return string '' when none is.
	 */
	public static function first( array $barcodes ): string {
		foreach ( $barcodes as $barcode ) {
			if ( is_string( $barcode ) && self::is_valid( $barcode ) ) {
				return $barcode;
			}
		}

		return '';
	}

	/**
	 * Works out the GS1 check digit: the digits are weighted 3, 1, 3, … from the right.
	 *
	 * @param string $digits The GTIN without its check digit.
	 * @return int
	 */
	private static function check_digit( string $digits ): int {
		$sum = 0;

		foreach ( str_split( strrev( $digits ) ) as $index => $digit ) {
			$sum += (int) $digit * ( 0 === $index % 2 ? 3 : 1 );
		}

		return ( 10 - $sum % 10 ) % 10;
	}
}
