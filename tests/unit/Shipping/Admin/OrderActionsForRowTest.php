<?php
/**
 * Unit: the «Редактировать» row action (#710 spec D5, card #972).
 *
 * `Order_Actions::for_row()` is `for_order()` plus one client-side action, offered by the SAME
 * `is_editable()` policy the load / update routes re-check. Pinned here: when it appears, where it
 * sits, that it never leaks into the executable set (`for_order()` / `is_offered()`), and that it
 * does not need a shipment handler (#988) — a carrier without one shows «Редактировать» and nothing else.
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
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once __DIR__ . '/order-edit-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_row
 */
final class OrderActionsForRowTest extends TestCase {

	/** @var array<string,mixed> post meta of the active order, by meta key. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $status ): string {
				return ucfirst( $status );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];
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
					'NEW'       => 'created',
					'DELIVERED' => 'delivered',
				],
			]
		);
	}

	private function order( string $status = 'processing' ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );

		return $order;
	}

	private function actions(): Order_Actions {
		return new Order_Actions( Orders_Registry::instance() );
	}

	private function register_handler( bool $supports_update = true ): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( $supports_update );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );
	}

	/** @return string[] */
	private function ids( \WC_Order $order ): array {
		return array_column( $this->actions()->for_row( $order, $this->provider() ), 'action' );
	}

	public function test_an_editable_order_gets_the_edit_action_ahead_of_export(): void {
		$this->register_handler();

		$this->assertSame( [ Order_Actions::EDIT, Order_Actions::EXPORT ], $this->ids( $this->order() ) );
	}

	public function test_the_edit_action_is_a_plain_button_with_a_label_and_a_tooltip(): void {
		$this->register_handler();

		$edit = $this->actions()->for_row( $this->order(), $this->provider() )[0];

		$this->assertSame( 'edit', $edit['action'] );
		$this->assertSame( 'Редактировать', $edit['label'] );
		$this->assertNotSame( '', $edit['title'] );
		$this->assertFalse( $edit['destructive'], 'opening a form needs no «Да / Нет»' );
	}

	public function test_another_managers_live_woocommerce_lock_disables_edit_and_carries_its_owner(): void {
		$this->register_handler();
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks[123] = [ 'time' => time(), 'user_id' => 7 ];

		$user               = new \stdClass();
		$user->ID           = 7;
		$user->display_name = 'Мария';
		Functions\expect( 'get_user_by' )->once()->with( 'id', 7 )->andReturn( $user );

		$edit = $this->actions()->for_row( $this->order(), $this->provider() )[0];

		$this->assertTrue( $edit['disabled'] );
		$this->assertSame( 'Мария', $edit['lock_owner'] );
		$this->assertSame( 'This order is already being edited by Мария', $edit['title'] );
	}

	public function test_a_status_export_is_not_offered_for_still_gets_the_edit_action(): void {
		$this->register_handler();

		// `for_order()` offers nothing here (a custom status is outside the exportable three), yet the
		// order is editable — the edit button stands alone.
		$this->assertSame( [ Order_Actions::EDIT ], $this->ids( $this->order( 'awaiting-call' ) ) );
	}

