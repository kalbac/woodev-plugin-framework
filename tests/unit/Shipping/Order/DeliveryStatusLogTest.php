<?php
/**
 * Unit tests for the forward-only delivery status log (#1564).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\{Delivery_Status, Delivery_Status_Log};
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status-log.php';

/** @covers \Woodev\Framework\Shipping\Order\Delivery_Status_Log */
final class DeliveryStatusLogTest extends TestCase {

	/** @var array<string,mixed> order meta fake. */
	private array $meta = [];

	/** @var int how many times the meta was written. */
	private int $writes = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->meta   = [];
		$this->writes = 0;
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ) {
				++$this->writes;
				$this->meta[ $key ] = $value;

				return true;
			}
		);
	}

	private function provider( string $id = 'test' ): Orders_Provider {
		return Orders_Provider::create( $id, 'Тестовая доставка', '_' . $id . '_marker', [ $id . '_shipping' ] );
	}

	private function order(): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );

		return $order;
	}

	public function test_an_entry_is_state_and_unix_time_stored_as_json_under_the_carriers_own_key(): void {
		$this->assertTrue( Delivery_Status_Log::append( $this->order(), $this->provider(), Delivery_Status::CREATED, 1000 ) );

		$this->assertSame( '[["created",1000]]', $this->meta['_woodev_delivery_status_log_test'] );
		$this->assertSame( [ [ 'created', 1000 ] ], Delivery_Status_Log::read( $this->order(), $this->provider() ) );
	}

	public function test_entries_accumulate_oldest_first_one_write_each(): void {
		$order    = $this->order();
		$provider = $this->provider();

		Delivery_Status_Log::append( $order, $provider, Delivery_Status::CREATED, 1000 );
		Delivery_Status_Log::append( $order, $provider, Delivery_Status::IN_TRANSIT, 2000 );
		Delivery_Status_Log::append( $order, $provider, Delivery_Status::DELIVERED, 3000 );

		$this->assertSame( [ [ 'created', 1000 ], [ 'in_transit', 2000 ], [ 'delivered', 3000 ] ], Delivery_Status_Log::read( $order, $provider ) );
		$this->assertSame( 3, $this->writes );
	}

	public function test_a_repeated_state_writes_nothing(): void {
		$order    = $this->order();
		$provider = $this->provider();

		Delivery_Status_Log::append( $order, $provider, Delivery_Status::IN_TRANSIT, 1000 );

		$this->assertFalse( Delivery_Status_Log::append( $order, $provider, Delivery_Status::IN_TRANSIT, 2000 ) );
		$this->assertSame( 1, $this->writes );
		$this->assertSame( [ [ 'in_transit', 1000 ] ], Delivery_Status_Log::read( $order, $provider ), 'the first time stays' );
	}

	public function test_a_state_that_comes_back_after_another_is_a_new_entry(): void {
		$order    = $this->order();
		$provider = $this->provider();

		Delivery_Status_Log::append( $order, $provider, Delivery_Status::IN_TRANSIT, 1000 );
		Delivery_Status_Log::append( $order, $provider, Delivery_Status::FAILED, 2000 );
		Delivery_Status_Log::append( $order, $provider, Delivery_Status::IN_TRANSIT, 3000 );

		$this->assertCount( 3, Delivery_Status_Log::read( $order, $provider ) );
	}

	public function test_each_carrier_keeps_its_own_log_on_the_same_order(): void {
		$order = $this->order();

		Delivery_Status_Log::append( $order, $this->provider( 'cdek' ), Delivery_Status::CREATED, 1000 );
		Delivery_Status_Log::append( $order, $this->provider( 'yandex' ), Delivery_Status::DELIVERED, 2000 );

		$this->assertSame( [ [ 'created', 1000 ] ], Delivery_Status_Log::read( $order, $this->provider( 'cdek' ) ) );
		$this->assertSame( [ [ 'delivered', 2000 ] ], Delivery_Status_Log::read( $order, $this->provider( 'yandex' ) ) );
		$this->assertSame( '_woodev_delivery_status_log_cdek', Delivery_Status_Log::meta_key( $this->provider( 'cdek' ) ) );
	}

	public function test_the_log_keeps_the_newest_entries_up_to_the_cap(): void {
		$order    = $this->order();
		$provider = $this->provider();

		for ( $i = 1; $i <= Delivery_Status_Log::CAP + 5; $i++ ) {
			Delivery_Status_Log::append( $order, $provider, 0 === $i % 2 ? Delivery_Status::IN_TRANSIT : Delivery_Status::FAILED, $i );
		}

		$log = Delivery_Status_Log::read( $order, $provider );

		$this->assertCount( Delivery_Status_Log::CAP, $log );
		$this->assertSame( 6, $log[0][1], 'the five oldest were dropped' );
		$this->assertSame( Delivery_Status_Log::CAP + 5, $log[ Delivery_Status_Log::CAP - 1 ][1] );
	}

	public function test_decode_drops_anything_that_is_not_a_state_time_pair(): void {
		$this->assertSame( [], Delivery_Status_Log::decode( '' ) );
		$this->assertSame( [], Delivery_Status_Log::decode( 'garbage' ) );
		$this->assertSame( [], Delivery_Status_Log::decode( null ) );
		$this->assertSame( [], Delivery_Status_Log::decode( [ [ 'created', 1 ] ] ), 'only the stored JSON string is read' );
		$this->assertSame(
			[ [ 'created', 5 ], [ 'delivered', 9 ] ],
			Delivery_Status_Log::decode( '[["created",5],["x"],[1,2],"junk",["delivered","9"]]' )
		);
	}

	/**
	 * @dataProvider handover_cases
	 *
	 * @param array<int,array{0:string,1:int}> $entries  log.
	 * @param int|null                         $expected seconds.
	 */
	public function test_handover_to_delivery( array $entries, ?int $expected ): void {
		$this->assertSame( $expected, Delivery_Status_Log::handover_to_delivery( $entries ) );
	}

	public static function handover_cases(): array {
		$flapping = [];
		for ( $i = 0; $i < Delivery_Status_Log::CAP - 2; $i++ ) {
			$flapping[] = [ 0 === $i % 2 ? 'failed' : 'in_transit', 100 + $i ];
		}

		return [
			'in transit, then delivered'               => [ [ [ 'created', 0 ], [ 'in_transit', 100 ], [ 'delivered', 400 ] ], 300 ],
			'the first handover wins over a re-entry'  => [ [ [ 'in_transit', 100 ], [ 'failed', 200 ], [ 'in_transit', 300 ], [ 'delivered', 500 ] ], 400 ],
			'a pickup point counts as the handover'    => [ [ [ 'created', 0 ], [ 'ready_for_pickup', 100 ], [ 'delivered', 700 ] ], 600 ],
			'created is not a handover'                => [ [ [ 'created', 0 ], [ 'delivered', 700 ] ], null ],
			'delivered before any handover'            => [ [ [ 'delivered', 700 ], [ 'in_transit', 800 ] ], null ],
			'never delivered'                          => [ [ [ 'in_transit', 100 ], [ 'returning', 200 ] ], null ],
			'empty'                                    => [ [], null ],
			'both in the same second'                  => [ [ [ 'in_transit', 100 ], [ 'delivered', 100 ] ], 0 ],
			'a clock that went backwards'              => [ [ [ 'in_transit', 500 ], [ 'delivered', 100 ] ], null ],
			'longer than the bound'                    => [ [ [ 'in_transit', 0 ], [ 'delivered', Delivery_Status_Log::MAX_SECONDS + 1 ] ], null ],
			'exactly the bound'                        => [ [ [ 'in_transit', 0 ], [ 'delivered', Delivery_Status_Log::MAX_SECONDS ] ], Delivery_Status_Log::MAX_SECONDS ],
			'a log below the cap still answers'        => [ array_merge( $flapping, [ [ 'delivered', 9999 ] ] ), 9898 ],
		];
	}

	/** A log at the cap may have lost its beginning: its «first» handover could be a later re-entry, so it is not trusted. */
	public function test_a_log_at_the_cap_is_not_trusted(): void {
		$entries = [];
		for ( $i = 0; $i < Delivery_Status_Log::CAP - 1; $i++ ) {
			$entries[] = [ 'in_transit', $i ];
		}
		$entries[] = [ 'delivered', 1000 ];

		$this->assertCount( Delivery_Status_Log::CAP, $entries );
		$this->assertNull( Delivery_Status_Log::handover_to_delivery( $entries ) );
	}
}
