<?php
/**
 * Unit: the «parcel handed to delivery» concept and the «Оформить отказ» action (#1204) —
 * `Order_Actions::is_handed_over()`, `can_refuse()`, the action's gate and `perform()`'s refusal.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Action_Result;
use Woodev\Framework\Shipping\Order\Carrier_Cancel;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once __DIR__ . '/order-edit-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions
 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::get_handed_over_statuses
 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::get_refusable_statuses
 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::supports_refusal
 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::refuse
 */
final class OrderActionsRefusalTest extends TestCase {

	/** @var array<string,mixed> post meta of the active order, by meta key. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [
			'_cdek_carrier_order_id' => 'CARRIER-1',
			'_cdek_status'           => 'ON_WAY',
		];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider(): Orders_Provider {
		return Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_cdek_marker',
			[ 'cdek' ],
			[
				'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
				'status_meta_key'           => '_cdek_status',
				'status_map'                => [
					'NEW'       => Delivery_Status::CREATED,
					'ON_WAY'    => Delivery_Status::IN_TRANSIT,
					'DELIVERED' => Delivery_Status::DELIVERED,
				],
			]
		);
	}

	private function order( string $status = 'processing' ) {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( $key ) => $this->meta[ $key ] ?? '' );

		return $order;
	}

	/**
	 * @param string[] $handed_over states the carrier declares deletion impossible from.
	 * @param bool     $refusal     whether it supports the refusal.
	 * @param string[]|null $refusable states the refusal is offered in; null => the interface default.
	 */
	private function handler( array $handed_over = [], bool $refusal = false, ?array $refusable = null ): Abstract_Shipment_Handler {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( false );
		$handler->shouldReceive( 'get_handed_over_statuses' )->andReturn( $handed_over );
		$handler->shouldReceive( 'supports_refusal' )->andReturn( $refusal );
		$handler->shouldReceive( 'get_refusable_statuses' )->andReturn( $refusable ?? $handed_over );
		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		return $handler;
	}

	private function actions(): Order_Actions {
		return new Order_Actions( Orders_Registry::instance() );
	}

	/** @return string[] the ids of the actions the order offers. */
	private function offered( $order ): array {
		return array_column( $this->actions()->for_order( $order, $this->provider() ), 'action' );
	}

	// ----- the handler's defaults -----