	public function test_an_exported_order_loses_the_edit_action_and_keeps_the_carrier_ones(): void {
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';

		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL ], $this->ids( $this->order() ) );
	}

	public function test_every_final_wc_status_hides_the_edit_action(): void {
		$this->register_handler();

		foreach ( Order_Actions::FINAL_STATUSES as $status ) {
			$this->assertNotContains( Order_Actions::EDIT, $this->ids( $this->order( $status ) ), $status );
		}
	}

	public function test_a_finished_delivery_hides_the_edit_action(): void {
		$this->register_handler();
		$this->meta['_cdek_status'] = 'DELIVERED';

		$this->assertNotContains( Order_Actions::EDIT, $this->ids( $this->order() ) );
	}

	public function test_the_row_follows_the_same_policy_the_routes_use(): void {
		$this->register_handler();

		foreach ( [ 'pending', 'processing', 'completed', 'cancelled' ] as $status ) {
			$order = $this->order( $status );

			$this->assertSame(
				Order_Actions::is_editable( $order, $this->provider() ),
				in_array( Order_Actions::EDIT, $this->ids( $order ), true ),
				$status
			);
		}
	}

	public function test_no_actions_at_all_without_a_provider(): void {
		$this->assertSame( [], $this->actions()->for_row( $this->order(), null ) );
	}

	public function test_a_carrier_without_a_handler_still_gets_the_edit_action_and_nothing_else(): void {
		// No register_handler() call — that is the case under test (#988).
		$this->assertSame( [ Order_Actions::EDIT ], $this->ids( $this->order() ) );
	}

	public function test_a_carrier_without_a_handler_loses_the_edit_action_by_the_same_policy(): void {
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->assertSame( [], $this->ids( $this->order() ), 'exported' );

		$this->meta = [];
		$this->assertSame( [], $this->ids( $this->order( 'completed' ) ), 'final status' );

		$this->meta = [ '_cdek_status' => 'DELIVERED' ];
		$this->assertSame( [], $this->ids( $this->order() ), 'finished delivery' );
	}

	public function test_the_executable_set_never_carries_the_edit_action(): void {
		$this->register_handler();

		$order = $this->order();

		$this->assertNotContains( Order_Actions::EDIT, array_column( $this->actions()->for_order( $order, $this->provider() ), 'action' ) );
		$this->assertFalse( $this->actions()->is_offered( $order, $this->provider(), Order_Actions::EDIT ), 'the action / bulk routes refuse it like any unknown id' );
	}

	// ----- #1000: an order another manager holds the edit lock on -----

	private function lock_order_for_another_manager( int $order_id = 123, int $user_id = 7, string $name = 'Мария' ): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks[ $order_id ] = [ 'time' => time(), 'user_id' => $user_id ];

		$user               = new \stdClass();
		$user->ID           = $user_id;
		$user->display_name = $name;
		Functions\when( 'get_user_by' )->justReturn( $user );
	}

	public function test_a_locked_order_offers_no_executable_action_and_the_gate_refuses_each_of_them(): void {
		$this->register_handler();
		$this->lock_order_for_another_manager();

		$order = $this->order();

		$this->assertSame( [], $this->actions()->for_order( $order, $this->provider() ) );
		$this->assertFalse( $this->actions()->is_offered( $order, $this->provider(), Order_Actions::EXPORT ) );
	}

	public function test_a_locked_exported_order_refuses_update_and_cancel_too(): void {
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->lock_order_for_another_manager();

		$order = $this->order();

		$this->assertFalse( $this->actions()->is_offered( $order, $this->provider(), Order_Actions::UPDATE ) );
		$this->assertFalse( $this->actions()->is_offered( $order, $this->provider(), Order_Actions::CANCEL ) );
	}

	public function test_the_refusal_reason_names_the_manager_holding_the_lock(): void {
		$this->register_handler();
		$this->lock_order_for_another_manager();

		$this->assertSame(
			'This order is already being edited by Мария',
			$this->actions()->unavailable_reason( $this->order(), $this->provider(), Order_Actions::EXPORT )
		);
	}

	public function test_the_row_keeps_the_locked_orders_actions_but_disables_every_one_of_them(): void {
		$this->register_handler();
		$this->lock_order_for_another_manager();

		$actions = $this->actions()->for_row( $this->order(), $this->provider() );

		$this->assertSame( [ Order_Actions::EDIT, Order_Actions::EXPORT ], array_column( $actions, 'action' ) );

		foreach ( $actions as $action ) {
			$this->assertTrue( $action['disabled'], $action['action'] );
			$this->assertSame( 'Мария', $action['lock_owner'], $action['action'] );
			$this->assertSame( 'This order is already being edited by Мария', $action['title'], $action['action'] );
		}
	}

	public function test_a_locked_row_without_a_handler_disables_the_edit_action_alone(): void {
		$this->lock_order_for_another_manager();

		$actions = $this->actions()->for_row( $this->order(), $this->provider() );

		$this->assertSame( [ Order_Actions::EDIT ], array_column( $actions, 'action' ) );
		$this->assertTrue( $actions[0]['disabled'] );
	}

	public function test_a_locked_exported_row_disables_update_and_cancel(): void {
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->lock_order_for_another_manager();

		$actions = $this->actions()->for_row( $this->order(), $this->provider() );

		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL ], array_column( $actions, 'action' ) );
		$this->assertSame( [ true, true ], array_column( $actions, 'disabled' ) );
	}

	public function test_an_order_nobody_else_has_locked_stays_fully_usable(): void {
		$this->register_handler();

		$order   = $this->order();
		$actions = $this->actions()->for_row( $order, $this->provider() );

		$this->assertSame( [ Order_Actions::EXPORT ], array_column( $this->actions()->for_order( $order, $this->provider() ), 'action' ) );
		$this->assertSame( [ Order_Actions::EDIT, Order_Actions::EXPORT ], array_column( $actions, 'action' ) );

		foreach ( $actions as $action ) {
			$this->assertArrayNotHasKey( 'disabled', $action, $action['action'] );
			$this->assertArrayNotHasKey( 'lock_owner', $action, $action['action'] );
		}
	}
}
