<?php
/**
 * Unit tests for the forward-only delivery status log (#1564).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\{Delivery_Status, Delivery_Status_Log};
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status-log.php';
require_once __DIR__ . '/delivery-status-order-fakes.php';

/** @covers \Woodev\Framework\Shipping\Order\Delivery_Status_Log */
final class DeliveryStatusLogTest extends TestCase {

	use Fakes_Status_Orders;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_status_orders();
	}

	protected function tearDown(): void {
		$this->restore_status_wpdb();
		parent::tearDown();
	}

	/**
	 * @param string[] $handover raw statuses the carrier declares as the handover.
	 */
	private function provider( string $id = 'test', array $handover = [] ): Orders_Provider {
		return Orders_Provider::create( $id, 'Тестовая доставка', '_' . $id . '_marker', [ $id . '_shipping' ], [ 'handover_statuses' => $handover ] );
	}

	/** The key the order object holds the log under, in its own (unsaved) meta. */
	private function staged( $order, string $id = 'test' ) {
		return $order->get_meta( '_woodev_delivery_status_log_' . $id );
	}

	public function test_an_entry_is_state_time_and_raw_code_stored_as_json_under_the_carriers_own_key(): void {
		$order = $this->snapshot_order();

		$this->assertTrue( Delivery_Status_Log::stage( $order, $this->provider(), Delivery_Status::CREATED, 'NEW', true, 1000 ) );

		$this->assertSame( '[["created",1000,"NEW"]]', $this->staged( $order ) );
		$this->assertSame( [ [ 'created', 1000, 'NEW' ] ], Delivery_Status_Log::read( $order, $this->provider() ) );
	}

	public function test_staging_does_not_save_the_order(): void {
		$order = $this->snapshot_order();

		Delivery_Status_Log::stage( $order, $this->provider(), Delivery_Status::CREATED, 'NEW', true, 1000 );

		$this->assertSame( 0, $this->saves, 'the caller saves, under its lock' );
		$this->assertArrayNotHasKey( '_woodev_delivery_status_log_test', $this->meta );
	}

	public function test_entries_accumulate_oldest_first(): void {
		$order    = $this->snapshot_order();
		$provider = $this->provider();

		Delivery_Status_Log::stage( $order, $provider, Delivery_Status::CREATED, 'A', true, 1000 );
		Delivery_Status_Log::stage( $order, $provider, Delivery_Status::IN_TRANSIT, 'B', true, 2000 );
		Delivery_Status_Log::stage( $order, $provider, Delivery_Status::DELIVERED, 'C', true, 3000 );

		$this->assertSame( [ [ 'created', 1000, 'A' ], [ 'in_transit', 2000, 'B' ], [ 'delivered', 3000, 'C' ] ], Delivery_Status_Log::read( $order, $provider ) );
	}

	public function test_a_repeated_state_stages_nothing(): void {
		$order    = $this->snapshot_order();
		$provider = $this->provider();

		Delivery_Status_Log::stage( $order, $provider, Delivery_Status::IN_TRANSIT, 'B', true, 1000 );

		$this->assertFalse( Delivery_Status_Log::stage( $order, $provider, Delivery_Status::IN_TRANSIT, 'B', true, 2000 ) );
		$this->assertSame( [ [ 'in_transit', 1000, 'B' ] ], Delivery_Status_Log::read( $order, $provider ), 'the first time stays' );
	}

	public function test_a_state_that_did_not_change_stages_nothing_even_for_an_empty_log(): void {
		$order = $this->snapshot_order();

		$this->assertFalse( Delivery_Status_Log::stage( $order, $this->provider(), Delivery_Status::IN_TRANSIT, 'B', false, 1000 ), 'no back-fill' );
		$this->assertSame( [], Delivery_Status_Log::read( $order, $this->provider() ) );
	}

	public function test_a_state_that_comes_back_after_another_is_a_new_entry(): void {
		$order    = $this->snapshot_order();
		$provider = $this->provider();

		Delivery_Status_Log::stage( $order, $provider, Delivery_Status::IN_TRANSIT, 'B', true, 1000 );
		Delivery_Status_Log::stage( $order, $provider, Delivery_Status::FAILED, 'F', true, 2000 );
		Delivery_Status_Log::stage( $order, $provider, Delivery_Status::IN_TRANSIT, 'B', true, 3000 );

		$this->assertCount( 3, Delivery_Status_Log::read( $order, $provider ) );
	}

	public function test_each_carrier_keeps_its_own_log_on_the_same_order(): void {
		$order = $this->snapshot_order();

		Delivery_Status_Log::stage( $order, $this->provider( 'cdek' ), Delivery_Status::CREATED, 'A', true, 1000 );
		Delivery_Status_Log::stage( $order, $this->provider( 'yandex' ), Delivery_Status::DELIVERED, 'C', true, 2000 );

		$this->assertSame( [ [ 'created', 1000, 'A' ] ], Delivery_Status_Log::read( $order, $this->provider( 'cdek' ) ) );
		$this->assertSame( [ [ 'delivered', 2000, 'C' ] ], Delivery_Status_Log::read( $order, $this->provider( 'yandex' ) ) );
		$this->assertSame( '_woodev_delivery_status_log_cdek', Delivery_Status_Log::meta_key( $this->provider( 'cdek' ) ) );
	}

	public function test_the_log_keeps_the_newest_entries_up_to_the_cap(): void {
		$order    = $this->snapshot_order();
		$provider = $this->provider();

		for ( $i = 1; $i <= Delivery_Status_Log::CAP + 5; $i++ ) {
			Delivery_Status_Log::stage( $order, $provider, 0 === $i % 2 ? Delivery_Status::IN_TRANSIT : Delivery_Status::FAILED, '', true, $i );
		}

		$log = Delivery_Status_Log::read( $order, $provider );

		$this->assertCount( Delivery_Status_Log::CAP, $log );
		$this->assertSame( 6, $log[0][1], 'the five oldest were dropped' );
		$this->assertSame( Delivery_Status_Log::CAP + 5, $log[ Delivery_Status_Log::CAP - 1 ][1] );
	}

	public function test_the_first_declared_handover_code_is_staged_although_the_state_did_not_change(): void {
		$order    = $this->snapshot_order();
		$provider = $this->provider( 'test', [ 'WAREHOUSE' ] );

		Delivery_Status_Log::stage( $order, $provider, Delivery_Status::CREATED, 'NEW', true, 1000 );

		$this->assertTrue( Delivery_Status_Log::stage( $order, $provider, Delivery_Status::CREATED, 'WAREHOUSE', false, 2000 ) );
		$this->assertFalse( Delivery_Status_Log::stage( $order, $provider, Delivery_Status::CREATED, 'WAREHOUSE', false, 3000 ), 'only the first' );
		$this->assertSame( [ [ 'created', 1000, 'NEW' ], [ 'created', 2000, 'WAREHOUSE' ] ], Delivery_Status_Log::read( $order, $provider ) );
	}

	public function test_a_code_the_carrier_did_not_declare_is_not_staged(): void {
		$order = $this->snapshot_order();

		Delivery_Status_Log::stage( $order, $this->provider( 'test', [ 'WAREHOUSE' ] ), Delivery_Status::CREATED, 'NEW', true, 1000 );

		$this->assertFalse( Delivery_Status_Log::stage( $order, $this->provider( 'test', [ 'WAREHOUSE' ] ), Delivery_Status::CREATED, 'OTHER', false, 2000 ) );
		$this->assertFalse( Delivery_Status_Log::stage( $order, $this->provider(), Delivery_Status::CREATED, 'WAREHOUSE', false, 3000 ), 'a carrier that declares nothing' );
	}

	public function test_decode_drops_anything_that_is_not_a_state_time_entry_and_reads_both_shapes(): void {
		$this->assertSame( [], Delivery_Status_Log::decode( '' ) );
		$this->assertSame( [], Delivery_Status_Log::decode( 'garbage' ) );
		$this->assertSame( [], Delivery_Status_Log::decode( null ) );
		$this->assertSame( [], Delivery_Status_Log::decode( [ [ 'created', 1 ] ] ), 'only the stored JSON string is read' );
		$this->assertSame(
			[ [ 'created', 5, '' ], [ 'delivered', 9, 'DONE' ], [ 'delivered', 11, '' ] ],
			Delivery_Status_Log::decode( '[["created",5],["x"],[1,2],"junk",["delivered","9","DONE"],["delivered",11,7]]' ),
			'a two-element entry (written before the raw code was kept) decodes with an empty raw code; a non-string raw code is dropped'
		);
	}

	/**
	 * @dataProvider handover_cases
	 *
	 * @param array<int,array{0:string,1:int,2:string}> $entries  log.
	 * @param int|null                                  $expected seconds.
	 * @param string[]                                  $declared the carrier's declared handover raw codes.
	 */
	public function test_handover_to_delivery( array $entries, ?int $expected, array $declared = [] ): void {
		$this->assertSame( $expected, Delivery_Status_Log::handover_to_delivery( $entries, $declared ) );
	}

	public static function handover_cases(): array {
		$flapping = [];
		for ( $i = 0; $i < Delivery_Status_Log::CAP - 2; $i++ ) {
			$flapping[] = [ 0 === $i % 2 ? 'failed' : 'in_transit', 100 + $i, '' ];
		}

		return [
			'in transit, then delivered'               => [ [ [ 'created', 0, '' ], [ 'in_transit', 100, '' ], [ 'delivered', 400, '' ] ], 300 ],
			'the first handover wins over a re-entry'  => [ [ [ 'in_transit', 100, '' ], [ 'failed', 200, '' ], [ 'in_transit', 300, '' ], [ 'delivered', 500, '' ] ], 400 ],
			'created is not a handover'                => [ [ [ 'created', 0, '' ], [ 'delivered', 700, '' ] ], null ],
			'delivered before any handover'            => [ [ [ 'delivered', 700, '' ], [ 'in_transit', 800, '' ] ], null ],
			'never delivered'                          => [ [ [ 'in_transit', 100, '' ], [ 'returning', 200, '' ] ], null ],
			'empty'                                    => [ [], null ],
			'both in the same second'                  => [ [ [ 'in_transit', 100, '' ], [ 'delivered', 100, '' ] ], 0 ],
			'a clock that went backwards'              => [ [ [ 'in_transit', 500, '' ], [ 'delivered', 100, '' ] ], null ],
			'longer than the bound'                    => [ [ [ 'in_transit', 0, '' ], [ 'delivered', Delivery_Status_Log::MAX_SECONDS + 1, '' ] ], null ],
			'exactly the bound'                        => [ [ [ 'in_transit', 0, '' ], [ 'delivered', Delivery_Status_Log::MAX_SECONDS, '' ] ], Delivery_Status_Log::MAX_SECONDS ],
			'a log below the cap still answers'        => [ array_merge( $flapping, [ [ 'delivered', 9999, '' ] ] ), 9898 ],

			// ready_for_pickup is arrival at the destination: it never starts the interval (#1564 review).
			'a pickup arrival is not a handover'       => [ [ [ 'created', 0, '' ], [ 'ready_for_pickup', 100, 'AT_PICKUP' ], [ 'delivered', 700, '' ] ], null ],
			'pickup and delivery only (rollout in flight)' => [ [ [ 'ready_for_pickup', 100, 'AT_PICKUP' ], [ 'delivered', 700, '' ] ], null ],
			'a pickup arrival does not end a real transit' => [ [ [ 'in_transit', 100, 'ROAD' ], [ 'ready_for_pickup', 500, 'AT_PICKUP' ], [ 'delivered', 700, '' ] ], 600 ],

			// A CDEK-like carrier: the warehouse receipt maps to `created`, the carrier declares it a handover.
			'a declared code starts the interval'      => [ [ [ 'created', 0, 'REGISTERED' ], [ 'created', 100, 'AT_WAREHOUSE' ], [ 'in_transit', 300, 'ROAD' ], [ 'delivered', 600, 'DONE' ] ], 500, [ 'AT_WAREHOUSE' ] ],
			'the same log without a declaration'       => [ [ [ 'created', 0, 'REGISTERED' ], [ 'created', 100, 'AT_WAREHOUSE' ], [ 'in_transit', 300, 'ROAD' ], [ 'delivered', 600, 'DONE' ] ], 300 ],
			'a declared code that never appeared falls back to in transit' => [ [ [ 'created', 0, 'REGISTERED' ], [ 'in_transit', 300, 'ROAD' ], [ 'delivered', 600, 'DONE' ] ], 300, [ 'AT_WAREHOUSE' ] ],
			'an entry with no raw code never matches a declaration' => [ [ [ 'created', 0, '' ], [ 'delivered', 600, '' ] ], null, [ '' ] ],
		];
	}

	/** A log at the cap may have lost its beginning: its «first» handover could be a later re-entry, so it is not trusted. */
	public function test_a_log_at_the_cap_is_not_trusted(): void {
		$entries = [];
		for ( $i = 0; $i < Delivery_Status_Log::CAP - 1; $i++ ) {
			$entries[] = [ 'in_transit', $i, '' ];
		}
		$entries[] = [ 'delivered', 1000, '' ];

		$this->assertCount( Delivery_Status_Log::CAP, $entries );
		$this->assertNull( Delivery_Status_Log::handover_to_delivery( $entries ) );
	}
}
