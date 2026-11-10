<?php
/**
 * Category mappings in the `mappings` and `mapping_terms` tables.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes mappings. Mappings are account-agnostic: a marketplace's categories, types and attribute ids are global.
 *
 * A mapping keeps the adapter's category descriptor (`Mapping::remote_category()`) as one JSON value: the store never looks inside
 * it, so a marketplace whose categories are one id and one whose categories are two ids are stored the same way.
 *
 * Two kinds share the table: a **term-bound** mapping (`wc_product_id` NULL; WooCommerce categories point at it through
 * `mapping_terms`) and a **product-scoped** one (`wc_product_id` set; it exists for exactly one product, the destination of a v1
 * product configured directly with a category).
 */
final class Wpdb_Mapping_Store {
	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Mappings table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Term-to-mapping table.
	 *
	 * @var string
	 */
	private $terms_table;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the store.
	 *
	 * @param \wpdb         $db          Database.
	 * @param string        $table       Full name of the mappings table.
	 * @param string        $terms_table Full name of the mapping-terms table.
	 * @param callable|null $clock       Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, string $terms_table, ?callable $clock = null ) {
		$this->db          = $db;
		$this->table       = $table;
		$this->terms_table = $terms_table;
		$this->clock       = $clock ?? 'time';
	}

	/**
	 * Inserts a mapping with a given id unless that id exists (the migration keeps the v1 instance id).
	 *
	 * @param int                 $mapping_id    Mapping id.
	 * @param string              $name          Merchant label.
	 * @param int|null            $wc_product_id Product for a product-scoped mapping, null for a term-bound one.
	 * @param array<string,mixed> $category      The adapter's category descriptor, empty when unknown.
	 * @param int|null            $profile_id    Profile id.
	 * @param array<string,mixed> $attributes    Attribute sources (`attribute_id => { source, value, dictionary_value_id, wc_key }`).
	 * @return bool True when a row was inserted, false when the id already existed.
	 * @throws Persistence_Exception When the database refuses the insert.
	 */
	public function insert_if_absent( int $mapping_id, string $name, ?int $wc_product_id, array $category, ?int $profile_id, array $attributes ): bool {
		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
			$this->db->prepare(
				'INSERT IGNORE INTO %i (mapping_id, name, wc_product_id, category, profile_id, attributes, updated_at) VALUES (%d, %s, ' . $this->nullable( $wc_product_id ) . ', %s, ' . $this->nullable( $profile_id ) . ', %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed fragments only.
				$this->bind( $this->table, $mapping_id, $name, $wc_product_id, $category, $profile_id, $attributes )
			)
		);

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'mapping ' . $mapping_id, (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return 1 === $changed;
	}

	/**
	 * Creates a mapping with a generated id.
	 *
	 * @param string              $name          Merchant label.
	 * @param int|null            $wc_product_id Product for a product-scoped mapping, null for a term-bound one.
	 * @param array<string,mixed> $category      The adapter's category descriptor.
	 * @param int|null            $profile_id    Profile id.
	 * @param array<string,mixed> $attributes    Attribute sources.
	 * @return int New mapping id.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function create( string $name, ?int $wc_product_id, array $category, ?int $profile_id, array $attributes ): int {
		$ok = $this->db->insert(
			$this->table,
			[
				'name'          => $name,
				'wc_product_id' => $wc_product_id,
				'category'      => $this->encode( $category ),
				'profile_id'    => $profile_id,
				'attributes'    => $this->encode( $attributes ),
				'updated_at'    => gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) ),
			]
		);

		if ( false === $ok ) {
			throw new \RuntimeException( 'The mapping could not be saved.' );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Points a WooCommerce category at a mapping unless the category already belongs to one (a category is in at most one mapping).
	 *
	 * @param int $term_id    Product category term id.
	 * @param int $mapping_id Mapping id.
	 * @return bool True when the link was created, false when the category already belonged to a mapping.
	 * @throws Persistence_Exception When the database refuses the insert.
	 */
	public function link_term_if_absent( int $term_id, int $mapping_id ): bool {
		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
			$this->db->prepare( 'INSERT IGNORE INTO %i (term_id, mapping_id) VALUES (%d, %d)', $this->terms_table, $term_id, $mapping_id )
		);

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'category ' . $term_id . ' of mapping ' . $mapping_id, (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return 1 === $changed;
	}

	/**
	 * Returns the mapping a category points at.
	 *
	 * @param int $term_id Product category term id.
	 * @return int|null
	 */
	public function mapping_id_for_term( int $term_id ): ?int {
		$found = $this->db->get_var( $this->db->prepare( 'SELECT mapping_id FROM %i WHERE term_id = %d', $this->terms_table, $term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		return null === $found ? null : (int) $found;
	}

	/**
	 * Lists the term-bound mappings (the ones the «Сопоставление» page shows), by name.
	 *
	 * @return array<int,array{mapping_id:int,name:string,wc_product_id:int|null,category:array<string,mixed>,profile_id:int|null,attributes:array<string,mixed>}>
	 */
	public function all_term_bound(): array {
		$ids = $this->db->get_results( $this->db->prepare( 'SELECT mapping_id FROM %i WHERE wc_product_id IS NULL ORDER BY name ASC, mapping_id ASC', $this->table ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		$rows = [];
		foreach ( is_array( $ids ) ? $ids : [] as $id ) {
			$row = $this->find( (int) $id['mapping_id'] );

			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Returns the categories of every mapping: `mapping_id => term ids`.
	 *
	 * @return array<int,int[]>
	 */
	public function terms_by_mapping(): array {
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT mapping_id, term_id FROM %i ORDER BY term_id ASC', $this->terms_table ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		$by_mapping = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$by_mapping[ (int) $row['mapping_id'] ][] = (int) $row['term_id'];
		}

		return $by_mapping;
	}

	/**
	 * Updates a term-bound mapping. A product-scoped mapping is never touched here (it belongs to the product's own tab).
	 *
	 * @param int                 $mapping_id  Mapping id.
	 * @param string              $name        Merchant label.
	 * @param array<string,mixed> $category    The adapter's category descriptor, empty when not chosen.
	 * @param int|null            $profile_id  Profile id.
	 * @param array<string,mixed> $attributes  Attribute sources.
	 * @return bool False when the database refused or the id is not a term-bound mapping.
	 */
	public function update( int $mapping_id, string $name, array $category, ?int $profile_id, array $attributes ): bool {
		$row = $this->find( $mapping_id );

		if ( null === $row || null !== $row['wc_product_id'] ) {
			return false;
		}

		$done = $this->db->update(
			$this->table,
			[
				'name'       => $name,
				'category'   => $this->encode( $category ),
				'profile_id' => $profile_id,
				'attributes' => $this->encode( $attributes ),
				'updated_at' => gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) ),
			],
			[ 'mapping_id' => $mapping_id ]
		);

		return false !== $done;
	}

	/**
	 * Makes a set of categories the categories of a mapping: the ones it had and the new set no longer holds are released, the new ones are taken.
	 *
	 * A category belongs to at most one mapping, so one that another mapping holds is skipped, not stolen.
	 *
	 * @param int   $mapping_id Mapping id.
	 * @param int[] $term_ids   Wanted product category term ids.
	 * @return int[] The wanted categories another mapping already holds.
	 * @throws Persistence_Exception When the database refuses a write.
	 */
	public function replace_terms( int $mapping_id, array $term_ids ): array {
		$wanted = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );

		foreach ( $this->terms_by_mapping()[ $mapping_id ] ?? [] as $held ) {
			if ( ! in_array( $held, $wanted, true ) ) {
				$this->release_term( $held, $mapping_id );
			}
		}

		$refused = [];
		foreach ( $wanted as $term_id ) {
			$this->link_term_if_absent( $term_id, $mapping_id );

			if ( $mapping_id !== $this->mapping_id_for_term( $term_id ) ) {
				$refused[] = $term_id;
			}
		}

		return $refused;
	}

	/**
	 * Deletes a term-bound mapping and its category links.
	 *
	 * @param int $mapping_id Mapping id.
	 * @return bool False when the id is not a term-bound mapping or the database refused.
	 */
	public function delete( int $mapping_id ): bool {
		$row = $this->find( $mapping_id );

		if ( null === $row || null !== $row['wc_product_id'] ) {
			return false;
		}

		$terms = $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE mapping_id = %d', $this->terms_table, $mapping_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.
		$rows  = $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE mapping_id = %d', $this->table, $mapping_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		return false !== $terms && false !== $rows;
	}

	/**
	 * Counts the mappings that use a profile.
	 *
	 * @param int $profile_id Profile id.
	 * @return int
	 */
	public function count_using_profile( int $profile_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE profile_id = %d', $this->table, $profile_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.
	}

	/**
	 * Counts the links that name a mapping explicitly.
	 *
	 * @param string $links_table Full name of the links table.
	 * @param int    $mapping_id  Mapping id.
	 * @return int
	 */
	public function count_linked( string $links_table, int $mapping_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE mapping_id = %d', $links_table, $mapping_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.
	}

	/**
	 * Frees a category held by a mapping.
	 *
	 * @param int $term_id    Product category term id.
	 * @param int $mapping_id Mapping that holds it.
	 * @return void
	 * @throws Persistence_Exception When the database refuses the delete.
	 */
	private function release_term( int $term_id, int $mapping_id ): void {
		$done = $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE term_id = %d AND mapping_id = %d', $this->terms_table, $term_id, $mapping_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		if ( false === $done ) {
			throw Persistence_Exception::database( 'category ' . $term_id . ' of mapping ' . $mapping_id, (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Returns the product-scoped mapping of a product.
	 *
	 * @param int $wc_product_id Product id.
	 * @return int|null
	 */
	public function product_scoped_id( int $wc_product_id ): ?int {
		$found = $this->db->get_var( $this->db->prepare( 'SELECT mapping_id FROM %i WHERE wc_product_id = %d ORDER BY mapping_id ASC LIMIT 1', $this->table, $wc_product_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		return null === $found ? null : (int) $found;
	}

	/**
	 * Loads a stored mapping.
	 *
	 * @param int $mapping_id Mapping id.
	 * @return array{mapping_id:int,name:string,wc_product_id:int|null,category:array<string,mixed>,profile_id:int|null,attributes:array<string,mixed>}|null
	 */
	public function find( int $mapping_id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE mapping_id = %d', $this->table, $mapping_id ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'mapping_id'    => (int) $row['mapping_id'],
			'name'          => (string) $row['name'],
			'wc_product_id' => null === $row['wc_product_id'] ? null : (int) $row['wc_product_id'],
			'category'      => $this->decode( $row['category'] ?? null ),
			'profile_id'    => null === $row['profile_id'] || '' === $row['profile_id'] ? null : (int) $row['profile_id'],
			'attributes'    => $this->decode( $row['attributes'] ?? null ),
		];
	}

	/**
	 * Builds the marketplace-layer {@see Mapping} of a stored mapping.
	 *
	 * @param int $mapping_id Mapping id.
	 * @return Mapping|null
	 */
	public function get( int $mapping_id ): ?Mapping {
		$row = $this->find( $mapping_id );

		if ( null === $row ) {
			return null;
		}

		return new Mapping( $row['mapping_id'], $row['category'], $row['profile_id'], self::sources( $row['attributes'] ) );
	}

	/**
	 * Converts stored attribute entries into marketplace sources.
	 *
	 * @param array<string,mixed> $attributes Stored `attributes` JSON.
	 * @return array<string,Source>
	 */
	private static function sources( array $attributes ): array {
		$sources = [];

		foreach ( $attributes as $attribute_id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			try {
				$dictionary_id                     = isset( $entry['dictionary_value_id'] ) && '' !== (string) $entry['dictionary_value_id'] ? (string) $entry['dictionary_value_id'] : null;
				$sources[ (string) $attribute_id ] = new Source( (string) ( $entry['source'] ?? Source::FIXED ), (string) ( $entry['value'] ?? '' ), (string) ( $entry['wc_key'] ?? '' ), $dictionary_id );
			} catch ( \InvalidArgumentException $exception ) {
				continue; // An unknown kind is dropped, so the field shows as «no source» and the merchant fixes it.
			}
		}

		return $sources;
	}

	/**
	 * Values for the insert placeholders, in order, without the NULL columns (those are literals).
	 *
	 * @param string              $table         Table.
	 * @param int                 $mapping_id    Mapping id.
	 * @param string              $name          Label.
	 * @param int|null            $wc_product_id Product id.
	 * @param array<string,mixed> $category      Category descriptor.
	 * @param int|null            $profile_id    Profile id.
	 * @param array<string,mixed> $attributes    Attribute sources.
	 * @return array<int,mixed>
	 */
	private function bind( string $table, int $mapping_id, string $name, ?int $wc_product_id, array $category, ?int $profile_id, array $attributes ): array {
		$values = [ $table, $mapping_id, $name ];

		if ( null !== $wc_product_id ) {
			$values[] = $wc_product_id;
		}

		$values[] = $this->encode( $category );

		if ( null !== $profile_id ) {
			$values[] = $profile_id;
		}

		array_push( $values, $this->encode( $attributes ), gmdate( 'Y-m-d H:i:s', (int) call_user_func( $this->clock ) ) );

		return $values;
	}

	/**
	 * Placeholder or NULL literal for a nullable integer.
	 *
	 * @param int|null $value Value.
	 * @return string
	 */
	private function nullable( ?int $value ): string {
		return null === $value ? 'NULL' : '%d';
	}

	/**
	 * Encodes a JSON column (the category descriptor, the attribute sources).
	 *
	 * @param array<string,mixed> $value Entries.
	 * @return string
	 */
	private function encode( array $value ): string {
		return [] === $value ? '{}' : (string) wp_json_encode( $value );
	}

	/**
	 * Decodes a JSON column.
	 *
	 * @param mixed $value Column value.
	 * @return array<string,mixed>
	 */
	private function decode( $value ): array {
		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;

		return is_array( $decoded ) ? $decoded : [];
	}
}
