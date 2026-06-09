<?php
/**
 * Data-preservation tests for the Abstract_Warehouses_Controller redesign.
 *
 * Proves the headline fix: the controller separates the storage row id (the
 * integer PK, exposed as `id`) from the carrier-unique id (exposed as `code`),
 * and a partial update is read-merge so omitted fields are never overwritten
 * with empties — the installed-site data-loss bug that deferred this controller.
 *
 * The fixture is yandex-shaped: a store over the `wc_yandex_delivery_warehouses`
 * contract (PK `id`, carrier code `station_id`, carrier-specific columns
 * round-tripped through the Warehouse `raw` escape hatch) and a concrete
 * controller subclass that adds those carrier fields through the three new seams.
 *
 * Runs in a separate process so the full \WP_REST_Controller / \WP_REST_Request
 * stubs declared here cannot collide with the lighter stubs other test classes
 * install into the shared process.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/pickup/interface-warehouse-store.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/pickup/class-warehouse.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/pickup/class-abstract-warehouse-store.php';

	use Woodev\Framework\Shipping\Pickup\Abstract_Warehouse_Store;
	use Woodev\Framework\Shipping\Pickup\Warehouse;
	use Woodev\Framework\Shipping\Pickup\Warehouse_Store;
	use Woodev\Framework\Shipping\Rest_Api\Abstract_Warehouses_Controller;

	// WP row-format constants the abstract store passes to wpdb reads.
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	// The abstract store type-hints \wpdb; a minimal stub lets the fake below satisfy it.
	if ( ! class_exists( '\wpdb', false ) ) {
		class Warehouses_Controller_Wpdb_Stub {}

		class_alias( Warehouses_Controller_Wpdb_Stub::class, 'wpdb' );
	}

	/**
	 * Minimal \WP_REST_Server constant carrier (method tokens only).
	 */
	if ( ! class_exists( 'WP_REST_Server', false ) ) {
		class WP_REST_Server {
			const READABLE  = 'GET';
			const CREATABLE = 'POST';
			const EDITABLE  = 'POST, PUT, PATCH';
			const DELETABLE = 'DELETE';
		}
	}

	/**
	 * Minimal \WP_REST_Controller stub exposing exactly the helper methods the
	 * warehouses controller calls. Defined under a class_exists guard so other
	 * suites' stubs win when this test does not run in isolation.
	 */
	if ( ! class_exists( 'WP_REST_Controller', false ) ) {
		class WP_REST_Controller {

			/**
			 * Returns the context-param schema fragment.
			 *
			 * @param array $args overrides
			 * @return array
			 */
			public function get_context_param( $args = [] ) {
				return array_merge( [ 'type' => 'string' ], $args );
			}

			/**
			 * Returns the endpoint args for the item schema (unused detail here).
			 *
			 * @param string|null $method HTTP method
			 * @return array
			 */
			public function get_endpoint_args_for_item_schema( $method = null ) {
				return [];
			}

			/**
			 * Returns the public item schema (passthrough of get_item_schema()).
			 *
			 * @return array
			 */
			public function get_public_item_schema() {
				return $this->get_item_schema();
			}

			/**
			 * Schema passthrough.
			 *
			 * @param array $schema schema
			 * @return array
			 */
			public function add_additional_fields_schema( $schema ) {
				return $schema;
			}

			/**
			 * Object passthrough (no registered additional fields under test).
			 *
			 * @param array $object  response data
			 * @param mixed $request request
			 * @return array
			 */
			public function add_additional_fields_to_object( $object, $request ) {
				return $object;
			}

			/**
			 * Filters response data by context using the schema's per-property context.
			 *
			 * @param array  $data    response data
			 * @param string $context requested context
			 * @return array
			 */
			public function filter_response_by_context( $data, $context ) {
				return $data;
			}

			/**
			 * Collapses a prepared response down to its data for collections.
			 *
			 * @param mixed $response prepared response
			 * @return mixed
			 */
			public function prepare_response_for_collection( $response ) {
				return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
			}
		}
	}

	/**
	 * Minimal \WP_REST_Response carrying data + status.
	 */
	if ( ! class_exists( 'WP_REST_Response', false ) ) {
		class WP_REST_Response {

			/** @var mixed response payload */
			private $data;

			/** @var int HTTP status */
			private $status = 200;

			/**
			 * Constructor.
			 *
			 * @param mixed $data response payload
			 */
			public function __construct( $data = null ) {
				$this->data = $data;
			}

			/**
			 * Gets the payload.
			 *
			 * @return mixed
			 */
			public function get_data() {
				return $this->data;
			}

			/**
			 * Sets the HTTP status.
			 *
			 * @param int $status status code
			 * @return void
			 */
			public function set_status( $status ) {
				$this->status = (int) $status;
			}

			/**
			 * Gets the HTTP status.
			 *
			 * @return int
			 */
			public function get_status() {
				return $this->status;
			}
		}
	}

	/**
	 * Minimal \WP_Error stub carrying the error code, message and data.
	 */
	if ( ! class_exists( 'WP_Error', false ) ) {
		class WP_Error {

			/** @var string error code */
			public $code;

			/** @var string error message */
			public $message;

			/** @var array<string,mixed> error data */
			public $data;

			/**
			 * Constructor.
			 *
			 * @param string              $code    error code
			 * @param string              $message error message
			 * @param array<string,mixed> $data    error data
			 */
			public function __construct( $code = '', $message = '', $data = [] ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}
		}
	}

	/**
	 * Minimal \WP_REST_Request: an array-accessible parameter bag.
	 */
	if ( ! class_exists( 'WP_REST_Request', false ) ) {
		class WP_REST_Request implements ArrayAccess {

			/** @var array<string,mixed> request params */
			private $params;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $params request params
			 */
			public function __construct( array $params = [] ) {
				$this->params = $params;
			}

			/**
			 * Gets a single param, or null when absent.
			 *
			 * @param string $key param name
			 * @return mixed|null
			 */
			public function get_param( $key ) {
				return $this->params[ $key ] ?? null;
			}

			#[\ReturnTypeWillChange]
			public function offsetExists( $offset ) {
				return isset( $this->params[ $offset ] );
			}

			#[\ReturnTypeWillChange]
			public function offsetGet( $offset ) {
				return $this->params[ $offset ] ?? null;
			}

			#[\ReturnTypeWillChange]
			public function offsetSet( $offset, $value ) {
				$this->params[ $offset ] = $value;
			}

			#[\ReturnTypeWillChange]
			public function offsetUnset( $offset ) {
				unset( $this->params[ $offset ] );
			}
		}
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/rest-api/abstract-warehouses-controller.php';

	/**
	 * In-memory yandex-shaped \wpdb double over the warehouse table contract.
	 *
	 * Models the `wc_yandex_delivery_warehouses` columns: PK `id`, carrier code
	 * `station_id`, and carrier-specific columns (geo_id, comment, time_from,
	 * time_to, flat, entrance, intercom, floor) round-tripped via the store.
	 */
	class Warehouses_Controller_Fake_Wpdb extends \wpdb {

		/** @var string table prefix */
		public string $prefix = 'wp_';

		/** @var int auto-increment counter */
		public int $insert_id = 0;

		/** @var array<int,array<string,mixed>> stored rows keyed by id */
		public array $store = [];

		/**
		 * Returns the row whose id matches the prepared query's bound id.
		 *
		 * @param string $query  SQL (carries the id rendered by prepare())
		 * @param string $output output type (ignored)
		 * @return array<string,mixed>|null
		 */
		public function get_row( $query, $output = OBJECT ) {
			if ( 1 === preg_match( '/WHERE `id` = (\d+)/', (string) $query, $m ) ) {
				$id = (int) $m[1];

				return $this->store[ $id ] ?? null;
			}

			return null;
		}

		/**
		 * Returns every stored row.
		 *
		 * @param string $query  SQL (ignored)
		 * @param string $output output type (ignored)
		 * @return array<int,array<string,mixed>>
		 */
		public function get_results( $query, $output = OBJECT ) {
			return array_values( $this->store );
		}

		/**
		 * Inlines bound args so get_row() can recover the queried id.
		 *
		 * @param string $query   SQL with %d placeholders
		 * @param mixed  ...$args bound args
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%d/', (string) (int) $arg, (string) $query, 1 );
			}

			return $query;
		}

		/**
		 * Inserts a row, assigning the next id.
		 *
		 * @param string              $table table name (ignored)
		 * @param array<string,mixed> $data  column => value
		 * @return int
		 */
		public function insert( $table, $data ) {
			$id                 = ++$this->insert_id;
			$data['id']         = $id;
			$this->store[ $id ] = $data;

			return 1;
		}

		/**
		 * Updates the row identified by the where clause's id.
		 *
		 * @param string              $table table name (ignored)
		 * @param array<string,mixed> $data  column => value
		 * @param array<string,mixed> $where where column => value
		 * @return int
		 */
		public function update( $table, $data, $where ) {
			$id = (int) ( $where['id'] ?? 0 );

			if ( ! isset( $this->store[ $id ] ) ) {
				return 0;
			}

			$this->store[ $id ] = array_merge( $this->store[ $id ], $data, [ 'id' => $id ] );

			return 1;
		}

		/**
		 * Deletes the row identified by the where clause's id.
		 *
		 * @param string              $table table name (ignored)
		 * @param array<string,mixed> $where where column => value
		 * @return int
		 */
		public function delete( $table, $where ) {
			$id = (int) ( $where['id'] ?? 0 );

			if ( ! isset( $this->store[ $id ] ) ) {
				return 0;
			}

			unset( $this->store[ $id ] );

			return 1;
		}
	}

	/**
	 * Yandex-shaped store: PK `id`, carrier code `station_id`, carrier-specific
	 * columns mapped through the Warehouse `raw` escape hatch.
	 */
	final class Warehouses_Controller_Yandex_Store extends Abstract_Warehouse_Store {

		/** @var string[] carrier-specific columns round-tripped via `raw` */
		private const CARRIER_COLUMNS = [
			'geo_id',
			'comment',
			'time_from',
			'time_to',
			'flat',
			'entrance',
			'intercom',
			'floor',
		];

		/**
		 * Gets the prefixed table name (installed-site contract preserved).
		 *
		 * @return string
		 */
		protected function get_table_name(): string {
			return $this->wpdb->prefix . 'wc_yandex_delivery_warehouses';
		}

		/**
		 * Gets the CREATE TABLE statement (unused by these in-memory tests).
		 *
		 * @return string
		 */
		protected function get_schema(): string {
			return 'CREATE TABLE `' . $this->get_table_name() . '` ( id BIGINT );';
		}

		/**
		 * Maps a warehouse to a yandex row.
		 *
		 * Generic fields map to their columns; `station_id` carries the carrier id;
		 * carrier-specific columns are read out of the `raw` escape hatch.
		 *
		 * @param Warehouse $warehouse warehouse to serialize
		 * @return array<string,mixed>
		 */
		protected function to_row( Warehouse $warehouse ): array {
			$raw = $warehouse->get_raw();

			$row = [
				'station_id'    => $warehouse->get_id(),
				'name'          => $warehouse->get_name(),
				'address'       => $warehouse->get_address(),
				'lat'           => $warehouse->get_lat(),
				'lng'           => $warehouse->get_lng(),
				'contact_name'  => $warehouse->get_contact_name(),
				'contact_phone' => $warehouse->get_contact_phone(),
				'contact_email' => $warehouse->get_contact_email(),
			];

			foreach ( self::CARRIER_COLUMNS as $column ) {
				$row[ $column ] = $raw[ $column ] ?? '';
			}

			return $row;
		}

		/**
		 * Maps a yandex row back into a warehouse.
		 *
		 * The carrier code comes from `station_id`; carrier-specific columns are
		 * written back into the `raw` escape hatch so nothing is lost.
		 *
		 * @param array<string,mixed> $row raw database row
		 * @return Warehouse
		 */
		protected function from_row( array $row ): Warehouse {
			$raw = [];

			foreach ( self::CARRIER_COLUMNS as $column ) {
				$raw[ $column ] = $row[ $column ] ?? '';
			}

			return Warehouse::from_array(
				[
					'id'            => $row['station_id'] ?? '',
					'name'          => $row['name'] ?? '',
					'address'       => $row['address'] ?? '',
					'lat'           => $row['lat'] ?? 0.0,
					'lng'           => $row['lng'] ?? 0.0,
					'contact_name'  => $row['contact_name'] ?? '',
					'contact_phone' => $row['contact_phone'] ?? '',
					'contact_email' => $row['contact_email'] ?? '',
					'raw'           => $raw,
				]
			);
		}
	}

	/**
	 * Yandex-shaped controller subclass exercising the three carrier-field seams.
	 */
	final class Warehouses_Controller_Yandex_Controller extends Abstract_Warehouses_Controller {

		/** @var Warehouse_Store backing store */
		private Warehouse_Store $store;

		/**
		 * Constructor.
		 *
		 * @param Warehouse_Store $store backing store
		 */
		public function __construct( Warehouse_Store $store ) {
			$this->store = $store;
		}

		/**
		 * Gets the REST namespace (the yandex installed-site contract).
		 *
		 * @return string
		 */
		protected function get_namespace(): string {
			return 'yandex-delivery';
		}

		/**
		 * Gets the route base.
		 *
		 * @return string
		 */
		protected function get_rest_base(): string {
			return 'warehouses';
		}

		/**
		 * Gets the backing store.
		 *
		 * @return Warehouse_Store
		 */
		protected function get_warehouse_store(): Warehouse_Store {
			return $this->store;
		}

		/**
		 * Declares the yandex carrier-specific schema properties.
		 *
		 * @return array<string,array<string,mixed>>
		 */
		protected function get_additional_schema_properties(): array {
			return [
				'geo_id'  => [
					'type'    => 'integer',
					'context' => [ 'view', 'edit' ],
				],
				'comment' => [
					'type'    => 'string',
					'context' => [ 'view', 'edit' ],
				],
			];
		}

		/**
		 * Reads the yandex carrier fields from the request into the `raw` hatch.
		 *
		 * Read-merge: only overlay fields the request actually supplies.
		 *
		 * @param array            $data    persisted-shape data
		 * @param \WP_REST_Request $request request object
		 * @return array
		 */
		protected function merge_additional_fields_into_data( array $data, $request ): array {
			$raw = $data['raw'] ?? [];

			foreach ( [ 'geo_id', 'comment', 'time_from', 'time_to' ] as $key ) {
				if ( null !== $request->get_param( $key ) ) {
					$raw[ $key ] = $request->get_param( $key );
				}
			}

			$data['raw'] = $raw;

			return $data;
		}

		/**
		 * Exposes the yandex carrier fields from the `raw` hatch.
		 *
		 * @param Warehouse        $warehouse warehouse being serialized
		 * @param \WP_REST_Request $request   request object
		 * @return array
		 */
		protected function prepare_additional_response_fields( Warehouse $warehouse, $request ): array {
			$raw = $warehouse->get_raw();

			return [
				'geo_id'    => $raw['geo_id'] ?? null,
				'comment'   => $raw['comment'] ?? null,
				'time_from' => $raw['time_from'] ?? null,
				'time_to'   => $raw['time_to'] ?? null,
			];
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Pickup\Warehouse;

	/**
	 * Class WarehousesControllerDataPreservationTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	class WarehousesControllerDataPreservationTest extends TestCase {

		/**
		 * Sets up the WP runtime function stubs the controller leans on.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'rest_ensure_response' )->alias(
				static function ( $data ) {
					return $data instanceof \WP_REST_Response ? $data : new \WP_REST_Response( $data );
				}
			);
		}

		/**
		 * Builds a fresh yandex-shaped controller + store over an in-memory wpdb.
		 *
		 * @return array{0:\Warehouses_Controller_Yandex_Controller,1:\Warehouses_Controller_Yandex_Store,2:\Warehouses_Controller_Fake_Wpdb}
		 */
		private function make_controller(): array {
			$wpdb       = new \Warehouses_Controller_Fake_Wpdb();
			$store      = new \Warehouses_Controller_Yandex_Store( $wpdb );
			$controller = new \Warehouses_Controller_Yandex_Controller( $store );

			return [ $controller, $store, $wpdb ];
		}

		/**
		 * Seeds one yandex warehouse directly into the store and returns its row id.
		 *
		 * @param \Warehouses_Controller_Yandex_Store $store store
		 * @return int storage row id
		 */
		private function seed_warehouse( \Warehouses_Controller_Yandex_Store $store ): int {
			return $store->save(
				Warehouse::from_array(
					[
						'id'            => 'yandex-wh-001',
						'name'          => 'Central Hub',
						'address'       => 'Tverskaya 1, Moscow',
						'lat'           => 55.7558,
						'lng'           => 37.6173,
						'contact_name'  => 'Ivan Petrov',
						'contact_phone' => '+7 495 000 00 00',
						'contact_email' => 'ivan@example.com',
						'raw'           => [
							'geo_id'    => 213,
							'comment'   => 'Ring road entrance',
							'time_from' => '09:00',
							'time_to'   => '18:00',
							'flat'      => '12',
							'entrance'  => '3',
							'intercom'  => '12K',
							'floor'     => '2',
						],
					]
				)
			);
		}

		/**
		 * Headline: a partial update touching ONLY `name` preserves every other
		 * field — carrier code, coordinates, contacts and the carrier-specific
		 * columns (geo_id, comment, time_from/to) all survive. No data loss.
		 *
		 * @return void
		 */
		public function test_partial_update_preserves_all_untouched_fields(): void {
			[ $controller, $store ] = $this->make_controller();

			$id = $this->seed_warehouse( $store );
			$this->assertGreaterThan( 0, $id );

			$request  = new \WP_REST_Request(
				[
					'id'   => $id,
					'name' => 'Central Hub (renamed)',
				]
			);
			$response = $controller->update_item( $request );
			$data     = $response->get_data();

			// The one supplied field changed.
			$this->assertSame( 'Central Hub (renamed)', $data['name'] );

			// Everything else is byte-for-byte intact.
			$this->assertSame( 'yandex-wh-001', $data['code'], 'Carrier code must be preserved.' );
			$this->assertSame( $id, $data['id'], 'Storage row id must be preserved.' );
			$this->assertSame( 'Tverskaya 1, Moscow', $data['address'] );
			$this->assertSame( 55.7558, $data['lat'] );
			$this->assertSame( 37.6173, $data['lng'] );
			$this->assertSame( 'Ivan Petrov', $data['contact_name'] );
			$this->assertSame( '+7 495 000 00 00', $data['contact_phone'] );
			$this->assertSame( 'ivan@example.com', $data['contact_email'] );

			// Carrier-specific fields exposed via the response seam.
			$this->assertSame( 213, $data['geo_id'] );
			$this->assertSame( 'Ring road entrance', $data['comment'] );
			$this->assertSame( '09:00', $data['time_from'] );
			$this->assertSame( '18:00', $data['time_to'] );

			// Carrier columns never surfaced through a seam still survive in raw.
			$this->assertSame( '12', $data['raw']['flat'] );
			$this->assertSame( '3', $data['raw']['entrance'] );
			$this->assertSame( '12K', $data['raw']['intercom'] );
			$this->assertSame( '2', $data['raw']['floor'] );
		}

		/**
		 * Id separation: after updating via the numeric route id, the carrier id
		 * (station_id / `code`) is still 'yandex-wh-001', NOT the route id.
		 *
		 * @return void
		 */
		public function test_route_id_is_never_folded_into_carrier_id(): void {
			[ $controller, $store ] = $this->make_controller();

			$id = $this->seed_warehouse( $store );

			$request  = new \WP_REST_Request(
				[
					'id'   => $id,
					'name' => 'Touched',
				]
			);
			$response = $controller->update_item( $request );
			$data     = $response->get_data();

			$this->assertSame( 'yandex-wh-001', $data['code'] );
			$this->assertNotSame( (string) $id, $data['code'] );

			// Re-reading from the store confirms the persisted carrier id is intact.
			$persisted = $store->get( $id );
			$this->assertInstanceOf( Warehouse::class, $persisted );
			$this->assertSame( 'yandex-wh-001', $persisted->get_id() );
			$this->assertSame( $id, $persisted->get_storage_id() );
		}

		/**
		 * Create returns a positive storage row id; the response `id` is that row id
		 * and `code` is the carrier station_id from the body.
		 *
		 * @return void
		 */
		public function test_create_returns_row_id_and_carrier_code(): void {
			[ $controller, $store ] = $this->make_controller();

			$request  = new \WP_REST_Request(
				[
					'code'    => 'yandex-wh-777',
					'name'    => 'New Depot',
					'address' => 'Lenina 5',
					'lat'     => 59.93,
					'lng'     => 30.33,
					'geo_id'  => 2,
					'comment' => 'Created via REST',
				]
			);
			$response = $controller->create_item( $request );

			$this->assertSame( 201, $response->get_status() );

			$data = $response->get_data();

			$this->assertIsInt( $data['id'] );
			$this->assertGreaterThan( 0, $data['id'] );
			$this->assertSame( 'yandex-wh-777', $data['code'], 'Response code must be the carrier station_id, not the row id.' );
			$this->assertSame( 'New Depot', $data['name'] );
			$this->assertSame( 2, $data['geo_id'] );
			$this->assertSame( 'Created via REST', $data['comment'] );

			// The store actually holds the new row under the returned id.
			$persisted = $store->get( $data['id'] );
			$this->assertInstanceOf( Warehouse::class, $persisted );
			$this->assertSame( 'yandex-wh-777', $persisted->get_id() );
		}

		/**
		 * prepare_item_for_database() read-merge proven directly: starting from an
		 * existing warehouse, a request with only `name` yields a value object with
		 * every other field (carrier id, coords, contacts, raw) preserved.
		 *
		 * @return void
		 */
		public function test_prepare_for_database_read_merge_preserves_existing(): void {
			[ $controller, $store ] = $this->make_controller();

			$id       = $this->seed_warehouse( $store );
			$existing = $store->get( $id );
			$this->assertInstanceOf( Warehouse::class, $existing );

			$request = new \WP_REST_Request(
				[
					'id'   => $id,
					'name' => 'Merged Name',
				]
			);

			$method = new \ReflectionMethod( \Warehouses_Controller_Yandex_Controller::class, 'prepare_item_for_database' );

			// setAccessible() is required on PHP < 8.1 and deprecated on 8.5+.
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			/** @var Warehouse $merged */
			$merged = $method->invoke( $controller, $request, $existing );

			$this->assertSame( 'Merged Name', $merged->get_name() );
			$this->assertSame( 'yandex-wh-001', $merged->get_id(), 'Carrier id preserved through merge.' );
			$this->assertSame( $id, $merged->get_storage_id(), 'Storage id preserved so save() updates, not inserts.' );
			$this->assertSame( 55.7558, $merged->get_lat() );
			$this->assertSame( 'ivan@example.com', $merged->get_contact_email() );
			$this->assertSame( 213, $merged->get_raw()['geo_id'] );
			$this->assertSame( 'Ring road entrance', $merged->get_raw()['comment'] );
		}

		/**
		 * prepare_item_for_database() on create (no existing) leaves the carrier id
		 * empty when no `code` is supplied and keeps the storage id null so the
		 * store inserts.
		 *
		 * @return void
		 */
		public function test_prepare_for_database_create_defaults(): void {
			[ $controller ] = $this->make_controller();

			$request = new \WP_REST_Request( [ 'name' => 'Fresh' ] );

			$method = new \ReflectionMethod( \Warehouses_Controller_Yandex_Controller::class, 'prepare_item_for_database' );

			// setAccessible() is required on PHP < 8.1 and deprecated on 8.5+.
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			/** @var Warehouse $built */
			$built = $method->invoke( $controller, $request, null );

			$this->assertSame( 'Fresh', $built->get_name() );
			$this->assertSame( '', $built->get_id(), 'Carrier id stays empty on create with no code.' );
			$this->assertNull( $built->get_storage_id(), 'Storage id stays null so the store inserts.' );
		}

		/**
		 * Update against an unknown row id returns the 404 not-found error.
		 *
		 * @return void
		 */
		public function test_update_unknown_id_returns_not_found(): void {
			[ $controller ] = $this->make_controller();

			$request = new \WP_REST_Request(
				[
					'id'   => 9999,
					'name' => 'Nope',
				]
			);
			$result  = $controller->update_item( $request );

			$this->assertInstanceOf( \WP_Error::class, $result );
		}
	}
}
