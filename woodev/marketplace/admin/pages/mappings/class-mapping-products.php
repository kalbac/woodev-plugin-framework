<?php
/**
 * The shop products a mapping covers.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * A mapping covers the products in its categories and in their children («a parent mapping covers children»), except the ones a
 * nearer mapping on a child category takes. The lookup is one tax query on the product categories; a product in the categories of
 * two unrelated mappings is counted for both, which only matters to the count shown in the list.
 *
 * @since 2.0.30
 */
final class Mapping_Products {
	private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

	/**
	 * Gives the shop categories of every mapping: `function (): array<int,int[]>` (`Wpdb_Mapping_Store::terms_by_mapping()`).
	 *
	 * @var callable
	 */
	private $terms_by_mapping;

	/**
	 * Creates the lookup.
	 *
	 * @since 2.0.30
	 *
	 * @param callable $terms_by_mapping Gives the shop categories of every mapping: `function (): array<int,int[]>`.
	 */
	public function __construct( callable $terms_by_mapping ) {
		$this->terms_by_mapping = $terms_by_mapping;
	}

	/**
	 * Counts the products of a mapping.
	 *
	 * @since 2.0.30
	 *
	 * @param int $mapping_id Mapping id.
	 * @return int
	 */
	public function count( int $mapping_id ): int {
		$args = $this->query_args( $mapping_id, 1 );

		if ( null === $args ) {
			return 0;
		}

		$args['no_found_rows'] = false;

		return (int) ( new \WP_Query( $args ) )->found_posts;
	}

	/**
	 * Lists product ids of a mapping.
	 *
	 * @since 2.0.30
	 *
	 * @param int $mapping_id Mapping id.
	 * @param int $limit      Most ids to return.
	 * @param int $offset     Products to skip, in id order.
	 * @return int[]
	 */
	public function ids( int $mapping_id, int $limit, int $offset = 0 ): array {
		$args = $this->query_args( $mapping_id, $limit );

		if ( null === $args ) {
			return [];
		}

		$args['offset'] = max( 0, $offset );

		return array_map( 'intval', (array) ( new \WP_Query( $args ) )->posts );
	}

	/**
	 * Builds the query of a mapping's products.
	 *
	 * @param int $mapping_id Mapping id.
	 * @param int $limit      Page size.
	 * @return array<string,mixed>|null Null when the mapping has no categories.
	 */
	private function query_args( int $mapping_id, int $limit ): ?array {
		$by_mapping = (array) call_user_func( $this->terms_by_mapping );
		$own        = $by_mapping[ $mapping_id ] ?? [];

		if ( [] === $own ) {
			return null;
		}

		$others   = self::other_terms( $by_mapping, $mapping_id );
		$branches = [];
		foreach ( $own as $term_id ) {
			$branches[] = self::branch( $term_id, $others );
		}

		// One branch per owned category: a nearer mapping inside one branch must not take away what another owned category holds.
		$tax_query = 1 === count( $branches ) ? $branches[0] : array_merge( [ 'relation' => 'OR' ], $branches );

		return [
			'post_type'              => 'product',
			'post_status'            => self::STATUSES,
			'fields'                 => 'ids',
			'posts_per_page'         => $limit,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The only way to ask for a category set.
		];
	}

	/**
	 * Builds the clause of one owned category: its subtree, minus the subtrees of other mappings' categories inside it. A product in
	 * such a category is nearer to the other mapping, because a category's own mapping comes before an ancestor's.
	 * Categories of an unrelated mapping are left alone: a product in both is resolved to either one, so it stays in both counts.
	 *
	 * @param int   $term_id Owned category.
	 * @param int[] $others  Categories of the other mappings.
	 * @return array<mixed>
	 */
	private static function branch( int $term_id, array $others ): array {
		$clause = [
			'relation' => 'AND',
			[
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => [ $term_id ],
				'include_children' => true,
				'operator'         => 'IN',
			],
		];

		$nearer = array_values( array_intersect( $others, array_map( 'intval', (array) get_term_children( $term_id, 'product_cat' ) ) ) );

		if ( [] !== $nearer ) {
			$clause[] = [
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $nearer,
				'include_children' => true,
				'operator'         => 'NOT IN',
			];
		}

		return $clause;
	}

	/**
	 * Lists the categories of every other mapping.
	 *
	 * @param array<int,int[]> $by_mapping All mappings' categories.
	 * @param int              $mapping_id This mapping.
	 * @return int[]
	 */
	private static function other_terms( array $by_mapping, int $mapping_id ): array {
		$others = [];
		foreach ( $by_mapping as $other_id => $term_ids ) {
			if ( $other_id !== $mapping_id ) {
				$others = array_merge( $others, $term_ids );
			}
		}

		return array_values( array_unique( $others ) );
	}
}
