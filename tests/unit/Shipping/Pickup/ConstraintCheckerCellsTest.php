<?php
/**
 * Unit tests for Constraint_Checker's storage-cell check (issue #1215): the order's items are placed into the
 * cells of a parcel locker, and a locker is refused only when NO cell can hold them.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Constraint_Checker;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
require_once dirname( __DIR__, 4 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';
require_once dirname( __DIR__, 4 ) . '/woodev/box-packer/class-packer-input-item.php';
require_once dirname( __DIR__, 4 ) . '/woodev/box-packer/class-packer-free-space.php';

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Constraint_Checker
 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Point
 */
final class ConstraintCheckerCellsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, $decimals );
			}
		);
	}

	/**
	 * @param array<int, mixed>|null $cells
	 * @param array<string, mixed>   $extra
	 */
	private function locker( $cells, array $extra = [] ): Pickup_Point {
		return Pickup_Point::from_array(
			array_merge(
				[
					'id'      => 'L1',
					'name'    => 'Постамат',
					'lat'     => 55.75,
					'lng'     => 37.61,
					'address' => 'Москва',
					'type'    => [ 'code' => 'POSTAMAT', 'label' => 'Постамат' ],
					'cells'   => $cells,
				],
				$extra
			)
		);
	}

	/**
	 * @return array{length: int, width: int, height: int}
	 */
	private function cell( int $a, int $b, int $c, ?int $max_weight = null ): array {
		return [
			'length'     => $a,
			'width'      => $b,
			'height'     => $c,
			'max_weight' => $max_weight,
		];
	}

	/** One product line: sides in cm, quantity. */
	private function line( float $a, float $b, float $c, int $quantity = 1 ): \Woodev_Packer_Input_Item {
		return new \Woodev_Packer_Input_Item( $a, $b, $c, 1.0, $quantity );
	}

	/** @param \Woodev_Packer_Input_Item[] $items */
	private function checker( array $items ): Constraint_Checker {
		return new Constraint_Checker(
			[ 'cod' ],
			static function () use ( $items ) {
				return $items;
			}
		);
	}

	/** @param \Woodev_Packer_Input_Item[] $items */
	private function verdict( Pickup_Point $point, array $items, int $weight = 0 ): array {
		return $this->checker( $items )->check( $point, 'bacs', $weight );
	}

	public function test_an_order_that_fits_the_cell_is_selectable(): void {
		$verdict = $this->verdict( $this->locker( [ $this->cell( 64, 36, 40 ) ] ), [ $this->line( 30, 20, 10 ) ] );

		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	public function test_an_order_too_big_for_the_only_cell_is_refused_with_the_reason(): void {
		$verdict = $this->verdict( $this->locker( [ $this->cell( 10, 10, 10 ) ] ), [ $this->line( 30, 20, 10 ) ] );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertSame( 'The order does not fit the cells of this parcel locker', $verdict['reason'] );
	}

	public function test_an_order_that_fits_only_the_second_of_two_cells_is_selectable(): void {
		$cells = [ $this->cell( 10, 10, 10 ), $this->cell( 40, 30, 20 ) ];

		$this->assertTrue( $this->verdict( $this->locker( $cells ), [ $this->line( 35, 25, 15 ) ] )['allowed'] );
	}

	public function test_an_item_is_rotated_to_fit(): void {
		// 60 x 10 x 10 only fits a 10 x 10 x 70 cell standing on its end: the sides are given in no order
		$this->assertTrue( $this->verdict( $this->locker( [ $this->cell( 10, 10, 70 ) ] ), [ $this->line( 60, 10, 10 ) ] )['allowed'] );
		$this->assertTrue( $this->verdict( $this->locker( [ $this->cell( 70, 10, 10 ) ] ), [ $this->line( 10, 10, 60 ) ] )['allowed'] );
	}

	public function test_each_item_fits_alone_but_not_together(): void {
		// two 30 x 30 x 30 items: each fits a 30 x 30 x 40 cell, the pair needs 60 cm of height
		$verdict = $this->verdict( $this->locker( [ $this->cell( 30, 30, 40 ) ] ), [ $this->line( 30, 30, 30, 2 ) ] );

		$this->assertFalse( $verdict['allowed'] );
	}

	public function test_items_that_tile_the_cell_are_accepted(): void {
		// four 40 x 25 x 10 bars lie flat in a 50 x 40 x 20 cell, two layers of two
		$this->assertTrue( $this->verdict( $this->locker( [ $this->cell( 50, 40, 20 ) ] ), [ $this->line( 40, 25, 10, 4 ) ] )['allowed'] );
	}

	public function test_the_cells_own_weight_limit_is_compared_with_the_order_weight(): void {
		$point = $this->locker( [ $this->cell( 40, 40, 40, 5000 ), $this->cell( 20, 20, 20, 30000 ) ] );
		$items = [ $this->line( 30, 30, 30 ) ];

		// the roomy cell takes 5 kg, the light order of 4 kg goes in
		$this->assertTrue( $this->verdict( $point, $items, 4000 )['allowed'] );
		// 6 kg: the roomy cell is too weak, the strong one too small
		$this->assertFalse( $this->verdict( $point, $items, 6000 )['allowed'] );
		// an unknown weight (0) never trips a cell's limit
		$this->assertTrue( $this->verdict( $point, $items, 0 )['allowed'] );
	}

	public function test_a_point_without_cells_gets_no_size_verdict(): void {
		$never = new Constraint_Checker(
			[ 'cod' ],
			static function () {
				throw new \LogicException( 'the cart must not be read for a point with no cells' );
			}
		);

		foreach ( [ null, [], 'junk' ] as $cells ) {
			$verdict = $never->check( $this->locker( $cells ), 'bacs', 0 );

			$this->assertTrue( $verdict['allowed'] );
			$this->assertNull( $verdict['reason'] );
		}
	}

	public function test_an_item_without_a_size_cannot_be_proven_too_big(): void {
		$point = $this->locker( [ $this->cell( 10, 10, 10 ) ] );

		$this->assertTrue( $this->verdict( $point, [ $this->line( 0, 0, 0 ), $this->line( 50, 50, 50 ) ] )['allowed'] );
		$this->assertTrue( $this->verdict( $point, [ $this->line( 50, 50, 0 ) ] )['allowed'] );
	}

	public function test_an_empty_or_unreadable_cart_gives_no_size_verdict(): void {
		$this->assertTrue( $this->verdict( $this->locker( [ $this->cell( 10, 10, 10 ) ] ), [] )['allowed'] );
	}

	public function test_a_malformed_cell_degrades_the_whole_list_to_no_cells(): void {
		// the second cell is junk: the first, well-formed one must not become the only size that counts
		$point = $this->locker( [ $this->cell( 10, 10, 10 ), [ 'length' => 'n/a', 'width' => 50, 'height' => 50 ] ] );

		$this->assertSame( [], $point->get_cells() );
		$this->assertTrue( $this->verdict( $point, [ $this->line( 40, 40, 40 ) ] )['allowed'] );
	}

	public function test_above_the_placement_threshold_the_sides_and_volume_decide(): void {
		$units = \Woodev_Packer_Free_Space::MAX_UNITS + 1;

		// 121 cubes of 2 cm = 968 cm3: they fit a 20 x 20 x 20 cell by volume (8000), and none is too long
		$this->assertTrue( $this->verdict( $this->locker( [ $this->cell( 20, 20, 20 ) ] ), [ $this->line( 2, 2, 2, $units ) ] )['allowed'] );
		// the same units in a cell of 9 x 9 x 9 = 729 cm3 are refused by volume
		$this->assertFalse( $this->verdict( $this->locker( [ $this->cell( 9, 9, 9 ) ] ), [ $this->line( 2, 2, 2, $units ) ] )['allowed'] );
		// one of them longer than every side of the cell is refused by sides, whatever the volume
		$this->assertFalse( $this->verdict( $this->locker( [ $this->cell( 50, 50, 5 ) ] ), [ $this->line( 2, 2, 2, $units ), $this->line( 6, 6, 6 ) ] )['allowed'] );
	}

	public function test_the_weight_reason_still_wins_over_the_size_reason(): void {
		$point   = $this->locker( [ $this->cell( 10, 10, 10 ) ], [ 'max_weight' => 1000 ] );
		$verdict = $this->verdict( $point, [ $this->line( 30, 30, 30 ) ], 2000 );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertStringContainsString( 'weight', $verdict['reason'] );
	}

	public function test_the_size_reason_wins_over_cash_on_delivery(): void {
		$point   = $this->locker( [ $this->cell( 10, 10, 10 ) ], [ 'accepts_cod' => false ] );
		$verdict = $this->checker( [ $this->line( 30, 30, 30 ) ] )->check( $point, 'cod', 0 );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertSame( 'The order does not fit the cells of this parcel locker', $verdict['reason'] );
	}

	public function test_the_answer_is_the_same_on_every_run(): void {
		$point = $this->locker( [ $this->cell( 50, 40, 20 ) ] );
		$items = [ $this->line( 40, 25, 10, 4 ), $this->line( 10, 10, 10, 2 ) ];

		$first = $this->verdict( $point, $items );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( $first, $this->verdict( $point, $items ) );
		}
	}

	public function test_cells_are_normalized_deduplicated_and_kept_out_of_the_browser_payload(): void {
		$point = $this->locker(
			[
				[ 'length' => '10', 'width' => 20.5, 'height' => 30, 'max_weight' => 0 ],
				[ 'length' => 30, 'width' => 10, 'height' => 20.5 ], // the same box with its sides in another order
				[ 'length' => 5, 'width' => 5, 'height' => 5, 'max_weight' => 2000 ],
			]
		);

		$this->assertSame(
			[
				[ 'length' => 10.0, 'width' => 20.5, 'height' => 30.0, 'max_weight' => null ],
				[ 'length' => 5.0, 'width' => 5.0, 'height' => 5.0, 'max_weight' => 2000 ],
			],
			$point->get_cells()
		);
		$this->assertArrayHasKey( 'cells', $point->to_array() );
	}

	public function test_a_point_survives_the_to_array_round_trip_with_its_cells(): void {
		$point = $this->locker( [ $this->cell( 64, 36, 40, 30000 ) ] );

		$this->assertSame( $point->get_cells(), Pickup_Point::from_array( $point->to_array() )->get_cells() );
	}

	public function test_a_list_with_too_many_distinct_cells_is_treated_as_unknown(): void {
		$cells = [];

		for ( $i = 1; $i <= Pickup_Point::MAX_CELLS + 1; $i++ ) {
			$cells[] = $this->cell( $i, $i, $i );
		}

		$this->assertSame( [], $this->locker( $cells )->get_cells() );
	}
}
