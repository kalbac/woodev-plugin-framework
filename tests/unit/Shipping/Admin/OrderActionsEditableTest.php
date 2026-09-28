<?php
/**
 * Unit: the editable-state policy of the admin order wizard (#710 spec D5, card #968).
 *
 * ONE named policy — `Order_Actions::is_editable()` / `not_editable_reason()` — read by the row
 * action AND by the update / load routes. Pinned here: every refusal, in both directions, and
 * that the reason is always a sentence when (and only when) the order is not editable.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::is_editable
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::not_editable_reason
 */
final class OrderActionsEditableTest extends TestCase {

	/** @var array<string,mixed> post meta of the active order, by meta key. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

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
					'RETURNED'  => 'returned',
				],
			]
		);
	}

	private function order( string $status = 'pending' ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );

		return $order;
	}

	public function test_a_fresh_order_of_a_known_carrier_is_editable(): void {
		foreach ( [ 'pending', 'on-hold', 'processing' ] as $status ) {
			$order = $this->order( $status );

			$this->assertTrue( Order_Actions::is_editable( $order, $this->provider() ), $status );
			$this->assertSame( '', Order_Actions::not_editable_reason( $order, $this->provider() ), $status );
		}
	}

	public function test_an_order_that_is_not_a_row_of_the_page_is_not_editable(): void {
		$this->assertFalse( Order_Actions::is_editable( $this->order(), null ) );
		$this->assertNotSame( '', Order_Actions::not_editable_reason( $this->order(), null ) );
	}

	public function test_an_exported_order_is_not_editable_and_the_reason_says_to_cancel_the_export(): void {
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';

		$order = $this->order();

		$this->assertFalse( Order_Actions::is_editable( $order, $this->provider() ) );
		$this->assertStringContainsString( 'отмените выгрузку', Order_Actions::not_editable_reason( $order, $this->provider() ) );
	}

	public function test_an_empty_stored_carrier_order_id_does_not_count_as_exported(): void {
		$this->meta['_cdek_carrier_order_id'] = '';

		$this->assertTrue( Order_Actions::is_editable( $this->order(), $this->provider() ) );
	}

	public function test_every_final_wc_status_is_refused_and_named_in_the_reason(): void {
		foreach ( Order_Actions::FINAL_STATUSES as $status ) {
			$order = $this->order( $status );

			$this->assertFalse( Order_Actions::is_editable( $order, $this->provider() ), $status );
			$this->assertStringContainsString( ucfirst( $status ), Order_Actions::not_editable_reason( $order, $this->provider() ), $status );
		}
	}

	public function test_the_final_statuses_are_the_four_the_spec_names(): void {
		$this->assertSame( [ 'completed', 'cancelled', 'refunded', 'failed' ], Order_Actions::FINAL_STATUSES );
	}

	public function test_a_finished_delivery_is_refused_even_when_the_carrier_id_is_absent(): void {
		$this->meta['_cdek_status'] = 'DELIVERED';

		$this->assertFalse( Order_Actions::is_editable( $this->order( 'processing' ), $this->provider() ) );
	}

	public function test_a_delivery_that_is_still_moving_is_editable(): void {
		$this->meta['_cdek_status'] = 'NEW';

		$this->assertTrue( Order_Actions::is_editable( $this->order( 'processing' ), $this->provider() ) );
	}

	public function test_the_export_check_comes_before_the_status_check(): void {
		// An exported, completed order is refused for the export — the one thing the manager can undo.
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';

		$this->assertStringContainsString( 'отмените выгрузку', Order_Actions::not_editable_reason( $this->order( 'completed' ), $this->provider() ) );
	}
}
