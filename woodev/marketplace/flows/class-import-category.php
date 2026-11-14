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
 * Finds where an imported card goes in the shop catalogue, and which export profile it then carries.
 *
 * The card's own category descriptor is looked up backwards through the mappings ({@see Wpdb_Mapping_Store::for_category()}): the
 * mapping's shop categories are the product's, and the mapping's profile says where each field of the card is written. With no
 * mapping, the card's category path is created by name when the merchant allows it (an existing category with the same name and
 * parent is reused), and no profile applies. `wp_insert_term()` has no capability check, so this works in a background job.
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
	 * Returns the shop categories and the profile of a card.
	 *
	 * @since 2.1.6
	 *
	 * @param Card_Content $content           The card.
	 * @param bool         $create_categories Whether a card with no mapping gets its category path created.
	 * @return array{term_ids:int[],profile_id:int|null} The profile is that of the card's mapping, null when it has none.
	 */
	public function resolve( Card_Content $content, bool $create_categories ): array {
		$mapped = $this->mappings->for_category( $content->remote_category() );

		if ( null !== $mapped ) {
			return [
				'term_ids'   => $mapped['term_ids'],
				'profile_id' => $mapped['mapping']['profile_id'],
			];
		}

		return [
			'term_ids'   => $create_categories ? $this->created( $content->category_path() ) : [],
			'profile_id' => null,
		];
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
