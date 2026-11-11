<?php
/**
 * The order in which a product's categories are tried against the mappings.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * A product finds its mapping by walking its own categories, then their ancestors, so a mapping on a parent
 * category covers every child (the «thousands of one-product leaf categories» case). The walk is closest first: all direct
 * categories, then all their parents, then all grandparents.
 *
 * {@see Link_Context_Resolver} takes this list as its `$term_ids`.
 *
 * @since 2.1.0
 */
final class Category_Ancestry {
	/**
	 * Guard against a cycle in a broken category tree.
	 */
	private const MAX_DEPTH = 50;

	/**
	 * Expands direct categories with their ancestors, closest first, each id once.
	 *
	 * @since 2.1.0
	 *
	 * @param int[]    $direct_ids Direct category term ids of a product.
	 * @param callable $parent_of  `function ( int $term_id ): int`; the parent term id, 0 for a top-level category.
	 * @return int[]
	 */
	public static function expand( array $direct_ids, callable $parent_of ): array {
		$ordered = [];
		$level   = array_values( array_unique( array_filter( array_map( 'intval', $direct_ids ) ) ) );

		for ( $depth = 0; $depth < self::MAX_DEPTH && [] !== $level; $depth++ ) {
			$next = [];

			foreach ( $level as $term_id ) {
				if ( in_array( $term_id, $ordered, true ) ) {
					continue;
				}

				$ordered[] = $term_id;
				$parent    = (int) call_user_func( $parent_of, $term_id );

				if ( $parent > 0 ) {
					$next[] = $parent;
				}
			}

			$level = array_values( array_unique( $next ) );
		}

		return $ordered;
	}

	/**
	 * Returns the ordered category list of a WooCommerce product; a variation uses its parent's categories.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return int[]
	 */
	public static function for_product( \WC_Product $product ): array {
		$source = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
		$direct = wc_get_product_term_ids( $source, 'product_cat' );

		return self::expand(
			$direct,
			static function ( int $term_id ): int {
				$term = get_term( $term_id, 'product_cat' );

				return $term instanceof \WP_Term ? (int) $term->parent : 0;
			}
		);
	}
}
