<?php
/**
 * Integration: the background auto-export by order status, the automatic cancellation at the carrier,
 * and the «exports in progress» count — on BOTH WooCommerce order datastores, HPOS and the legacy CPT
 * (card #1007).
 *
 * What the unit tier cannot see. The unit tests pin the decisions against doubles of the order and of the
 * queue. Here a REAL status change on a REAL order fires WooCommerce's `woocommerce_order_status_changed`,
 * the framework queues its action on the REAL Action Scheduler, the queue runner carries it out through the
 * framework-owned hook, and everything the automation keeps on the order — the carrier id, the notes, the
 * «cancellation failed» marker — is read back from a FRESH order object, so a value that lands on one
 * datastore only is caught. The carrier is the only fake.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping {

	use Woodev\Framework\Settings\Settings_Page_Registry;
	use Woodev\Framework\Shipping\Admin\Orders\Export_Queue_Notice;
	use Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Order\Carrier_Cancel;
	use Woodev\Framework\Shipping\Order\Export_Queue;
	use Woodev\Framework\Shipping\Order\Export_Retry;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Framework\Shipping\Settings\Export_Settings;
	use Woodev\Tests\Integration\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Order_Automation
	 * @covers \Woodev\Framework\Shipping\Order\Carrier_Cancel
	 * @covers \Woodev\Framework\Shipping\Order\Export_Queue
	 * @covers \Woodev\Framework\Shipping\Admin\Orders\Export_Queue_Notice
	 */
	class AutoExportAndCancelDatastoresTest extends TestCase {

		private const PROVIDER_ID     = 'auto_carrier';
		private const MARKER_META     = '_woodev_auto_marker';
		private const CARRIER_ID_META = '_woodev_auto_carrier_order_id';
		private const STATUS_META     = '_woodev_auto_status';

		/** @var \Woodev_Auto_Fake_Api */
		private $api;

		/** @var \Woodev_Auto_Shipment_Handler */
		private $handler;

		protected function setUp(): void {
			parent::setUp();

			$this->api     = new \Woodev_Auto_Fake_Api();
			$this->handler = new \Woodev_Auto_Shipment_Handler(
				$this->api,
				new Shipping_Order_Handler( [ 'carrier_order_id' => self::CARRIER_ID_META ] ),
				'auto'
			);

			// The fixture shipping plugin is the carrier's real `Shipping_Plugin`: its «Выгрузка» settings (the
			// plugin's own tab on `woodev-settings`) are where the merchant's «Автоэкспорт» choice lives.
			$plugin = \woodev_test_shipping_method_plugin();

			$registry = Orders_Registry::instance();
			$registry->reset_for_tests();
			$registry->register_provider(
				Orders_Provider::create(
					self::PROVIDER_ID,
					'Auto carrier',
					self::MARKER_META,
					[ self::PROVIDER_ID ],
					[
						'carrier_order_id_meta_key' => self::CARRIER_ID_META,
						'status_meta_key'           => self::STATUS_META,
						'status_map'                => [ 'DELIVERED' => 'delivered' ],
					]
				),
				$plugin
			);
			$registry->register_shipment_handler( self::PROVIDER_ID, $this->handler );

			$this->set_settings( true, [ 'wc-processing' ] );
		}

		protected function tearDown(): void {
			$plugin = \woodev_test_shipping_method_plugin();

			$this->reset_export_settings();
			unload_textdomain( 'woodev-plugin-framework' );
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

		/**
		 * The merchant's per-carrier settings, stored the way WooCommerce stores an integration's.
		 *
		 * @param array<string,mixed> $settings option values.
		 * @return void
		 */
		/**
		 * Sets the carrier's «Выгрузка» settings the way the settings page does — through the plugin's own
		 * handler, so its in-memory copy and the stored options agree.
		 *
		 * @param bool     $auto_export whether auto-export is on.
		 * @param string[] $statuses    the `wc-` statuses it fires on.
		 */
		private function set_settings( bool $auto_export, array $statuses ): void {
			$settings = \woodev_test_shipping_method_plugin()->get_export_settings();

			$settings->update_value( 'auto_export_orders', $auto_export );
			$settings->update_value( 'export_statuses', $statuses );
		}

		/** Back to a clean slate: the plugin's handler at its defaults, the stored options and the v1 option gone. */
		private function reset_export_settings(): void {
			$plugin = \woodev_test_shipping_method_plugin();
			$prefix = 'woodev_' . $plugin->get_id_underscored() . '_export_';

			$this->set_settings( false, [ 'wc-processing' ] );

			delete_option( $prefix . 'auto_export_orders' );
			delete_option( $prefix . 'export_statuses' );
			delete_option( $prefix . 'migrated_from_integration' );
			delete_option( 'woocommerce_' . $plugin->get_id_underscored() . '_settings' );
		}

		/** A pending order of this carrier — the status change under test is made by the caller. */
		private function new_order( bool $carrier_order = true ): \WC_Order {
			$order = wc_create_order();

			if ( $carrier_order ) {
				$order->update_meta_data( self::MARKER_META, '1' );
			}

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
		 * @param string $hook     the Action Scheduler hook.
		 * @param int    $order_id the order.
		 * @param string $status   an `ActionScheduler_Store` status.
		 * @return int[] action ids.
		 */
		private function actions_of( string $hook, int $order_id, string $status = \ActionScheduler_Store::STATUS_PENDING ): array {
			return array_map(
				'intval',
				as_get_scheduled_actions(
					[
						'hook'     => $hook,
						'args'     => [ $order_id ],
						'group'    => Export_Retry::GROUP,
						'status'   => $status,
						'per_page' => -1,
					],
					'ids'
				)
			);
		}

		private function run_action( int $action_id ): void {
			\ActionScheduler::runner()->process_action( $action_id, 'Woodev auto export test' );
		}

		/**
		 * @param \WC_Order $order the order.
		 * @return object[] the order's notes.
		 */
		private function notes( \WC_Order $order ): array {
			return wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		}

		/**
		 * @param \WC_Order $order the order.
		 * @return string[] the contents of the order's notes.
		 */
		private function note_texts( \WC_Order $order ): array {
			return array_map( static fn( $note ) => $note->content, $this->notes( $order ) );
		}

		private function export_by_hand( \WC_Order $order ): void {
			$this->assertTrue( $this->handler->export( $this->reread( $order ) )->is_success() );
		}

		// ----- auto-export -----

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_entering_a_configured_status_queues_one_background_export_that_the_queue_runner_carries_out( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$order->update_status( 'processing' );

			$this->assertSame( 0, $this->api->create_calls, 'the carrier is never called inside the status change' );

			$pending = $this->actions_of( Export_Retry::HOOK, $order->get_id() );
			$this->assertCount( 1, $pending, 'exactly one export waits in the queue' );

			$this->run_action( $pending[0] );

			$this->assertSame( 1, $this->api->create_calls );
			$done = $this->reread( $order );
			$this->assertSame( 'CARRIER-' . $order->get_id(), $done->get_meta( self::CARRIER_ID_META ), 'the carrier id is stored on the datastore' );
			$this->assertSame( [ $pending[0] ], $this->actions_of( Export_Retry::HOOK, $order->get_id(), \ActionScheduler_Store::STATUS_COMPLETE ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_leaving_and_re_entering_the_status_does_not_queue_a_second_export( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$order->update_status( 'processing' );
			$order->update_status( 'on-hold' );
			$order->update_status( 'processing' );

			$this->assertCount( 1, $this->actions_of( Export_Retry::HOOK, $order->get_id() ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_status_the_merchant_did_not_pick_queues_nothing( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$order->update_status( 'on-hold' );

			$this->assertSame( [], $this->actions_of( Export_Retry::HOOK, $order->get_id() ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_auto_export_switched_off_queues_nothing( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$this->set_settings( false, [ 'wc-processing' ] );
			$order = $this->new_order();

			$order->update_status( 'processing' );

			$this->assertSame( [], $this->actions_of( Export_Retry::HOOK, $order->get_id() ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_an_order_of_another_carrier_queues_nothing( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order( false );

			$order->update_status( 'processing' );

			$this->assertSame( [], $this->actions_of( Export_Retry::HOOK, $order->get_id() ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_an_order_already_exported_queues_nothing( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$this->export_by_hand( $order );

			$this->reread( $order )->update_status( 'processing' );

			$this->assertSame( [], $this->actions_of( Export_Retry::HOOK, $order->get_id() ) );
			$this->assertSame( 1, $this->api->create_calls, 'and the carrier has not been asked again' );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_payment_complete_is_covered_by_the_same_single_trigger( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			// A gateway's callback ends in payment_complete(); an order with nothing to ship would go to «completed».
			add_filter( 'woocommerce_payment_complete_order_status', static fn() => 'processing' );

			$order->payment_complete(); // pending -> processing: the SAME status change, no second hook of ours.

			$this->assertCount( 1, $this->actions_of( Export_Retry::HOOK, $order->get_id() ) );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_refused_background_export_leaves_the_reason_in_a_private_note_and_is_not_retried( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->create_fails_with = new \Woodev_API_Exception( 'Неверный индекс получателя' );

			$order->update_status( 'processing' );
			$pending = $this->actions_of( Export_Retry::HOOK, $order->get_id() );
			$this->assertCount( 1, $pending );
			$this->run_action( $pending[0] );

			$this->assertSame( [], $this->actions_of( Export_Retry::HOOK, $order->get_id() ), 'a refusal is not retried' );

			$fresh = $this->reread( $order );
			$this->assertSame( '', (string) $fresh->get_meta( self::CARRIER_ID_META ) );
			$this->assertContains( 'Не удалось выгрузить заказ перевозчику в фоне: Неверный индекс получателя', $this->note_texts( $fresh ) );

			foreach ( $this->notes( $fresh ) as $note ) {
				$this->assertFalse( (bool) $note->customer_note, 'the carrier\'s text never reaches the buyer' );
			}
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_retryable_failure_of_the_background_export_follows_the_retry_schedule( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->api->create_fails_with = new \Woodev_API_Rate_Limit_Exception( 'Too Many Requests', 429, null, 600 );

			$order->update_status( 'processing' );
			$first = $this->actions_of( Export_Retry::HOOK, $order->get_id() );
			$this->run_action( $first[0] );

			$next = $this->actions_of( Export_Retry::HOOK, $order->get_id() );
			$this->assertCount( 1, $next, 'the #1008 retry takes over: one attempt waits again' );
			$this->assertNotSame( $first[0], $next[0] );
			$this->assertSame( 1, (int) $this->reread( $order )->get_meta( Export_Retry::ATTEMPTS_META ) );
		}

		// ----- cancellation -----

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_cancelled_exported_order_is_cancelled_at_the_carrier_in_the_background( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$this->export_by_hand( $order );
			$carrier_id = 'CARRIER-' . $order->get_id();

			$this->reread( $order )->update_status( 'cancelled' );

			$this->assertSame( [], $this->api->cancelled, 'the carrier is never called inside the status change' );
			$pending = $this->actions_of( Carrier_Cancel::HOOK, $order->get_id() );
			$this->assertCount( 1, $pending );

			$this->run_action( $pending[0] );

			$this->assertSame( [ $carrier_id ], $this->api->cancelled );
			$done = $this->reread( $order );
			$this->assertSame( 'cancelled', $done->get_status(), 'the WooCommerce order stays cancelled' );
			$this->assertSame( '', (string) $done->get_meta( self::CARRIER_ID_META ), 'the shipment is gone from the order' );
			$this->assertContains( 'Заявка отменена у перевозчика', $this->note_texts( $done ) );
			$this->assertFalse( ( new Order_Row_Builder() )->build( $done, Orders_Registry::instance()->get_provider( self::PROVIDER_ID ) )['cancel_failed'] );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_full_refund_cancels_at_the_carrier_too( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$this->export_by_hand( $order );

			$this->reread( $order )->update_status( 'refunded' );

			$pending = $this->actions_of( Carrier_Cancel::HOOK, $order->get_id() );
			$this->assertCount( 1, $pending );
			$this->run_action( $pending[0] );

			$this->assertCount( 1, $this->api->cancelled );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_refused_cancellation_is_noted_and_marked_on_the_orders_page_and_the_order_stays_cancelled( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$this->export_by_hand( $order );
			$carrier_id = 'CARRIER-' . $order->get_id();

			$this->api->cancel_fails_with = new \Woodev_API_Exception( 'Заказ уже передан курьеру' );

			$this->reread( $order )->update_status( 'cancelled' );
			$pending = $this->actions_of( Carrier_Cancel::HOOK, $order->get_id() );
			$this->run_action( $pending[0] );

			$done = $this->reread( $order );
			$this->assertSame( 'cancelled', $done->get_status() );
			$this->assertSame( $carrier_id, $done->get_meta( self::CARRIER_ID_META ), 'the shipment is still alive at the carrier' );
			$this->assertContains( 'Не удалось отменить заявку у перевозчика: Заказ уже передан курьеру', $this->note_texts( $done ) );
			$this->assertSame( $carrier_id, $done->get_meta( Carrier_Cancel::FAILED_META ) );

			$row = ( new Order_Row_Builder() )->build( $done, Orders_Registry::instance()->get_provider( self::PROVIDER_ID ) );
			$this->assertTrue( $row['cancel_failed'], 'the orders page shows the order as not cancelled at the carrier' );

			foreach ( $this->notes( $done ) as $note ) {
				$this->assertFalse( (bool) $note->customer_note );
			}
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_shipment_that_can_no_longer_be_cancelled_is_left_alone_with_a_note( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$this->export_by_hand( $order );

			$fresh = $this->reread( $order );
			$fresh->update_meta_data( self::STATUS_META, 'DELIVERED' );
			$fresh->save();

			$this->reread( $order )->update_status( 'cancelled' );
			$pending = $this->actions_of( Carrier_Cancel::HOOK, $order->get_id() );
			$this->run_action( $pending[0] );

			$this->assertSame( [], $this->api->cancelled, 'the carrier is not asked' );
			$done = $this->reread( $order );
			$this->assertSame( 'cancelled', $done->get_status() );

			$notes = array_values( array_filter( $this->note_texts( $done ), static fn( $text ) => 0 === strpos( $text, 'Отменить заявку у перевозчика нельзя' ) ) );
			$this->assertCount( 1, $notes );
			$this->assertStringEndsWith( '— свяжитесь с перевозчиком', $notes[0] );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_cancelled_order_that_was_never_exported_costs_nothing( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$order->update_status( 'cancelled' );

			$this->assertSame( [], $this->actions_of( Carrier_Cancel::HOOK, $order->get_id() ) );
			$this->assertSame( [], $this->api->cancelled );
			$this->assertSame(
				[],
				array_values( array_filter( $this->note_texts( $this->reread( $order ) ), static fn( $text ) => false !== strpos( $text, 'перевозчик' ) ) ),
				'and nothing is written on the order'
			);
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_pending_export_is_cancelled_with_the_order( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$order->update_status( 'processing' );
			$this->assertCount( 1, $this->actions_of( Export_Retry::HOOK, $order->get_id() ) );

			$order->update_status( 'cancelled' );

			$this->assertSame( [], $this->actions_of( Export_Retry::HOOK, $order->get_id() ), 'no export may still come for a cancelled order' );
			$this->assertSame( 0, $this->api->create_calls );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_cancellation_that_meets_a_running_export_waits_and_then_cancels_what_was_exported( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();
			$this->export_by_hand( $order );

			$this->handler->lock_free = false; // an export holds the per-order lock.

			$this->reread( $order )->update_status( 'cancelled' );
			$first = $this->actions_of( Carrier_Cancel::HOOK, $order->get_id() );
			$this->run_action( $first[0] );

			$this->assertSame( [], $this->api->cancelled, 'busy: the carrier is not asked' );
			$again = $this->actions_of( Carrier_Cancel::HOOK, $order->get_id() );
			$this->assertCount( 1, $again, 'put back for a minute' );
			$this->assertSame( 1, (int) $this->reread( $order )->get_meta( Carrier_Cancel::DEFERRALS_META ) );

			$this->handler->lock_free = true; // the export finished.
			$this->run_action( $again[0] );

			$this->assertCount( 1, $this->api->cancelled );
			$this->assertSame( '', (string) $this->reread( $order )->get_meta( Carrier_Cancel::DEFERRALS_META ), 'the counter is cleared' );
		}

		// ----- «exports in progress» -----

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_the_count_follows_the_queue_and_the_heartbeat_carries_the_finished_sentence( bool $hpos ): void {
			$this->use_datastore( $hpos );
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

			$notice = Orders_Registry::instance()->export_queue_notice();
			$key    = Export_Queue_Notice::HEARTBEAT_KEY;

			$this->assertSame( 0, Export_Queue::count_in_progress() );
			$empty = apply_filters( 'heartbeat_received', [], [ $key => true ], 'woocommerce_page_wc-orders' );
			$this->assertSame( [ 'count' => 0, 'text' => '' ], $empty[ $key ], 'empty queue: the notice disappears' );

			$first  = $this->new_order();
			$second = $this->new_order();
			$first->update_status( 'processing' );
			$second->update_status( 'processing' );

			$this->assertSame( 2, Export_Queue::count_in_progress() );
			$busy = apply_filters( 'heartbeat_received', [], [ $key => true ], 'woocommerce_page_wc-orders' );
			$this->assertSame( 2, $busy[ $key ]['count'] );
			$this->assertStringContainsString( '2', $busy[ $key ]['text'] );
			$this->assertSame( $notice->state()['text'], $busy[ $key ]['text'] );

			// One is carried out: it leaves the count.
			$this->run_action( $this->actions_of( Export_Retry::HOOK, $first->get_id() )[0] );
			$this->assertSame( 1, Export_Queue::count_in_progress() );

			$this->run_action( $this->actions_of( Export_Retry::HOOK, $second->get_id() )[0] );
			$this->assertSame( 0, Export_Queue::count_in_progress() );
		}

		/**
		 * @dataProvider datastore_provider
		 * @param bool $hpos datastore under test.
		 * @return void
		 */
		public function test_a_retry_that_waits_for_later_is_not_counted_as_being_exported_now( bool $hpos ): void {
			$this->use_datastore( $hpos );
			$order = $this->new_order();

			$this->assertTrue( Export_Retry::enqueue( $order->get_id(), HOUR_IN_SECONDS ) );
			$this->assertSame( 0, Export_Queue::count_in_progress() );

			$other = $this->new_order();
			$this->assertTrue( Export_Retry::enqueue( $other->get_id(), 0 ) );
			$this->assertSame( 1, Export_Queue::count_in_progress() );
		}

		public function test_the_sentence_has_the_right_russian_plural_form_under_the_ru_ru_catalogue(): void {
			$mo = dirname( __DIR__, 3 ) . '/woodev/languages/woodev-plugin-framework-ru_RU.mo';
			$this->assertFileExists( $mo );
			$this->assertTrue( load_textdomain( 'woodev-plugin-framework', $mo ) );

			$this->assertSame(
				[
					1  => 'Сейчас выгружается 1 заказ перевозчику',
					2  => 'Сейчас выгружаются 2 заказа перевозчику',
					4  => 'Сейчас выгружаются 4 заказа перевозчику',
					5  => 'Сейчас выгружаются 5 заказов перевозчику',
					11 => 'Сейчас выгружаются 11 заказов перевозчику',
					12 => 'Сейчас выгружаются 12 заказов перевозчику',
					21 => 'Сейчас выгружается 21 заказ перевозчику',
					22 => 'Сейчас выгружаются 22 заказа перевозчику',
				],
				array_combine( [ 1, 2, 4, 5, 11, 12, 21, 22 ], array_map( [ Export_Queue::class, 'notice_text' ], [ 1, 2, 4, 5, 11, 12, 21, 22 ] ) )
			);
		}

		// ----- the carrier's own settings: the plugin's tab on `woodev-settings` -----

		public function test_the_framework_gives_every_carrier_the_export_section_on_its_own_tab_with_the_right_defaults(): void {
			$plugin = \woodev_test_shipping_method_plugin();
			$this->reset_export_settings();

			$providers = $plugin->get_settings_providers();
			$this->assertCount( 1, $providers );
			$this->assertSame( $plugin->get_id(), $providers[0]->get_id() );

			$tabs = Settings_Page_Registry::instance()->build_tabs(
				array_map( static fn( $provider ) => [ 'provider' => $provider, 'is_woocommerce' => true ], $providers ),
				static fn( string $capability ): bool => true
			);

			$this->assertCount( 1, $tabs );
			$this->assertSame( [ 'export', 'advanced' ], array_column( $tabs[0]['sections'], 'id' ), 'the export section, then «Дополнительно» which every carrier has' );
			$this->assertSame( 'Выгрузка заказов', $tabs[0]['sections'][0]['label'] );

			$fields = $tabs[0]['sections'][0]['fields'];

			$this->assertSame( [ 'auto_export_orders', 'export_statuses', 'status_delivered' ], array_keys( $fields ) );
			$this->assertFalse( $fields['auto_export_orders']['value'], 'default OFF' );
			$this->assertSame( 'wc-completed', $fields['status_delivered']['value'], 'a delivered parcel completes the order unless the merchant says otherwise' );
			$this->assertSame( [ 'wc-processing' ], $fields['export_statuses']['value'] );
			$this->assertSame( [ 'wc-pending', 'wc-on-hold', 'wc-processing' ], array_keys( $fields['export_statuses']['options'] ), 'only statuses the export gate accepts' );
			$this->assertSame(
				[],
				Orders_Registry::instance()->automation()->auto_export_statuses( Orders_Registry::instance()->get_provider( self::PROVIDER_ID ) ),
				'while auto-export is off the carrier reports no statuses'
			);
		}

		public function test_a_carrier_that_does_not_export_gets_no_export_section(): void {
			$plugin = \woodev_test_shipping_method_plugin();

			// rates-only: the plugin registered no Orders_Provider / shipment handler of its own (#1014)
			Orders_Registry::instance()->reset_for_tests();

			$this->assertFalse( Orders_Registry::instance()->plugin_exports_orders( $plugin ) );

			// Every carrier has a tab now («Дополнительно»: logging, hide on cart), so a rates-only
			// carrier is no longer tab-less — what it must not get is the «Выгрузка заказов» section.
			$providers = $plugin->get_settings_providers();
			$this->assertCount( 1, $providers );

			$tabs = Settings_Page_Registry::instance()->build_tabs(
				array_map( static fn( $provider ) => [ 'provider' => $provider, 'is_woocommerce' => true ], $providers ),
				static fn( string $capability ): bool => true
			);

			$this->assertCount( 1, $tabs );
			$this->assertSame( [ 'advanced' ], array_column( $tabs[0]['sections'], 'id' ), 'no export section for a carrier that does not export' );
			$this->assertSame( [ 'enable_debug', 'disable_methods_on_cart' ], array_keys( $tabs[0]['sections'][0]['fields'] ) );
		}

		public function test_the_export_settings_are_no_longer_on_the_woocommerce_integration(): void {
			$integration = \woodev_test_shipping_method_plugin()->get_integration_handler();

			$this->assertArrayNotHasKey( 'auto_export_orders', $integration->get_form_fields() );
			$this->assertArrayNotHasKey( 'export_statuses', $integration->get_form_fields() );
		}

		public function test_the_values_saved_on_the_page_are_stored_per_plugin_and_read_by_the_automation(): void {
			$plugin = \woodev_test_shipping_method_plugin();
			$this->reset_export_settings();

			$this->set_settings( true, [ 'wc-on-hold', 'wc-pending' ] );

			$this->assertSame( 'yes', get_option( 'woodev_' . $plugin->get_id_underscored() . '_export_auto_export_orders' ) );
			$this->assertSame( [ 'wc-on-hold', 'wc-pending' ], get_option( 'woodev_' . $plugin->get_id_underscored() . '_export_export_statuses' ) );
			$this->assertSame(
				[ 'on-hold', 'pending' ],
				Orders_Registry::instance()->automation()->auto_export_statuses( Orders_Registry::instance()->get_provider( self::PROVIDER_ID ) )
			);
		}

		public function test_a_v1_site_keeps_its_auto_export_when_the_settings_move_and_the_carry_over_runs_once(): void {
			$plugin = \woodev_test_shipping_method_plugin();
			$id     = $plugin->get_id_underscored();
			$prefix = 'woodev_' . $id . '_export_';
			$this->reset_export_settings();

			$legacy = [
				'auto_export_orders' => 'yes',
				'export_statuses'    => [ 'wc-processing', 'wc-shipped' ],
				'api_key'            => 'secret',
			];
			update_option( 'woocommerce_' . $id . '_settings', $legacy );

			$migrated = new Export_Settings( $id );

			$this->assertTrue( $migrated->is_auto_export_enabled() );
			$this->assertSame( [ 'processing', 'shipped' ], $migrated->get_export_statuses() );
			$this->assertSame( [ 'wc-shipped' ], array_keys( $migrated->get_unsupported_statuses() ), 'reported, not erased' );
			$this->assertSame( 'yes', get_option( $prefix . 'migrated_from_integration' ) );
			$this->assertSame( $legacy, get_option( 'woocommerce_' . $id . '_settings' ), 'the v1 option is left as it was' );

			// the merchant changes their mind on the new page; the v1 option still says «yes» and must not win again
			$migrated->update_value( 'auto_export_orders', false );

			$this->assertFalse( ( new Export_Settings( $id ) )->is_auto_export_enabled() );
		}
	}
}

// The carrier fixtures live AFTER the test class on purpose: IntegrationSuiteBaseClassTest reads the
// first `class … extends` of a file and must see the test case.

namespace {

	if ( ! class_exists( 'Woodev_Auto_Fake_Response' ) ) {

		/**
		 * A carrier response carrying just an order id.
		 */
		class Woodev_Auto_Fake_Response implements \Woodev_API_Response {

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

	if ( ! class_exists( 'Woodev_Auto_Fake_Api' ) ) {

		/**
		 * Offline `Shipping_API` that counts `create_order()` calls, records every `cancel_order()` and can be told to fail.
		 */
		class Woodev_Auto_Fake_Api implements \Woodev\Framework\Shipping\Api\Shipping_API {

			/** @var int */
			public int $create_calls = 0;

			/** @var string[] carrier ids `cancel_order()` was asked to cancel */
			public array $cancelled = [];

			/** @var \Throwable|null thrown by create_order() when set */
			public $create_fails_with;

			/** @var \Throwable|null thrown by cancel_order() when set */
			public $cancel_fails_with;

			/** @inheritDoc */
			public function create_order( \WC_Order $order ): \Woodev_API_Response {
				++$this->create_calls;

				if ( null !== $this->create_fails_with ) {
					throw $this->create_fails_with;
				}

				return new Woodev_Auto_Fake_Response( 'CARRIER-' . $order->get_id() );
			}

			/** @inheritDoc */
			public function cancel_order( string $order_id ): \Woodev_API_Response {
				if ( null !== $this->cancel_fails_with ) {
					throw $this->cancel_fails_with;
				}

				$this->cancelled[] = $order_id;

				return new Woodev_Auto_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function calculate_rates( array $params ): \Woodev_API_Response {
				return new Woodev_Auto_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_pickup_points( array $params ): \Woodev_API_Response {
				return new Woodev_Auto_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_order( string $order_id ): \Woodev_API_Response {
				return new Woodev_Auto_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_tracking( string $tracking_number ): \Woodev_API_Response {
				return new Woodev_Auto_Fake_Response( '' );
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

	if ( ! class_exists( 'Woodev_Auto_Shipment_Handler' ) ) {

		/**
		 * The REAL handler, lock and order re-read included; only the carrier is fake.
		 */
		class Woodev_Auto_Shipment_Handler extends \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler {

			/** @var bool false => the per-order export lock is held by someone else */
			public bool $lock_free = true;

			/** @inheritDoc */
			protected function acquire_export_lock( int $order_id ): bool {
				return $this->lock_free && parent::acquire_export_lock( $order_id );
			}

			/** @inheritDoc */
			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return $response instanceof Woodev_Auto_Fake_Response ? $response->order_id : '';
			}
		}
	}
}
