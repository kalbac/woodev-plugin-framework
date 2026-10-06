<?php
/**
 * Unit tests for the shared delivery status change event.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Actions;
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

	/** @var array<string,mixed> order meta fake. */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		$this->meta = [];
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );
		Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ) { $this->meta[ $key ] = $value; return true; } );
	}

	private function provider(): Orders_Provider {
		return Orders_Provider::create(
			'test',
			'Тестовая доставка',
			'_test_marker',
			[ 'test_shipping' ],
			[
				'status_meta_key' => '_test_status',
				'status_map'      => [
					'NEW'       => Delivery_Status::CREATED,
					'ROAD'      => Delivery_Status::IN_TRANSIT,
					'DELIVERED' => Delivery_Status::DELIVERED,
				],
			]
		);
	}

	private function order(): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( string $key ) { return $this->meta[ $key ] ?? ''; } );

		return $order;
	}

	public function test_notify_resolves_and_publishes_the_current_canonical_state(): void {
		$this->meta['_test_status'] = 'ROAD';
		$order                      = $this->order();
		$provider                   = $this->provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )
			->once()
			->with( $order, Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT, $provider );

		$this->assertSame( Delivery_Status::IN_TRANSIT, Delivery_Status_Events::notify( $order, $provider, Delivery_Status::CREATED ) );
		$this->assertSame( Delivery_Status::IN_TRANSIT, $this->meta['_woodev_delivery_status_published_test'], 'the published state is remembered' );
	}

	public function test_notify_publishes_nothing_when_the_canonical_state_did_not_change(): void {
		$this->meta['_test_status'] = 'ROAD';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		$this->assertSame( Delivery_Status::IN_TRANSIT, Delivery_Status_Events::notify( $this->order(), $this->provider(), Delivery_Status::IN_TRANSIT ) );
	}

	public function test_a_null_previous_state_means_the_last_one_published(): void {
		$this->meta['_test_status']                    = 'ROAD';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::IN_TRANSIT;

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		// A cron re-poll that re-writes the same raw status and calls notify() with no previous state.
		Delivery_Status_Events::notify( $this->order(), $this->provider() );
	}

	public function test_a_first_ever_status_is_published_with_a_null_previous_state(): void {
		$this->meta['_test_status'] = 'NEW';
		$order                      = $this->order();
		$provider                   = $this->provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once()->with( $order, null, Delivery_Status::CREATED, $provider );

		Delivery_Status_Events::notify( $order, $provider );
	}

	public function test_sync_publishes_a_real_change_against_the_remembered_state(): void {
		$this->meta['_test_status']                    = 'DELIVERED';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::IN_TRANSIT;
		$order                                         = $this->order();
		$provider                                      = $this->provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once()->with( $order, Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED, $provider );

		$this->assertSame( Delivery_Status::DELIVERED, Delivery_Status_Events::sync( $order, $provider ) );
		$this->assertNull( Delivery_Status_Events::sync( $order, $provider ), 'the same state again publishes nothing' );
	}

	/**
	 * Shipments already in flight when the watcher started have no remembered state; their next re-poll must not
	 * mail the buyer an old status. They are adopted silently.
	 */
	public function test_sync_adopts_an_order_with_no_remembered_state_silently(): void {
		$this->meta['_test_status'] = 'ROAD';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		$this->assertNull( Delivery_Status_Events::sync( $this->order(), $this->provider() ) );
		$this->assertSame( Delivery_Status::IN_TRANSIT, $this->meta['_woodev_delivery_status_published_test'] );
	}

	public function test_sync_publishes_the_first_write_of_a_status(): void {
		$this->meta['_test_status'] = 'NEW';
		$order                      = $this->order();
		$provider                   = $this->provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once()->with( $order, null, Delivery_Status::CREATED, $provider );

		$this->assertSame( Delivery_Status::CREATED, Delivery_Status_Events::sync( $order, $provider, true ) );
	}
}
