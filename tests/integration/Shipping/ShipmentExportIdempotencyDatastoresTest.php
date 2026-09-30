<?php
/**
 * Integration: an order is exported to the carrier at most once, on BOTH WooCommerce order
 * datastores — HPOS and the legacy CPT (card #945).
 *
 * What the unit tier cannot see. The unit tests pin the handler's decisions against doubles of the
 * order and of the lock. Here the REAL lock (`GET_LOCK` on the test database, «the other request»
 * being a second connection) and the REAL order meta are used, and everything is read back from a
 * FRESH order object, so an «export unknown» flag or a carrier id that lands on one datastore only
 * (or on an object the datastore never saved) is caught.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping {

	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
	use Woodev\Framework\Shipping\Order\Order_Lock;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Tests\Integration\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export
	 * @covers \Woodev\Framework\Shipping\Order\Order_Lock
	 */
	class ShipmentExportIdempotencyDatastoresTest extends TestCase {

		private const CARRIER_ID_META = '_woodev_idempotency_carrier_order_id';

		/** @var \mysqli[] the «other request's» connections, closed in tearDown. */
		private $connections = [];

		/** @var \Woodev_Idempotency_Fake_Api */
		private $api;

		/** @var \Woodev_Idempotency_Retry_Queue */
		private $queue;

		/** @var \Woodev_Idempotency_Shipment_Handler */
		private $handler;

		protected function setUp(): void {
			parent::setUp();

			$this->api     = new \Woodev_Idempotency_Fake_Api();
			$this->queue   = new \Woodev_Idempotency_Retry_Queue();
			$this->handler = new \Woodev_Idempotency_Shipment_Handler(
				$this->api,
				new Shipping_Order_Handler( [ 'carrier_order_id' => self::CARRIER_ID_META ] ),
				$this->queue,
				'idempotency'
			);
		}

		protected function tearDown(): void {
			foreach ( $this->connections as $connection ) {
				$connection->close(); // Releases every named lock it still holds.
			}

			$this->connections = [];

			parent::tearDown();
		}

		/** @return array<string,array{0:bool}> */
		public function datastore_provider(): array {
			return [
				'HPOS'       => [ true ],
				'legacy CPT' => [ false ],
			];
		}

		/**
		 * @param bool $hpos true => HPOS `wc_orders*`; false => legacy `posts` / `postmeta`.
		 * @return void
		 */
		private function use_datastore( bool $hpos ): void {
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
			update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

			$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
		}

		private function new_order(): \WC_Order {
			$order = wc_create_order();
			$order->set_status( 'processing' );
			$order->save();

			return $order;
		}

		/**
		 * The order as the datastore holds it now.
		 *
		 * @param \WC_Order $order the order.
		 * @return \WC_Order
		 */
		private function reread( \WC_Order $order ): \WC_Order {
			wp_cache_flush();

			$fresh = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( \WC_Order::class, $fresh );

			return $fresh;
		}

		/**
		 * «The other request»: a second connection to the test database.
		 *
		 * @return \mysqli
		 */
		private function other_request(): \mysqli {
			if ( ! class_exists( '\mysqli' ) ) {
				$this->markTestSkipped( 'mysqli is not available in this PHP' );
			}

			$host = (string) DB_HOST;
			$port = null;

			if ( false !== strpos( $host, ':' ) ) {
				[ $host, $port ] = explode( ':', $host, 2 );
				$port            = is_numeric( $port ) ? (int) $port : null;
			}

			$connection = null === $port
				? new \mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME )
				: new \mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME, $port );

			$this->assertSame( 0, $connection->connect_errno, 'the other request connected to the test database' );

			$this->connections[] = $connection;

			return $connection;
		}

		/**
		 * Runs one lock function on the other request's connection on the order's EXPORT lock.
		 *
		 * @param \mysqli $other    the other request.
		 * @param string  $call     the function call, `%s` standing for the quoted lock name.
		 * @param int     $order_id the order.
		 * @return string MySQL's answer: '1' done, '0' not, '' NULL.
		 */
		private function export_lock_call( \mysqli $other, string $call, int $order_id ): string {
			$result = $other->query( 'SELECT ' . sprintf( $call, "'" . $other->real_escape_string( Order_Lock::name( 'export', $order_id ) ) . "'" ) );

			$this->assertInstanceOf( \mysqli_result::class, $result );

			$row = $result->fetch_row();

			return (string) ( $row[0] ?? '' );
		}

		/**
		 * Writes an order meta row the way ANOTHER request's connection does: straight into the
		 * datastore's table, so nothing this request cached (the post-meta cache, an order object it
		 * already loaded) hears about it. No `wp_cache_flush()` anywhere on this path.
		 *
		 * @param int    $order_id the order.
		 * @param string $key      the meta key.
		 * @param string $value    the value.
		 * @return void
		 */
		private function write_meta_behind_the_caches( int $order_id, string $key, string $value ): void {
			global $wpdb;

			if ( \Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
				$wpdb->insert(
					$wpdb->prefix . 'wc_orders_meta',
					[
						'order_id'   => $order_id,
						'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					]
				);

				return;
			}

			$wpdb->insert(
				$wpdb->postmeta,
				[
					'post_id'    => $order_id,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);
		}

		/**
		 * How many rows of a meta key the datastore holds for the order — read from the table itself.
		 *
		 * @param int    $order_id the order.
		 * @param string $key      the meta key.
		 * @return int
		 */
		private function meta_rows( int $order_id, string $key ): int {
			global $wpdb;

			if ( \Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
				$table  = $wpdb->prefix . 'wc_orders_meta';
				$column = 'order_id';
			} else {
				$table  = $wpdb->postmeta;
				$column = 'post_id';
			}

			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %d AND meta_key = %s", $order_id, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		/**
		 * An order object loaded BEFORE another request wrote, with the caches primed the way a
		 * real request primes them (the carrier id and the unknown flag both read once).
		 *
		 * @param \WC_Order $order the order.
		 * @return \WC_Order
		 */
		private function load_and_prime( \WC_Order $order ): \WC_Order {
			$loaded = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( \WC_Order::class, $loaded );

			foreach ( [ self::CARRIER_ID_META, Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ] as $key ) {
				$this->assertSame( '', (string) \Woodev_Order_Compatibility::get_order_meta( $loaded, $key ), 'primed: nothing stored yet' );
				$this->assertSame( '', $loaded->get_meta( $key ) );
			}

			return $loaded;
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_an_export_stores_the_id_and_a_second_export_makes_no_call( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$first = $this->handler->export( $order );

			$this->assertTrue( $first->is_success() );
			$this->assertSame( 'CARRIER-' . $order->get_id(), $first->get_carrier_order_id() );
			$this->assertSame( 'CARRIER-' . $order->get_id(), $this->reread( $order )->get_meta( self::CARRIER_ID_META ) );

			$again = $this->handler->export( $this->reread( $order ) );

			$this->assertTrue( $again->is_success() );
			$this->assertSame( $first->get_carrier_order_id(), $again->get_carrier_order_id() );
			$this->assertSame( 1, $this->api->create_calls, 'the carrier was called once, not twice' );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_request_that_finds_the_export_lock_held_makes_no_call_and_the_lock_is_freed_afterwards( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$other = $this->other_request();
			$this->assertSame( '1', $this->export_lock_call( $other, 'GET_LOCK(%s, 0)', $order->get_id() ), 'the other request is exporting this order' );

			$refused = $this->handler->export( $order );

			$this->assertFalse( $refused->is_success() );
			$this->assertSame( 'Этот заказ уже выгружается — дождитесь окончания.', $refused->get_message() );
			$this->assertSame( 0, $this->api->create_calls );
			$this->assertSame( 0, $this->queue->queued, 'a busy order is not queued for retry' );

			$this->assertSame( '1', $this->export_lock_call( $other, 'RELEASE_LOCK(%s)', $order->get_id() ) );

			$this->assertTrue( $this->handler->export( $order )->is_success(), 'the other request finished: the export goes through' );
			$this->assertSame( 1, $this->api->create_calls );

			// The handler's own `finally` let go: a third connection can take the lock at once.
			$third = $this->other_request();
			$this->assertSame( '1', $this->export_lock_call( $third, 'GET_LOCK(%s, 0)', $order->get_id() ), 'the export released the lock' );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_carrier_refusal_leaves_no_trace_on_the_order_and_no_retry( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Exception( 'Неверный индекс получателя' );

			$result = $this->handler->export( $order );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'Неверный индекс получателя', $result->get_message() );
			$this->assertSame( 0, $this->queue->queued );
			$this->assertSame( '', $this->reread( $order )->get_meta( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_timeout_persists_the_unknown_state_and_the_reconcile_clears_it( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->handler->reconcile = true;
			$this->api->fail_with     = new \Woodev_API_Transport_Exception( 'cURL error 28: Operation timed out' );

			$timed_out = $this->handler->export( $order );

			$this->assertFalse( $timed_out->is_success() );
			$this->assertSame( 1, $this->queue->queued, 'a carrier that can reconcile is retried' );

			$fresh = $this->reread( $order );
			$this->assertGreaterThan( 0, (int) $fresh->get_meta( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ), 'the unknown state survives on the datastore' );
			$this->assertSame( '', $fresh->get_meta( self::CARRIER_ID_META ) );

			// The retry: the carrier turns out to have the order.
			$exported = 0;
			add_action(
				'woodev_shipping_idempotency_shipment_exported',
				static function () use ( &$exported ) {
					++$exported;
				}
			);

			$this->handler->found = 'CARRIER-ALREADY-THERE';

			$reconciled = $this->handler->export( $this->reread( $order ) );

			$this->assertTrue( $reconciled->is_success() );
			$this->assertSame( 'CARRIER-ALREADY-THERE', $reconciled->get_carrier_order_id() );
			$this->assertSame( 1, $this->api->create_calls, 'no second create call: only the one that timed out' );
			$this->assertSame( 1, $exported, 'the export hook fires once' );

			$fresh = $this->reread( $order );
			$this->assertSame( 'CARRIER-ALREADY-THERE', $fresh->get_meta( self::CARRIER_ID_META ) );
			$this->assertSame( '', $fresh->get_meta( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ), 'the unknown state is gone' );
		}

		/**
		 * The delayed double click (#945, round 2): request B loaded the order BEFORE request A
		 * stored the carrier id, then took the lock A had just released. The re-read under the lock
		 * must see A's id — `wc_get_order()` alone is served from the request's caches and does not.
		 *
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_stale_order_object_still_sees_the_id_another_request_stored_and_makes_no_call( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$stale = $this->load_and_prime( $order );

			$this->write_meta_behind_the_caches( $order->get_id(), self::CARRIER_ID_META, 'CARRIER-FROM-REQUEST-A' );

			// The premise: the caller's object and the request caches really are stale.
			$this->assertSame( '', $stale->get_meta( self::CARRIER_ID_META ), 'the loaded object does not know the id' );
			$this->assertSame( '', (string) \Woodev_Order_Compatibility::get_order_meta( $stale, self::CARRIER_ID_META ), 'and neither does the plugin\'s own read of it' );

			$result = $this->handler->export( $stale );

			$this->assertSame( 0, $this->api->create_calls, 'no second carrier order' );
			$this->assertTrue( $result->is_success() );
			$this->assertSame( 'CARRIER-FROM-REQUEST-A', $result->get_carrier_order_id() );
		}

		/**
		 * The same staleness hides a just-written «unknown» flag: the export must still see it, so a
		 * carrier that can reconcile is asked FIRST instead of being sent a second create call — and
		 * the flag must not survive beside the id the reconcile stores.
		 *
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_stale_order_object_still_sees_the_unknown_flag_and_the_reconcile_clears_it( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$stale = $this->load_and_prime( $order );

			$this->write_meta_behind_the_caches( $order->get_id(), Abstract_Shipment_Handler::EXPORT_UNKNOWN_META, '1000' );

			$this->assertSame( '', $stale->get_meta( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ), 'the loaded object never saw the flag' );

			$this->handler->reconcile = true;
			$this->handler->found     = 'CARRIER-ALREADY-THERE';

			$result = $this->handler->export( $stale );

			$this->assertSame( 0, $this->api->create_calls, 'the reconcile ran first: no create call' );
			$this->assertTrue( $result->is_success() );
			$this->assertSame( 'CARRIER-ALREADY-THERE', $result->get_carrier_order_id() );

			$this->assertSame( 0, $this->meta_rows( $order->get_id(), Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ), 'the flag row is gone from the datastore' );
			$this->assertSame( 1, $this->meta_rows( $order->get_id(), self::CARRIER_ID_META ) );
		}

		/**
		 * A failure after another request already flagged the order must not add a second flag row.
		 *
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_transport_failure_on_a_stale_object_does_not_duplicate_the_unknown_flag( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$stale = $this->load_and_prime( $order );

			$this->write_meta_behind_the_caches( $order->get_id(), Abstract_Shipment_Handler::EXPORT_UNKNOWN_META, '1000' );

			$this->api->fail_with = new \Woodev_API_Transport_Exception( 'cURL error 28: Operation timed out' );

			$this->assertFalse( $this->handler->export( $stale )->is_success() );

			$this->assertSame( 1, $this->meta_rows( $order->get_id(), Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ), 'one flag row, with the time of the FIRST failure' );
			$this->assertSame( '1000', $this->reread( $order )->get_meta( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_response_without_a_carrier_id_is_unknown_and_stores_no_empty_id( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->id_prefix = '';

			$result = $this->handler->export( $order );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 0, $this->meta_rows( $order->get_id(), self::CARRIER_ID_META ), 'no empty id row: a key-EXISTS query would count it as «exported»' );
			$this->assertGreaterThan( 0, (int) $this->reread( $order )->get_meta( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ) );
		}
	}
}

// The carrier fixtures live AFTER the test class on purpose: IntegrationSuiteBaseClassTest reads the
// first `class … extends` of a file and must see the test case.

namespace {

	if ( ! class_exists( 'Woodev_Idempotency_Fake_Response' ) ) {

		/**
		 * A carrier response carrying just an order id.
		 */
		class Woodev_Idempotency_Fake_Response implements \Woodev_API_Response {

			/** @var string */
			public string $order_id;

			/**
			 * @param string $order_id the carrier's id.
			 */
			public function __construct( string $order_id ) {
				$this->order_id = $order_id;
			}

			/** @inheritDoc */
			public function to_string(): string {
				return $this->order_id;
			}

			/** @inheritDoc */
			public function to_string_safe(): string {
				return $this->order_id;
			}
		}
	}

	if ( ! class_exists( 'Woodev_Idempotency_Fake_Api' ) ) {

		/**
		 * Offline `Shipping_API` that counts `create_order()` calls and can be told to fail.
		 */
		class Woodev_Idempotency_Fake_Api implements \Woodev\Framework\Shipping\Api\Shipping_API {

			/** @var int */
			public int $create_calls = 0;

			/** @var \Throwable|null thrown by create_order() when set */
			public $fail_with;

			/** @var string prepended to the order id; '' makes the response carry NO carrier id */
			public string $id_prefix = 'CARRIER-';

			/** @inheritDoc */
			public function create_order( \WC_Order $order ): \Woodev_API_Response {
				++$this->create_calls;

				if ( null !== $this->fail_with ) {
					throw $this->fail_with;
				}

				return new Woodev_Idempotency_Fake_Response( '' === $this->id_prefix ? '' : $this->id_prefix . $order->get_id() );
			}

			/** @inheritDoc */
			public function cancel_order( string $order_id ): \Woodev_API_Response {
				return new Woodev_Idempotency_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function calculate_rates( array $params ): \Woodev_API_Response {
				return new Woodev_Idempotency_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_pickup_points( array $params ): \Woodev_API_Response {
				return new Woodev_Idempotency_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_order( string $order_id ): \Woodev_API_Response {
				return new Woodev_Idempotency_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_tracking( string $tracking_number ): \Woodev_API_Response {
				return new Woodev_Idempotency_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_request(): \Woodev_API_Request {
				throw new \LogicException( 'not used' );
			}

			/** @inheritDoc */
			public function get_response(): ?\Woodev_API_Response {
				return null;
			}
		}
	}

	if ( ! class_exists( 'Woodev_Idempotency_Retry_Queue' ) ) {

		/**
		 * The retry queue: records what the handler queues instead of dispatching a real job.
		 */
		class Woodev_Idempotency_Retry_Queue extends \Woodev_Background_Job_Handler {

			/** @var string */
			protected $prefix = 'woodev_idempotency';

			/** @var string */
			protected $action = 'export_retry';

			/** @var int */
			public int $queued = 0;

			/** @inheritDoc */
			public function create_job( $attrs = [] ) {
				++$this->queued;

				return null;
			}

			/** @inheritDoc */
			public function dispatch() {
				return null;
			}

			/** @inheritDoc */
			protected function process_item( $item, $job ) {
				return null;
			}
		}
	}

	if ( ! class_exists( 'Woodev_Idempotency_Shipment_Handler' ) ) {

		/**
		 * The REAL handler, lock and order re-read included; only the carrier is fake.
		 */
		class Woodev_Idempotency_Shipment_Handler extends \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler {

			/** @var bool */
			public bool $reconcile = false;

			/** @var string|null what find_exported_order() answers */
			public $found;

			/** @inheritDoc */
			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return $response instanceof Woodev_Idempotency_Fake_Response ? $response->order_id : '';
			}

			/** @inheritDoc */
			public function supports_reconcile(): bool {
				return $this->reconcile;
			}

			/** @inheritDoc */
			public function find_exported_order( \WC_Order $order ): ?string {
				return $this->found;
			}
		}
	}
}
