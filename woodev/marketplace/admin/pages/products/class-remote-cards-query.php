<?php
/**
 * The filter values of the «Карточки на маркетплейсе» table, and the near-match report's paging.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. The cards themselves are filtered and paged in SQL by {@see \Woodev\Framework\Marketplace\Data\Wpdb_Card_Store} (#1863);
 * here are the values of the filters and the paging of the near-match report, which is a short list kept in an option. A card is
 * *on the site* when a link row carries its article (the answer comes from the links table, never from guessing a product from a name).
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
	 * The «Показать» filter: the near-match report instead of the cards.
	 */
	public const SHOW_NEAR = 'near';

	/**
	 * Reads the «На сайте» filter.
	 *
	 * @since 2.1.13
	 *
	 * @param string $on_site {@see self::NOT_ON_SITE}, {@see self::ON_SITE}, or '' for both.
	 * @return bool|null True: only the cards on the site; false: only the others; null: both.
	 */
	public static function on_site_flag( string $on_site ): ?bool {
		if ( self::ON_SITE === $on_site ) {
			return true;
		}

		return self::NOT_ON_SITE === $on_site ? false : null;
	}

	/**
	 * The articles our link table knows, each with the product that carries it.
	 *
	 * @since 2.1.6
	 *
	 * @param array<int,array{offer_id:?string,has_remote_ids:bool,card_state:string}> $identity Identity of the account's links by product id ({@see \Woodev\Framework\Marketplace\Data\Wpdb_Link_Store::identity_map()}).
	 * @return array<string,int> Product id by article.
	 */
	public static function linked_offers( array $identity ): array {
		$linked = [];

		foreach ( $identity as $product_id => $row ) {
			if ( null !== $row['offer_id'] ) {
				$linked[ $row['offer_id'] ] = $product_id;
			}
		}

		return $linked;
	}

	/**
	 * Selects a page of the near-match report.
	 *
	 * @since 2.1.5
	 *
	 * @param array<int,array<string,mixed>> $entries  The report: `wc_product_id`, `wc_sku`, `relation`, `card`.
	 * @param string                         $search   Text looked for in the card's article and name and in the product's article; empty for none.
	 * @param int                            $page     Page number, from 1.
	 * @param int                            $per_page Entries per page.
	 * @return array{entries:array<int,array<string,mixed>>,total:int}
	 */
	public static function near_page( array $entries, string $search, int $page, int $per_page ): array {
		$needle   = trim( $search );
		$matching = array_values(
			array_filter(
				$entries,
				static function ( array $entry ) use ( $needle ): bool {
					return '' === $needle
						|| false !== mb_stripos( (string) ( $entry['card']['offer_id'] ?? '' ), $needle )
						|| false !== mb_stripos( (string) ( $entry['card']['name'] ?? '' ), $needle )
						|| false !== mb_stripos( (string) ( $entry['wc_sku'] ?? '' ), $needle );
				}
			)
		);

		return [
			'entries' => array_slice( $matching, ( max( 1, $page ) - 1 ) * max( 1, $per_page ), max( 1, $per_page ) ),
			'total'   => count( $matching ),
		];
	}

	/**
	 * Finds an entry of the near-match report by the key of its card's article and the product.
	 *
	 * @since 2.1.5
	 *
	 * @param array<int,array<string,mixed>> $entries    The report.
	 * @param string                         $key        {@see Remote_Cards_Ids::key()}.
	 * @param int                            $product_id The shop product.
	 * @return array<string,mixed>|null Null when the report no longer holds the pair.
	 */
	public static function find_near( array $entries, string $key, int $product_id ): ?array {
		foreach ( $entries as $entry ) {
			if ( (int) ( $entry['wc_product_id'] ?? 0 ) === $product_id && Remote_Cards_Ids::key( (string) ( $entry['card']['offer_id'] ?? '' ) ) === $key ) {
				return $entry;
			}
		}

		return null;
	}
}
