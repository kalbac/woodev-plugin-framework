<?php
/**
 * The shop's products a unit card may be found among.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what {@see Unit_Matcher} compares: of every product that can carry a card (simple products and variations, never a
 * variable parent) its SKU, its barcodes — WooCommerce's GTIN field and the custom fields the export profiles read the barcode
 * from — and the unit the importer is making it for. Plain SQL over the SKU lookup table and the post meta, not one
 * `get_post_meta()` call per product. A product with none of the three is not returned: no key could find it.
 *
 * {@see self::matching()} reads only the products some value of some card could find, so an import does not load the whole shop.
 *
 * @since 2.1.22
 */
final class Unit_Product_Source {
	private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];
	private const GTIN_KEY = '_global_unique_id';

	/**
	 * Most values one statement compares.
	 */
	private const CHUNK = 500;

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Export profiles: they name the custom field of the barcode.
	 *
	 * @var Wpdb_Profile_Store
	 */
	private $profiles;

	/**
	 * Meta key that names the unit an unfinished import is making a product for.
	 *
	 * @var string
	 */
	private $pending_key;

	/**
	 * Creates the source.
	 *
	 * @since 2.1.22
	 *
	 * @param \wpdb              $db             Database.
	 * @param Wpdb_Profile_Store $profiles       Export profiles.
	 * @param string             $marketplace_id The adapter's id.
	 */
	public function __construct( \wpdb $db, Wpdb_Profile_Store $profiles, string $marketplace_id ) {
		$this->db          = $db;
		$this->profiles    = $profiles;
		$this->pending_key = Unit_Products::pending_key( $marketplace_id );
	}

	/**
	 * Lists the whole shop, or one category with its subcategories.
	 *
	 * @since 2.1.22
	 *
	 * @param int|null $category_id Product category (`product_cat` term id), or null for the whole shop.
	 * @param int      $account_id  Account the cards belong to.
	 * @return array<int,array{id:int,parent_id:int,sku:string,barcodes:string[],importing:string}> In product id order.
	 */
	public function products( ?int $category_id, int $account_id ): array {
		return $this->read( $account_id, $category_id, null );
	}

	/**
	 * Lists the products that carry one of the values as a SKU or a barcode, or that are being imported for one of the units.
	 *
	 * @since 2.1.22
	 *
	 * @param int      $account_id Account.
	 * @param string[] $values     Values of the cards' match keys.
	 * @param string[] $offer_ids  Unit keys of the cards.
	 * @return array<int,array{id:int,parent_id:int,sku:string,barcodes:string[],importing:string}>
	 */
	public function matching( int $account_id, array $values, array $offer_ids ): array {
		$ids  = [];
		$keys = $this->barcode_keys();

		foreach ( array_chunk( array_values( array_unique( array_filter( $values, 'strlen' ) ) ), self::CHUNK ) as $chunk ) {
			$ids = array_merge(
				$ids,
				$this->column( 'SELECT product_id FROM %i WHERE sku IN (' . self::holders( $chunk ) . ')', array_merge( [ $this->db->prefix . 'wc_product_meta_lookup' ], $chunk ) ),
				$this->column( 'SELECT post_id FROM %i WHERE meta_key IN (' . self::holders( $keys ) . ') AND meta_value IN (' . self::holders( $chunk ) . ')', array_merge( [ $this->db->postmeta ], $keys, $chunk ) )
			);
		}

		$pending = [];
		foreach ( $offer_ids as $offer_id ) {
			$pending[] = Unit_Products::pending_value( $account_id, $offer_id );
		}

		foreach ( array_chunk( $pending, self::CHUNK ) as $chunk ) {
			$ids = array_merge( $ids, $this->column( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value IN (' . self::holders( $chunk ) . ')', array_merge( [ $this->db->postmeta, $this->pending_key ], $chunk ) ) );
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return [] === $ids ? [] : $this->read( $account_id, null, $ids );
	}

	/**
	 * Reads products with what is compared.
	 *
	 * @param int        $account_id  Account.
	 * @param int|null   $category_id Category subtree, or null.
	 * @param int[]|null $ids         Only these products, or null for all.
	 * @return array<int,array{id:int,parent_id:int,sku:string,barcodes:string[],importing:string}>
	 */
	private function read( int $account_id, ?int $category_id, ?array $ids ): array {
		$sql  = 'SELECT p.ID AS id, p.post_parent AS parent_id, l.sku AS sku FROM %i AS p LEFT JOIN %i AS l ON l.product_id = p.ID'
			. ' WHERE p.post_status IN (' . self::holders( self::STATUSES ) . ')'
			. " AND ( p.post_type = 'product_variation' OR ( p.post_type = 'product' AND NOT EXISTS ( SELECT 1 FROM %i AS v WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' ) ) )";
		$args = array_merge( [ $this->db->posts, $this->db->prefix . 'wc_product_meta_lookup' ], self::STATUSES, [ $this->db->posts ] );

		if ( null !== $category_id ) {
			$terms = array_merge( [ $category_id ], array_map( 'intval', (array) get_term_children( $category_id, 'product_cat' ) ) );
			$sql  .= ' AND CASE WHEN p.post_parent > 0 THEN p.post_parent ELSE p.ID END IN ( SELECT tr.object_id FROM %i AS tr INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
				. " WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (" . implode( ', ', array_fill( 0, count( $terms ), '%d' ) ) . ') )';
			$args  = array_merge( $args, [ $this->db->term_relationships, $this->db->term_taxonomy ], $terms );
		}

		if ( null !== $ids ) {
			$sql .= ' AND p.ID IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$args = array_merge( $args, $ids );
		}

		$rows = $this->rows( $sql . ' ORDER BY p.ID ASC', $args );
		$meta = $this->meta( null === $ids && null === $category_id ? null : array_map( 'intval', array_column( $rows, 'id' ) ) );
		$list = [];

		foreach ( $rows as $row ) {
			$id        = (int) $row['id'];
			$sku       = (string) $row['sku'];
			$barcodes  = $meta[ $id ]['barcodes'] ?? [];
			$importing = $this->importing( $meta[ $id ]['pending'] ?? '', $account_id );

			if ( '' === $sku && [] === $barcodes && '' === $importing ) {
				continue;
			}

			$list[] = [
				'id'        => $id,
				'parent_id' => (int) $row['parent_id'],
				'sku'       => $sku,
				'barcodes'  => $barcodes,
				'importing' => $importing,
			];
		}

		return $list;
	}

	/**
	 * Reads the barcodes and the pending-import mark of products.
	 *
	 * @param int[]|null $ids Products, or null for all of them.
	 * @return array<int,array{barcodes:string[],pending:string}>
	 */
	private function meta( ?array $ids ): array {
		$keys   = array_merge( $this->barcode_keys(), [ $this->pending_key ] );
		$sql    = 'SELECT post_id, meta_key, meta_value FROM %i WHERE meta_key IN (' . self::holders( $keys ) . ") AND meta_value <> ''";
		$chunks = null === $ids ? [ null ] : array_chunk( $ids, self::CHUNK );
		$meta   = [];

		foreach ( $chunks as $chunk ) {
			$in   = null === $chunk ? '' : ' AND post_id IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ')';
			$rows = $this->rows( $sql . $in, array_merge( [ $this->db->postmeta ], $keys, (array) $chunk ) );

			foreach ( $rows as $row ) {
				$id          = (int) $row['post_id'];
				$meta[ $id ] = $meta[ $id ] ?? [
					'barcodes' => [],
					'pending'  => '',
				];

				if ( $this->pending_key === $row['meta_key'] ) {
					$meta[ $id ]['pending'] = (string) $row['meta_value'];
				} else {
					$meta[ $id ]['barcodes'][] = (string) $row['meta_value'];
				}
			}
		}

		return $meta;
	}

	/**
	 * Returns the unit an unfinished import makes a product for: the unit key for this account, the whole mark for another one,
	 * so that it never equals a card of this account.
	 *
	 * @param string $mark       Stored mark, `{account}:{unit key}`, or ''.
	 * @param int    $account_id Account.
	 * @return string
	 */
	private function importing( string $mark, int $account_id ): string {
		$own = Unit_Products::pending_value( $account_id, '' );

		return 0 === strpos( $mark, $own ) ? substr( $mark, strlen( $own ) ) : $mark;
	}

	/**
	 * Names the meta keys a product's barcode is read from.
	 *
	 * @return string[]
	 */
	private function barcode_keys(): array {
		$keys = [ self::GTIN_KEY ];

		foreach ( $this->profiles->all() as $profile ) {
			$source = (string) $profile->setting( 'barcode_source', '' );

			if ( 0 === strpos( $source, 'meta:' ) && '' !== substr( $source, 5 ) ) {
				$keys[] = substr( $source, 5 );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Builds the placeholders of a list of texts.
	 *
	 * @param string[] $values Values.
	 * @return string
	 */
	private static function holders( array $values ): string {
		return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
	}

	/**
	 * Runs a prepared read of rows.
	 *
	 * @param string           $sql  Statement of fixed fragments and placeholders.
	 * @param array<int,mixed> $args Arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( string $sql, array $args ): array {
		$rows = $this->db->get_results( $this->db->prepare( $sql, $args ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Only fixed fragments and placeholders are concatenated.

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Runs a prepared read of one column.
	 *
	 * @param string           $sql  Statement of fixed fragments and placeholders.
	 * @param array<int,mixed> $args Arguments.
	 * @return array<int,mixed>
	 */
	private function column( string $sql, array $args ): array {
		return (array) $this->db->get_col( $this->db->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Only fixed fragments and placeholders are concatenated.
	}
}
