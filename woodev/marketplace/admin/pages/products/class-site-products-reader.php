<?php
/**
 * Reads the rows of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce products joined to the links of one account. It reads only our tables and WooCommerce's, never the marketplace: the
 * marketplace side of stock and price is what the last readback and the last push stored.
 *
 * A listed product is a simple or a variable one. A simple product has one offer (itself); a variable product has one offer per
 * published variation, so a filter on state or errors finds a variable product when any of its variations matches. Grouped and
 * external products are not listed: a marketplace sells goods that ship, and they have no card to send.
 *
 * Offer shape: `product_id`, `label`, `sku`, `offer_id`, `link_id`, `card_state`, `group` ({@see State_Groups}), `error`,
 * `site_stock`, `stock_pool` (the parent's id when the variation shares the parent's stock, else null), `remote_stock`,
 * `remote_stock_at`, `site_price`, `remote_price`, `warehouse_id`, `sync_stock`, `sync_price` (the last three null when there is no
 * link row).
 *
 * @since 2.1.3
 */
final class Site_Products_Reader {
	/**
	 * Post statuses a merchant sees.
	 */
	private const LISTED_STATUSES = "'publish', 'draft', 'pending', 'private'";

	/**
	 * @var \wpdb
	 */
	private $db;

	/**
	 * @var string
	 */
	private $links_table;

	/**
	 * @var string
	 */
	private $sync_table;

	/**
	 * Loads a product: `function ( int $id ): ?\WC_Product`.
	 *
	 * @var callable
	 */
	private $load;

	/**
	 * Gives the category and its descendants: `function ( int $term_id ): int[]`.
	 *
	 * @var callable
	 */
	private $category_tree;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.3
	 *
	 * @param \wpdb         $db            Database.
	 * @param string        $links_table   Full name of the links table.
	 * @param string        $sync_table    Full name of the sync state table.
	 * @param callable|null $category_tree `function ( int $term_id ): int[]`; the term and its `product_cat` descendants when null.
	 * @param callable|null $load          `function ( int $id ): ?\WC_Product`, used only for a variation that may share its parent's stock; `wc_get_product()` when null.
	 */
	public function __construct( \wpdb $db, string $links_table, string $sync_table, ?callable $category_tree = null, ?callable $load = null ) {
		$this->db            = $db;
		$this->links_table   = $links_table;
		$this->sync_table    = $sync_table;
		$this->load          = $load ?? static function ( int $id ): ?\WC_Product {
			$product = wc_get_product( $id );

			return $product instanceof \WC_Product ? $product : null;
		};
		$this->category_tree = $category_tree ?? static function ( int $term_id ): array {
			$children = get_term_children( $term_id, 'product_cat' );

			return array_merge( [ $term_id ], is_array( $children ) ? array_map( 'intval', $children ) : [] );
		};
	}

	/**
	 * Reads one page of product ids, sorted and filtered in SQL: the whole result is ordered, not the page.
	 *
	 * @since 2.1.3
	 *
	 * @param Site_Products_Filter $filter   Filters and sort.
	 * @param int                  $page     Page number, from 1.
	 * @param int                  $per_page Rows per page.
	 * @return array{ids:int[],total:int}
	 */
	public function page( Site_Products_Filter $filter, int $page, int $per_page ): array {
		$where = $this->where( $filter );
		$total = (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . $this->db->prepare( '%i AS p', $this->db->posts ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every fragment is prepared.

		if ( 0 === $total ) {
			return [
				'ids'   => [],
				'total' => 0,
			];
		}

		$ids = $this->db->get_col(
			'SELECT p.ID FROM ' . $this->db->prepare( '%i AS p LEFT JOIN %i AS lk ON lk.product_id = p.ID', $this->db->posts, $this->lookup_table() ) . ' WHERE ' . $where // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every fragment is prepared.
			. ' ORDER BY ' . $filter->order_by() . $this->db->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, $page - 1 ) * $per_page )
		);

		return [
			'ids'   => array_map( 'intval', $ids ),
			'total' => $total,
		];
	}

