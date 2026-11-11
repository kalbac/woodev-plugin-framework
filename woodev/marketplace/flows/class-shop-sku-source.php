<?php
/**
 * The shop's products that have an article, for linking by article.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * One query on WooCommerce's product lookup table (`wc_product_meta_lookup`, the table WooCommerce itself searches SKUs in), not
 * a `get_post_meta()` call per product. It returns what can carry a marketplace card: simple products, and the variations of variable
 * products — **never the variable parent**, which stands for no card. A variable product that has no variation yet is returned as a
 * simple one.
 *
 * Scoped to a product category and its children when one is given (the optional category of the linking tool); a variation belongs
 * to the categories of its parent.
 *
 * @since 2.1.0
 */
final class Shop_Sku_Source {
	private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Creates the source.
	 *
	 * @since 2.1.0
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Lists products with a SKU.
	 *
	 * @since 2.1.0
	 *
	 * @param int|null $category_id Product category (`product_cat` term id) whose subtree to read, or null for the whole shop.
	 * @return array<int,array{id:int,parent_id:int,sku:string}> In product id order.
	 */
	public function products( ?int $category_id = null ): array {
		$lookup = $this->db->prefix . 'wc_product_meta_lookup';
		$status = implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$sql    = 'SELECT l.product_id AS id, p.post_parent AS parent_id, l.sku AS sku FROM %i AS l INNER JOIN %i AS p ON p.ID = l.product_id'
			. " WHERE l.sku <> '' AND p.post_status IN ({$status})"
			. " AND ( p.post_type = 'product_variation' OR ( p.post_type = 'product' AND NOT EXISTS ( SELECT 1 FROM %i AS v WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' ) ) )";
		$args   = array_merge( [ $lookup, $this->db->posts ], self::STATUSES, [ $this->db->posts ] );

		if ( null !== $category_id ) {
			$terms = array_merge( [ $category_id ], array_map( 'intval', (array) get_term_children( $category_id, 'product_cat' ) ) );
			$in    = implode( ', ', array_fill( 0, count( $terms ), '%d' ) );
			$sql  .= ' AND CASE WHEN p.post_parent > 0 THEN p.post_parent ELSE p.ID END IN ( SELECT tr.object_id FROM %i AS tr INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
				. " WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ({$in}) )";
			$args  = array_merge( $args, [ $this->db->term_relationships, $this->db->term_taxonomy ], $terms );
		}

		$rows = $this->db->get_results( $this->db->prepare( $sql . ' ORDER BY l.product_id ASC', $args ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Only fixed fragments and placeholders are concatenated.
		$list = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$list[] = [
				'id'        => (int) $row['id'],
				'parent_id' => (int) $row['parent_id'],
				'sku'       => (string) $row['sku'],
			];
		}

		return $list;
	}
}
