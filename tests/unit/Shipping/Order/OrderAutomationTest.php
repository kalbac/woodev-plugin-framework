<?php
/**
 * Unit: what the framework does on its own when an order changes status (#1007).
 *
 * `Order_Automation` QUEUES a background export when an order of a carrier enters a status the
 * merchant picked for auto-export, and a background cancellation when it is cancelled or fully
 * refunded. It never calls a carrier inside the status change. The cancellation runner then cancels
 * the shipment under the export lock, and leaves the outcome on the order as a private note (and a
 * marker when the carrier refused).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Action_Result;
use Woodev\Framework\Shipping\Order\Carrier_Cancel;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Order\Export_Retry;
use Woodev\Framework\Shipping\Order\Order_Automation;
use Woodev\Framework\Shipping\Settings\Export_Settings;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-export-settings.php';

/**
 * @covers \Woodev\Framework\Shipping\Order\Order_Automation
 * @covers \Woodev\Framework\Shipping\Order\Carrier_Cancel
 */
final class OrderAutomationTest extends TestCase {

	/** @var array<string,mixed> post meta of the active order, by meta key. */
	private array $meta = [];

	/** @var array<int,array<string,mixed>> every action scheduled: [ timestamp, hook, args, group ]. */
	private array $scheduled = [];

	/** @var array<int,array<string,mixed>> every Action Scheduler call that cancels waiting actions: [ hook, args, group ]. */
	private array $unscheduled = [];

	/** @var int[] the ids `as_get_scheduled_actions()` reports for a PENDING query of the hook under test; empty => nothing waits. */
	private array $waiting = [];

	/** @var bool whether an export attempt of the order is running right now. */
	private bool $export_running = false;

	/** @var array<string,mixed> the carrier plugin's «Выгрузка» settings, by setting key (`auto_export_orders` stored as `yes`/`no`). */
	private array $settings = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta           = [];
		$this->scheduled      = [];
		$this->unscheduled    = [];
		$this->waiting        = [];
		$this->export_running = false;
		$this->settings       = [
			'auto_export_orders' => 'yes',
			'export_statuses'    => [ 'wc-processing' ],
		];

