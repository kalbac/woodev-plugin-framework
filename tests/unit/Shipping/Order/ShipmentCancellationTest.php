<?php
/**
 * Unit: the framework's own «shipment cancelled» order meta (#1037).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Order\Shipment_Cancellation;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

/**
 * @covers \Woodev\Framework\Shipping\Order\Shipment_Cancellation
 */
final class ShipmentCancellationTest extends TestCase {

	/** @var array<string,mixed> the post meta of order 123 */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);
	}

	/**
	 * @return \Mockery\MockInterface&\WC_Order
	 */
	private function order() {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );

		return $order;
	}

	public function test_the_meta_key_is_the_frameworks_own_and_stable(): void {
		$this->assertSame( '_woodev_shipment_cancelled_at', Shipment_Cancellation::CANCELLED_AT_META );
	}

	public function test_an_order_with_no_marker_is_not_cancelled(): void {
		$this->assertSame( 0, Shipment_Cancellation::cancelled_at( $this->order() ) );
		$this->assertFalse( Shipment_Cancellation::is_cancelled( $this->order() ) );
	}

	public function test_a_marker_makes_the_order_cancelled_and_reports_when(): void {
		$this->meta[ Shipment_Cancellation::CANCELLED_AT_META ] = '1790000000';

		$this->assertSame( 1790000000, Shipment_Cancellation::cancelled_at( $this->order() ) );
		$this->assertTrue( Shipment_Cancellation::is_cancelled( $this->order() ) );
	}

	public function test_a_junk_marker_value_is_not_a_cancellation(): void {
		$this->meta[ Shipment_Cancellation::CANCELLED_AT_META ] = 'garbage';

		$this->assertFalse( Shipment_Cancellation::is_cancelled( $this->order() ) );
	}

	public function test_mark_writes_the_current_time_through_the_orders_own_meta_api_and_saves(): void {
		$before = time();
		$order  = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with(
				'_woodev_shipment_cancelled_at',
				Mockery::on(
					static function ( $value ) use ( $before ): bool {
						return is_int( $value ) && $value >= $before && $value <= time();
					}
				)
			)
			->ordered();
		$order->shouldReceive( 'save_meta_data' )->once()->ordered();

		Shipment_Cancellation::mark( $order );
	}

	public function test_clear_deletes_the_marker_off_the_fresh_order_and_the_callers_copy(): void {
		$fresh  = Mockery::mock( '\WC_Order' );
		$caller = Mockery::mock( '\WC_Order' );

		foreach ( [ $fresh, $caller ] as $order ) {
			$order->shouldReceive( 'get_meta' )->with( '_woodev_shipment_cancelled_at' )->andReturn( '1790000000' );
			$order->shouldReceive( 'delete_meta_data' )->once()->with( '_woodev_shipment_cancelled_at' );
			$order->shouldReceive( 'save_meta_data' )->once();
		}

		Shipment_Cancellation::clear( $fresh, $caller );
	}

	public function test_clear_is_a_no_op_with_no_datastore_write_when_nothing_was_recorded(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_woodev_shipment_cancelled_at' )->andReturn( '' );
		$order->shouldNotReceive( 'delete_meta_data' );
		$order->shouldNotReceive( 'save_meta_data' );

		Shipment_Cancellation::clear( $order, $order );

		$this->addToAssertionCount( 1 );
	}

	public function test_clear_touches_the_order_once_when_fresh_and_caller_are_the_same_object(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_woodev_shipment_cancelled_at' )->andReturn( '1790000000' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_woodev_shipment_cancelled_at' );
		$order->shouldReceive( 'save_meta_data' )->once();

		Shipment_Cancellation::clear( $order, $order );
	}
}
