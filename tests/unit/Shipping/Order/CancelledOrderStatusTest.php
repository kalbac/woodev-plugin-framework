<?php
/**
 * Unit: the merchant's «Статус отменённого заказа» (#1203) — set when the CARRIER reports the shipment cancelled.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Cancelled_Order_Status;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Order\Shipment_Cancellation;
use Woodev\Framework\Shipping\Settings\Export_Settings;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-cancelled-order-status.php';

/** @covers \Woodev\Framework\Shipping\Order\Cancelled_Order_Status */
final class CancelledOrderStatusTest extends TestCase {

	/** @var array<string,mixed> order meta fake. */
	private array $meta = [];

	/** @var string|null what the carrier's «Статус отменённого заказа» is (null = «Не менять») */
	private ?string $target = 'cancelled';

	protected function setUp(): void {
		parent::setUp();

		$this->meta   = [];
		$this->target = 'cancelled';

		Functions\stubs( [ 'remove_action', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_is_order_status' )->alias( static fn( string $status ) => in_array( $status, [ 'wc-cancelled', 'wc-on-hold', 'wc-processing', 'wc-carrier-cancelled' ], true ) );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ) => $this->meta[ $key ] ?? '' );

		Orders_Registry::instance()->reset_for_tests();

		$export = Mockery::mock( Export_Settings::class );
		$export->shouldReceive( 'get_cancelled_status' )->andReturnUsing( fn() => $this->target );

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

	public function test_a_carrier_cancelled_order_gets_the_chosen_status_with_a_note_naming_the_reason(): void {
		$this->target = 'on-hold';
		$order        = $this->order( 'processing' );
		$order->shouldReceive( 'update_status' )->once()->with( 'on-hold', 'Перевозчик сообщил, что отправление отменено.' );

		Cancelled_Order_Status::apply( $order, Delivery_Status::IN_TRANSIT, Delivery_Status::CANCELLED, $this->provider() );

		$this->assertFalse( Cancelled_Order_Status::is_applying( 55 ), 'the flag is only up while the status is being set' );
	}

	public function test_the_default_choice_cancels_the_order_once(): void {
		$order = $this->order( 'processing' );
		$order->shouldReceive( 'update_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );

		Cancelled_Order_Status::apply( $order, Delivery_Status::CREATED, Delivery_Status::CANCELLED, $this->provider() );
	}

	public function test_dont_change_does_nothing(): void {
		$this->target = null;
		$order        = $this->order();
		$order->shouldReceive( 'update_status' )->never();

		Cancelled_Order_Status::apply( $order, null, Delivery_Status::CANCELLED, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_status_woocommerce_no_longer_has_does_nothing(): void {
		$this->target = 'removed-custom-status';
		$order        = $this->order();
		$order->shouldReceive( 'update_status' )->never();

		Cancelled_Order_Status::apply( $order, null, Delivery_Status::CANCELLED, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_order_already_in_the_chosen_status_is_left_alone(): void {
		$this->target = 'on-hold';
		$order        = $this->order( 'on-hold' );
		$order->shouldReceive( 'update_status' )->never();

		Cancelled_Order_Status::apply( $order, null, Delivery_Status::CANCELLED, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function protected_status_provider(): array {
		return [
			'cancelled' => [ 'cancelled' ],
			'completed' => [ 'completed' ],
			'refunded'  => [ 'refunded' ],
			'failed'    => [ 'failed' ],
			'trash'     => [ 'trash' ],
		];
	}

	/**
	 * @dataProvider protected_status_provider
	 */
	public function test_an_order_the_merchant_already_settled_is_never_touched( string $status ): void {
		$this->target = 'on-hold';
		$order        = $this->order( $status );
		$order->shouldReceive( 'update_status' )->never();

		Cancelled_Order_Status::apply( $order, null, Delivery_Status::CANCELLED, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_cancellation_the_framework_recorded_is_not_the_carriers_news(): void {
		// the «Отменить» button / a cancelled order: Shipment_Cancellation is the merchant's act, the order must not follow it.
		$this->meta[ Shipment_Cancellation::CANCELLED_AT_META ] = '1790000000';
		$order = $this->order( 'processing' );
		$order->shouldReceive( 'update_status' )->never();

		Cancelled_Order_Status::apply( $order, Delivery_Status::CREATED, Delivery_Status::CANCELLED, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	public function test_any_other_canonical_state_is_ignored(): void {
		$order = $this->order();
		$order->shouldReceive( 'update_status' )->never();

		foreach ( [ Delivery_Status::IN_TRANSIT, Delivery_Status::RETURNED, Delivery_Status::DELIVERED, Delivery_Status::FAILED, Delivery_Status::UNKNOWN ] as $state ) {
			Cancelled_Order_Status::apply( $order, null, $state, $this->provider() );
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_a_provider_registered_without_a_plugin_is_ignored(): void {
		Orders_Registry::instance()->reset_for_tests();
		Orders_Registry::instance()->register_provider( $this->provider() );

		$order = $this->order();
		$order->shouldReceive( 'update_status' )->never();

		Cancelled_Order_Status::apply( $order, null, Delivery_Status::CANCELLED, $this->provider() );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_foreign_payload_is_ignored(): void {
		Cancelled_Order_Status::apply( 'not-an-order', null, Delivery_Status::CANCELLED, $this->provider() );
		Cancelled_Order_Status::apply( $this->order(), null, Delivery_Status::CANCELLED, 'not-a-provider' );

		$this->addToAssertionCount( 1 );
	}

	public function test_the_flag_is_up_during_the_status_change_and_down_after_a_failure(): void {
		$seen  = null;
		$order = $this->order();
		$order->shouldReceive( 'update_status' )->once()->andReturnUsing(
			static function () use ( &$seen ) {
				$seen = Cancelled_Order_Status::is_applying( 55 );

				throw new \RuntimeException( 'a status hook blew up' );
			}
		);

		try {
			Cancelled_Order_Status::apply( $order, null, Delivery_Status::CANCELLED, $this->provider() );
			$this->fail( 'the exception should propagate' );
		} catch ( \RuntimeException $e ) {
			$this->assertTrue( $seen );
			$this->assertFalse( Cancelled_Order_Status::is_applying( 55 ), 'a failure must not leave the guard up for the rest of the request' );
		}
	}

	public function test_it_listens_to_the_delivery_status_changed_event(): void {
		\Brain\Monkey\Actions\expectAdded( 'woodev_shipping_delivery_status_changed' )->once();

		Cancelled_Order_Status::register();

		$this->addToAssertionCount( 1 );
	}
}
