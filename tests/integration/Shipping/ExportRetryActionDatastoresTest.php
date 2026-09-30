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
		 * The seconds from now an action is scheduled for.
		 *
		 * @param int $action_id the Action Scheduler action.
		 * @return int
		 */
		private function scheduled_in( int $action_id ): int {
			return \ActionScheduler::store()->get_date( $action_id )->getTimestamp() - time();
		}

		/**
		 * The one attempt waiting for an order, asserted to be the only one.
		 *
		 * @param int $order_id the order.
		 * @return int the action id.
		 */
		private function the_pending_attempt( int $order_id ): int {
			$pending = $this->actions_of( $order_id );

			$this->assertCount( 1, $pending, 'exactly one attempt waits for the order' );

			return (int) $pending[0];
		}

		/**
		 * Carries an action out through the real queue runner — it is `in-progress` while it runs.
		 *
		 * @param int $action_id the action.
		 * @return void
		 */
		private function run_action( int $action_id ): void {
			\ActionScheduler::runner()->process_action( $action_id, 'Woodev export retry test' );
		}

		/**
		 * The native edit lock of another manager, written for the selected datastore.
		 *
		 * @param \WC_Order $order the order.
		 * @param bool      $hpos  active datastore.
		 * @return void
		 */
		private function lock_for_another_manager( \WC_Order $order, bool $hpos ): void {
			$manager = self::factory()->user->create( [ 'role' => 'shop_manager' ] );

			if ( ! $hpos ) {
				update_post_meta( $order->get_id(), '_edit_lock', time() . ':' . $manager );

				return;
			}

			$fresh = $this->reread( $order );
			$fresh->update_meta_data( '_edit_lock', time() . ':' . $manager );
			$fresh->save_meta_data();
		}

		/**
		 * @param \WC_Order $order the order.
		 * @param bool      $hpos  active datastore.
		 * @return void
		 */
		private function release_edit_lock( \WC_Order $order, bool $hpos ): void {
			if ( ! $hpos ) {
				delete_post_meta( $order->get_id(), '_edit_lock' );

				return;
			}

			$fresh = $this->reread( $order );
			$fresh->delete_meta_data( '_edit_lock' );
			$fresh->save_meta_data();
		}

		/**
		 * @param \WC_Order $order the order.
		 * @return string[] the contents of the order's notes.
		 */
		private function note_texts( \WC_Order $order ): array {
			return array_map( static fn( $note ) => $note->content, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
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
		 * The defect of round 1: the «already scheduled?» guard saw the action that was RUNNING, so the
		 * chain died after the first automatic retry — two attempts instead of five, no note, a stuck counter.
		 *
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_an_attempt_that_fails_inside_the_queue_runner_queues_the_next_one( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 );
			$this->handler->export( $order );

			$second = $this->the_pending_attempt( $order->get_id() );

			// The carrier is still throttling when attempt 2 runs — inside the real runner.
			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests' );
			$this->run_action( $second );

			$this->assertSame( 2, $this->api->create_calls );
			$this->assertSame( [ $second ], array_map( 'intval', $this->actions_of( $order->get_id(), \ActionScheduler_Store::STATUS_COMPLETE ) ) );

			$third = $this->the_pending_attempt( $order->get_id() );
			$this->assertNotSame( $second, $third );
			$this->assertEqualsWithDelta( 300, $this->scheduled_in( $third ), 5, 'attempt 3 waits five minutes' );
			$this->assertSame( 2, (int) $this->reread( $order )->get_meta( Export_Retry::ATTEMPTS_META ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_chain_that_keeps_failing_makes_exactly_five_attempts_then_stops_with_a_note( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests' );
			$this->handler->export( $order );

			// Failures 1..4 each queue the next attempt, after 1 min, 5 min, 30 min, 2 h.
			foreach ( [ 60, 300, 1800, 7200 ] as $index => $wait ) {
				$attempt = $this->the_pending_attempt( $order->get_id() );

				$this->assertEqualsWithDelta( $wait, $this->scheduled_in( $attempt ), 5, 'wait before attempt ' . ( $index + 2 ) );
				$this->assertSame( $index + 1, (int) $this->reread( $order )->get_meta( Export_Retry::ATTEMPTS_META ) );

				$this->run_action( $attempt );
			}

			$this->assertSame( 5, $this->api->create_calls, 'the first export plus four retries — five attempts in all' );
			$this->assertSame( [], $this->actions_of( $order->get_id() ), 'no sixth attempt' );

			$fresh = $this->reread( $order );
			$this->assertSame( '', (string) $fresh->get_meta( Export_Retry::ATTEMPTS_META ), 'the counter is cleared' );
			$this->assertSame( '', (string) $fresh->get_meta( Export_Retry::DEFERRALS_META ) );
			$this->assertSame(
				1,
				count( array_keys( $this->note_texts( $order ), 'Не удалось выгрузить заказ перевозчику за 5 попыток: Too Many Requests', true ) ),
				'one note, written once'
			);
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_due_attempt_under_an_edit_lock_is_put_back_uncounted_and_exports_once_the_lock_lets_go( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 );
			$this->handler->export( $order );

			$this->lock_for_another_manager( $order, $hpos );

			$first = $this->the_pending_attempt( $order->get_id() );
			$this->run_action( $first );

			$this->assertSame( 1, $this->api->create_calls, 'the carrier is not called under a lock' );

			$again = $this->the_pending_attempt( $order->get_id() );
			$this->assertNotSame( $first, $again );
			$this->assertEqualsWithDelta( 300, $this->scheduled_in( $again ), 5, 'back in five minutes' );

			$fresh = $this->reread( $order );
			$this->assertSame( 1, (int) $fresh->get_meta( Export_Retry::ATTEMPTS_META ), 'a lock is not an attempt' );
			$this->assertSame( 1, (int) $fresh->get_meta( Export_Retry::DEFERRALS_META ) );

			// The lock goes away; the attempt that comes back exports, and both counters clear.
			$this->release_edit_lock( $order, $hpos );
			$this->api->fail_with = null;
			$this->run_action( $again );

			$this->assertSame( 2, $this->api->create_calls );
			$this->assertSame( [], $this->actions_of( $order->get_id() ) );

			$done = $this->reread( $order );
			$this->assertSame( 'CARRIER-' . $order->get_id(), $done->get_meta( self::CARRIER_ID_META ) );
			$this->assertSame( '', (string) $done->get_meta( Export_Retry::ATTEMPTS_META ) );
			$this->assertSame( '', (string) $done->get_meta( Export_Retry::DEFERRALS_META ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_due_attempt_that_meets_the_export_lock_is_put_back_uncounted( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 );
			$this->handler->export( $order );

			$this->handler->lock_free = false; // a concurrent click holds the per-order export lock.
			$this->run_action( $this->the_pending_attempt( $order->get_id() ) );

			$this->assertSame( 1, $this->api->create_calls );
			$again = $this->the_pending_attempt( $order->get_id() );
			$this->assertEqualsWithDelta( 300, $this->scheduled_in( $again ), 5 );

			$fresh = $this->reread( $order );
			$this->assertSame( 1, (int) $fresh->get_meta( Export_Retry::ATTEMPTS_META ) );
			$this->assertSame( 1, (int) $fresh->get_meta( Export_Retry::DEFERRALS_META ) );

			$this->handler->lock_free = true;
			$this->api->fail_with     = null;
			$this->run_action( $again );

			$this->assertSame( 2, $this->api->create_calls );
			$this->assertSame( [], $this->actions_of( $order->get_id() ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_lock_that_never_lets_go_is_put_back_at_most_24_times_then_counts_as_a_failed_attempt( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 );
			$this->handler->export( $order );

			$this->lock_for_another_manager( $order, $hpos );

			for ( $round = 1; $round <= Export_Retry::MAX_DEFERRALS; $round++ ) {
				$this->run_action( $this->the_pending_attempt( $order->get_id() ) );

				$fresh = $this->reread( $order );
				$this->assertSame( $round, (int) $fresh->get_meta( Export_Retry::DEFERRALS_META ), "deferral $round" );
				$this->assertSame( 1, (int) $fresh->get_meta( Export_Retry::ATTEMPTS_META ), 'still one failed attempt' );
			}

			$this->assertSame( 1, $this->api->create_calls, 'the carrier was never called under the lock' );

			// The 25th time the order is still locked: it is booked as a failed attempt.
			$this->run_action( $this->the_pending_attempt( $order->get_id() ) );

			$this->assertSame( 2, (int) $this->reread( $order )->get_meta( Export_Retry::ATTEMPTS_META ) );
			$this->assertEqualsWithDelta( 300, $this->scheduled_in( $this->the_pending_attempt( $order->get_id() ) ), 5 );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_manual_failure_while_an_attempt_waits_leaves_one_pending_action_and_a_true_count( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 3600 );
			$this->handler->export( $order );
			$this->assertEqualsWithDelta( 3600, $this->scheduled_in( $this->the_pending_attempt( $order->get_id() ) ), 5 );

			// The merchant clicks «Выгрузить» while the hour-long wait is queued; the carrier times out.
			$this->handler->reconcile = true;
			$this->api->fail_with     = new \Woodev_API_Transport_Exception( 'cURL error 28: Operation timed out' );
			$this->handler->export( $this->reread( $order ) );

			$pending = $this->the_pending_attempt( $order->get_id() );
			$this->assertEqualsWithDelta( 300, $this->scheduled_in( $pending ), 5, 'the newer failure sets the time, not the older one' );
			$this->assertSame( 2, (int) $this->reread( $order )->get_meta( Export_Retry::ATTEMPTS_META ), 'the carrier was called twice' );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_due_attempt_whose_carrier_is_gone_ends_the_chain_with_a_note( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->fail_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 60 );
			$this->handler->export( $order );
			$attempt = $this->the_pending_attempt( $order->get_id() );

			// The carrier's plugin is deactivated: the provider is still resolvable from the marker, the handler is not.
			$registry = Orders_Registry::instance();
			$registry->reset_for_tests();
			$registry->register_provider(
				Orders_Provider::create( self::PROVIDER_ID, 'Retry carrier', self::MARKER_META, [ self::PROVIDER_ID ], [ 'carrier_order_id_meta_key' => self::CARRIER_ID_META ] )
			);

			$this->api->fail_with = null;
			$this->run_action( $attempt );

			$this->assertSame( 1, $this->api->create_calls, 'nobody exported' );
			$this->assertSame( [], $this->actions_of( $order->get_id() ) );
			$this->assertSame( '', (string) $this->reread( $order )->get_meta( Export_Retry::ATTEMPTS_META ), 'the counter is cleared' );
			$this->assertContains( 'Повтор выгрузки не выполнен: перевозчик не найден (плагин отключён?)', $this->note_texts( $order ) );
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

			/** @var bool false => the per-order export lock is held by someone else, so export() answers «busy» */
			public bool $lock_free = true;

			/** @inheritDoc */
			protected function acquire_export_lock( int $order_id ): bool {
				return $this->lock_free && parent::acquire_export_lock( $order_id );
			}

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
