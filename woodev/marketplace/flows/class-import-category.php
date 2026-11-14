<?php
/**
 * The shop categories of an imported card.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Finds where an imported card goes in the shop catalogue, and which export profile then governs the product.
 *
 * The card's own category descriptor is looked up backwards through the mappings ({@see Wpdb_Mapping_Store::for_category()}): the
 * mapping's shop categories are the product's. With no mapping, the card's category path is created by name when the merchant
 * allows it (an existing category with the same name and parent is reused). The profile is found afterwards, from the saved
 * product ({@see self::profile_id()}). `wp_insert_term()` has no capability check, so this works in a background job.
 *
 * @since 2.1.6
 */
final class Import_Category {
	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Creates the resolver.
	 *
	 * @since 2.1.6
	 *
	 * @param Wpdb_Mapping_Store $mappings Mappings.
	 */
	public function __construct( Wpdb_Mapping_Store $mappings ) {
		$this->mappings = $mappings;
	}

	/**
	 * Returns the shop categories of a card: the mapping's, else the card's path created by name when allowed.
	 *
	 * @since 2.1.6
	 *
	 * @param Card_Content $content           The card.
	 * @param bool         $create_categories Whether a card with no mapping gets its category path created.
	 * @return int[]
	 */
	public function term_ids( Card_Content $content, bool $create_categories ): array {
		$mapped = $this->mappings->for_category( $content->remote_category() );

		if ( null !== $mapped ) {
			return $mapped['term_ids'];
		}

		return $create_categories ? $this->created( $content->category_path() ) : [];
	}

	/**
	 * Returns the profile that governs a saved product: the one of the mapping its stored categories point at, closest category
	 * first and then their ancestors. This is the lookup the price and every later export make ({@see Link_Context_Resolver}), so
	 * one profile governs the price and the fields of an imported product — also when the categories came from the card's path
	 * under a category the merchant mapped, not from the card's own descriptor.
	 *
	 * @since 2.1.6
	 *
	 * @param \WC_Product $product A product whose categories are saved.
	 * @return int|null Null when no mapping covers the product, or its mapping names no profile.
	 */
	public function profile_id( \WC_Product $product ): ?int {
		foreach ( Category_Ancestry::for_product( $product ) as $term_id ) {
			$mapping_id = $this->mappings->mapping_id_for_term( $term_id );
			$mapping    = null === $mapping_id ? null : $this->mappings->find( $mapping_id );

			if ( null !== $mapping ) {
				return $mapping['profile_id'];
			}
		}

		return null;
	}

	/**
	 * Makes sure a category path exists, root first, and returns its last category.
	 *
	 * @param string[] $names Category names from the root down.
	 * @return int[] The last category, or nothing when the path is empty or a category could not be created.
	 */
	private function created( array $names ): array {
		$parent = 0;

		foreach ( array_filter( array_map( 'trim', $names ), 'strlen' ) as $name ) {
			$parent = $this->term( $name, $parent );

			if ( 0 === $parent ) {
				return [];
			}
		}

		return $parent > 0 ? [ $parent ] : [];
	}

	/**
	 * Returns the product category with a name and a parent, creating it when it does not exist.
	 *
	 * @param string $name   Category name.
	 * @param int    $parent Parent category id, 0 for a top-level one.
	 * @return int Category id, 0 when it could not be created.
	 */
	private function term( string $name, int $parent ): int {
		// `term_exists()` ignores a parent of 0 and would reuse a child category of the same name; `get_terms()` filters on it.
		$found = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'name'       => $name,
				'parent'     => $parent,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
			]
		);

		if ( is_array( $found ) && [] !== $found ) {
			return (int) $found[0];
		}

		$created = wp_insert_term( $name, 'product_cat', [ 'parent' => $parent ] );

		return is_array( $created ) ? (int) $created['term_id'] : 0;
	}
}
