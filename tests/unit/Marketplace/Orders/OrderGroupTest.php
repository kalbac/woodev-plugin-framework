<?php
/**
 * Tests for the grouped-order rules.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Orders;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Group;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Writer;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;

/**
 * A Wildberries cart is several one-unit assembly orders sharing an `orderUid`. They must end as one order with the
 * right units and money whatever order the marketplace's answers arrive in.
 */
final class OrderGroupTest extends TestCase {
	private const UID = 'cart-7f3a';

	/**
	 * Member A as first seen: new, 1000.00.
	 *
	 * @return Order_DTO
	 */
	private function a_new(): Order_DTO {
		return Fake_Marketplace_Adapter::wb_order( 1, 11, self::UID, 100000, 'new', 'waiting', '2026-11-08T10:00:00Z' );
	}

	/**
	 * Member B as first seen: new, 500.00.
	 *
	 * @return Order_DTO
	 */
	private function b_new(): Order_DTO {
		return Fake_Marketplace_Adapter::wb_order( 1, 12, self::UID, 50000, 'new', 'waiting', '2026-11-08T10:01:00Z' );
	}

	/**
	 * Member A cancelled by the seller.
	 *
	 * @return Order_DTO
	 */
	private function a_cancelled(): Order_DTO {
		return Fake_Marketplace_Adapter::wb_order( 1, 11, self::UID, 100000, 'cancel', 'canceled', '2026-11-08T10:04:00Z' );
	}

	/**
	 * Member B after an update: confirmed, price changed to 450.00.
	 *
	 * @return Order_DTO
	 */
	private function b_updated(): Order_DTO {
		return Fake_Marketplace_Adapter::wb_order( 1, 12, self::UID, 45000, 'confirm', 'sorted', '2026-11-08T10:05:00Z' );
	}

	/**
	 * Builds the group state after a sequence of snapshots.
	 *
	 * @param Order_DTO[] $sequence Snapshots in arrival order.
	 * @return Order_Group
	 */
	private function apply( array $sequence ): Order_Group {
		$group = Order_Group::start( self::UID );
		foreach ( $sequence as $snapshot ) {
			$group = $group->with_member( $snapshot );
		}

		return $group;
	}

	/**
	 * All permutations of a list.
	 *
	 * @param array<int,Order_DTO> $items Items.
	 * @return array<int,array<int,Order_DTO>>
	 */
	private function permutations( array $items ): array {
		if ( count( $items ) <= 1 ) {
			return array( $items );
		}

		$result = array();
		foreach ( array_keys( $items ) as $index ) {
			$rest = $items;
			unset( $rest[ $index ] );
			foreach ( $this->permutations( array_values( $rest ) ) as $tail ) {
				$result[] = array_merge( array( $items[ $index ] ), $tail );
			}
		}

		return $result;
	}

	/**
	 * Two members arriving in either order make two units and the exact sum.
	 *
	 * @return void
	 */
	public function test_two_members_make_two_units_in_either_order(): void {
		foreach ( array( array( $this->a_new(), $this->b_new() ), array( $this->b_new(), $this->a_new() ) ) as $sequence ) {
			$group = $this->apply( $sequence );

			$this->assertSame( 2, $group->quantity() );
			$this->assertSame( '1500.00', $group->total( 'RUB' )->to_string() );
			$this->assertSame( Status_Map::NEW, $group->status() );
		}
	}

	/**
	 * The §7.3 sequence A, B, duplicate A, cancel A, update B in every one of the 120 orders ends at one unit and the
	 * exact updated total: the arrival order must not matter.
	 *
	 * @return void
	 */
	public function test_a_b_duplicate_a_cancel_a_update_b_in_every_order_ends_the_same(): void {
		$events       = array( $this->a_new(), $this->b_new(), $this->a_new(), $this->a_cancelled(), $this->b_updated() );
		$permutations = $this->permutations( $events );
		$this->assertCount( 120, $permutations );

		foreach ( $permutations as $sequence ) {
			$group = $this->apply( $sequence );

			$this->assertSame( 1, $group->quantity() );
			$this->assertSame( '450.00', $group->total( 'RUB' )->to_string() );
			$this->assertSame( Status_Map::AWAITING_SHIPMENT, $group->status() );
			$this->assertSame(
				array(
					'11' => array(
						'status_canonical' => Status_Map::CANCELLED,
						'quantity'         => 0,
					),
					'12' => array(
						'status_canonical' => Status_Map::AWAITING_SHIPMENT,
						'quantity'         => 1,
					),
				),
				$group->member_registry()
			);
		}
	}

