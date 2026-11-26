<?php
/**
 * Unit tests for the unit records of a grouped order.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Flows\Order_Unit;
use Woodev\Framework\Marketplace\Flows\Order_Units;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Order_Units
 * @covers \Woodev\Framework\Marketplace\Flows\Order_Unit
 */
final class OrderUnitsTest extends TestCase {

	public function test_a_unit_turning_cancelled_is_announced_once_and_its_record_is_kept_as_it_was(): void {
		$live = $this->refreshed( [], [ self::member( '11', Status_Map::NEW, 100 ), self::member( '12', Status_Map::NEW, 200 ) ] );

		$this->assertSame( [], Order_Units::newly_cancelled( [], $live ) );

		$cancelled = $this->refreshed( $live, [ self::member( '11', Status_Map::CANCELLED, 100, '2026-11-02T11:30:00+03:00' ), self::member( '12', Status_Map::NEW, 200 ) ] );

		$this->assertSame( [ '11' ], self::ids( Order_Units::newly_cancelled( $live, $cancelled ) ) );
		$this->assertSame( '2026-11-02T08:30:00Z', $cancelled[0]->cancelled_at(), 'Kept in UTC, whatever offset the marketplace sent.' );

		// A later poll with another price and time, and a stale view that calls the unit live again, change nothing.
		foreach ( [ self::member( '11', Status_Map::CANCELLED, 999, '2026-11-09T11:30:00Z' ), self::member( '11', Status_Map::NEW, 999 ) ] as $later ) {
			$again = $this->refreshed( $cancelled, [ $later ] );

			$this->assertSame( [], Order_Units::newly_cancelled( $cancelled, $again ) );
			$this->assertEquals( $cancelled, $again );
		}
	}

	public function test_a_unit_first_seen_cancelled_is_announced(): void {
		$records = $this->refreshed( [], [ self::member( '11', Status_Map::CANCELLED, 100 ) ] );

		$this->assertSame( [ '11' ], self::ids( Order_Units::newly_cancelled( [], $records ) ) );
		$this->assertTrue( $records[0]->is_cancelled(), 'A unit with no time of the marketplace takes the current one.' );
	}

	public function test_a_record_keeps_the_goods_the_price_and_the_product_through_storage(): void {
		$records = $this->refreshed( [], [ self::member( '11', Status_Map::NEW, 45050 ) ] );
		$read    = Order_Unit::from_array( $records[0]->to_array() );

		$this->assertSame(
			[
				[
					'product_id' => 77,
					'offer_id'   => 'tee|M',
					'name'       => 'Футболка',
					'quantity'   => 1,
					'unit_minor' => 45050,
					'currency'   => 'RUB',
				],
			],
			$read->lines()
		);
		$this->assertSame( '450.50', $read->total()->to_string() );
		$this->assertSame( 'confirm/waiting', $read->status_raw() );
	}

	public function test_a_line_without_a_name_takes_the_shop_products(): void {
		$product = \Mockery::mock( '\WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Кепка' );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$records = $this->refreshed( [], [ self::member( '11', Status_Map::NEW, 100, '', '' ) ] );

		$this->assertSame( 'Кепка', $records[0]->lines()[0]['name'] );
	}

	public function test_a_stored_value_that_is_not_a_unit_is_ignored(): void {
		$order = \Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( Order_Units::META )->andReturn( '' );

		$this->assertSame( [], Order_Units::of( $order ) );
		$this->assertNull( Order_Unit::from_array( [ 'status_raw' => 'x' ] ) );
	}

	/**
	 * @param Order_Unit[] $stored  Records.
	 * @param Order_DTO[]  $members Members of the group.
	 * @return Order_Unit[]
	 */
	private function refreshed( array $stored, array $members ): array {
		$order = ( new Order_DTO( 1, 'cart-1', 'cart-1', 'new', Status_Map::NEW, [] ) )->with_units( $members );

		return Order_Units::refreshed(
			$stored,
			$order,
			static function (): int {
				return 77;
			}
		);
	}

	private static function member( string $id, string $canonical, int $minor, string $updated_at = '', string $name = 'Футболка' ): Order_DTO {
		$line  = new Order_Line( 'tee|M', [ 'item' => 1 ], 1, Money::of( $minor, 'RUB' ), $name );
		$dates = '' === $updated_at ? [] : [ 'updated_at' => $updated_at ];

		return new Order_DTO( 1, $id, 'cart-1', 'confirm/waiting', $canonical, [ $line ], [], null, $dates );
	}

	/**
	 * @param Order_Unit[] $units Units.
	 * @return string[]
	 */
	private static function ids( array $units ): array {
		return array_map(
			static function ( Order_Unit $unit ): string {
				return $unit->id();
			},
			$units
		);
	}
}
