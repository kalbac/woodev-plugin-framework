<?php
/**
 * Storage-row-id model tests for the pickup Warehouse value object and the
 * Abstract_Warehouse_Store CRUD plumbing.
 *
 * Proves the fix that separates the carrier-unique id from the backing-store PK:
 * the VO now carries a nullable storage_id, to_array()/from_array() round-trip it,
 * with_storage_id() is immutable, and the store stamps the PK on read and decides
 * insert-vs-update from Warehouse::get_storage_id() (not from the row data).
 *
 * Pure PHP — no WooCommerce or WordPress runtime required; the store is driven
 * through a fake \wpdb double that records the calls it receives.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/pickup/interface-warehouse-store.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/pickup/class-warehouse.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/pickup/class-abstract-warehouse-store.php';

	use Woodev\Framework\Shipping\Pickup\Abstract_Warehouse_Store;
	use Woodev\Framework\Shipping\Pickup\Warehouse;

	// WP row-format constants the abstract store passes to wpdb reads.
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	// The store constructor type-hints \wpdb; a minimal stub lets the fake below satisfy it.
	if ( ! class_exists( '\wpdb', false ) ) {
		class WarehouseStorageId_Wpdb_Stub {}

		class_alias( WarehouseStorageId_Wpdb_Stub::class, 'wpdb' );
	}

	/**
	 * Records the wpdb calls the store makes so assertions can inspect them.
	 *
	 * Implements only the surface the abstract store touches; ARRAY_A reads are
	 * served from a preconfigured row set keyed by the id column.
	 */
	class Warehouse_Storage_Id_Fake_Wpdb extends \wpdb {

		/** @var string table prefix */
		public string $prefix = 'wp_';

		/** @var int insert id returned by the next insert() */
		public int $insert_id = 0;

		/** @var array<string,mixed>|null row returned by get_row() */
		public ?array $row = null;

		/** @var array<int,array<string,mixed>> rows returned by get_results() */
		public array $rows = [];

		/** @var array<int,array<string,mixed>> recorded insert() calls */
		public array $inserts = [];

		/** @var array<int,array<string,mixed>> recorded update() calls */
		public array $updates = [];

		/**
		 * Returns a single row (ARRAY_A) — the preconfigured {@see $row}.
		 *
		 * @param string $query  SQL query (ignored)
		 * @param string $output output type (ignored)
		 * @return array<string,mixed>|null
		 */
		public function get_row( $query, $output = OBJECT ) {
			return $this->row;
		}

		/**
		 * Returns the preconfigured result set.
		 *
		 * @param string $query  SQL query (ignored)
		 * @param string $output output type (ignored)
		 * @return array<int,array<string,mixed>>
		 */
		public function get_results( $query, $output = OBJECT ) {
			return $this->rows;
		}

		/**
		 * No-op SQL preparer good enough for the store's prepared reads.
		 *
		 * @param string $query SQL with placeholders
		 * @param mixed  ...$args bound arguments
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			return $query;
		}

		/**
		 * Records an insert and reports one affected row.
		 *
		 * @param string               $table table name
		 * @param array<string,mixed>  $data  column => value
		 * @return int
		 */
		public function insert( $table, $data ) {
			$this->inserts[] = [
				'table' => $table,
				'data'  => $data,
			];

			return 1;
		}

		/**
		 * Records an update and reports one affected row.
		 *
		 * @param string               $table table name
		 * @param array<string,mixed>  $data  column => value
		 * @param array<string,mixed>  $where where column => value
		 * @return int
		 */
		public function update( $table, $data, $where ) {
			$this->updates[] = [
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			];

			return 1;
		}

		/**
		 * Records a delete and reports one affected row.
		 *
		 * @param string              $table table name
		 * @param array<string,mixed> $where where column => value
		 * @return int
		 */
		public function delete( $table, $where ) {
			return 1;
		}
	}

	/**
	 * Concrete store double over a simple two-column table (id + name).
	 *
	 * to_row() deliberately does NOT emit the id column — the store owns the PK.
	 */
	final class Warehouse_Storage_Id_Test_Store extends Abstract_Warehouse_Store {

		/**
		 * Gets the (prefixed) backing table name.
		 *
		 * @return string
		 */
		protected function get_table_name(): string {
			return $this->wpdb->prefix . 'test_warehouses';
		}

		/**
		 * Gets the CREATE TABLE statement (unused by these tests).
		 *
		 * @return string
		 */
		protected function get_schema(): string {
			return 'CREATE TABLE `' . $this->get_table_name() . '` ( id BIGINT, name VARCHAR(255) );';
		}

		/**
		 * Maps a warehouse to a row — name only; the PK is store-owned.
		 *
		 * @param Warehouse $warehouse warehouse to serialize
		 * @return array<string,mixed>
		 */
		protected function to_row( Warehouse $warehouse ): array {
			return [
				'external_id' => $warehouse->get_id(),
				'name'        => $warehouse->get_name(),
			];
		}

		/**
		 * Maps a row back into a warehouse (carrier id from external_id column).
		 *
		 * @param array<string,mixed> $row raw database row
		 * @return Warehouse
		 */
		protected function from_row( array $row ): Warehouse {
			return Warehouse::from_array(
				[
					'id'   => $row['external_id'] ?? '',
					'name' => $row['name'] ?? '',
					'raw'  => $row,
				]
			);
		}
	}
}

