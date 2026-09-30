<?php
/**
 * Unit: an order is exported to the carrier at most once (card #945) —
 * the already-exported short-circuit, the per-order lock, the transport/carrier
 * classification of a failure, the persisted «unknown» state and the reconcile seam.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/api/interface-shipping-api.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipping-order-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-action-result.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-order-lock.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/utilities/class-woodev-async-request.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/utilities/class-woodev-background-job-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-transport-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';

	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;

	if ( ! class_exists( 'Idempotency_Test_Api_Exception' ) ) {
		/**
		 * A third-party exception class: a subclass of the base, so the API base leaves it alone.
		 */
		class Idempotency_Test_Api_Exception extends Woodev_API_Exception {}
	}

	if ( ! class_exists( 'Idempotency_Test_Shipment_Handler' ) ) {
		/**
		 * Concrete handler whose lock, fresh-order and reconcile seams the test drives.
		 */
		class Idempotency_Test_Shipment_Handler extends Abstract_Shipment_Handler {

			/** @var bool whether the export lock is granted */
			public bool $lock_granted = true;

			/** @var int[] order ids the lock was taken for */
			public array $locked = [];

			/** @var int[] order ids the lock was released for */
			public array $released = [];

			/** @var \WC_Order|null what fresh_order() returns; null = the order given */
			public $fresh;

			/** @var bool */
			public bool $reconcile = false;

			/** @var string|\Throwable|null what find_exported_order() yields */
			public $found;

			/** @var int how many times the carrier was asked to look the order up */
			public int $lookups = 0;

			/** @var string what extract_carrier_order_id() yields; '' = the response carried no id */
			public string $extracted = 'CARRIER-NEW';

			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return $this->extracted;
			}

			public function supports_reconcile(): bool {
				return $this->reconcile;
			}

			public function find_exported_order( \WC_Order $order ): ?string {
				++$this->lookups;

				if ( $this->found instanceof \Throwable ) {
					throw $this->found;
				}

				return $this->found;
			}

			/** @var bool a carrier that classifies EVERY failure as a refusal */
			public bool $never_transport = false;

			protected function is_transport_failure( \Woodev_API_Exception $exception ): bool {
				return ! $this->never_transport && parent::is_transport_failure( $exception );
			}

			public function classify( \Woodev_API_Exception $exception ): bool {
				return $this->is_transport_failure( $exception );
			}

			protected function acquire_export_lock( int $order_id ): bool {
				$this->locked[] = $order_id;

				return $this->lock_granted;
			}

			protected function release_export_lock( int $order_id ): void {
				$this->released[] = $order_id;
			}

			protected function fresh_order( \WC_Order $order ): \WC_Order {
				return $this->fresh ?? $order;
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Actions;
	use Mockery;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::is_transport_failure
	 */
	final class AbstractShipmentHandlerIdempotencyTest extends TestCase {

		private const META = Abstract_Shipment_Handler::EXPORT_UNKNOWN_META;

		/** @var array<int, array<string, mixed>> order id => meta, the datastore the order doubles write to */
		private array $meta = [];

		/** @var array<int, string> order id => the stored carrier id */
		private array $carrier_ids = [];

		/** @var int[] order ids the carrier id was written for, in order */
		private array $id_writes = [];

		protected function setUp(): void {
			parent::setUp();

			$this->meta        = [];
			$this->carrier_ids = [];
			$this->id_writes   = [];

			Location_Provider_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * An order double whose meta lives in `$this->meta`.
		 *
		 * @param int $id order id.
		 * @return \WC_Order
		 */
		private function order( int $id = 55 ) {
			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( $id );
			$order->shouldReceive( 'get_meta' )->andReturnUsing(
				fn( $key ) => $this->meta[ $id ][ $key ] ?? ''
			);
			$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
				function ( $key, $value ) use ( $id ) {
					$this->meta[ $id ][ $key ] = $value;
				}
			);
			$order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
				function ( $key ) use ( $id ) {
					unset( $this->meta[ $id ][ $key ] );
				}
			);
			$order->shouldReceive( 'save_meta_data' );

			return $order;
		}

		/**
		 * @param mixed                $api           a Shipping_API mock.
		 * @param mixed                $retry_handler a Woodev_Background_Job_Handler mock.
		 * @return \Idempotency_Test_Shipment_Handler
		 */
		private function handler( $api, $retry_handler = null ): \Idempotency_Test_Shipment_Handler {
			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturnUsing(
				fn( $order ) => $this->carrier_ids[ $order->get_id() ] ?? ''
			);
			$order_handler->shouldReceive( 'set' )->andReturnUsing(
				function ( $order, $logical, $value ) {
					$this->carrier_ids[ $order->get_id() ] = $value;
					$this->id_writes[]                     = $order->get_id();
				}
			);

			return new \Idempotency_Test_Shipment_Handler(
				$api,
				$order_handler,
				$retry_handler ?? Mockery::mock( '\Woodev_Background_Job_Handler' ),
				'test',
				Mockery::mock( Popular_Settlement_Store::class )
			);
		}

		/**
		 * @param \Throwable|null $throws what create_order() throws; null = it succeeds.
		 * @param int|null        $times  how many calls to expect; null = any number, including none.
		 * @return \Mockery\MockInterface
		 */
		private function api( ?\Throwable $throws = null, ?int $times = 1 ) {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );

			if ( 0 === $times ) {
				$api->shouldNotReceive( 'create_order' );

				return $api;
			}

			$expectation = $api->shouldReceive( 'create_order' );

			if ( null !== $times ) {
				$expectation->times( $times );
			}

			if ( null !== $throws ) {
				$expectation->andThrow( $throws );
			} else {
				$expectation->andReturn( Mockery::mock( '\Woodev_API_Response' ) );
			}

			return $api;
		}

		private function retry_queue( int $times ) {
			$retry = Mockery::mock( '\Woodev_Background_Job_Handler' );

			if ( 0 === $times ) {
				$retry->shouldNotReceive( 'create_job' );
				$retry->shouldNotReceive( 'dispatch' );
			} else {
				$retry->shouldReceive( 'create_job' )->times( $times )->with( [ 'data' => [ 55 ] ] );
				$retry->shouldReceive( 'dispatch' )->times( $times );
			}

			return $retry;
		}

		// ----- D1: already exported → no call -----

		public function test_an_already_exported_order_returns_its_id_without_a_call_a_lock_or_a_hook(): void {
			$this->carrier_ids[55] = 'CARRIER-OLD';

			Actions\expectDone( 'woodev_shipping_test_shipment_exported' )->never();
			Actions\expectDone( 'woodev_shipping_order_exported' )->never();

			$handler = $this->handler( $this->api( null, 0 ) );
			$result  = $handler->export( $this->order() );

			$this->assertTrue( $result->is_success() );
			$this->assertSame( 'CARRIER-OLD', $result->get_carrier_order_id() );
			$this->assertSame( [], $handler->locked, 'nothing to protect: the lock is not even taken' );
			$this->assertSame( [], $this->id_writes );
		}

		public function test_an_export_that_finished_while_this_request_waited_for_the_lock_is_returned_not_repeated(): void {
			$fresh = $this->order( 56 );

			$this->carrier_ids[56] = 'CARRIER-FROM-THE-OTHER-REQUEST';

			$handler        = $this->handler( $this->api( null, 0 ) );
			$handler->fresh = $fresh;

			Actions\expectDone( 'woodev_shipping_test_shipment_exported' )->never();

			$result = $handler->export( $this->order( 55 ) );

			$this->assertTrue( $result->is_success() );
			$this->assertSame( 'CARRIER-FROM-THE-OTHER-REQUEST', $result->get_carrier_order_id() );
			$this->assertSame( [ 55 ], $handler->released, 'the lock is released on this path too' );
		}

		// ----- D2: the lock -----

		public function test_a_busy_lock_refuses_the_export_without_a_call_and_without_a_retry(): void {
			$handler               = $this->handler( $this->api( null, 0 ), $this->retry_queue( 0 ) );
			$handler->lock_granted = false;

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'Этот заказ уже выгружается — дождитесь окончания.', $result->get_message() );
			$this->assertSame( [ 55 ], $handler->locked );
			$this->assertSame( [], $handler->released, 'a lock that was never granted is not released' );
			$this->assertSame( [], $this->meta, 'a refused export marks nothing' );
		}

		public function test_the_lock_is_released_after_a_success_and_after_a_failure(): void {
			$ok = $this->handler( $this->api() );
			$ok->export( $this->order() );
			$this->assertSame( [ 55 ], $ok->released );

			$this->carrier_ids = []; // the first export stored an id; the second handler starts from an un-exported order.

			$failed = $this->handler( $this->api( new \Woodev_API_Exception( 'refused' ) ), $this->retry_queue( 0 ) );
			$failed->export( $this->order() );
			$this->assertSame( [ 55 ], $failed->released );
		}

		public function test_the_lock_is_released_even_when_something_else_blows_up(): void {
			$handler = $this->handler( $this->api( new \RuntimeException( 'not an API exception' ) ) );

			try {
				$handler->export( $this->order() );
				$this->fail( 'the exception must propagate' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( [ 55 ], $handler->released, 'finally: the lock is never left held' );
			}
		}

		// ----- D3 / D4 / D6: classification, the unknown state, the retry policy -----

		public function test_a_carrier_level_failure_carries_the_carriers_text_marks_nothing_and_is_not_retried(): void {
			$handler = $this->handler( $this->api( new \Woodev_API_Exception( 'Неверный индекс получателя' ) ), $this->retry_queue( 0 ) );
			$handler->reconcile = true; // even a carrier that CAN reconcile does not retry a refusal.

			Actions\expectDone( 'woodev_shipping_test_shipment_export_failed' )->once();

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'Неверный индекс получателя', $result->get_message() );
			$this->assertSame( [], $this->meta, 'the carrier said no: the order is not «unknown»' );
			$this->assertSame( [], $this->id_writes );
		}

		public function test_a_transport_failure_without_reconcile_is_unknown_not_retried_and_tells_the_merchant_to_check_the_carrier(): void {
			$handler = $this->handler(
				$this->api( new \Woodev_API_Transport_Exception( 'cURL error 28: timed out' ) ),
				$this->retry_queue( 0 )
			);

			Actions\expectDone( 'woodev_shipping_test_shipment_export_failed' )->once();

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertStringContainsString( 'cURL error 28: timed out', $result->get_message() );
			$this->assertStringContainsString( 'мог быть создан', $result->get_message() );
			$this->assertStringContainsString( 'личный кабинет', $result->get_message() );
			$this->assertGreaterThan( 0, $this->meta[55][ self::META ] ?? 0, 'the unknown state is persisted with its time' );
		}

		public function test_a_transport_failure_with_reconcile_is_unknown_and_queued_for_retry(): void {
			$handler = $this->handler(
				$this->api( new \Woodev_API_Transport_Exception( 'cURL error 28: timed out' ) ),
				$this->retry_queue( 1 )
			);
			$handler->reconcile = true;

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'cURL error 28: timed out', $result->get_message() );
			$this->assertGreaterThan( 0, $this->meta[55][ self::META ] ?? 0 );
			$this->assertSame( 0, $handler->lookups, 'the order was not unknown BEFORE this attempt: nothing to reconcile yet' );
		}

		public function test_a_second_transport_failure_keeps_the_time_of_the_first(): void {
			$this->meta[55][ self::META ] = 1000;

			$handler = $this->handler( $this->api( new \Woodev_API_Transport_Exception( 'timed out' ) ), $this->retry_queue( 0 ) );
			$handler->export( $this->order() );

			$this->assertSame( 1000, $this->meta[55][ self::META ] );
		}

		public function test_the_default_classification_is_the_exception_type_and_a_carrier_can_override_it(): void {
			$handler = $this->handler( $this->api( null, 0 ) );

			$this->assertTrue( $handler->classify( new \Woodev_API_Transport_Exception( 'x' ) ) );
			$this->assertFalse( $handler->classify( new \Woodev_API_Exception( 'x' ) ) );
		}

		// ----- D5: the reconcile seam -----

		public function test_an_unknown_order_the_carrier_already_has_is_stored_without_a_create_call(): void {
			$this->meta[55][ self::META ] = 1000;

			$handler        = $this->handler( $this->api( null, 0 ), $this->retry_queue( 0 ) );
			$handler->reconcile = true;
			$handler->found     = 'CARRIER-FOUND';

			Actions\expectDone( 'woodev_shipping_test_shipment_exported' )->once()->with( Mockery::type( '\WC_Order' ), 'CARRIER-FOUND' );
			Actions\expectDone( 'woodev_shipping_order_exported' )->once()->with( Mockery::type( '\WC_Order' ), 'CARRIER-FOUND' );

			$result = $handler->export( $this->order() );

			$this->assertTrue( $result->is_success() );
			$this->assertSame( 'CARRIER-FOUND', $result->get_carrier_order_id() );
			$this->assertSame( 'CARRIER-FOUND', $this->carrier_ids[55], 'the found id is stored' );
			$this->assertArrayNotHasKey( self::META, $this->meta[55], 'the unknown state is cleared' );
			$this->assertSame( 1, $handler->lookups );
		}

		public function test_an_unknown_order_the_carrier_does_not_have_is_created_and_the_state_cleared(): void {
			$this->meta[55][ self::META ] = 1000;

			$handler            = $this->handler( $this->api( null, 1 ) );
			$handler->reconcile = true;
			$handler->found     = null;

			$result = $handler->export( $this->order() );

			$this->assertTrue( $result->is_success() );
			$this->assertSame( 'CARRIER-NEW', $result->get_carrier_order_id() );
			$this->assertSame( 1, $handler->lookups, 'the lookup came FIRST' );
			$this->assertArrayNotHasKey( self::META, $this->meta[55] );
		}

		public function test_an_unknown_order_of_a_carrier_that_cannot_look_up_is_created_by_a_manual_export(): void {
			$this->meta[55][ self::META ] = 1000;

			$handler = $this->handler( $this->api( null, 1 ) );

			$result = $handler->export( $this->order() );

			$this->assertTrue( $result->is_success(), 'a MANUAL export stays allowed' );
			$this->assertSame( 0, $handler->lookups );
			$this->assertArrayNotHasKey( self::META, $this->meta[55] );
		}

		public function test_a_lookup_that_could_not_answer_creates_nothing_and_keeps_the_state(): void {
			$this->meta[55][ self::META ] = 1000;

			$handler            = $this->handler( $this->api( null, 0 ), $this->retry_queue( 1 ) );
			$handler->reconcile = true;
			$handler->found     = new \Woodev_API_Transport_Exception( 'lookup timed out' );

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'lookup timed out', $result->get_message() );
			$this->assertSame( 1000, $this->meta[55][ self::META ], 'still unknown' );
			$this->assertSame( [], $this->id_writes );
		}

		public function test_a_lookup_the_carrier_refused_creates_nothing_and_is_not_retried(): void {
			$this->meta[55][ self::META ] = 1000;

			$handler            = $this->handler( $this->api( null, 0 ), $this->retry_queue( 0 ) );
			$handler->reconcile = true;
			$handler->found     = new \Woodev_API_Exception( 'forbidden' );

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 1000, $this->meta[55][ self::META ] );
		}

		public function test_a_response_without_a_carrier_id_is_unknown_stores_nothing_and_fires_no_hook(): void {
			$handler            = $this->handler( $this->api(), $this->retry_queue( 0 ) );
			$handler->extracted = '';

			Actions\expectDone( 'woodev_shipping_test_shipment_exported' )->never();
			Actions\expectDone( 'woodev_shipping_order_exported' )->never();
			Actions\expectDone( 'woodev_shipping_test_shipment_export_failed' )->once();

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertStringContainsString( 'могла быть создана', $result->get_message() );
			$this->assertStringContainsString( 'личный кабинет', $result->get_message() );
			$this->assertStringNotContainsString( 'не ответил', $result->get_message(), 'the carrier DID answer, only without an id' );
			$this->assertSame( [], $this->id_writes, 'no empty id is stored' );
			$this->assertGreaterThan( 0, $this->meta[55][ self::META ] ?? 0, 'the state is «unknown»' );
		}

		public function test_a_response_without_a_carrier_id_is_queued_for_retry_by_a_carrier_that_can_reconcile(): void {
			$handler            = $this->handler( $this->api(), $this->retry_queue( 1 ) );
			$handler->reconcile = true;
			$handler->extracted = '';

			Actions\expectDone( 'woodev_shipping_test_shipment_exported' )->never();

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertStringNotContainsString( 'личный кабинет', $result->get_message(), 'a reconcile-capable carrier retries: the transport-failure text, not the check-the-account one' );
			$this->assertSame( [], $this->id_writes );
			$this->assertGreaterThan( 0, $this->meta[55][ self::META ] ?? 0 );
		}

		public function test_a_response_without_a_carrier_id_is_unknown_even_when_the_carrier_classifies_every_failure_as_a_refusal(): void {
			$handler            = $this->handler( $this->api(), $this->retry_queue( 0 ) );
			$handler->extracted = '';
			$handler->never_transport = true;

			$handler->export( $this->order() );

			$this->assertGreaterThan( 0, $this->meta[55][ self::META ] ?? 0 );
		}

		public function test_a_third_party_subclass_is_classified_by_the_http_status_it_carries(): void {
			$handler = $this->handler( $this->api( null, 0 ) );

			$this->assertTrue( $handler->classify( new \Idempotency_Test_Api_Exception( 'gateway', 503 ) ) );
			$this->assertFalse( $handler->classify( new \Idempotency_Test_Api_Exception( 'bad zip', 422 ) ) );
			$this->assertFalse( $handler->classify( new \Idempotency_Test_Api_Exception( 'no code' ) ) );
			$this->assertFalse( $handler->classify( new \Idempotency_Test_Api_Exception( 'not http', 600 ) ) );
		}

		public function test_a_plain_exception_is_a_refusal_whatever_code_it_carries(): void {
			$handler = $this->handler( $this->api( null, 0 ) );

			// A carrier error code that happens to lie in 500-599 is not an HTTP status.
			$this->assertFalse( $handler->classify( new \Woodev_API_Exception( 'invalid address', 500 ) ) );
			$this->assertFalse( $handler->classify( new \Woodev_API_Exception( 'invalid address', 510 ) ) );
		}

		public function test_a_subclass_exception_with_a_5xx_code_makes_the_order_unknown(): void {
			$handler = $this->handler( $this->api( new \Idempotency_Test_Api_Exception( 'gateway', 502 ) ), $this->retry_queue( 0 ) );

			$handler->export( $this->order() );

			$this->assertGreaterThan( 0, $this->meta[55][ self::META ] ?? 0 );
		}

		public function test_the_unknown_flag_is_written_to_the_fresh_order_not_to_a_stale_copy(): void {
			$fresh = $this->order( 56 );

			$stale = Mockery::mock( '\WC_Order' );
			$stale->shouldReceive( 'get_id' )->andReturn( 55 );
			$stale->shouldReceive( 'get_meta' )->andReturn( '' );
			$stale->shouldNotReceive( 'update_meta_data' );

			$handler        = $this->handler( $this->api( new \Woodev_API_Transport_Exception( 'timed out' ) ), $this->retry_queue( 0 ) );
			$handler->fresh = $fresh;

			$handler->export( $stale );

			$this->assertGreaterThan( 0, $this->meta[56][ self::META ] ?? 0 );
		}

		public function test_the_unknown_flag_the_stale_copy_never_saw_is_still_seen_and_cleared_on_the_fresh_order(): void {
			// Another request stored the flag after this request loaded its copy of the order.
			$this->meta[56][ self::META ] = 1000;

			$fresh = $this->order( 56 );

			$stale = Mockery::mock( '\WC_Order' );
			$stale->shouldReceive( 'get_id' )->andReturn( 55 );
			$stale->shouldReceive( 'get_meta' )->andReturn( '' );
			$stale->shouldReceive( 'delete_meta_data' );
			$stale->shouldReceive( 'save_meta_data' );

			$handler            = $this->handler( $this->api( null, 0 ), $this->retry_queue( 0 ) );
			$handler->fresh     = $fresh;
			$handler->reconcile = true;
			$handler->found     = 'CARRIER-FOUND';

			$result = $handler->export( $stale );

			$this->assertTrue( $result->is_success(), 'the fresh order carried the flag, so the carrier was asked' );
			$this->assertSame( 1, $handler->lookups );
			$this->assertArrayNotHasKey( self::META, $this->meta[56], 'the flag does not survive beside the stored id' );
		}

		public function test_a_plain_success_leaves_no_unknown_state_behind(): void {
			$result = $this->handler( $this->api() )->export( $this->order() );

			$this->assertTrue( $result->is_success() );
			$this->assertSame( 'CARRIER-NEW', $this->carrier_ids[55] );
			$this->assertArrayNotHasKey( self::META, $this->meta[55] ?? [] );
		}
	}
}
