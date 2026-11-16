<?php
/**
 * The shop's product categories, as the mapping editor offers them.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the `product_cat` terms with their path from the top («Clothing › Shoes › Boots»), so two categories of the
 * same name stay distinguishable, and answers whether a posted term id is a product category at all.
 *
 * @since 2.1.0
 */
final class Shop_Categories {
	/**
	 * Separator of the path shown to the merchant.
	 */
	private const SEPARATOR = ' › ';

	/**
	 * Categories per page of {@see self::page()}.
	 */
	private const PAGE = 30;

	/**
	 * Lists every product category, by path.
	 *
	 * @since 2.1.0
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
	 * One page of the product categories whose name matches what the merchant typed, by name, each with its parents. For a
	 * searchable select that must not load every category of a shop with thousands of them. The cursor is the offset of the next page.
	 *
	 * @since 2.1.8
	 *
	 * @param string $search Typed text, '' for every category.
	 * @param string $cursor The `next_cursor` of the previous page, '' for the first.
	 * @return array{options:array<int,array{value:string,label:string}>,next_cursor:string|null}
	 */
	public static function page( string $search, string $cursor ): array {
		$offset = ctype_digit( $cursor ) ? (int) $cursor : 0;
		$terms  = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'search'     => $search,
				'number'     => self::PAGE + 1,
				'offset'     => $offset,
			]
		);
		$terms  = is_array( $terms ) ? $terms : [];
		$list   = [];

		foreach ( array_slice( $terms, 0, self::PAGE ) as $term ) {
			$names = [];

			foreach ( array_reverse( (array) get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) ) as $parent_id ) {
				$names[] = self::name_of( (int) $parent_id );
			}

			$names[] = (string) $term->name;
			$list[]  = [
				'value' => (string) $term->term_id,
				'label' => html_entity_decode( implode( self::SEPARATOR, $names ), ENT_QUOTES, 'UTF-8' ),
			];
		}

		return [
			'options'     => $list,
			'next_cursor' => count( $terms ) > self::PAGE ? (string) ( $offset + self::PAGE ) : null,
		];
	}

	/**
	 * Tells whether a term id is a product category.
	 *
	 * @since 2.1.0
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
	 * @since 2.1.0
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

		while ( self::has_unseen_parent( $step, $by_id, $seen ) ) {
			$step                     = $by_id[ (int) $step->parent ];
			$seen[ (int) $step->term_id ] = true;
			array_unshift( $names, (string) $step->name );
		}

		return implode( self::SEPARATOR, $names );
	}

	/**
	 * Tells whether the term has a parent in the list that the walk up has not been through yet.
	 *
	 * @param \WP_Term            $term  Term being walked from.
	 * @param array<int,\WP_Term> $by_id Every term by id.
	 * @param array<int,bool>     $seen  Term ids already on the path (a loop guard).
	 * @return bool
	 */
	private static function has_unseen_parent( \WP_Term $term, array $by_id, array $seen ): bool {
		$parent = (int) $term->parent;

		return $parent > 0 && isset( $by_id[ $parent ] ) && ! isset( $seen[ $parent ] );
	}
}