	/**
	 * Applies a cancellation and a «new» snapshot of one member in both arrival orders and expects the cancellation to win.
	 *
	 * @param string $new_at       `updated_at` of the «new» snapshot.
	 * @param string $cancelled_at `updated_at` of the cancellation, later by instant.
	 * @return void
	 */
	private function assert_cancellation_wins_in_both_orders( string $new_at, string $cancelled_at ): void {
		$new       = Fake_Marketplace_Adapter::wb_order( 1, 11, self::UID, 100000, 'new', 'waiting', $new_at );
		$cancelled = Fake_Marketplace_Adapter::wb_order( 1, 11, self::UID, 100000, 'cancel', 'canceled', $cancelled_at );

		foreach ( array( array( $new, $cancelled ), array( $cancelled, $new ) ) as $sequence ) {
			$group = $this->apply( $sequence );

			$this->assertSame( Status_Map::CANCELLED, $group->status(), $new_at . ' vs ' . $cancelled_at );
			$this->assertSame( 0, $group->quantity() );
			$this->assertSame( '0.00', $group->total( 'RUB' )->to_string() );
		}
	}

	/**
	 * Critic finding 2: fractions of a second. As text "10:00:00.500Z" sorts before "10:00:00Z", but it is later.
	 *
	 * @return void
	 */
	public function test_freshness_compares_fractions_of_a_second(): void {
		$this->assert_cancellation_wins_in_both_orders( '2026-11-08T10:00:00Z', '2026-11-08T10:00:00.500Z' );
		$this->assert_cancellation_wins_in_both_orders( '2026-11-08T10:00:00.250Z', '2026-11-08T10:00:00.750Z' );
		$this->assert_cancellation_wins_in_both_orders( '2026-11-08T10:00:00.9Z', '2026-11-08T10:00:01Z' );
	}

	/**
	 * Critic finding 2: offsets. 13:00+03:00 is 10:00Z; the text order of different offsets means nothing.
	 *
	 * @return void
	 */
	public function test_freshness_compares_instants_across_offsets(): void {
		$this->assert_cancellation_wins_in_both_orders( '2026-11-08T10:00:00Z', '2026-11-08T13:00:01+03:00' );
		$this->assert_cancellation_wins_in_both_orders( '2026-11-08T12:59:59+03:00', '2026-11-08T10:00:00Z' );
		$this->assert_cancellation_wins_in_both_orders( '2026-11-08T10:00:00.5+00:00', '2026-11-08T07:30:00-03:00' );
	}

	/**
	 * Two spellings of the same instant are equal, so the later delivery simply wins (a duplicate is harmless).
	 *
	 * @return void
	 */
	public function test_the_same_instant_in_two_spellings_is_not_stale(): void {
		$utc    = Fake_Marketplace_Adapter::wb_order( 1, 11, self::UID, 100000, 'new', 'waiting', '2026-11-08T10:00:00Z' );
		$offset = Fake_Marketplace_Adapter::wb_order( 1, 11, self::UID, 90000, 'new', 'waiting', '2026-11-08T13:00:00+03:00' );

		$this->assertSame( '900.00', $this->apply( array( $utc, $offset ) )->total( 'RUB' )->to_string() );
	}

	/**
	 * A time that is not a time, or has no zone, is refused at the boundary instead of being compared as text.
	 *
	 * @dataProvider invalid_times
	 * @param string $time Invalid `updated_at`.
	 * @return void
	 */
	public function test_an_invalid_updated_at_is_refused( string $time ): void {
		$this->expectException( \InvalidArgumentException::class );
		new Order_DTO( 1, '1', '', 'x', 'new', array(), array(), null, array( 'updated_at' => $time ) );
	}

	/**
	 * Inputs that are not an ISO 8601 time with a zone.
	 *
	 * @return array<string,string[]>
	 */
	public function invalid_times(): array {
		return array(
			'text'       => array( 'yesterday' ),
			'no zone'    => array( '2026-11-08T10:00:00' ),
			'date only'  => array( '2026-11-08' ),
			'impossible' => array( '2026-13-45T10:00:00Z' ),
		);
	}

	/**
	 * A duplicate delivery changes nothing.
	 *
	 * @return void
	 */
	public function test_a_duplicate_delivery_is_idempotent(): void {
		$once  = $this->apply( array( $this->a_new() ) );
		$twice = $this->apply( array( $this->a_new(), $this->a_new() ) );

		$this->assertSame( $once->member_registry(), $twice->member_registry() );
		$this->assertSame( 1, $twice->quantity() );
	}

	/**
	 * A cancelled member is never revived by a late «new» snapshot, and an older snapshot never overwrites a newer one.
	 *
	 * @return void
	 */
	public function test_cancelled_is_sticky_and_stale_snapshots_are_ignored(): void {
		$group = $this->apply( array( $this->a_cancelled(), $this->a_new() ) );
		$this->assertSame( 0, $group->quantity() );
		$this->assertSame( Status_Map::CANCELLED, $group->status() );

		$group = $this->apply( array( $this->b_updated(), $this->b_new() ) );
		$this->assertSame( '450.00', $group->total( 'RUB' )->to_string() );
	}

