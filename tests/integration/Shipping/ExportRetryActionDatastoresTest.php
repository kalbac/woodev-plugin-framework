<?php
/**
 * Integration: a delayed export retry really goes through Action Scheduler, runs, and exports —
 * on BOTH WooCommerce order datastores, HPOS and the legacy CPT (card #954).
 *
 * What the unit tier cannot see. The unit tests pin the schedule and the cap against doubles of the
 * order and of the queue. Here the REAL Action Scheduler holds the action, its REAL queue runner
 * carries it out through the framework-owned hook ({@see Export_Retry::HOOK}) and
 * `Orders_Registry::run_export_retry()` finds the carrier's handler, and everything the retry keeps on
 * the order — the attempt counter, the «export unknown» flag, the give-up note — is read back from a
 * FRESH order object, so a value that lands on one datastore only is caught.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping {

	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
	use Woodev\Framework\Shipping\Order\Export_Retry;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Tests\Integration\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Export_Retry
	 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::run_export_retry
	 */
	class ExportRetryActionDatastoresTest extends TestCase {

		private const PROVIDER_ID     = 'retry_carrier';
		private const MARKER_META     = '_woodev_retry_marker';
		private const CARRIER_ID_META = '_woodev_retry_carrier_order_id';

		/** @var \Woodev_Retry_Fake_Api */
		private $api;

		/** @var \Woodev_Retry_Shipment_Handler */
		private $handler;

		protected function setUp(): void {
			parent::setUp();

			$this->api     = new \Woodev_Retry_Fake_Api();
			$this->handler = new \Woodev_Retry_Shipment_Handler(
				$this->api,
				new Shipping_Order_Handler( [ 'carrier_order_id' => self::CARRIER_ID_META ] ),
				'retry'
			);

			// A fresh registry: WP_UnitTestCase restores the hooks after every test, so a registry that
			// remembered it had already hooked itself would leave the retry action unhooked.
			$registry = Orders_Registry::instance();
			$registry->reset_for_tests();
			$registry->register_provider(
				Orders_Provider::create(
					self::PROVIDER_ID,
					'Retry carrier',
					self::MARKER_META,
					[ self::PROVIDER_ID ],
					[ 'carrier_order_id_meta_key' => self::CARRIER_ID_META ]
				)
			);
			$registry->register_shipment_handler( self::PROVIDER_ID, $this->handler );
		}

		protected function tearDown(): void {
			Orders_Registry::instance()->reset_for_tests();

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
			$order->update_meta_data( self::MARKER_META, '1' );
			$order->save();

			return $order;
		}

		private function reread( \WC_Order $order ): \WC_Order {
			wp_cache_flush();

			$fresh = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( \WC_Order::class, $fresh );

			return $fresh;
		}

		/**
		 * The pending export attempts of an order in the Action Scheduler queue.
		 *
		 * @param int    $order_id the order.
		 * @param string $status   an `ActionScheduler_Store` status.
		 * @return int[] action ids.
		 */
		private function actions_of( int $order_id, string $status = \ActionScheduler_Store::STATUS_PENDING ): array {
			return as_get_scheduled_actions(
				[
					'hook'     => Export_Retry::HOOK,
					'args'     => [ $order_id ],
					'group'    => Export_Retry::GROUP,
					'status'   => $status,
					'per_page' => -1,
				],
				'ids'
			);
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_429_schedules_a_delayed_attempt_that_the_queue_runner_carries_out( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 600 );

			$before = time();
			$first  = $this->handler->export( $order );

			$this->assertFalse( $first->is_success() );
			$this->assertSame( 1, $this->api->create_calls );

			$pending = $this->actions_of( $order->get_id() );
			$this->assertCount( 1, $pending, 'one attempt waits in the queue' );

			$scheduled_for = \ActionScheduler::store()->get_date( $pending[0] )->getTimestamp();
			$this->assertEqualsWithDelta( $before + 600, $scheduled_for, 5, 'after the Retry-After the carrier asked for' );

			$fresh = $this->reread( $order );
			$this->assertSame( 1, (int) $fresh->get_meta( Export_Retry::ATTEMPTS_META ), 'the failed attempt is counted on the datastore' );
			$this->assertSame( '', (string) $fresh->get_meta( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ), 'a 429 created nothing: the order is not «unknown»' );

			// The carrier recovers; the queue runner reaches the scheduled action.
			$this->api->fail_with = null;
			\ActionScheduler::runner()->process_action( $pending[0], 'Woodev export retry test' );

			$this->assertSame( 2, $this->api->create_calls, 'the scheduled action exported the order' );
			$this->assertSame( [ (int) $pending[0] ], array_map( 'intval', $this->actions_of( $order->get_id(), \ActionScheduler_Store::STATUS_COMPLETE ) ) );
			$this->assertSame( [], $this->actions_of( $order->get_id() ), 'nothing is left waiting' );

			$done = $this->reread( $order );
			$this->assertSame( 'CARRIER-' . $order->get_id(), $done->get_meta( self::CARRIER_ID_META ) );
			$this->assertSame( '', (string) $done->get_meta( Export_Retry::ATTEMPTS_META ), 'a success clears the counter' );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_timeout_of_a_carrier_that_can_reconcile_is_retried_through_the_queue( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->handler->reconcile = true;
			$this->api->fail_with     = new \Woodev_API_Transport_Exception( 'cURL error 28: Operation timed out' );

			$before = time();
			$this->handler->export( $order );

			$pending = $this->actions_of( $order->get_id() );
			$this->assertCount( 1, $pending );
			$this->assertEqualsWithDelta( $before + 60, \ActionScheduler::store()->get_date( $pending[0] )->getTimestamp(), 5, 'the first retry waits a minute' );

			// The carrier has the order after all: the scheduled attempt reconciles instead of creating.
			$this->handler->found = 'CARRIER-FOUND';
			\ActionScheduler::runner()->process_action( $pending[0], 'Woodev export retry test' );

			$this->assertSame( 1, $this->api->create_calls, 'no second order is created' );

			$done = $this->reread( $order );
			$this->assertSame( 'CARRIER-FOUND', $done->get_meta( self::CARRIER_ID_META ) );
			$this->assertSame( '', (string) $done->get_meta( Export_Retry::ATTEMPTS_META ) );
			$this->assertSame( '', (string) $done->get_meta( Abstract_Shipment_Handler::EXPORT_UNKNOWN_META ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_the_fifth_failed_attempt_stops_with_a_private_note_and_a_cleared_counter( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$order->update_meta_data( Export_Retry::ATTEMPTS_META, 4 );
			$order->save();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 );

			$result = $this->handler->export( $this->reread( $order ) );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( [], $this->actions_of( $order->get_id() ), 'no sixth attempt' );

			$fresh = $this->reread( $order );
			$this->assertSame( '', (string) $fresh->get_meta( Export_Retry::ATTEMPTS_META ), 'the counter is cleared' );

			$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
			$texts = array_map( static fn( $note ) => $note->content, $notes );

			$this->assertContains( 'Не удалось выгрузить заказ перевозчику за 5 попыток: Too Many Requests', $texts );

			foreach ( $notes as $note ) {
				$this->assertFalse( (bool) $note->customer_note, 'the note is private: the buyer never sees the carrier\'s text' );
			}
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_due_attempt_for_an_order_exported_meanwhile_does_nothing( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 );
			$this->handler->export( $order );

			$pending = $this->actions_of( $order->get_id() );
			$this->assertCount( 1, $pending );

			// The merchant exports by hand before the attempt is due.
			$this->api->fail_with = null;
			$this->assertTrue( $this->handler->export( $this->reread( $order ) )->is_success() );
			$this->assertSame( 2, $this->api->create_calls );

			\ActionScheduler::runner()->process_action( $pending[0], 'Woodev export retry test' );

			$this->assertSame( 2, $this->api->create_calls, 'the order is not sent a second time' );
		}
	}
}

// The carrier fixtures live AFTER the test class on purpose: IntegrationSuiteBaseClassTest reads the
// first `class … extends` of a file and must see the test case.

namespace {

	if ( ! class_exists( 'Woodev_Retry_Fake_Response' ) ) {

		/**
		 * A carrier response carrying just an order id.
		 */
		class Woodev_Retry_Fake_Response implements \Woodev_API_Response {

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

	if ( ! class_exists( 'Woodev_Retry_Fake_Api' ) ) {

		/**
		 * Offline `Shipping_API` that counts `create_order()` calls and can be told to fail.
		 */
		class Woodev_Retry_Fake_Api implements \Woodev\Framework\Shipping\Api\Shipping_API {

			/** @var int */
			public int $create_calls = 0;

			/** @var \Throwable|null thrown by create_order() when set */
			public $fail_with;

			/** @inheritDoc */
			public function create_order( \WC_Order $order ): \Woodev_API_Response {
				++$this->create_calls;

				if ( null !== $this->fail_with ) {
					throw $this->fail_with;
				}

				return new Woodev_Retry_Fake_Response( 'CARRIER-' . $order->get_id() );
			}

			/** @inheritDoc */
			public function cancel_order( string $order_id ): \Woodev_API_Response {
				return new Woodev_Retry_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function calculate_rates( array $params ): \Woodev_API_Response {
				return new Woodev_Retry_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_pickup_points( array $params ): \Woodev_API_Response {
				return new Woodev_Retry_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_order( string $order_id ): \Woodev_API_Response {
				return new Woodev_Retry_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_tracking( string $tracking_number ): \Woodev_API_Response {
				return new Woodev_Retry_Fake_Response( '' );
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

	if ( ! class_exists( 'Woodev_Retry_Shipment_Handler' ) ) {

		/**
		 * The REAL handler, lock and order re-read included; only the carrier is fake.
		 */
		class Woodev_Retry_Shipment_Handler extends \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler {

			/** @var bool */
			public bool $reconcile = false;

			/** @var string|null what find_exported_order() answers */
			public $found;

			/** @inheritDoc */
			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return $response instanceof Woodev_Retry_Fake_Response ? $response->order_id : '';
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