namespace Woodev\Tests\Unit {

	use Woodev\Framework\Shipping\Pickup\Warehouse;

	/**
	 * Class WarehouseStorageIdTest
	 */
	class WarehouseStorageIdTest extends TestCase {

		/**
		 * A fresh warehouse carries a null storage id.
		 *
		 * @return void
		 */
		public function test_storage_id_defaults_to_null(): void {
			$warehouse = new Warehouse( 'CARRIER-1', 'Main' );

			$this->assertNull( $warehouse->get_storage_id() );
		}

		/**
		 * from_array() without a storage_id key keeps it null (not coerced to 0).
		 *
		 * @return void
		 */
		public function test_from_array_without_storage_id_is_null(): void {
			$warehouse = Warehouse::from_array(
				[
					'id'   => 'CARRIER-1',
					'name' => 'Main',
				]
			);

			$this->assertNull( $warehouse->get_storage_id() );
		}

		/**
		 * from_array() with a storage_id key yields that int.
		 *
		 * @return void
		 */
		public function test_from_array_with_storage_id_is_int(): void {
			$warehouse = Warehouse::from_array(
				[
					'id'         => 'CARRIER-1',
					'name'       => 'Main',
					'storage_id' => '42',
				]
			);

			$this->assertSame( 42, $warehouse->get_storage_id() );
		}

		/**
		 * to_array() exposes storage_id and round-trips back to an identical object.
		 *
		 * @return void
		 */
		public function test_to_array_round_trips_storage_id(): void {
			$warehouse = new Warehouse(
				'CARRIER-1',
				'Main',
				'1 Some Street',
				55.75,
				37.62,
				'Ivan',
				'+7 000 000 00 00',
				'ivan@example.com',
				[ 'mon' => '9-18' ],
				[ 'provider' => 'x' ],
				7
			);

			$array = $warehouse->to_array();

			$this->assertArrayHasKey( 'storage_id', $array );
			$this->assertSame( 7, $array['storage_id'] );

			$restored = Warehouse::from_array( $array );

			$this->assertEquals( $warehouse->to_array(), $restored->to_array() );
			$this->assertSame( 7, $restored->get_storage_id() );
		}

		/**
		 * to_array()/from_array() round-trip preserves a null storage_id.
		 *
		 * @return void
		 */
		public function test_to_array_round_trips_null_storage_id(): void {
			$warehouse = new Warehouse( 'CARRIER-1', 'Main' );

			$restored = Warehouse::from_array( $warehouse->to_array() );

			$this->assertNull( $restored->get_storage_id() );
			$this->assertEquals( $warehouse->to_array(), $restored->to_array() );
		}

		/**
		 * with_storage_id() returns a new instance with the new id; the original is
		 * untouched and all other fields are identical.
		 *
		 * @return void
		 */
		public function test_with_storage_id_is_immutable(): void {
			$original = new Warehouse(
				'CARRIER-1',
				'Main',
				'1 Some Street',
				55.75,
				37.62,
				'Ivan',
				'+7 000 000 00 00',
				'ivan@example.com',
				[ 'mon' => '9-18' ],
				[ 'provider' => 'x' ]
			);

			$stamped = $original->with_storage_id( 99 );

			$this->assertNotSame( $original, $stamped, 'with_storage_id() must return a new instance.' );
			$this->assertNull( $original->get_storage_id(), 'Original instance must be unchanged.' );
			$this->assertSame( 99, $stamped->get_storage_id() );

			// Every other field is identical between the two copies.
			$original_without_id = $original->to_array();
			$stamped_without_id  = $stamped->to_array();
			unset( $original_without_id['storage_id'], $stamped_without_id['storage_id'] );

			$this->assertEquals( $original_without_id, $stamped_without_id );
		}