	/**
	 * Builds the rows of listed products, each with all its offers.
	 *
	 * @since 2.1.3
	 *
	 * @param int   $account_id Account.
	 * @param int[] $ids        Product ids, in display order.
	 * @return array<int,array<string,mixed>> `id`, `title`, `status`, `sku`, `is_variable`, `offers`.
	 */
	public function rows( int $account_id, array $ids ): array {
		if ( [] === $ids ) {
			return [];
		}

		$products   = $this->products( $ids );
		$variations = $this->with_inherited_stock( $this->variations( $ids ), $products );
		$offers     = $this->offers( $account_id, $products, $variations );
		$rows       = [];

		foreach ( $ids as $id ) {
			if ( ! isset( $products[ $id ] ) ) {
				continue;
			}

			$members = array_filter(
				$variations,
				static function ( array $variation ) use ( $id ): bool {
					return $variation['parent'] === $id;
				}
			);
			$own     = [] === $members ? [ $offers[ $id ] ] : array_values( array_intersect_key( $offers, $members ) );

			$rows[] = [
				'id'          => $id,
				'title'       => $products[ $id ]['title'],
				'status'      => $products[ $id ]['status'],
				'sku'         => $products[ $id ]['sku'],
				'is_variable' => [] !== $members,
				'offers'      => $own,
			];
		}

		return $rows;
	}

