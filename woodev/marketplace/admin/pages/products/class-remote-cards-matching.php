<?php
/**
 * The cards of the «Карточки на маркетплейсе» table that a filter reaches.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;

defined( 'ABSPATH' ) || exit;

/**
 * «Импортировать все по фильтру» (#1796) needs the cards the table's own filter reaches, without a page: how many (the apply-time
 * count) and which (the first tick's freeze). Both read the one transient snapshot of the cabinet and one identity-map read of the
 * links, and run the filter the page already runs ({@see Remote_Cards_Query}) — never a WooCommerce lookup per card.
 *
 * The «Показать → Похожие артикулы» view is not part of it: that is a report the merchant decides on row by row, and its rows are
 * not cards of the snapshot.
 *
 * @since 2.1.10
 */
final class Remote_Cards_Matching {
	/**
	 * @var Cards_Snapshot_Store
	 */
	private $snapshots;

	/**
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.10
	 *
	 * @param Cards_Snapshot_Store $snapshots Snapshot of the cabinet's cards.
	 * @param Wpdb_Link_Store      $links     Links.
	 */
	public function __construct( Cards_Snapshot_Store $snapshots, Wpdb_Link_Store $links ) {
		$this->snapshots = $snapshots;
		$this->links     = $links;
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
	 * @return int|null Null when the list is not loaded or has expired.
	 */
	public function count( int $account_id, array $query ): ?int {
		$snapshot = $this->snapshots->get( $account_id );

		if ( null === $snapshot ) {
			return null;
		}

		$on_site = $this->on_site( $query );
		$counts  = Remote_Cards_Query::on_site_counts( $snapshot['cards'], $this->linked( $account_id ), $this->search( $query ) );

		if ( '' === $on_site ) {
			return $counts[ Remote_Cards_Query::NOT_ON_SITE ] + $counts[ Remote_Cards_Query::ON_SITE ];
		}

		return $counts[ $on_site ];
	}

	/**
	 * Every card the filter reaches, split by whether a product carries its article already: the `[ article, group key ]` pair of each
	 * card to import (the pair the run stores, built once) and the articles of the ones on the site.
	 *
	 * @since 2.1.10
	 *
	 * @param int                                              $account_id Account.
	 * @param array{search:string,filters:array<string,mixed>} $query      Validated filter and search.
	 * @return array{cards:array<int,array{0:string,1:string}>,on_site:string[]}|null Null when the list is not loaded or has expired.
	 */
	public function entries( int $account_id, array $query ): ?array {
		$snapshot = $this->snapshots->get( $account_id );

		if ( null === $snapshot ) {
			return null;
		}

		return Remote_Cards_Query::entries( $snapshot['cards'], $this->linked( $account_id ), $this->on_site( $query ), $this->search( $query ) );
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

	/**
	 * @param int $account_id Account.
	 * @return array<string,int> Product id by article.
	 */
	private function linked( int $account_id ): array {
		return Remote_Cards_Query::linked_offers( $this->links->identity_map( $account_id ) );
	}
}
