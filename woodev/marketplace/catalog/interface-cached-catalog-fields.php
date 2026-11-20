<?php
/**
 * Cached field definitions contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: a {@see Catalog_Schema} that implements it can say which fields of a category it already holds, without a request to the
 * marketplace. The mappings list reads it to show «N из M» in its «Атрибуты» column on page load, where a request per mapping would
 * be a burst of calls; a schema that does not implement it, or has not cached the category yet, leaves the column a dash and the
 * editor still loads the fields as before.
 *
 * @since 2.1.13
 */
interface Cached_Catalog_Fields {
	/**
	 * Returns the fields of a category from the schema's own cache only. It never reaches the marketplace and never writes the cache.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $remote_category Category descriptor from {@see Catalog_Schema::categories()}.
	 * @return Field_Definition[]|null Null when the category is not cached (an empty list is a cached category with no fields).
	 */
	public function cached_fields( array $remote_category ): ?array;
}
