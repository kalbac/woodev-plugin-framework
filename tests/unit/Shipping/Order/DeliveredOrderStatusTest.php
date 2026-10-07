<?php
/**
 * Unit: the merchant's «Статус доставленного заказа» (s158) — set once, when the canonical state becomes «delivered».
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivered_Order_Status;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Settings\Export_Settings;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivered-order-status.php';

/** @covers \Woodev\Framework\Shipping\Order\Delivered_Order_Status */
final class DeliveredOrderStatusTest extends TestCase {

	private const MARKER = '_woodev_delivered_status_applied_cdek';

	/** @var array<string,mixed> order meta fake. */
	private array $meta = [];

	/** @var string|null what the carrier's «Статус доставленного заказа» is (null = «Не менять») */
	private ?string $target = 'completed';

	protected function setUp(): void {
		parent::setUp();

		$this->meta   = [];
		$this->target = 'completed';

		Functions\stubs( [ 'remove_action', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_is_order_status' )->alias( static fn( string $status ) => in_array( $status, [ 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-delivered' ], true ) );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ) {
				$this->meta[ $key ] = $value;

				return true;
			}
		);

		Orders_Registry::instance()->reset_for_tests();

		$export = Mockery::mock( Export_Settings::class );
		$export->shouldReceive( 'get_delivered_status' )->andReturnUsing( fn() => $this->target );

		$plugin = Mockery::mock( Shipping_Plugin::class )->makePartial();
		$plugin->shouldReceive( 'get_export_settings' )->andReturn( $export );

		Orders_Registry::instance()->register_provider( $this->provider(), $plugin );
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider(): Orders_Provider {
		return Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] );
	}

	/**
	 * @param string $status the order's current status, without `wc-`.
	 * @return \WC_Order&\Mockery\MockInterface
	 */
	private function order( string $status = 'processing' ) {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 55 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );

		return $order;
	}

	public function test_a_delivered_order_gets_the_chosen_status_once_and_is_marked(): void {
		$order = $this->order( 'processing' );
		$order->shouldReceive( 'update_status' )->once()->with( 'completed', Mockery::type( 'string' ) );

		Delivered_Order_Status::apply( $order, Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED, $this->provider() );

		$this->assertSame( '1', $this->meta[ self::MARKER ] );
	}

	public function test_a_re_sync_that_publishes_delivered_again_does_not_set_it_again(): void {
		$first = $this->order( 'processing' );
		$first->shouldReceive( 'update_status' )->once();
		Delivered_Order_Status::apply( $first, null, Delivery_Status::DELIVERED, $this->provider() );

		// the merchant moved the order back by hand; the carrier re-polls and the state is published again
		$second = $this->order( 'processing' );
		$second->shouldReceive( 'update_status' )->never();
		Delivered_Order_Status::apply( $second, Delivery_Status::RETURNING, Delivery_Status::DELIVERED, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_already_in_the_chosen_status_is_left_alone_and_counted_as_done(): void {
		$order = $this->order( 'completed' );
		$order->shouldReceive( 'update_status' )->never();

		Delivered_Order_Status::apply( $order, Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED, $this->provider() );

		$this->assertSame( '1', $this->meta[ self::MARKER ] );
	}

	public function test_dont_change_does_nothing(): void {
		$this->target = null;
		$order        = $this->order();
		$order->shouldReceive( 'update_status' )->never();

		Delivered_Order_Status::apply( $order, null, Delivery_Status::DELIVERED, $this->provider() );

		$this->assertArrayNotHasKey( self::MARKER, $this->meta );
	}

	public function test_a_status_woocommerce_no_longer_has_does_nothing(): void {
		$this->target = 'removed-custom-status';
		$order        = $this->order();
		$order->shouldReceive( 'update_status' )->never();

		Delivered_Order_Status::apply( $order, null, Delivery_Status::DELIVERED, $this->provider() );

		$this->assertArrayNotHasKey( self::MARKER, $this->meta );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function protected_status_provider(): array {
		return [
			'cancelled' => [ 'cancelled' ],
			'refunded'  => [ 'refunded' ],
			'failed'    => [ 'failed' ],
			'trash'     => [ 'trash' ],
		];
	}

	/**
	 * @dataProvider protected_status_provider
	 */
	public function test_a_cancelled_refunded_failed_or_trashed_order_is_never_touched_nor_marked( string $status ): void {
		$order = $this->order( $status );
		$order->shouldReceive( 'update_status' )->never();

		Delivered_Order_Status::apply( $order, null, Delivery_Status::DELIVERED, $this->provider() );

		$this->assertArrayNotHasKey( self::MARKER, $this->meta, 'not done: a later, legitimate delivery may still apply' );
	}

	public function test_any_other_canonical_state_is_ignored(): void {
		$order = $this->order();
		$order->shouldReceive( 'update_status' )->never();

		foreach ( [ Delivery_Status::IN_TRANSIT, Delivery_Status::RETURNED, Delivery_Status::READY_FOR_PICKUP, Delivery_Status::CANCELLED ] as $state ) {
			Delivered_Order_Status::apply( $order, null, $state, $this->provider() );
		}

		$this->assertArrayNotHasKey( self::MARKER, $this->meta );
	}

	public function test_a_provider_registered_without_a_plugin_is_ignored(): void {
		Orders_Registry::instance()->reset_for_tests();
		Orders_Registry::instance()->register_provider( $this->provider() );

		$order = $this->order();
		$order->shouldReceive( 'update_status' )->never();

		Delivered_Order_Status::apply( $order, null, Delivery_Status::DELIVERED, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_foreign_payload_is_ignored(): void {
		Delivered_Order_Status::apply( 'not-an-order', null, Delivery_Status::DELIVERED, $this->provider() );
		Delivered_Order_Status::apply( $this->order(), null, Delivery_Status::DELIVERED, 'not-a-provider' );

		$this->assertArrayNotHasKey( self::MARKER, $this->meta );
	}

	public function test_it_listens_to_the_delivery_status_changed_event(): void {
		\Brain\Monkey\Actions\expectAdded( 'woodev_shipping_delivery_status_changed' )->once();

		Delivered_Order_Status::register();

		$this->addToAssertionCount( 1 );
	}
}