	/**
	 * The order is cancelled only when every member is; otherwise the least progressed live member decides.
	 *
	 * @return void
	 */
	public function test_status_follows_the_least_progressed_live_member(): void {
		$b_cancelled = Fake_Marketplace_Adapter::wb_order( 1, 12, self::UID, 50000, 'cancel', 'canceled', '2026-11-08T10:06:00Z' );
		$this->assertSame( Status_Map::CANCELLED, $this->apply( array( $this->a_new(), $this->b_new(), $this->a_cancelled(), $b_cancelled ) )->status() );

		$delivered = Fake_Marketplace_Adapter::wb_order( 1, 12, self::UID, 50000, 'complete', 'sold', '2026-11-08T10:07:00Z' );
		$this->assertSame( Status_Map::NEW, $this->apply( array( $this->a_new(), $delivered ) )->status(), 'one member delivered, one still new' );

		$disputed = new Order_DTO( 1, '12', self::UID, 'x', Status_Map::ARBITRATION, array(), array(), null, array( 'updated_at' => '2026-11-08T10:08:00Z' ) );
		$this->assertSame( Status_Map::ARBITRATION, $this->apply( array( $this->a_new(), $disputed ) )->status(), 'a dispute stays visible' );

		$this->assertNull( Order_Group::start( self::UID )->status() );
	}

	/**
	 * A member of another cart cannot join the group.
	 *
	 * @return void
	 */
	public function test_a_member_of_another_group_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Order_Group::start( 'another-cart' )->with_member( $this->a_new() );
	}

	/**
	 * The writer double keeps one order per cart however many members arrive.
	 *
	 * @return void
	 */
	public function test_the_writer_double_keeps_one_order_per_cart(): void {
		$writer = new Fake_Order_Writer();
		$map    = new Status_Map();
		$writer->upsert_member( $this->a_new(), $map );
		$writer->upsert_member( $this->b_new(), $map );
		$writer->upsert_member( Fake_Marketplace_Adapter::wb_order( 1, 13, 'cart-other', 70000, 'new', 'waiting', '2026-11-08T10:09:00Z' ), $map );

		$this->assertSame( 2, $writer->order_count() );
		$this->assertSame( 2, $writer->group( 1, self::UID )->quantity() );
	}

	/**
	 * Status mapping and the canonical ladder.
	 *
	 * @return void
	 */
	public function test_status_map(): void {
		$map = new Status_Map();
		$this->assertSame( 'completed', $map->wc_status( Status_Map::DELIVERED, 'processing' ) );
		$this->assertSame( 'cancelled', $map->wc_status( Status_Map::CANCELLED, 'processing' ) );
		$this->assertSame( 'processing', $map->wc_status( Status_Map::SHIPPED, 'processing' ) );
		$this->assertSame( 'on-hold', $map->wc_status( 'no-such-status', 'on-hold' ) );
		$this->assertTrue( Status_Map::rank( Status_Map::NEW ) < Status_Map::rank( Status_Map::DELIVERED ) );
		$this->assertContains( Status_Map::ARBITRATION, Status_Map::canonical() );

		$this->expectException( \InvalidArgumentException::class );
		Status_Map::rank( Status_Map::CANCELLED );
	}

	/**
	 * Wildberries' two status fields map to the canonical vocabulary.
	 *
	 * @dataProvider wb_status_pairs
	 * @param string $supplier  `supplierStatus`.
	 * @param string $wb_status `wbStatus`.
	 * @param string $expected  Canonical status.
	 * @return void
	 */
	public function test_wb_status_pairs_map_to_canonical( string $supplier, string $wb_status, string $expected ): void {
		$this->assertSame( $expected, Fake_Marketplace_Adapter::canonical_status( $supplier, $wb_status ) );
	}

	/**
	 * Status pairs.
	 *
	 * @return array<string,string[]>
	 */
	public function wb_status_pairs(): array {
		return array(
			'new'                 => array( 'new', 'waiting', Status_Map::NEW ),
			'in assembly'         => array( 'confirm', 'sorted', Status_Map::AWAITING_SHIPMENT ),
			'in delivery'         => array( 'complete', 'waiting', Status_Map::DELIVERING ),
			'sold'                => array( 'complete', 'sold', Status_Map::DELIVERED ),
			'seller cancel'       => array( 'cancel', 'waiting', Status_Map::CANCELLED ),
			'buyer cancel'        => array( 'confirm', 'canceled_by_client', Status_Map::CANCELLED ),
			'cross-border cancel' => array( 'cancel_carrier', 'waiting', Status_Map::CANCELLED ),
		);
	}
}
