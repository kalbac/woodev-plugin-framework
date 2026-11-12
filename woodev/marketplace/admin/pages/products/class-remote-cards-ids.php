<?php
/**
 * Row ids of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

defined( 'ABSPATH' ) || exit;

/**
 * A row id names an account and a card: `{account id}:{key of the card's article}`. An article can be up to 191 characters of anything
 * (spaces, slashes, a Cyrillic letter), and a row id is a short opaque token, so the id carries a hash of the article, not the article.
 * The account is in the id for the reason it is in {@see Site_Products_Ids}: a row shown under one account must not be usable under another.
 *
 * @since 2.1.5
 */
final class Remote_Cards_Ids {
	/**
	 * Builds the key of a card.
	 *
	 * @since 2.1.5
	 *
	 * @param string $offer_id The card's article.
	 * @return string 32 hexadecimal characters.
	 */
	public static function key( string $offer_id ): string {
		return substr( hash( 'sha256', $offer_id ), 0, 32 );
	}

	/**
	 * Builds the id of a row.
	 *
	 * @since 2.1.5
	 *
	 * @param int    $account_id Account id.
	 * @param string $offer_id   The card's article.
	 * @return string
	 */
	public static function row_id( int $account_id, string $offer_id ): string {
		return $account_id . ':' . self::key( $offer_id );
	}

	/**
	 * Reads the key of the card out of a row id of the given account.
	 *
	 * @since 2.1.5
	 *
	 * @param string $row_id     Row id.
	 * @param int    $account_id Account the table is open for.
	 * @return string|null The key; null for another account's id or a malformed one.
	 */
	public static function key_of( string $row_id, int $account_id ): ?string {
		if ( 1 !== preg_match( '/^([1-9]\d{0,11}):([0-9a-f]{32})$/D', $row_id, $parts ) ) {
			return null;
		}

		return (int) $parts[1] === $account_id ? $parts[2] : null;
	}
}