		Functions\stubs( [ 'remove_action', 'add_filter', 'remove_filter', 'wp_cache_delete' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		Functions\when( 'wc_string_to_bool' )->alias( static fn( $value ) => in_array( strtolower( (string) $value ), [ 'yes', 'true', '1' ], true ) );
		// The carrier's «Выгрузка» settings are real `Export_Settings` over the options table, so the read
		// path — storage keys, the `wc-` prefix, the default — is the one a site runs.
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				$key = 'woodev_cdek_export_';

				return 0 === strpos( $name, $key ) && array_key_exists( substr( $name, strlen( $key ) ), $this->settings )
					? $this->settings[ substr( $name, strlen( $key ) ) ]
					: $default;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $status ) => $status );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ) {
				if ( 'in-progress' === $args['status'] ) {
					return $this->export_running && Export_Retry::HOOK === $args['hook'] ? [ 77 ] : [];
				}

				return $this->waiting;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( $hook, $args, $group ) {
				$this->unscheduled[] = [ $hook, $args, $group ];

				return null;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args = [], $group = '' ) {
				$this->scheduled[] = [ $timestamp, $hook, $args, $group ];

				return 500 + count( $this->scheduled );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $options extra `Orders_Provider::create()` options.
	 */
	private function register_carrier( array $options = [], bool $with_plugin = true ): void {
		$plugin = null;

		if ( $with_plugin ) {
			$plugin = Mockery::mock( Shipping_Plugin::class );
			$plugin->shouldReceive( 'get_export_settings' )->andReturnUsing( static fn() => new Export_Settings( 'cdek' ) );
		}

		Orders_Registry::instance()->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], $options + [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] ),
			$plugin
		);
	}

	private function register_handler(): Abstract_Shipment_Handler {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( false );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		return $handler;
	}

	/**
	 * @param string $status the order status.
	 * @return \WC_Order
	 */
	private function order( string $status ) {
		$this->meta['_cdek_marker'] = '1';

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( $key ) => $this->meta[ $key ] ?? '' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		return $order;
	}

	/** @return array<int,array<string,mixed>> the scheduled actions of one hook. */
	private function scheduled_of( string $hook ): array {
		return array_values( array_filter( $this->scheduled, static fn( $action ) => $hook === $action[1] ) );
	}

	// ----- auto-export: a status change only QUEUES work -----

	public function test_entering_a_configured_status_queues_exactly_one_background_export(): void {
		$this->register_carrier();
		$this->register_handler();
		$order = $this->order( 'processing' );

		$before = time();
		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'processing', $order );

		$exports = $this->scheduled_of( Export_Retry::HOOK );
		$this->assertCount( 1, $exports );
		$this->assertSame( [ 123 ], $exports[0][2] );
		$this->assertSame( Export_Retry::GROUP, $exports[0][3] );
		$this->assertEqualsWithDelta( $before, $exports[0][0], 3, 'on the next queue pass, not delayed' );
		$this->assertSame( [], $this->scheduled_of( Carrier_Cancel::HOOK ) );
	}

	public function test_the_carrier_is_never_called_inside_the_status_change(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$handler->shouldNotReceive( 'export' );
		$handler->shouldNotReceive( 'cancel' );
		$handler->shouldNotReceive( 'cancel_under_lock' );
		$order = $this->order( 'processing' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'processing', $order );
		Orders_Registry::instance()->handle_order_status_changed( 123, 'processing', 'cancelled', $order );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_export_already_waiting_for_the_order_is_not_doubled(): void {
		$this->register_carrier();
		$this->register_handler();
		$order = $this->order( 'processing' );

		$this->waiting = [ 9 ];

		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'processing', $order );
		Orders_Registry::instance()->handle_order_status_changed( 123, 'on-hold', 'processing', $order );

		$this->assertSame( [], $this->scheduled, 'one waiting action per order' );
	}

	public function test_a_status_the_merchant_did_not_pick_queues_nothing(): void {
		$this->register_carrier();
		$this->register_handler();
		$order = $this->order( 'on-hold' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'on-hold', $order );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_auto_export_switched_off_queues_nothing(): void {
		$this->settings['auto_export_orders'] = 'no';
		$this->register_carrier();
		$this->register_handler();
		$order = $this->order( 'processing' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'processing', $order );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_an_order_already_exported_queues_nothing(): void {
		$this->register_carrier();
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'processing' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'on-hold', 'processing', $order );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_an_order_of_another_carrier_queues_nothing(): void {
		$this->register_carrier();
		$this->register_handler();
		$order = $this->order( 'processing' );

		unset( $this->meta['_cdek_marker'] ); // a different carrier's order: this provider's marker is absent.

		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'processing', $order );

		$this->assertSame( [], $this->scheduled );
		$this->assertSame( [], $this->unscheduled );
	}

	public function test_a_carrier_without_a_shipment_handler_queues_nothing(): void {
		$this->register_carrier();
		$order = $this->order( 'processing' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'processing', $order );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_a_carrier_registered_without_its_plugin_has_no_auto_export(): void {
		$this->register_carrier( [], false );
		$this->register_handler();
		$order = $this->order( 'processing' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'processing', $order );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_the_statuses_are_read_with_or_without_the_wc_prefix_and_an_empty_selection_means_none(): void {
		$this->register_carrier();
		$provider   = Orders_Registry::instance()->get_provider( 'cdek' );
		$automation = Orders_Registry::instance()->automation();

		$this->settings['export_statuses'] = [ 'wc-processing', 'on-hold', 'wc-processing', '', 7 ];
		$this->assertSame( [ 'processing', 'on-hold' ], $automation->auto_export_statuses( $provider ) );

		$this->settings['export_statuses'] = '';
		$this->assertSame( [], $automation->auto_export_statuses( $provider ), 'nothing picked => nothing exported' );
	}

	// ----- cancellation: queued on cancel / full refund -----

	public function test_a_cancelled_exported_order_queues_the_cancellation_and_drops_a_waiting_export(): void {
		$this->register_carrier();
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'cancelled' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'processing', 'cancelled', $order );

		$this->assertSame( [ [ Export_Retry::HOOK, [ 123 ], Export_Retry::GROUP ] ], $this->unscheduled, 'the waiting auto-export / retry is cancelled' );
		$cancels = $this->scheduled_of( Carrier_Cancel::HOOK );
		$this->assertCount( 1, $cancels );
		$this->assertSame( [ 123 ], $cancels[0][2] );
		$this->assertSame( Export_Retry::GROUP, $cancels[0][3] );
		$this->assertSame( [], $this->scheduled_of( Export_Retry::HOOK ) );
	}

	public function test_a_full_refund_cancels_at_the_carrier_like_a_cancelled_order(): void {
		$this->register_carrier();
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'refunded' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'processing', 'refunded', $order );

		$this->assertCount( 1, $this->scheduled_of( Carrier_Cancel::HOOK ) );
	}

	public function test_a_cancelled_order_that_was_never_exported_costs_nothing_at_the_carrier(): void {
		$this->register_carrier();
		$this->register_handler();
		$order = $this->order( 'cancelled' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'pending', 'cancelled', $order );

		$this->assertSame( [], $this->scheduled, 'not exported => nothing to cancel' );
		$this->assertCount( 1, $this->unscheduled, 'but a waiting export is still dropped' );
	}

	public function test_a_cancelled_order_whose_export_is_running_right_now_is_looked_at_again_by_the_queue(): void {
		$this->register_carrier();
		$this->register_handler();
		$this->export_running = true;
		$order                = $this->order( 'cancelled' );

		Orders_Registry::instance()->handle_order_status_changed( 123, 'processing', 'cancelled', $order );

		$this->assertCount( 1, $this->scheduled_of( Carrier_Cancel::HOOK ), 'the export about to store a carrier id must be cancelled too' );
	}

	public function test_a_cancellation_already_waiting_is_not_doubled(): void {
		$this->register_carrier();
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'cancelled' );

		$this->waiting = [ 9 ];

		Orders_Registry::instance()->handle_order_status_changed( 123, 'processing', 'cancelled', $order );

		$this->assertSame( [], $this->scheduled );
	}

	// ----- cancellation: the runner -----

	public function test_the_runner_cancels_under_the_export_lock_and_notes_success(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'cancelled' );

		$handler->shouldReceive( 'cancel_under_lock' )->once()->with( $order )->andReturn( Action_Result::success() );
		$order->shouldReceive( 'read_meta_data' )->with( true );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Заявка отменена у перевозчика' );
		$order->shouldNotReceive( 'update_meta_data' );

		Orders_Registry::instance()->run_cancel_at_carrier( '123' ); // Action Scheduler hands the stored argument over as it is.

		$this->addToAssertionCount( 1 );
	}

	public function test_a_successful_cancellation_clears_an_earlier_failure_marker(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$this->meta['_cdek_carrier_order_id']  = 'CARRIER-1';
		$this->meta[ Carrier_Cancel::FAILED_META ] = 'CARRIER-1';
		$order                                 = $this->order( 'cancelled' );

		$handler->shouldReceive( 'cancel_under_lock' )->once()->andReturn( Action_Result::success() );
		$order->shouldReceive( 'read_meta_data' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Carrier_Cancel::FAILED_META );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once();

		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_refused_cancellation_is_noted_and_marked_but_the_order_stays_cancelled(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'cancelled' );

		$handler->shouldReceive( 'cancel_under_lock' )->once()->andReturn( Action_Result::failure( 'Заказ уже передан курьеру' ) );
		$order->shouldReceive( 'read_meta_data' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( Carrier_Cancel::FAILED_META, 'CARRIER-1' );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Не удалось отменить заявку у перевозчика: Заказ уже передан курьеру' );
		$order->shouldNotReceive( 'set_status' );
		$order->shouldNotReceive( 'update_status' );

		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_refusal_without_a_reason_says_so(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'cancelled' );

		$handler->shouldReceive( 'cancel_under_lock' )->once()->andReturn( Action_Result::failure() );
		$order->shouldReceive( 'read_meta_data' );
		$order->shouldReceive( 'update_meta_data' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Не удалось отменить заявку у перевозчика: перевозчик не назвал причину' );

		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_shipment_already_delivered_is_not_cancelled_and_the_merchant_is_told_to_call_the_carrier(): void {
		$this->register_carrier(
			[
				'status_meta_key' => '_cdek_status',
				'status_map'      => [ 'DELIVERED' => Delivery_Status::DELIVERED ],
			]
		);
		$handler = $this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->meta['_cdek_status']           = 'DELIVERED';
		$order                                = $this->order( 'refunded' );

		$handler->shouldNotReceive( 'cancel_under_lock' );
		$order->shouldReceive( 'add_order_note' )->once()->with(
			sprintf( 'Отменить заявку у перевозчика нельзя (статус «%s») — свяжитесь с перевозчиком', Delivery_Status::label( Delivery_Status::DELIVERED ) )
		);
		$order->shouldNotReceive( 'update_meta_data' );

		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_with_no_shipment_at_the_carrier_is_left_alone(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$order   = $this->order( 'cancelled' );

		$handler->shouldReceive( 'cancel_under_lock' )->once()->andReturn( null );
		$order->shouldNotReceive( 'add_order_note' );
		$order->shouldNotReceive( 'update_meta_data' );

		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_restored_since_keeps_its_shipment(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'processing' );

		$handler->shouldNotReceive( 'cancel_under_lock' );
		$order->shouldNotReceive( 'add_order_note' );

		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_that_is_gone_is_left_alone(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$handler->shouldNotReceive( 'cancel_under_lock' );

		Functions\when( 'wc_get_order' )->justReturn( false );

		Orders_Registry::instance()->run_cancel_at_carrier( 999 );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_carrier_without_a_handler_says_so_on_an_exported_order(): void {
		$this->register_carrier();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$order                                = $this->order( 'cancelled' );

		$order->shouldReceive( 'add_order_note' )->once()->with( 'Отменить заявку у перевозчика не удалось: перевозчик не найден (плагин отключён?)' );

		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_busy_with_an_export_is_put_back_for_a_minute(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$order   = $this->order( 'cancelled' );

		$handler->shouldReceive( 'cancel_under_lock' )->once()->andReturn( Action_Result::busy( 'Этот заказ уже выгружается' ) );
		$order->shouldReceive( 'read_meta_data' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( Carrier_Cancel::DEFERRALS_META, 1 );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldNotReceive( 'add_order_note' );

		$before = time();
		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$cancels = $this->scheduled_of( Carrier_Cancel::HOOK );
		$this->assertCount( 1, $cancels );
		$this->assertEqualsWithDelta( $before + 60, $cancels[0][0], 3 );
	}

	public function test_a_cancellation_that_stays_busy_too_long_is_reported_and_marked(): void {
		$this->register_carrier();
		$handler = $this->register_handler();
		$this->meta['_cdek_carrier_order_id']         = 'CARRIER-1';
		$this->meta[ Carrier_Cancel::DEFERRALS_META ] = Carrier_Cancel::MAX_DEFERRALS;
		$order                                        = $this->order( 'cancelled' );

		$handler->shouldReceive( 'cancel_under_lock' )->once()->andReturn( Action_Result::busy( 'Этот заказ уже выгружается' ) );
		$order->shouldReceive( 'read_meta_data' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Carrier_Cancel::DEFERRALS_META );
		$order->shouldReceive( 'update_meta_data' )->once()->with( Carrier_Cancel::FAILED_META, 'CARRIER-1' );
		$order->shouldReceive( 'save_meta_data' );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Не удалось отменить заявку у перевозчика: заказ слишком долго выгружается. Проверьте заявку в личном кабинете перевозчика.' );

		Orders_Registry::instance()->run_cancel_at_carrier( 123 );

		$this->assertSame( [], $this->scheduled, 'no further put-back' );
	}

	// ----- the failure marker is bound to the shipment it failed for -----

	public function test_the_marker_counts_only_while_the_failed_shipment_is_still_the_stored_one(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );

		$this->meta[ Carrier_Cancel::FAILED_META ] = 'CARRIER-1';

		$this->assertTrue( Carrier_Cancel::has_failed( $order, 'CARRIER-1' ) );
		$this->assertFalse( Carrier_Cancel::has_failed( $order, 'CARRIER-2' ), 'the order was exported again: a stale marker is ignored' );
		$this->assertFalse( Carrier_Cancel::has_failed( $order, '' ), 'the shipment was cancelled later: nothing to flag' );
	}
}
