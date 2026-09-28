<?php
/**
 * Unit: the process-wide half of «an edit sends no New order» (#981 rounds 2–3, spec C4).
 *
 * {@see Order_Editor::mute_new_order_email()} answers WooCommerce's
 * `woocommerce_email_enabled_new_order` for an order the editor ARMED — «disabled», once per entry,
 * and only while the notification of exactly that entry's transition is being dispatched, consuming
 * the entry as it does and dropping the entries that have outlived
 * {@see Order_Editor::NEW_ORDER_EMAIL_MUTE_TTL}. Pinned here with a doubled order and a doubled
 * `doing_action()`; the real dispatch, synchronous and deferred, on both datastores, is proven by the
 * integration tests.
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
	 * An order carrying whatever the mute meta holds.
	 *
	 * A marked order is re-read from the datastore before anything is consumed, so its meta is
	 * asked for twice: `$stored` is what the object carried, `$fresh` what the datastore holds now
	 * (null = the same).
	 *
	 * @param mixed $stored the meta value ('' = not armed, as WooCommerce answers for a missing key).
	 * @param mixed $fresh  the meta value after the re-read, when it differs.
	 * @return \WC_Order&Mockery\MockInterface
	 */
	private function order( $stored, $fresh = null ) {
		$order = Mockery::mock( '\WC_Order' );

		if ( '' === $stored ) {
			$order->shouldReceive( 'get_meta' )->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->andReturn( '' );
			$order->shouldNotReceive( 'read_meta_data' );

			return $order;
		}

		$order->shouldReceive( 'get_meta' )->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->once()->andReturn( $stored )->ordered();
		$order->shouldReceive( 'read_meta_data' )->once()->with( true )->ordered();
		$order->shouldReceive( 'get_meta' )->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->andReturn( null === $fresh ? $stored : $fresh )->ordered();

		return $order;
	}

	/**
	 * One mute entry.
	 *
	 * @param string $transition the transition the entry is armed against.
	 * @param int    $age        seconds since the edit that wrote it.
	 * @return array{transition: string, created_at: int}
	 */
	private function entry( string $transition = self::TRANSITION, int $age = 0 ): array {
		return [
			'transition' => $transition,
			'created_at' => time() - $age,
		];
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

	/**
	 * The order must not be written to.
	 *
	 * @param Mockery\MockInterface $order the doubled order.
	 * @return void
	 */
	private function expect_no_write( $order ): void {
		$order->shouldNotReceive( 'delete_meta_data' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );
	}

	public function test_anything_that_is_not_an_order_passes_through(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, null ), 'the settings screen asks with no order at all' );
		$this->assertFalse( Order_Editor::mute_new_order_email( false, new \stdClass() ), 'what came in goes out' );
	}

	public function test_an_order_that_was_not_armed_passes_through(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$order = $this->order( '' );
		$this->expect_no_write( $order );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), 'a created order announces itself' );
	}

	public function test_an_armed_order_is_muted_only_during_its_own_transitions_notification(): void {
		$this->dispatching( 'woocommerce_order_status_on-hold_to_processing_notification' );

		$order = $this->order( [ $this->entry() ] );
		$this->expect_no_write( $order );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), 'a later, different transition of the same order still announces itself' );
	}

	public function test_a_manual_resend_runs_under_no_notification_and_passes_through(): void {
		Functions\when( 'doing_action' )->justReturn( false );

		$order = $this->order( [ $this->entry() ] );
		$this->expect_no_write( $order );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), '«Resend new order notification» is not the edit' );
	}

	public function test_an_armed_order_is_muted_once_and_the_entry_is_consumed(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$order = $this->order( [ $this->entry() ] );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->ordered();
		$order->shouldReceive( 'save' )->once()->ordered();

		$this->assertFalse( Order_Editor::mute_new_order_email( true, $order ), 'the edit\'s own «New order» is off' );
	}

	public function test_each_queued_edit_has_its_own_entry_and_the_oldest_matching_one_is_consumed(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$older    = $this->entry( self::TRANSITION, 120 );
		$newer    = $this->entry( self::TRANSITION, 5 );
		$on_hold  = $this->entry( 'woocommerce_order_status_pending_to_on-hold', 60 );
		$expected = [ $on_hold, $newer ];

		$order = $this->order( [ $older, $on_hold, $newer ] );
		$order->shouldNotReceive( 'delete_meta_data' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META, $expected )->ordered();
		$order->shouldReceive( 'save' )->once()->ordered();

		$this->assertFalse( Order_Editor::mute_new_order_email( true, $order ), 'the first queued edit\'s «New order» is off' );
	}

	public function test_an_expired_entry_no_longer_mutes_and_is_pruned(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$order = $this->order( [ $this->entry( self::TRANSITION, Order_Editor::NEW_ORDER_EMAIL_MUTE_TTL + 1 ) ] );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->ordered();
		$order->shouldReceive( 'save' )->once()->ordered();

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), 'a queue that never ran cannot mute a repeat of the transition a day later' );
	}

	public function test_an_expired_entry_is_pruned_alongside_the_one_being_consumed(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$order = $this->order(
			[
				$this->entry( self::TRANSITION, Order_Editor::NEW_ORDER_EMAIL_MUTE_TTL + 1 ),
				$this->entry(),
			]
		);
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->ordered();
		$order->shouldReceive( 'save' )->once()->ordered();

		$this->assertFalse( Order_Editor::mute_new_order_email( true, $order ), 'the fresh entry mutes; the expired one goes with it' );
	}

	public function test_a_stale_object_defers_to_what_the_datastore_holds_now(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		// The legacy background emailer's second notification of one batch: the object still carries
		// the entry the first notification consumed — the datastore no longer does.
		$order = $this->order( [ $this->entry() ], '' );
		$this->expect_no_write( $order );

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), 'an entry already consumed by an earlier dispatch mutes nothing twice' );
	}

	public function test_a_value_of_another_shape_is_not_an_entry_and_is_dropped(): void {
		$this->dispatching( self::TRANSITION . '_notification' );

		$order = $this->order( self::TRANSITION ); // Round 2's single-string marker.
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( Order_Editor::NEW_ORDER_EMAIL_MUTE_META )->ordered();
		$order->shouldReceive( 'save' )->once()->ordered();

		$this->assertTrue( Order_Editor::mute_new_order_email( true, $order ), 'only the documented shape mutes' );
	}
}
