<?php
/**
 * Unit tests for the shared delivery status change event.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Order\Delivery_Status_Events;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status-events.php';

/** @covers \Woodev\Framework\Shipping\Order\Delivery_Status_Events */
final class DeliveryStatusEventsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
	}

	public function test_notify_resolves_and_publishes_the_current_canonical_state(): void {
		$order    = Mockery::mock( '\WC_Order' );
		$provider = Orders_Provider::create( 'test', 'Тестовая доставка', '_test_marker', [ 'test_shipping' ] );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		Functions\expect( 'do_action' )
			->once()
			->with( 'woodev_shipping_delivery_status_changed', $order, Delivery_Status::CREATED, Delivery_Status::UNKNOWN, $provider );

		$this->assertSame( Delivery_Status::UNKNOWN, Delivery_Status_Events::notify( $order, $provider, Delivery_Status::CREATED ) );
	}
}