	/**
	 * Reads one page of the variations of a variable product, as offers.
	 *
	 * @since 2.1.3
	 *
	 * @param int $account_id Account.
	 * @param int $parent_id  Variable product.
	 * @param int $page       Page number, from 1.
	 * @param int $per_page   Variations per page.
	 * @return array{offers:array<int,array<string,mixed>>,total:int}
	 */
	public function variation_page( int $account_id, int $parent_id, int $page, int $per_page ): array {
		$scope = $this->db->prepare( "p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status IN ('publish', 'private')", $parent_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
		$total = (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . $this->db->prepare( '%i AS p', $this->db->posts ) . ' WHERE ' . $scope ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.
		$ids   = $this->db->get_col(
			'SELECT p.ID FROM ' . $this->db->prepare( '%i AS p', $this->db->posts ) . ' WHERE ' . $scope // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.
			. $this->db->prepare( ' ORDER BY p.menu_order ASC, p.ID ASC LIMIT %d OFFSET %d', $per_page, max( 0, $page - 1 ) * $per_page )
		);
		$ids   = array_map( 'intval', $ids );

		if ( [] === $ids ) {
			return [
				'offers' => [],
				'total'  => $total,
			];
		}

		return [
			'offers' => array_values( $this->variation_offers( $account_id, $ids ) ),
			'total'  => $total,
		];
	}

	/**
	 * Builds the offers of variations named by their own ids.
	 *
	 * @since 2.1.3
	 *
	 * @param int   $account_id Account.
	 * @param int[] $ids        Variation ids.
	 * @return array<int,array<string,mixed>> Offers by variation id, in the order of the ids; an id that is not a variation is absent.
	 */
	public function variation_offers( int $account_id, array $ids ): array {
		$rows    = array_filter(
			$this->lookup_rows_for( $ids ),
			static function ( array $row ): bool {
				return $row['parent'] > 0;
			}
		);
		$parents = [] === $rows ? [] : $this->products( array_values( array_unique( array_column( $rows, 'parent' ) ) ) );

		return $this->offers( $account_id, [], $this->with_inherited_stock( $rows, $parents ) );
	}

	/**
	 * Tells which of the ids are listed products or published variations, and what each is.
	 *
	 * @since 2.1.3
	 *
	 * @param int[] $ids Product or variation ids.
	 * @return array<int,array{parent:int,is_variation:bool}> Keyed by id; an id that is neither is absent.
	 */
	public function kinds( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$found = $this->db->get_results(
			$this->db->prepare(
				"SELECT p.ID AS id, p.post_parent AS parent, p.post_type AS type FROM %i AS p WHERE (p.post_type = 'product' AND p.post_status IN (" . self::LISTED_STATUSES . ") OR p.post_type = 'product_variation' AND p.post_status IN ('publish', 'private')) AND p.ID IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->db->posts ], $ids )
			),
			'ARRAY_A'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
		$kinds = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$kinds[ (int) $row['id'] ] = [
				'parent'       => (int) $row['parent'],
				'is_variation' => 'product_variation' === $row['type'],
			];
		}

		return $kinds;
	}

	/**
	 * Lists the published variations of variable products.
	 *
	 * @since 2.1.3
	 *
	 * @param int[] $parent_ids Product ids.
	 * @return array<int,int[]> Parent id => its variation ids, in display order; a parent with none is absent.
	 */
	public function variation_ids( array $parent_ids ): array {
		$ids = [];

		foreach ( $this->variations( $parent_ids ) as $id => $variation ) {
			$ids[ $variation['parent'] ][] = $id;
		}

		return $ids;
	}

	/**
	 * Builds the WHERE clause (without the keyword) of the product list.
	 *
	 * @param Site_Products_Filter $filter Filters.
	 * @return string Prepared SQL over the alias `p`.
	 */
	private function where( Site_Products_Filter $filter ): string {
		$account = $filter->account_id();
		$parts   = [
			"p.post_type = 'product' AND p.post_status IN (" . self::LISTED_STATUSES . ')',
			$this->db->prepare(
				"p.ID IN (SELECT tr.object_id FROM %i AS tr INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i AS t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type' AND t.slug IN ('simple', 'variable'))",
				$this->db->term_relationships,
				$this->db->term_taxonomy,
				$this->db->terms
			),
		];

		$state = $filter->state();

		if ( State_Groups::UNLINKED === $state ) {
			$parts[] = $this->unlinked_clause( $account );
		} elseif ( '' !== $state ) {
			$states  = State_Groups::states_of( $state );
			$parts[] = $this->member( $account, $this->db->prepare( 'AND l.card_state IN (' . implode( ', ', array_fill( 0, count( $states ), '%s' ) ) . ')', $states ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
		}

		if ( $filter->only_errors() ) {
			$parts[] = $this->member( $account, "AND l.last_error_text IS NOT NULL AND l.last_error_text <> ''" );
		}

		if ( $filter->category() > 0 ) {
			$parts[] = $this->category_clause( $filter->category() );
		}

		if ( '' !== $filter->search() ) {
			$parts[] = $this->search_clause( $account, $filter->search() );
		}

		return implode( ' AND ', $parts );
	}

	/**
	 * Builds the «Не связан» condition over the offers the row displays: a variable product matches when **any** published
	 * variation has no live link (no row, or a row still `unlinked`); a simple product, when its own row is missing or `unlinked`.
	 *
	 * @param int $account_id Account.
	 * @return string
	 */
	private function unlinked_clause( int $account_id ): string {
		$children = $this->db->prepare( "SELECT 1 FROM %i AS v WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status IN ('publish', 'private')", $this->db->posts ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
		$live     = function ( string $product ) use ( $account_id ): string {
			return $this->db->prepare( 'EXISTS (SELECT 1 FROM %i AS l WHERE l.account_id = %d AND l.kind = %s AND l.wc_product_id = ' . $product . ' AND l.card_state <> %s)', $this->links_table, $account_id, Link_Record::KIND_OFFER, Link_Record::STATE_UNLINKED ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only a column name of this query is concatenated.
		};

		return '(EXISTS (' . $children . ' AND NOT ' . $live( 'v.ID' ) . ') OR (NOT EXISTS (' . $children . ') AND NOT ' . $live( 'p.ID' ) . '))';
	}

	/**
	 * Builds `EXISTS (an offer row of the product in the account that also satisfies the extra condition)`.
	 *
	 * @param int    $account_id Account.
	 * @param string $extra      Prepared extra condition starting with AND, or empty.
	 * @return string
	 */
	private function member( int $account_id, string $extra ): string {
		return $this->db->prepare( 'EXISTS (SELECT 1 FROM %i AS l WHERE l.account_id = %d AND l.kind = %s AND (l.wc_product_id = p.ID OR l.wc_parent_id = p.ID) ', $this->links_table, $account_id, Link_Record::KIND_OFFER ) . $extra . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Builds the category condition: the category or any of its descendants.
	 *
	 * @param int $term_id Category term id.
	 * @return string
	 */
	private function category_clause( int $term_id ): string {
		$terms = array_values( array_unique( array_map( 'intval', (array) call_user_func( $this->category_tree, $term_id ) ) ) );

		return $this->db->prepare(
			"p.ID IN (SELECT tr.object_id FROM %i AS tr INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (" . implode( ', ', array_fill( 0, count( $terms ), '%d' ) ) . '))', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
			array_merge( [ $this->db->term_relationships, $this->db->term_taxonomy ], $terms )
		);
	}

	/**
	 * Builds the search condition: the title, the product's or a variation's SKU, or the article on the marketplace.
	 *
	 * @param int    $account_id Account.
	 * @param string $text       Search text.
	 * @return string
	 */
	private function search_clause( int $account_id, string $text ): string {
		$like = '%' . $this->db->esc_like( $text ) . '%';

		return '(' . implode(
			' OR ',
			[
				$this->db->prepare( 'p.post_title LIKE %s', $like ),
				$this->db->prepare( "EXISTS (SELECT 1 FROM %i AS pm WHERE pm.post_id = p.ID AND pm.meta_key = '_sku' AND pm.meta_value LIKE %s)", $this->db->postmeta, $like ),
				$this->db->prepare( "EXISTS (SELECT 1 FROM %i AS v INNER JOIN %i AS vm ON vm.post_id = v.ID AND vm.meta_key = '_sku' WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND vm.meta_value LIKE %s)", $this->db->posts, $this->db->postmeta, $like ),
				$this->member( $account_id, $this->db->prepare( 'AND l.offer_id LIKE %s', $like ) ),
			]
		) . ')';
	}

	/**
	 * Full name of WooCommerce's product lookup table.
	 *
	 * @return string
	 */
	private function lookup_table(): string {
		return $this->db->prefix . 'wc_product_meta_lookup';
	}

	/**
	 * Reads titles, SKUs, stock and price of products.
	 *
	 * @param int[] $ids Product ids.
	 * @return array<int,array<string,mixed>> Keyed by id.
	 */
	private function products( array $ids ): array {
		return $this->lookup_rows(
			'SELECT p.ID AS id, p.post_parent AS parent, p.post_title AS title, p.post_status AS status, lk.sku AS sku, lk.stock_quantity AS stock, lk.min_price AS price FROM %i AS p LEFT JOIN %i AS lk ON lk.product_id = p.ID WHERE p.ID IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
			$ids
		);
	}

	/**
	 * Reads the published variations of variable products.
	 *
	 * @param int[] $parent_ids Product ids.
	 * @return array<int,array<string,mixed>> Keyed by variation id, in display order.
	 */
	private function variations( array $parent_ids ): array {
		return $this->lookup_rows(
			"SELECT p.ID AS id, p.post_parent AS parent, p.post_title AS title, p.post_status AS status, lk.sku AS sku, lk.stock_quantity AS stock, lk.min_price AS price FROM %i AS p LEFT JOIN %i AS lk ON lk.product_id = p.ID WHERE p.post_type = 'product_variation' AND p.post_status IN ('publish', 'private') AND p.post_parent IN (" . implode( ', ', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') ORDER BY p.post_parent ASC, p.menu_order ASC, p.ID ASC', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
			$parent_ids
		);
	}

	/**
	 * Reads variations by their own ids, in the order of the ids.
	 *
	 * @param int[] $ids Variation ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function lookup_rows_for( array $ids ): array {
		$found = $this->products( $ids );
		$rows  = [];

		foreach ( $ids as $id ) {
			if ( isset( $found[ $id ] ) ) {
				$rows[ $id ] = $found[ $id ];
			}
		}

		return $rows;
	}

	/**
	 * Runs one of the product queries.
	 *
	 * @param string $sql Statement with `%i` for the posts and the lookup table, then one `%d` per id.
	 * @param int[]  $ids Ids.
	 * @return array<int,array<string,mixed>> Keyed by id, in query order.
	 */
	private function lookup_rows( string $sql, array $ids ): array {
		$found = $this->db->get_results( $this->db->prepare( $sql, array_merge( [ $this->db->posts, $this->lookup_table() ], $ids ) ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.
		$rows  = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$rows[ (int) $row['id'] ] = [
				'id'     => (int) $row['id'],
				'parent' => (int) $row['parent'],
				'title'  => (string) $row['title'],
				'status' => (string) $row['status'],
				'sku'    => (string) $row['sku'],
				'stock'  => null === $row['stock'] ? null : (int) $row['stock'],
				'price'  => null === $row['price'] || '' === $row['price'] ? null : (string) $row['price'],
			];
		}

		return $rows;
	}

	/**
	 * Gives a variation the stock WooCommerce gives it. A variation that does not manage stock itself but sits under a parent that
	 * does has no quantity in the lookup table; WooCommerce answers with the parent's pool. The offer then carries `stock_pool` (the
	 * parent's id), so a sum over the variations counts that pool once.
	 *
	 * @param array<int,array<string,mixed>> $variations Variations by id.
	 * @param array<int,array<string,mixed>> $products   Products by id.
	 * @return array<int,array<string,mixed>>
	 */
	private function with_inherited_stock( array $variations, array $products ): array {
		foreach ( $variations as $id => $variation ) {
			$parent = $products[ $variation['parent'] ] ?? null;

			if ( null !== $variation['stock'] || null === $parent || null === $parent['stock'] ) {
				continue;
			}

			$product  = call_user_func( $this->load, $id );
			$quantity = null === $product ? null : $product->get_stock_quantity();

			if ( null !== $quantity ) {
				$variations[ $id ]['stock']      = (int) $quantity;
				$variations[ $id ]['stock_pool'] = (int) $variation['parent'];
			}
		}

		return $variations;
	}

	/**
	 * Joins products and variations to their links and readbacks.
	 *
	 * @param int                            $account_id Account.
	 * @param array<int,array<string,mixed>> $products   Listed products by id.
	 * @param array<int,array<string,mixed>> $variations Variations by id.
	 * @return array<int,array<string,mixed>> Offers by product id: every variation, and every product that has none.
	 */
	private function offers( int $account_id, array $products, array $variations ): array {
		$parents = array_unique( array_column( $variations, 'parent' ) );
		$simple  = array_diff_key( $products, array_flip( $parents ) );
		$members = $variations + $simple;
		$labels  = $this->variation_labels( array_keys( $variations ) );
		$links   = $this->links( $account_id, array_keys( $members ) );
		$stock   = $this->stock_readbacks( $account_id, array_column( $links, 'link_id' ) );
		$offers  = [];

		foreach ( $members as $id => $member ) {
			$offers[ $id ] = $this->offer( $member, $links[ $id ] ?? null, $stock, $labels[ $id ] ?? '' );
		}

		return $offers;
	}

	/**
	 * Reads the offer rows of the products, keyed by product id.
	 *
	 * @param int   $account_id  Account.
	 * @param int[] $product_ids Product and variation ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function links( int $account_id, array $product_ids ): array {
		$ids   = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
		$found = $this->db->get_results(
			$this->db->prepare(
				'SELECT link_id, wc_product_id, offer_id, card_state, last_error_text, last_pushed_price, warehouse_id, sync_stock, sync_price FROM %i WHERE account_id = %d AND kind = %s AND wc_product_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->links_table, $account_id, Link_Record::KIND_OFFER ], $ids )
			),
			'ARRAY_A'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
		$links = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$links[ (int) $row['wc_product_id'] ] = $row;
		}

		return $links;
	}

	/**
	 * Reads the last stock readback of links.
	 *
	 * @param int   $account_id Account.
	 * @param int[] $link_ids   Link ids.
	 * @return array<int,array{present:?int,reserved:?int,read_at:?string}> Keyed by link id; the newest readback when a link has several warehouses.
	 */
	private function stock_readbacks( int $account_id, array $link_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $link_ids ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$found = $this->db->get_results(
			$this->db->prepare(
				'SELECT link_id, remote_present, remote_reserved, remote_read_at FROM %i WHERE account_id = %d AND kind = %s AND remote_present IS NOT NULL AND link_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY remote_read_at ASC', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->sync_table, $account_id, 'stock' ], $ids )
			),
			'ARRAY_A'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
		$reads = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$reads[ (int) $row['link_id'] ] = [
				'present'  => (int) $row['remote_present'],
				'reserved' => null === $row['remote_reserved'] ? null : (int) $row['remote_reserved'],
				'read_at'  => null === $row['remote_read_at'] ? null : (string) $row['remote_read_at'],
			];
		}

		return $reads;
	}

	/**
	 * Joins one product to its link and readbacks.
	 *
	 * @param array<string,mixed>                                          $product Product or variation.
	 * @param array<string,mixed>|null                                     $link    Its link row, or null.
	 * @param array<int,array{present:?int,reserved:?int,read_at:?string}> $stock   Readbacks by link id.
	 * @param string                                                       $label   Variation label, empty for a simple product.
	 * @return array<string,mixed>
	 */
	private function offer( array $product, ?array $link, array $stock, string $label ): array {
		$link_id = null === $link ? null : (int) $link['link_id'];
		$read    = null === $link_id ? null : ( $stock[ $link_id ] ?? null );
		$state   = null === $link ? '' : (string) $link['card_state'];

		return [
			'product_id'      => (int) $product['id'],
			'label'           => $label,
			'sku'             => (string) $product['sku'],
			'offer_id'        => null === $link ? '' : (string) $link['offer_id'],
			'link_id'         => $link_id,
			'card_state'      => $state,
			'group'           => State_Groups::group_of( $state ),
			'error'           => null === $link ? '' : trim( (string) $link['last_error_text'] ),
			'site_stock'      => $product['stock'],
			'stock_pool'      => $product['stock_pool'] ?? null,
			'remote_stock'    => null === $read ? null : (int) $read['present'] - (int) $read['reserved'],
			'remote_stock_at' => null === $read ? null : $read['read_at'],
			'site_price'      => $product['price'],
			'remote_price'    => null === $link || null === $link['last_pushed_price'] ? null : (string) $link['last_pushed_price'],
			'warehouse_id'    => null === $link || null === $link['warehouse_id'] ? null : (int) $link['warehouse_id'],
			'sync_stock'      => null === $link ? null : 1 === (int) $link['sync_stock'],
			'sync_price'      => null === $link ? null : 1 === (int) $link['sync_price'],
		];
	}

	/**
	 * Names variations by their attribute values: «Красный, M». WooCommerce stores a taxonomy attribute as the term slug and a custom
	 * one as the text; an empty value means «any».
	 *
	 * @param int[] $variation_ids Variation ids.
	 * @return array<int,string> Label by variation id.
	 */
	private function variation_labels( array $variation_ids ): array {
		if ( [] === $variation_ids ) {
			return [];
		}

		$found = $this->db->get_results(
			$this->db->prepare(
				'SELECT post_id, meta_key, meta_value FROM %i WHERE meta_key LIKE %s AND post_id IN (' . implode( ', ', array_fill( 0, count( $variation_ids ), '%d' ) ) . ') ORDER BY meta_id ASC', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->db->postmeta, $this->db->esc_like( 'attribute_' ) . '%' ], $variation_ids )
			),
			'ARRAY_A'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
		$found = is_array( $found ) ? $found : [];
		$names = $this->term_names( $found );
		$parts = [];

		foreach ( $found as $row ) {
			$taxonomy = substr( (string) $row['meta_key'], strlen( 'attribute_' ) );
			$value    = (string) $row['meta_value'];

			$parts[ (int) $row['post_id'] ][] = '' === $value ? __( 'любой', 'woodev-plugin-framework' ) : ( $names[ $taxonomy ][ $value ] ?? $value );
		}

		return array_map(
			static function ( array $values ): string {
				return implode( ', ', $values );
			},
			$parts
		);
	}

	/**
	 * Looks up the names of the terms that taxonomy attributes of variations point at.
	 *
	 * @param array<int,array<string,mixed>> $meta Attribute meta rows.
	 * @return array<string,array<string,string>> Taxonomy => slug => name.
	 */
	private function term_names( array $meta ): array {
		$slugs = [];

		foreach ( $meta as $row ) {
			$taxonomy = substr( (string) $row['meta_key'], strlen( 'attribute_' ) );

			if ( 0 === strpos( $taxonomy, 'pa_' ) && '' !== (string) $row['meta_value'] ) {
				$slugs[ $taxonomy ][ (string) $row['meta_value'] ] = true;
			}
		}

		$names = [];

		foreach ( $slugs as $taxonomy => $values ) {
			$slug_list = array_keys( $values );
			$terms     = $this->db->get_results(
				$this->db->prepare(
					'SELECT t.slug, t.name FROM %i AS t INNER JOIN %i AS tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug IN (' . implode( ', ', array_fill( 0, count( $slug_list ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
					array_merge( [ $this->db->terms, $this->db->term_taxonomy, $taxonomy ], $slug_list )
				),
				'ARRAY_A'
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.

			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$names[ $taxonomy ][ (string) $term['slug'] ] = (string) $term['name'];
			}
		}

		return $names;
	}
}
