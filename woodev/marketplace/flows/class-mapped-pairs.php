<?php
/**
 * The category descriptors that mappings use.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the distinct category descriptors of the `mappings` table (`Mapping::remote_category()`, whatever shape the adapter gave
 * them: one identifier, a category/type pair, …). Only these get their attributes cached by the catalogue refresh: the marketplace's
 * tree holds thousands of categories, a shop maps a handful.
 *
 * The store never looks inside a descriptor; two descriptors are the same when they give the same cache key
 * ({@see Wpdb_Catalog_Cache::attributes_key()}), and a mapping without a category yet (empty descriptor) is left out.
 *
 * @since 2.0.30
 */
final class Mapped_Pairs {
	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the `mappings` table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Creates the reader.
	 *
	 * @since 2.0.30
	 *
	 * @param \wpdb  $db    Database.
	 * @param string $table Full name of the `mappings` table.
	 */
	public function __construct( \wpdb $db, string $table ) {
		$this->db    = $db;
		$this->table = $table;
	}

	/**
	 * Lists the descriptors, each once, in the order of their cache keys.
	 *
	 * @since 2.0.30
	 *
	 * @return array<int,array<string,int|string>> Category descriptors.
	 */
	public function all(): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare( "SELECT DISTINCT category FROM %i WHERE category IS NOT NULL AND category <> ''", $this->table ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
			'ARRAY_A'
		);

		$by_key = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$descriptor = json_decode( (string) $row['category'], true );

			if ( is_array( $descriptor ) && [] !== $descriptor ) {
				$by_key[ Wpdb_Catalog_Cache::attributes_key( $descriptor ) ] = $descriptor;
			}
		}

		ksort( $by_key );

		return array_values( $by_key );
	}
}
