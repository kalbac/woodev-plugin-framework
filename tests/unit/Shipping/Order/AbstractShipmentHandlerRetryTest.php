<?php
/**
 * Unit: a failed export is retried LATER, with a growing pause and a cap (card #954).
 *
 * Attempt 2 after 1 min, 3 after 5 min, 4 after 30 min, 5 after 2 h; after the 5th failed attempt
 * the framework stops, leaves a note on the order and clears the counter. A success clears the
 * counter; so does a failure that is not retried. An HTTP 429 is retried too — after the
 * `Retry-After` wait, by ANY carrier, because nothing was created — and counts toward the cap.
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
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-export-retry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-transport-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-rate-limit-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-request-purpose.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';

	if ( ! class_exists( 'Retry_Test_Shipment_Handler' ) ) {
		/**
		 * Concrete handler with the lock and fresh-order seams neutralised and reconcile switchable.
		 */
		class Retry_Test_Shipment_Handler extends \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler {

			/** @var bool */
			public bool $reconcile = false;

			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return 'CARRIER-NEW';
			}

			public function supports_reconcile(): bool {
				return $this->reconcile;
			}

			public function find_exported_order( \WC_Order $order ): ?string {
				return null;
			}

			protected function acquire_export_lock( int $order_id ): bool {
				return true;
			}

			protected function release_export_lock( int $order_id ): void {}

			protected function fresh_order( \WC_Order $order ): \WC_Order {
				return $order;
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
	use Woodev\Framework\Shipping\Order\Export_Retry;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Export_Retry
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export
	 */
	final class AbstractShipmentHandlerRetryTest extends TestCase {

		private const ATTEMPTS = Export_Retry::ATTEMPTS_META;

		/** @var array<int, array<string, mixed>> order id => meta */
		private array $meta = [];

		/** @var array<int, string> order id => the stored carrier id */
		private array $carrier_ids = [];

		/** @var string[] every order note written, in order */
		private array $notes = [];

		/** @var array<int, array{timestamp: int, hook: string, args: array, group: string}> every action queued */
		private array $queued = [];

		protected function setUp(): void {
			parent::setUp();

			$this->meta        = [];
			$this->carrier_ids = [];
			$this->notes       = [];
			$this->queued      = [];

			Location_Provider_Registry::instance()->reset_for_tests();

			Functions\when( 'as_has_scheduled_action' )->justReturn( false );
			Functions\when( 'as_schedule_single_action' )->alias(
				function ( $timestamp, $hook, $args = [], $group = '' ) {
					$this->queued[] = [
						'timestamp' => $timestamp,
						'hook'      => $hook,
						'args'      => $args,
						'group'     => $group,
					];

					return 100 + count( $this->queued );
				}
			);
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		private function order( int $id = 55 ) {
			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( $id );
			$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( $key ) => $this->meta[ $id ][ $key ] ?? '' );
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
			// The note must be PRIVATE: exactly one argument, never the «customer note» flag.
			$order->shouldReceive( 'add_order_note' )->andReturnUsing(
				function ( ...$args ) {
					$this->assertCount( 1, $args, 'the note is a private one — no customer-note flag' );
					$this->notes[] = $args[0];
				}
			);

			return $order;
		}

		private function handler( ?\Throwable $throws, bool $reconcile = false ): \Retry_Test_Shipment_Handler {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );

			$expectation = $api->shouldReceive( 'create_order' );

			if ( null !== $throws ) {
				$expectation->andThrow( $throws );
			} else {
				$expectation->andReturn( Mockery::mock( '\Woodev_API_Response' ) );
			}

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturnUsing(
				fn( $order ) => $this->carrier_ids[ $order->get_id() ] ?? ''
			);
			$order_handler->shouldReceive( 'set' )->andReturnUsing(
				function ( $order, $logical, $value ) {
					$this->carrier_ids[ $order->get_id() ] = $value;
				}
			);

			$handler            = new \Retry_Test_Shipment_Handler( $api, $order_handler, 'test', Mockery::mock( Popular_Settlement_Store::class ) );
			$handler->reconcile = $reconcile;

			return $handler;
		}

		/**
		 * The wait of the N-th queued action, in seconds from now (the test runs in well under a second).
		 *
		 * @param int $index 0-based index in the queue.
		 * @return int
		 */
		private function queued_delay( int $index ): int {
			return $this->queued[ $index ]['timestamp'] - time();
		}

		private function assertDelay( int $expected, int $index ): void {
			$this->assertEqualsWithDelta( $expected, $this->queued_delay( $index ), 3, 'queued action #' . $index );
		}

		// ----- T3: backoff + cap -----

		public function test_five_failed_attempts_back_off_then_stop_with_a_note_and_a_cleared_counter(): void {
			$order   = $this->order();
			$handler = $this->handler( new \Woodev_API_Transport_Exception( 'cURL error 28: timed out' ), true );

			for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
				$result = $handler->export( $order );

				$this->assertFalse( $result->is_success() );
				$this->assertSame( $attempt, $this->meta[55][ self::ATTEMPTS ], "attempt $attempt is counted" );
				$this->assertCount( $attempt, $this->queued, "attempt $attempt schedules the next one" );
				$this->assertSame( [], $this->notes, 'no note while attempts remain' );
			}

			$this->assertDelay( 60, 0 );
			$this->assertDelay( 300, 1 );
			$this->assertDelay( 1800, 2 );
			$this->assertDelay( 7200, 3 );

			foreach ( $this->queued as $action ) {
				$this->assertSame( Export_Retry::HOOK, $action['hook'] );
				$this->assertSame( [ 55 ], $action['args'] );
				$this->assertSame( Export_Retry::GROUP, $action['group'] );
			}

			$fifth = $handler->export( $order );

			$this->assertFalse( $fifth->is_success() );
			$this->assertCount( 4, $this->queued, 'the fifth failure schedules nothing' );
			$this->assertSame( [ 'Не удалось выгрузить заказ перевозчику за 5 попыток: cURL error 28: timed out' ], $this->notes );
			$this->assertSame(
				'Не удалось выгрузить заказ перевозчику за 5 попыток: cURL error 28: timed out',
				$fifth->get_message(),
				'the merchant is told the same'
			);
			$this->assertArrayNotHasKey( self::ATTEMPTS, $this->meta[55], 'the counter is cleared at the cap' );
		}

		public function test_after_the_cap_a_manual_export_starts_a_fresh_chain(): void {
			$order   = $this->order();
			$handler = $this->handler( new \Woodev_API_Transport_Exception( 'timed out' ), true );

			$this->meta[55][ self::ATTEMPTS ] = 4;
			$handler->export( $order );
			$this->assertArrayNotHasKey( self::ATTEMPTS, $this->meta[55] );

			$handler->export( $order );

			$this->assertSame( 1, $this->meta[55][ self::ATTEMPTS ] );
			$this->assertCount( 1, $this->queued );
			$this->assertDelay( 60, 0 );
		}

		public function test_a_success_clears_the_counter(): void {
			$this->meta[55] = [
				self::ATTEMPTS                            => 3,
				Abstract_Shipment_Handler::EXPORT_UNKNOWN_META => 1000,
			];

			$result = $this->handler( null, true )->export( $this->order() );

			$this->assertTrue( $result->is_success() );
			$this->assertSame( [], $this->meta[55], 'the counter and the unknown flag are both gone' );
			$this->assertSame( [], $this->queued );
		}

		public function test_a_refusal_ends_the_chain_and_clears_the_counter(): void {
			$this->meta[55][ self::ATTEMPTS ] = 2;

			$result = $this->handler( new \Woodev_API_Exception( 'Неверный индекс получателя' ), true )->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'Неверный индекс получателя', $result->get_message() );
			$this->assertArrayNotHasKey( self::ATTEMPTS, $this->meta[55] );
			$this->assertSame( [], $this->queued );
			$this->assertSame( [], $this->notes );
		}

		public function test_a_transport_failure_of_a_carrier_that_cannot_reconcile_is_not_retried(): void {
			$this->meta[55][ self::ATTEMPTS ] = 2;

			$result = $this->handler( new \Woodev_API_Transport_Exception( 'timed out' ), false )->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( [], $this->queued );
			$this->assertArrayNotHasKey( self::ATTEMPTS, $this->meta[55], 'an unretried failure ends the chain' );
		}

		// ----- T4: 429 -----

		public function test_a_429_is_retried_after_its_retry_after_by_a_carrier_that_cannot_reconcile(): void {
			$handler = $this->handler( new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 120 ), false );

			$result = $handler->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertCount( 1, $this->queued );
			$this->assertDelay( 120, 0 );
			$this->assertSame( 1, $this->meta[55][ self::ATTEMPTS ] );
			$this->assertArrayNotHasKey( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META, $this->meta[55], 'a 429 created nothing: the order is not «unknown»' );
			$this->assertStringContainsString( 'повторена автоматически', $result->get_message() );
			$this->assertStringNotContainsString( 'мог быть создан', $result->get_message() );
		}

		public function test_a_429_without_retry_after_follows_the_standard_schedule(): void {
			$order   = $this->order();
			$handler = $this->handler( new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests' ), false );

			$handler->export( $order );
			$handler->export( $order );

			$this->assertCount( 2, $this->queued );
			$this->assertDelay( 60, 0 );
			$this->assertDelay( 300, 1 );
		}

		public function test_a_zero_retry_after_still_waits_a_moment(): void {
			$before = time();

			$this->handler( new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 0 ), false )->export( $this->order() );

			$this->assertGreaterThanOrEqual( $before + 1, $this->queued[0]['timestamp'], 'never a hot loop' );
		}

		public function test_a_429_counts_toward_the_same_five_attempts(): void {
			$this->meta[55][ self::ATTEMPTS ] = 4;

			$result = $this->handler( new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 10 ), false )->export( $this->order() );

			$this->assertSame( [], $this->queued );
			$this->assertSame( [ 'Не удалось выгрузить заказ перевозчику за 5 попыток: Too Many Requests' ], $this->notes );
			$this->assertStringContainsString( 'за 5 попыток', $result->get_message() );
			$this->assertArrayNotHasKey( self::ATTEMPTS, $this->meta[55] );
		}

		public function test_the_last_error_in_the_note_is_redacted(): void {
			$this->meta[55][ self::ATTEMPTS ] = 4;

			$this->handler( new \Woodev_API_Transport_Exception( 'GET https://api.example.test/x?api_key=LIVESECRET failed' ), true )->export( $this->order() );

			$this->assertCount( 1, $this->notes );
			$this->assertStringNotContainsString( 'LIVESECRET', $this->notes[0] );
		}

		// ----- the queue seam -----

		public function test_an_attempt_already_waiting_for_the_order_is_not_doubled(): void {
			Functions\when( 'as_has_scheduled_action' )->alias(
				function ( $hook, $args, $group ) {
					return Export_Retry::HOOK === $hook && [ 55 ] === $args && Export_Retry::GROUP === $group;
				}
			);

			$result = $this->handler( new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 ), false )->export( $this->order() );

			$this->assertSame( [], $this->queued, 'one waiting attempt per order' );
			$this->assertStringContainsString( 'повторена автоматически', $result->get_message() );
		}

		public function test_a_missing_action_scheduler_degrades_to_a_plain_failure(): void {
			Functions\when( 'function_exists' )->alias(
				static fn( $name ) => 'as_schedule_single_action' === $name ? false : \function_exists( $name )
			);

			$result = $this->handler( new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 ), false )->export( $this->order() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( [], $this->queued );
			$this->assertSame( 'Too Many Requests', $result->get_message(), 'the carrier text, not a promise of a retry that will not come' );
		}

		public function test_enqueue_is_the_reusable_seam_for_a_background_export(): void {
			$this->assertTrue( Export_Retry::enqueue( 77, 0 ) );

			$this->assertCount( 1, $this->queued );
			$this->assertSame( Export_Retry::HOOK, $this->queued[0]['hook'] );
			$this->assertSame( [ 77 ], $this->queued[0]['args'] );
			$this->assertSame( Export_Retry::GROUP, $this->queued[0]['group'] );
			$this->assertDelay( 0, 0 );
		}

		public function test_the_installed_site_contract_names_are_pinned(): void {
			$this->assertSame( 'woodev_shipping_export_retry', Export_Retry::HOOK );
			$this->assertSame( 'woodev-shipping', Export_Retry::GROUP );
			$this->assertSame( '_woodev_shipment_export_attempts', Export_Retry::ATTEMPTS_META );
			$this->assertSame( 5, Export_Retry::MAX_ATTEMPTS );
			$this->assertSame( [ 60, 300, 1800, 7200, null ], array_map( [ Export_Retry::class, 'delay_after' ], [ 1, 2, 3, 4, 5 ] ) );
		}
	}
}
