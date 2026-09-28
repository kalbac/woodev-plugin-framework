<?php
/**
 * Unit: the process-wide half of «an edit sends no New order» (#981 round 2, spec C4).
 *
 * {@see Order_Editor::mute_new_order_email()} answers WooCommerce's
 * `woocommerce_email_enabled_new_order` for an order the editor ARMED — «disabled», once, and only
 * while the notification of exactly the armed transition is being dispatched, consuming the marker
 * as it does. Pinned here with a doubled order and a doubled `doing_action()`; the real dispatch,
 * synchronous and deferred, on both datastores, is proven by the integration tests.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::mute_new_order_email
 */
final class OrderEditorEmailMuteTest extends TestCase {

	private const TRANSITION = 'woocommerce_order_status_pending_to_processing';

	/**
	 * An order carrying a marker.
	 *
	 * @param string $marker the transition the order is armed against ('' = not armed).
	 * @return \WC_Order&Mockery\MockInterface
	 */
	private function order( string $marker ) {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->andReturn( $marker );

		return $order;
	}

	/**
	 * Makes `doing_action()` answer true for one action only.
	 *
	 * @param string $action the action being dispatched.
	 * @return void
	 */
	private function dispatching( string $action ): void {
		Functions\when( 'doing_action' )->alias(
			static function ( string $hook ) use ( $action ): bool {
				return $hook === $action;
			}
		);
	}

	public function test_anything_that_is_not_an_order_passes_through(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, null ), 'the settings screen asks with no order at all' );
		$this->assertFalse( Order_Editor::mute_new_order_email( false, new \stdClass() ), 'what came in goes out' );
	}

	public function test_an_order_that_was_not_armed_passes_through(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$order = $this->order( '' );
		$order->shouldNotReceive( 'delete_meta_data' );
		$order->shouldNotReceive( 'save' );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), 'a created order announces itself' );
	}

	public function test_an_armed_order_is_muted_only_during_its_own_transitions_notification(): void {
		$this->dispatching( 'woocommerce_order_status_on-hold_to_processing_notification' );

		$order = $this->order( self::TRANSITION );
		$order->shouldNotReceive( 'delete_meta_data' );
		$order->shouldNotReceive( 'save' );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), 'a later, different transition of the same order still announces itself' );
	}

	public function test_a_manual_resend_runs_under_no_notification_and_passes_through(): void {
		Functions\when( 'doing_action' )->justReturn( false );

		$order = $this->order( self::TRANSITION );
		$order->shouldNotReceive( 'delete_meta_data' );
		$order->shouldNotReceive( 'save' );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), '«Resend new order notification» is not the edit' );
	}

	public function test_an_armed_order_is_muted_once_and_the_marker_is_consumed(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$order = $this->order( self::TRANSITION );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->ordered();
		$order->shouldReceive( 'save' )->once()->ordered();

		$this->assertFalse( Order_Editor::mute_new_order_email( true, $order ), 'the edit\'s own «New order» is off' );
	}
}
