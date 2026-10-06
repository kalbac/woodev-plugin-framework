<?php
/**
 * Unit tests for the delivery status watcher: carriers get the event without calling notify().
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Order\Delivery_Status_Watcher;
use Woodev\Framework\Shipping\Order\Shipment_Cancellation;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status-events.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status-watcher.php';

/** @covers \Woodev\Framework\Shipping\Order\Delivery_Status_Watcher */
final class DeliveryStatusWatcherTest extends TestCase {

	/** @var array<string,mixed> order meta fake. */
	private array $meta = [];

	/** @var string post type of every post id. */
	private string $post_type = 'shop_order';

	protected function setUp(): void {
		parent::setUp();
		$this->meta      = [ '_test_marker' => '1' ];
		$this->post_type = 'shop_order';

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );
		Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ) { $this->meta[ $key ] = $value; return true; } );
		Functions\when( 'get_post_type' )->alias( function () { return $this->post_type; } );
		Functions\when( 'wc_get_order' )->alias( function ( $id ) { return 123 === (int) $id ? $this->order() : false; } );

		Delivery_Status_Watcher::reset_for_tests();
		Orders_Registry::instance()->reset_for_tests();
		Orders_Registry::instance()->register_provider(
			Orders_Provider::create(
				'test',
				'Carrier',
				'_test_marker',
				[ 'test_shipping' ],
				[
					'status_meta_key' => '_test_status',
					'status_map'      => [
						'NEW'  => Delivery_Status::CREATED,
						'ROAD' => Delivery_Status::IN_TRANSIT,
					],
				]
			)
		);
	}

	protected function tearDown(): void {
		Delivery_Status_Watcher::reset_for_tests();
		Orders_Registry::instance()->reset_for_tests();
		parent::tearDown();
	}

	private function order(): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( string $key ) { return $this->meta[ $key ] ?? ''; } );

		return $order;
	}

	public function test_it_listens_to_both_order_storages_and_to_shutdown(): void {
		Delivery_Status_Watcher::instance()->register();

		foreach ( [ 'added_order_meta', 'updated_order_meta', 'deleted_order_meta', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'shutdown' ] as $hook ) {
			$this->assertNotFalse( has_action( $hook ), $hook );
		}
	}

	public function test_a_first_write_of_the_carrier_status_publishes_the_event_at_shutdown(): void {
		$watcher = Delivery_Status_Watcher::instance();
		$this->meta['_test_status'] = 'NEW';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )
			->once()
			->with( Mockery::type( '\WC_Order' ), null, Delivery_Status::CREATED, Mockery::type( Orders_Provider::class ) );

		$watcher->on_added_post_meta( 1, 123, '_test_status', 'NEW' );
		$watcher->process();
	}

	public function test_a_later_status_change_publishes_with_the_remembered_previous_state(): void {
		$watcher                                              = Delivery_Status_Watcher::instance();
		$this->meta['_test_status']                           = 'ROAD';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::CREATED;

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )
			->once()
			->with( Mockery::type( '\WC_Order' ), Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT, Mockery::type( Orders_Provider::class ) );

		$watcher->on_updated_post_meta( 1, 123, '_test_status', 'ROAD' );
		$watcher->process();
	}

	public function test_the_hpos_order_meta_hooks_work_the_same_way(): void {
		$watcher                                              = Delivery_Status_Watcher::instance();
		$this->meta['_test_status']                           = 'ROAD';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::CREATED;
		$this->post_type                                      = 'not-a-post-type-check-for-order-meta';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once();

		$watcher->on_updated_meta( 1, 123, '_test_status', 'ROAD' );
		$watcher->process();
	}

	public function test_a_re_poll_that_rewrites_the_same_status_publishes_nothing(): void {
		$watcher                                              = Delivery_Status_Watcher::instance();
		$this->meta['_test_status']                           = 'ROAD';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::IN_TRANSIT;

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		$watcher->on_updated_post_meta( 1, 123, '_test_status', 'ROAD' );
		$watcher->process();
	}

	/** Orders already in flight when the framework started watching must not mail an old status. */
	public function test_an_update_of_an_order_never_seen_before_is_adopted_silently(): void {
		$watcher                    = Delivery_Status_Watcher::instance();
		$this->meta['_test_status'] = 'ROAD';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		$watcher->on_updated_post_meta( 1, 123, '_test_status', 'ROAD' );
		$watcher->process();

		$this->assertSame( Delivery_Status::IN_TRANSIT, $this->meta['_woodev_delivery_status_published_test'] );
	}

	public function test_several_writes_in_one_request_are_reconciled_once(): void {
		$watcher                    = Delivery_Status_Watcher::instance();
		$this->meta['_test_status'] = 'NEW';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once();

		$watcher->on_added_post_meta( 1, 123, '_test_status', 'NEW' );
		$watcher->on_updated_post_meta( 2, 123, '_test_status', 'NEW' );
		$watcher->on_updated_meta( 3, 123, '_test_status', 'NEW' );
		$watcher->process();
		$watcher->process();
	}

	public function test_the_framework_cancellation_marker_is_watched_too(): void {
		$watcher                                              = Delivery_Status_Watcher::instance();
		$this->meta['_test_status']                           = 'ROAD';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::IN_TRANSIT;
		$this->meta[ Shipment_Cancellation::CANCELLED_AT_META ] = time();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )
			->once()
			->with( Mockery::type( '\WC_Order' ), Delivery_Status::IN_TRANSIT, Delivery_Status::CANCELLED, Mockery::type( Orders_Provider::class ) );

		$watcher->on_added_post_meta( 1, 123, Shipment_Cancellation::CANCELLED_AT_META, time() );
		$watcher->process();
	}

	public function test_unrelated_meta_other_post_types_and_foreign_orders_are_ignored(): void {
		$watcher                    = Delivery_Status_Watcher::instance();
		$this->meta['_test_status'] = 'NEW';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		$watcher->on_added_post_meta( 1, 123, '_some_other_meta', 'x' );
		$this->post_type = 'product';
		$watcher->on_added_post_meta( 2, 123, '_test_status', 'NEW' );
		$this->post_type = 'shop_order';
		unset( $this->meta['_test_marker'] );
		$watcher->on_added_post_meta( 3, 123, '_test_status', 'NEW' );
		$watcher->process();
	}
}
