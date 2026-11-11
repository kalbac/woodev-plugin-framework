<?php
/**
 * Resolves the complete export context of a linked product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;

defined( 'ABSPATH' ) || exit;

/**
 * What the validator and the card builder need to know about one product: its remote category, and the value of each
 * attribute. Three layers decide, most specific first (`attribute_overrides`):
 *
 * 1. the product's own link row (a variation's own overrides),
 * 2. its parent row (a variable product's overrides),
 * 3. the mapping: the link's explicit `mapping_id`, else the parent row's, else the mapping of the product's categories.
 *
 * The result is an **effective mapping**: the mapping's own sources with each override laid over them as a fixed source. A product
 * migrated with a category, type and attribute values and no mapping instance therefore validates and builds from its
 * own link row alone.
 *
 * @since 2.1.0
 */
final class Link_Context_Resolver {
	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Creates the resolver.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Link_Store    $links    Links.
	 * @param Wpdb_Mapping_Store $mappings Mappings.
	 */
	public function __construct( Wpdb_Link_Store $links, Wpdb_Mapping_Store $mappings ) {
		$this->links    = $links;
		$this->mappings = $mappings;
	}

	/**
	 * Builds the effective mapping of a product for an account.
	 *
	 * @since 2.1.0
	 *
	 * @param int   $account_id    Account id.
	 * @param int   $wc_product_id Product or variation id.
	 * @param int[] $term_ids      The product's category term ids, closest first (ancestors after their children).
	 * @param int   $wc_parent_id  WooCommerce parent of a variation, for one that has no row yet; 0 otherwise.
	 * @return Mapping|null Null when no layer names a mapping.
	 */
	public function effective_mapping( int $account_id, int $wc_product_id, array $term_ids, int $wc_parent_id = 0 ): ?Mapping {
		list( $own, $parent_row ) = $this->rows( $account_id, $wc_product_id, $wc_parent_id );

		$mapping_id = $this->mapping_id( $own, $parent_row, $term_ids );
		$base       = null === $mapping_id ? null : $this->mappings->get( $mapping_id );

		if ( null === $base ) {
			return null;
		}

		$sources = $base->sources();

		foreach ( $this->override_layers( $own, $parent_row ) as $attribute_id => $entries ) {
			$sources[ (string) $attribute_id ] = $this->fixed_source( $entries );
		}

		return new Mapping( $base->id(), $base->remote_category(), $base->profile_id(), $sources );
	}

	/**
	 * Returns every value an override gives: `attribute_id => [ { dictionary_value_id, value } ]`, variation over parent.
	 *
	 * @since 2.1.0
	 *
	 * @param int $account_id    Account id.
	 * @param int $wc_product_id Product or variation id.
	 * @param int $wc_parent_id  WooCommerce parent of a variation, for one that has no row yet; 0 otherwise.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function override_values( int $account_id, int $wc_product_id, int $wc_parent_id = 0 ): array {
		list( $own, $parent_row ) = $this->rows( $account_id, $wc_product_id, $wc_parent_id );

		return $this->override_layers( $own, $parent_row );
	}

	/**
	 * Returns the id of the mapping a product uses for an account: its row's, its parent row's, then its categories'.
	 *
	 * @since 2.1.0
	 *
	 * @param int   $account_id    Account id.
	 * @param int   $wc_product_id Product or variation id.
	 * @param int[] $term_ids      The product's category term ids, closest first (ancestors after their children).
	 * @return int|null Null when no layer names a mapping.
	 */
	public function mapping_id_for( int $account_id, int $wc_product_id, array $term_ids ): ?int {
		list( $own, $parent_row ) = $this->rows( $account_id, $wc_product_id );

		return $this->mapping_id( $own, $parent_row, $term_ids );
	}

	/**
	 * Loads the product's own row and its parent row. A variation seen for the first time has no row of its own yet; its WooCommerce
	 * parent id still finds the parent's configuration row.
	 *
	 * @param int $account_id    Account id.
	 * @param int $wc_product_id Product or variation id.
	 * @param int $wc_parent_id  WooCommerce parent when the caller knows it; 0 otherwise.
	 * @return array{0:Link_Record|null,1:Link_Record|null}
	 */
	private function rows( int $account_id, int $wc_product_id, int $wc_parent_id = 0 ): array {
		$own        = $this->links->find( $account_id, $wc_product_id );
		$parent_id  = null !== $own ? $own->wc_parent_id() : $wc_parent_id;
		$parent_row = $parent_id > 0 ? $this->links->find( $account_id, $parent_id ) : null;

		return [ $own, $parent_row ];
	}

	/**
	 * Picks the mapping id: the row's own, the parent's, then the one the product's categories point at.
	 *
	 * @param Link_Record|null $own      The product's row.
	 * @param Link_Record|null $parent_row   The parent row.
	 * @param int[]            $term_ids Category term ids.
	 * @return int|null
	 */
	private function mapping_id( ?Link_Record $own, ?Link_Record $parent_row, array $term_ids ): ?int {
		$explicit = null !== $own ? $own->mapping_id() : null;
		$explicit = $explicit ?? ( null !== $parent_row ? $parent_row->mapping_id() : null );

		if ( null !== $explicit ) {
			return $explicit;
		}

		foreach ( $term_ids as $term_id ) {
			$found = $this->mappings->mapping_id_for_term( (int) $term_id );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Merges the override layers: the parent's first, the product's own laid over it, attribute by attribute.
	 *
	 * @param Link_Record|null $own    The product's row.
	 * @param Link_Record|null $parent_row The parent row.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function override_layers( ?Link_Record $own, ?Link_Record $parent_row ): array {
		$merged = [];

		foreach ( [ $parent_row, $own ] as $row ) {
			foreach ( null === $row ? [] : (array) $row->attribute_overrides() as $attribute_id => $entries ) {
				$merged[ (string) $attribute_id ] = (array) $entries;
			}
		}

		return $merged;
	}

	/**
	 * Turns override entries into a fixed source holding the first entry (the rest travel through {@see self::override_values()}).
	 *
	 * @param array<int,array<string,mixed>> $entries Override entries.
	 * @return Source
	 */
	private function fixed_source( array $entries ): Source {
		$first         = (array) reset( $entries );
		$dictionary_id = isset( $first['dictionary_value_id'] ) && 0 !== (int) $first['dictionary_value_id'] ? (string) $first['dictionary_value_id'] : null;

		return new Source( Source::FIXED, (string) ( $first['value'] ?? '' ), '', $dictionary_id );
	}
}
