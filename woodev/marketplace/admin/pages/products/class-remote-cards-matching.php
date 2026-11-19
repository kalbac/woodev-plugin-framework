<?php
/**
 * The cards of the «Карточки на маркетплейсе» table that a filter reaches.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;

defined( 'ABSPATH' ) || exit;

/**
 * «Импортировать все по фильтру» (#1796) needs the cards the table's own filter reaches, without a page: how many (the apply-time
 * count) and which (the first tick's freeze). Both are one SQL read of the stored list joined with the links, with the filter the page
 * itself runs ({@see Wpdb_Card_Store}) — never a WooCommerce lookup per card.
 *
 * The «Показать → Похожие артикулы» view is not part of it: that is a report the merchant decides on row by row, and its rows are
 * not cards of the list.
 *
 * @since 2.1.10
 */
final class Remote_Cards_Matching {
	/**
	 * @var Wpdb_Card_Store
	 */
	private $cards;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.10
	 *
	 * @param Wpdb_Card_Store $cards The stored list of the cabinet's cards.
	 */
	public function __construct( Wpdb_Card_Store $cards ) {
		$this->cards = $cards;
	}

	/**
	 * Whether the query asks for the near-match report instead of the cards.
	 *
	 * @since 2.1.10
	 *
	 * @param array{search:string,filters:array<string,mixed>} $query Validated filter and search.
	 * @return bool
	 */
	public static function is_near_report( array $query ): bool {
		return Remote_Cards_Query::SHOW_NEAR === ( $query['filters'][ Remote_Cards_Definition::SHOW_FILTER ] ?? '' );
	}

	/**
	 * How many cards the filter reaches: the table's own total.
	 *
	 * @since 2.1.10
	 *
	 * @param int                                              $account_id Account.
	 * @param array{search:string,filters:array<string,mixed>} $query      Validated filter and search.
	 * @return int|null Null when the account has no list yet.
	 */
	public function count( int $account_id, array $query ): ?int {
		if ( ! $this->cards->has_list( $account_id ) ) {
			return null;
		}

		$counts  = $this->cards->on_site_counts( $account_id, $this->search( $query ) );
		$on_site = Remote_Cards_Query::on_site_flag( $this->on_site( $query ) );

		if ( null === $on_site ) {
			return $counts['on_site'] + $counts['not_on_site'];
		}

		return $on_site ? $counts['on_site'] : $counts['not_on_site'];
	}

	/**
	 * Every card the filter reaches, split by whether a product carries its article already: the `[ article, group key ]` pair of each
	 * card to import (the pair the run stores, built once) and the articles of the ones on the site.
	 *
	 * @since 2.1.10
	 *
	 * @param int                                              $account_id Account.
	 * @param array{search:string,filters:array<string,mixed>} $query      Validated filter and search.
	 * @return array{cards:array<int,array{0:string,1:string}>,on_site:string[]}|null Null when the account has no list yet.
	 */
	public function entries( int $account_id, array $query ): ?array {
		if ( ! $this->cards->has_list( $account_id ) ) {
			return null;
		}

		return $this->cards->entries( $account_id, Remote_Cards_Query::on_site_flag( $this->on_site( $query ) ), $this->search( $query ) );
	}

	/**
	 * @param array{search:string,filters:array<string,mixed>} $query Query.
	 * @return string
	 */
	private function on_site( array $query ): string {
		return (string) ( $query['filters']['on_site'] ?? '' );
	}

	/**
	 * @param array{search:string,filters:array<string,mixed>} $query Query.
	 * @return string
	 */
	private function search( array $query ): string {
		return (string) ( $query['filters']['q'] ?? $query['search'] ?? '' );
	}
}