		/**
		 * with_storage_id( null ) clears the id on the copy.
		 *
		 * @return void
		 */
		public function test_with_storage_id_can_clear(): void {
			$warehouse = ( new Warehouse( 'CARRIER-1', 'Main' ) )->with_storage_id( 5 );

			$cleared = $warehouse->with_storage_id( null );

			$this->assertSame( 5, $warehouse->get_storage_id() );
			$this->assertNull( $cleared->get_storage_id() );
		}

		/**
		 * get() stamps the storage id from the row's id column.
		 *
		 * @return void
		 */
		public function test_get_stamps_storage_id_from_row(): void {
			$wpdb            = new \Warehouse_Storage_Id_Fake_Wpdb();
			$wpdb->row       = [
				'id'          => '13',
				'external_id' => 'CARRIER-1',
				'name'        => 'Main',
			];
			$store           = new \Warehouse_Storage_Id_Test_Store( $wpdb );

			$warehouse = $store->get( 13 );

			$this->assertInstanceOf( Warehouse::class, $warehouse );
			$this->assertSame( 13, $warehouse->get_storage_id() );
			$this->assertSame( 'CARRIER-1', $warehouse->get_id() );
		}

		/**
		 * get() returns null when no row matches.
		 *
		 * @return void
		 */
		public function test_get_returns_null_when_missing(): void {
			$wpdb      = new \Warehouse_Storage_Id_Fake_Wpdb();
			$wpdb->row = null;
			$store     = new \Warehouse_Storage_Id_Test_Store( $wpdb );

			$this->assertNull( $store->get( 999 ) );
		}

		/**
		 * all() stamps each warehouse's storage id from its row id column.
		 *
		 * @return void
		 */
		public function test_all_stamps_storage_id_from_rows(): void {
			$wpdb        = new \Warehouse_Storage_Id_Fake_Wpdb();
			$wpdb->rows  = [
				[
					'id'          => '1',
					'external_id' => 'CARRIER-1',
					'name'        => 'A',
				],
				[
					'id'          => '2',
					'external_id' => 'CARRIER-2',
					'name'        => 'B',
				],
			];
			$store       = new \Warehouse_Storage_Id_Test_Store( $wpdb );

			$warehouses = $store->all();

			$this->assertCount( 2, $warehouses );
			$this->assertSame( 1, $warehouses[0]->get_storage_id() );
			$this->assertSame( 2, $warehouses[1]->get_storage_id() );
		}

		/**
		 * save() with a null storage id INSERTs and returns the generated id, never
		 * writing the PK column in the row data.
		 *
		 * @return void
		 */
		public function test_save_inserts_when_storage_id_is_null(): void {
			$wpdb            = new \Warehouse_Storage_Id_Fake_Wpdb();
			$wpdb->insert_id = 77;
			$store           = new \Warehouse_Storage_Id_Test_Store( $wpdb );

			$warehouse = new Warehouse( 'CARRIER-1', 'Main' );

			$id = $store->save( $warehouse );

			$this->assertSame( 77, $id );
			$this->assertCount( 1, $wpdb->inserts );
			$this->assertCount( 0, $wpdb->updates );
			$this->assertArrayNotHasKey( 'id', $wpdb->inserts[0]['data'], 'PK column must not be written.' );
		}

		/**
		 * save() with a positive storage id UPDATEs the existing row, targets it by
		 * the id column, and does not write the PK column in the data.
		 *
		 * @return void
		 */
		public function test_save_updates_when_storage_id_is_positive(): void {
			$wpdb  = new \Warehouse_Storage_Id_Fake_Wpdb();
			$store = new \Warehouse_Storage_Id_Test_Store( $wpdb );

			$warehouse = ( new Warehouse( 'CARRIER-1', 'Main' ) )->with_storage_id( 42 );

			$id = $store->save( $warehouse );

			$this->assertSame( 42, $id );
			$this->assertCount( 1, $wpdb->updates );
			$this->assertCount( 0, $wpdb->inserts );
			$this->assertSame( [ 'id' => 42 ], $wpdb->updates[0]['where'], 'UPDATE must target the row by its PK.' );
			$this->assertArrayNotHasKey( 'id', $wpdb->updates[0]['data'], 'PK column must not be written.' );
		}
	}
}
