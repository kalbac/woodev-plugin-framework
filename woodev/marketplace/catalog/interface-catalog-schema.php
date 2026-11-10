<?php
/**
 * Catalogue schema contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace's category tree and field definitions in the layer's shape. Schema parsing stays in the adapter.
 *
 * @since 2.0.29
 */
interface Catalog_Schema {
	/**
	 * Lists the categories a product can be placed in.
	 *
	 * @since 2.0.29
	 *
	 * @return array<int,array<string,mixed>> Adapter-defined category descriptors (Ozon: category and type ids; Wildberries: subject id).
	 */
	public function categories(): array;

	/**
	 * Lists the fields of a category.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $remote_category Category descriptor from `categories()`.
	 * @return Field_Definition[]
	 */
	public function fields( array $remote_category ): array;

	/**
	 * Searches the values of a dictionary field.
	 *
	 * @since 2.0.29
	 *
	 * @param Field_Definition $field Dictionary field.
	 * @param string           $term  Search text.
	 * @return array<int,array<string,mixed>> Matching values, each with at least `id` and `value`.
	 */
	public function search_dictionary( Field_Definition $field, string $term ): array;
}
