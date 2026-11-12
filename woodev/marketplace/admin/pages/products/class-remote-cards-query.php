<?php
/**
 * Filters and pages the snapshot of the marketplace's cards.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: the snapshot, the articles our link table knows, a filter and a page in; the page's cards out. A card is *on the site* when a
 * link row carries its article (the answer comes from the links table, never from guessing a product from a name).
 *
 * @since 2.1.5
 */
final class Remote_Cards_Query {
	/**
	 * The «На сайте» filter: only the cards no product carries yet (the table opens on it) …
	 */
	public const NOT_ON_SITE = 'no';

	/**
	 * … or only the cards a product already carries. No value shows both.
	 */
	public const ON_SITE = 'yes';

	/**
	 * Selects a page of cards.
	 *
	 * @since 2.1.5
	 *
	 * @param array<int,array<string,mixed>> $cards    Snapshot cards, in the marketplace's order.
	 * @param array<string,int>              $linked   Product id by article, for every article a link row carries.
	 * @param string                         $on_site  {@see self::NOT_ON_SITE}, {@see self::ON_SITE}, or '' for both.
	 * @param string                         $search   Text looked for in the article and the name; empty for none.
	 * @param int                            $page     Page number, from 1.
	 * @param int                            $per_page Cards per page.
	 * @return array{cards:array<int,array<string,mixed>>,total:int} The cards of the page, each with `wc_product_id` (0 when not on the site).
	 */
	public static function page( array $cards, array $linked, string $on_site, string $search, int $page, int $per_page ): array {
		$matching = [];

		foreach ( $cards as $card ) {
			$product_id = (int) ( $linked[ (string) ( $card['offer_id'] ?? '' ) ] ?? 0 );

			if ( ! self::wanted( $card, $product_id, $on_site, $search ) ) {
				continue;
			}

			$matching[] = array_merge( $card, [ 'wc_product_id' => $product_id ] );
		}

		return [
			'cards' => array_slice( $matching, ( max( 1, $page ) - 1 ) * max( 1, $per_page ), max( 1, $per_page ) ),
			'total' => count( $matching ),
		];
	}

	/**
	 * Finds a card by the key of its article.
	 *
	 * @since 2.1.5
	 *
	 * @param array<int,array<string,mixed>> $cards Snapshot cards.
	 * @param string                         $key   {@see Remote_Cards_Ids::key()}.
	 * @return array<string,mixed>|null Null when the snapshot has no such card.
	 */
	public static function find( array $cards, string $key ): ?array {
		foreach ( $cards as $card ) {
			if ( Remote_Cards_Ids::key( (string) ( $card['offer_id'] ?? '' ) ) === $key ) {
				return $card;
			}
		}

		return null;
	}

	/**
	 * Whether a card passes the filter.
	 *
	 * @param array<string,mixed> $card       Card.
	 * @param int                 $product_id The product that carries its article, 0 for none.
	 * @param string              $on_site    The «На сайте» filter.
	 * @param string              $search     Search text.
	 * @return bool
	 */
	private static function wanted( array $card, int $product_id, string $on_site, string $search ): bool {
		if ( self::NOT_ON_SITE === $on_site && $product_id > 0 ) {
			return false;
		}

		if ( self::ON_SITE === $on_site && 0 === $product_id ) {
			return false;
		}

		$needle = trim( $search );

		return '' === $needle
			|| false !== mb_stripos( (string) ( $card['offer_id'] ?? '' ), $needle )
			|| false !== mb_stripos( (string) ( $card['name'] ?? '' ), $needle );
	}
}