	public function test_a_handler_declares_nothing_and_refuses_nothing_by_default(): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class )->makePartial();

		$this->assertSame( [], $handler->get_handed_over_statuses() );
		$this->assertFalse( $handler->supports_refusal() );
		$this->assertSame( [], $handler->get_refusable_statuses() );
		$this->assertFalse( $handler->refuse( $this->order() )->is_success() );
	}

	public function test_the_refusable_states_default_to_the_handed_over_ones(): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class )->makePartial();
		$handler->shouldReceive( 'get_handed_over_statuses' )->andReturn( [ Delivery_Status::IN_TRANSIT, Delivery_Status::RETURNING ] );

		$this->assertSame( [ Delivery_Status::IN_TRANSIT, Delivery_Status::RETURNING ], $handler->get_refusable_statuses() );
	}

	// ----- is_handed_over -----

	public function test_an_exported_parcel_in_a_declared_state_is_handed_over(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ] );

		$this->assertTrue( $this->actions()->is_handed_over( $this->order(), $this->provider() ) );
	}

	public function test_a_parcel_not_yet_in_a_declared_state_is_not_handed_over(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ] );
		$this->meta['_cdek_status'] = 'NEW';

		$this->assertFalse( $this->actions()->is_handed_over( $this->order(), $this->provider() ) );
	}

	public function test_a_carrier_that_declares_nothing_never_has_a_handed_over_parcel(): void {
		$this->handler( [] );

		$this->assertFalse( $this->actions()->is_handed_over( $this->order(), $this->provider() ) );
	}

	public function test_an_order_that_was_never_exported_is_not_handed_over(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ] );
		unset( $this->meta['_cdek_carrier_order_id'] );

		$this->assertFalse( $this->actions()->is_handed_over( $this->order(), $this->provider() ) );
	}

	public function test_an_end_state_is_never_handed_over_even_if_a_carrier_names_it(): void {
		$this->handler( [ Delivery_Status::DELIVERED ] );
		$this->meta['_cdek_status'] = 'DELIVERED';

		$this->assertFalse( $this->actions()->is_handed_over( $this->order(), $this->provider() ) );
	}

	public function test_no_carrier_or_no_handler_is_not_handed_over(): void {
		$this->assertFalse( $this->actions()->is_handed_over( $this->order(), null ) );
		$this->assertFalse( $this->actions()->is_handed_over( $this->order(), $this->provider() ), 'no handler registered' );
	}

	// ----- the action's gate -----

	public function test_the_refusal_is_offered_when_the_carrier_supports_it_and_the_parcel_is_refusable(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ], true );
		$order = $this->order();

		$this->assertContains( Order_Actions::REFUSE, $this->offered( $order ) );
		$this->assertTrue( $this->actions()->can_refuse( $order, $this->provider() ) );
	}

	public function test_the_refusal_is_a_destructive_action_that_asks_for_a_confirmation_naming_the_cost(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ], true );

		$refuse = null;

		foreach ( $this->actions()->for_order( $this->order(), $this->provider() ) as $action ) {
			if ( Order_Actions::REFUSE === $action['action'] ) {
				$refuse = $action;
			}
		}

		$this->assertNotNull( $refuse );
		$this->assertSame( 'Оформить отказ', $refuse['label'] );
		$this->assertTrue( $refuse['destructive'], 'the client confirms first' );
		$this->assertStringContainsString( 'Возврат платный', $refuse['confirm'] );
		$this->assertSame( 'undo', $refuse['icon'] );
	}

	public function test_the_refusal_is_not_offered_when_the_carrier_does_not_support_it(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ], false );
		$order = $this->order();

		$this->assertNotContains( Order_Actions::REFUSE, $this->offered( $order ) );
		$this->assertFalse( $this->actions()->can_refuse( $order, $this->provider() ) );
	}

	public function test_the_refusal_is_not_offered_in_a_state_the_carrier_does_not_declare_refusable(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ], true );
		$this->meta['_cdek_status'] = 'NEW';

		$this->assertNotContains( Order_Actions::REFUSE, $this->offered( $this->order() ) );
	}

	public function test_a_carrier_may_declare_a_refusable_set_apart_from_the_handed_over_one(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ], true, [ Delivery_Status::READY_FOR_PICKUP ] );

		$this->assertNotContains( Order_Actions::REFUSE, $this->offered( $this->order() ), 'in transit is handed over but not refusable' );
	}

	public function test_the_refusal_is_not_offered_in_an_end_state_even_if_declared(): void {
		$this->handler( [ Delivery_Status::DELIVERED ], true );
		$this->meta['_cdek_status'] = 'DELIVERED';

		$this->assertNotContains( Order_Actions::REFUSE, $this->offered( $this->order() ) );
	}

	public function test_the_refusal_is_not_offered_for_an_order_that_was_never_exported(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ], true );
		unset( $this->meta['_cdek_carrier_order_id'] );

		$this->assertNotContains( Order_Actions::REFUSE, $this->offered( $this->order() ) );
	}

	public function test_the_cancel_button_is_untouched_by_the_declaration(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ], true );

		$this->assertContains( Order_Actions::CANCEL, $this->offered( $this->order() ) );
	}

	public function test_the_refusal_has_its_own_unavailable_reason(): void {
		$this->handler( [ Delivery_Status::IN_TRANSIT ], false );

		$this->assertStringContainsString( 'Отказ можно оформить', $this->actions()->unavailable_reason( $this->order(), $this->provider(), Order_Actions::REFUSE ) );

		unset( $this->meta['_cdek_carrier_order_id'] );

		$this->assertStringContainsString( 'не выгружен', $this->actions()->unavailable_reason( $this->order(), $this->provider(), Order_Actions::REFUSE ) );
	}

	// ----- performing it -----

	public function test_a_successful_refusal_is_noted_and_the_failed_cancellation_marker_is_cleared(): void {
		$handler = $this->handler( [ Delivery_Status::IN_TRANSIT ], true );
		$order   = $this->order();
		$this->meta[ Carrier_Cancel::FAILED_META ] = 'CARRIER-1';

		$handler->shouldReceive( 'refuse' )->once()->with( $order )->andReturn( Action_Result::success() );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Carrier_Cancel::FAILED_META );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Оформлен отказ от посылки: она вернётся к вам, возврат платный' );

		$result = $this->actions()->perform( $handler, $order, Order_Actions::REFUSE, $this->provider() );

		$this->assertTrue( $result->is_success() );
	}

	public function test_a_refused_refusal_is_noted_with_the_carriers_reason_and_leaves_the_marker(): void {
		$handler = $this->handler( [ Delivery_Status::IN_TRANSIT ], true );
		$order   = $this->order();
		$this->meta[ Carrier_Cancel::FAILED_META ] = 'CARRIER-1';

		$handler->shouldReceive( 'refuse' )->once()->andReturn( Action_Result::failure( 'Заказ уже вручён' ) );
		$order->shouldNotReceive( 'delete_meta_data' );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Не удалось оформить отказ от посылки: Заказ уже вручён' );

		$result = $this->actions()->perform( $handler, $order, Order_Actions::REFUSE, $this->provider() );

		$this->assertFalse( $result->is_success() );
	}

	public function test_a_refusal_with_no_reason_says_so(): void {
		$handler = $this->handler( [ Delivery_Status::IN_TRANSIT ], true );
		$order   = $this->order();

		$handler->shouldReceive( 'refuse' )->once()->andReturn( Action_Result::failure() );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Не удалось оформить отказ от посылки: перевозчик не назвал причину' );

		$this->actions()->perform( $handler, $order, Order_Actions::REFUSE, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_carrier_that_throws_leaves_a_note_and_the_exception_still_reaches_the_caller(): void {
		$handler = $this->handler( [ Delivery_Status::IN_TRANSIT ], true );
		$order   = $this->order();

		$handler->shouldReceive( 'refuse' )->once()->andThrow( new \RuntimeException( 'SQLSTATE secret-token-123' ) );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Не удалось оформить отказ от посылки: сервис перевозчика недоступен' );

		$this->expectException( \RuntimeException::class );

		$this->actions()->perform( $handler, $order, Order_Actions::REFUSE, $this->provider() );
	}
}
