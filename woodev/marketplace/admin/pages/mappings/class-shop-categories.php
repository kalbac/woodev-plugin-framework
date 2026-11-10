<?php
/**
 * The shop's product categories, as the mapping editor offers them.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the `product_cat` terms with their path from the top («Clothing › Shoes › Boots»), so two categories of the same name stay
 * distinguishable, and answers whether a posted term id is a product category at all.
 *
 * @since 2.0.30
 */
final class Shop_Categories {
	/**
	 * Separator of the path shown to the merchant.
	 */
	private const SEPARATOR = ' › ';

	/**
	 * Lists every product category, by path.
	 *
	 * @since 2.0.30
	 *
	 * @return array<int,array{id:int,label:string}>
	 */
	public static function all(): array {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$by_id = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$by_id[ (int) $term->term_id ] = $term;
			}
		}

		$list = [];
		foreach ( $by_id as $term_id => $term ) {
			$list[] = [
				'id'    => $term_id,
				'label' => self::path( $term, $by_id ),
			];
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return strcmp( $a['label'], $b['label'] );
			}
		);

		return $list;
	}

	/**
	 * Tells whether a term id is a product category.
	 *
	 * @since 2.0.30
	 *
	 * @param int $term_id Term id.
	 * @return bool
	 */
	public static function exists( int $term_id ): bool {
		return get_term( $term_id, 'product_cat' ) instanceof \WP_Term;
	}

	/**
	 * Returns the name of a category, or its id when it is gone.
	 *
	 * @since 2.0.30
	 *
	 * @param int $term_id Term id.
	 * @return string
	 */
	public static function name_of( int $term_id ): string {
		$term = get_term( $term_id, 'product_cat' );

		return $term instanceof \WP_Term ? (string) $term->name : (string) $term_id;
	}

	/**
	 * Builds the path of a term from its ancestors in the list.
	 *
	 * @param \WP_Term            $term  Term.
	 * @param array<int,\WP_Term> $by_id Every term by id.
	 * @return string
	 */
	private static function path( \WP_Term $term, array $by_id ): string {
		$names = [ (string) $term->name ];
		$seen  = [ (int) $term->term_id => true ];
		$step  = $term;

		while ( (int) $step->parent > 0 && isset( $by_id[ (int) $step->parent ] ) && ! isset( $seen[ (int) $step->parent ] ) ) {
			$step                     = $by_id[ (int) $step->parent ];
			$seen[ (int) $step->term_id ] = true;
			array_unshift( $names, (string) $step->name );
		}

		return implode( self::SEPARATOR, $names );
	}
}
