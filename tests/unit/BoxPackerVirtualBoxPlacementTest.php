<?php
/**
 * Woodev_Packer_Virtual_Box sizes the «everything in one box» parcel from a REAL placement (#1212):
 * big items first, small ones in the gaps. These tests verify that placement — every unit inside the
 * box, none overlapping, each a rotation of its item — and that the box beats the previous arithmetic
 * grid on volume on the measured cases, deterministically and within a checkout-sized time.
 *
 * The grid volumes quoted below are deterministic by design (the grid is pure arithmetic over the sorted
 * item sides), so they are safe to assert against; the new box's exact sides are not asserted, only its
 * bounds.
 *
 * No WooCommerce or WordPress required.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class BoxPackerVirtualBoxPlacement_WC_Product_Stub {}

		class_alias( BoxPackerVirtualBoxPlacement_WC_Product_Stub::class, 'WC_Product' );
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item-with-product.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-item-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-box-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-free-space.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packed-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/abstract-class-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-single-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-virtual-box.php';
}

namespace Woodev\Tests\Unit {

	class BoxPackerVirtualBoxPlacementTest extends TestCase {

		const EPS = 1e-6;

		// -------------------------------------------------------------------
		// Fixtures: [length, width, height, quantity] per item kind
		// -------------------------------------------------------------------

		/**
		 * The measured cases of #1212. `grid` is the old arithmetic box's volume on the case (deterministic by
		 * design), `real` the summed item volume.
		 *
		 * @return array<string, array{items: array<int, array<int, int>>, real: float, grid: float}>
		 */
		public static function cases(): array {
			return [
				'a: 16 mixed units, 8 kinds' => [
					'items' => [ [ 12, 8, 3, 2 ], [ 24, 17, 4, 3 ], [ 33, 20, 12, 2 ], [ 60, 8, 8, 1 ], [ 40, 30, 25, 1 ], [ 15, 15, 15, 2 ], [ 28, 22, 6, 3 ], [ 9, 9, 20, 2 ] ],
					'real'  => 76230.0,
					'grid'  => 390720.0, // 88 x 74 x 60; single was 141 x 60 x 30 = 253 800
				],
				'b: 1 item'                  => [
					'items' => [ [ 20, 15, 10, 1 ] ],
					'real'  => 3000.0,
					'grid'  => 3000.0,
				],
				'c: 10 identical flat items' => [
					'items' => [ [ 10, 10, 5, 10 ] ],
					'real'  => 5000.0,
					'grid'  => 6000.0, // 20 x 20 x 15
				],
				'd: 1 big + 9 small'         => [
					'items' => [ [ 40, 30, 25, 1 ], [ 10, 8, 5, 9 ] ],
					'real'  => 33600.0,
					'grid'  => 68400.0, // 45 x 40 x 38
				],
				'e: 30 mixed units'          => [
					'items' => [ [ 12, 8, 3, 4 ], [ 24, 17, 4, 5 ], [ 33, 20, 12, 3 ], [ 60, 8, 8, 2 ], [ 40, 30, 25, 2 ], [ 15, 15, 15, 4 ], [ 28, 22, 6, 5 ], [ 9, 9, 20, 3 ], [ 18, 12, 10, 2 ] ],
					'real'  => 141912.0,
					'grid'  => 934800.0, // 120 x 95 x 82
				],
				'f: 100 small mixed units'   => [
					'items' => [
						[ 11, 15, 9, 5 ],
						[ 19, 10, 4, 5 ],
						[ 17, 12, 6, 5 ],
						[ 14, 14, 6, 5 ],
						[ 15, 10, 2, 5 ],
						[ 9, 15, 6, 5 ],
						[ 12, 14, 6, 5 ],
						[ 9, 5, 7, 5 ],
						[ 16, 5, 4, 5 ],
						[ 6, 11, 3, 5 ],
						[ 9, 4, 9, 5 ],
						[ 14, 9, 9, 5 ],
						[ 16, 12, 3, 5 ],
						[ 15, 6, 5, 5 ],
						[ 14, 7, 5, 5 ],
						[ 16, 15, 6, 5 ],
						[ 18, 9, 5, 5 ],
						[ 18, 14, 2, 5 ],
						[ 11, 11, 3, 5 ],
						[ 13, 6, 5, 5 ],
					],
					'real'  => 70385.0,
					'grid'  => 269325.0, // 75 x 63 x 57
				],
				'g: long thin items'         => [
					'items' => [ [ 100, 5, 5, 3 ], [ 10, 8, 5, 4 ], [ 15, 15, 15, 1 ] ],
					'real'  => 12475.0,
					'grid'  => 69000.0, // 100 x 30 x 23
				],
			];
		}

		/**
		 * @return array<string, array{0: array<int, array<int, int>>, 1: float, 2: float}>
		 */
		public static function case_provider(): array {
			$rows = [];

			foreach ( self::cases() as $name => $case ) {
				$rows[ $name ] = [ $case['items'], $case['real'], $case['grid'] ];
			}

			return $rows;
		}

		/**
		 * @param  array<int, array<int, int>> $spec [length, width, height, quantity] per kind
		 * @return \Woodev_Packer_Item_Implementation[]
		 */
		private static function units( array $spec ): array {
			$units = [];

			foreach ( $spec as [ $length, $width, $height, $quantity ] ) {
				for ( $i = 0; $i < $quantity; $i++ ) {
					$units[] = new \Woodev_Packer_Item_Implementation( $length, $width, $height, 0.1 );
				}
			}

			return $units;
		}

		/**
		 * @param  \Woodev_Packer_Item_Implementation[] $units
		 * @return array{packer: \Woodev_Packer_Virtual_Box, sides: float[], seconds: float} sides longest first
		 */
		private static function pack( array $units ): array {
			$packer = new \Woodev_Packer_Virtual_Box();

			foreach ( $units as $unit ) {
				$packer->add_item( $unit );
			}

			$started = microtime( true );
			$packer->pack();
			$seconds = microtime( true ) - $started;

			$box   = $packer->get_packages()[0]->get_box();
			$sides = [ $box->get_length(), $box->get_width(), $box->get_height() ];
			rsort( $sides );

			return [
				'packer'  => $packer,
				'sides'   => $sides,
				'seconds' => $seconds,
			];
		}

		/**
		 * Verifies the placement the packer reports: one entry per unit, each a rotation of its unit (in the
		 * packer's largest-first order), inside the frame, none overlapping another, and the frame's sides
		 * are the box's sides.
		 *
		 * @param \Woodev_Packer_Virtual_Box           $packer
		 * @param \Woodev_Packer_Item_Implementation[] $units
		 * @param float[]                              $sides  the box's sides, longest first
		 */
		private function assert_placement_holds( \Woodev_Packer_Virtual_Box $packer, array $units, array $sides ): void {
			$placement = $packer->get_placement();

			$this->assertNotSame( [], $placement, 'a real placement sized the box, not the grid fallback' );

			$frame = [ $placement['length'], $placement['width'], $placement['height'] ];
			rsort( $frame );
			$this->assertEqualsWithDelta( $sides, $frame, self::EPS, 'the frame IS the box' );

			$expected = array_map(
				static function ( \Woodev_Packer_Item_Implementation $unit ): array {
					$s = [ $unit->get_length(), $unit->get_width(), $unit->get_height() ];
					rsort( $s );

					return $s;
				},
				$units
			);
			usort(
				$expected,
				static fn( array $a, array $b ): int => [ $b[0] * $b[1] * $b[2], $b[0], $b[1], $b[2] ] <=> [ $a[0] * $a[1] * $a[2], $a[0], $a[1], $a[2] ]
			);

			$placed = $placement['units'];
			$this->assertCount( count( $units ), $placed, 'every unit is placed' );

			foreach ( $placed as $i => $p ) {
				$extent = [ $p[3] - $p[0], $p[4] - $p[1], $p[5] - $p[2] ];
				rsort( $extent );
				$this->assertEqualsWithDelta( $expected[ $i ], $extent, self::EPS, "unit $i keeps its sides (rotated at most)" );

				$this->assertGreaterThanOrEqual( -self::EPS, min( $p[0], $p[1], $p[2] ), "unit $i starts inside the box" );
				$this->assertLessThanOrEqual( $placement['length'] + self::EPS, $p[3], "unit $i ends within the length" );
				$this->assertLessThanOrEqual( $placement['width'] + self::EPS, $p[4], "unit $i ends within the width" );
				$this->assertLessThanOrEqual( $placement['height'] + self::EPS, $p[5], "unit $i ends within the height" );

				for ( $j = 0; $j < $i; $j++ ) {
					$q        = $placed[ $j ];
					$separate = $p[3] <= $q[0] + self::EPS || $q[3] <= $p[0] + self::EPS
						|| $p[4] <= $q[1] + self::EPS || $q[4] <= $p[1] + self::EPS
						|| $p[5] <= $q[2] + self::EPS || $q[5] <= $p[2] + self::EPS;
					$this->assertTrue( $separate, "units $i and $j do not overlap" );
				}
			}
		}

		// -------------------------------------------------------------------
		// Correctness: a verifiable placement, never below the items
		// -------------------------------------------------------------------

		/**
		 * @dataProvider case_provider
		 */
		public function test_every_unit_is_really_placed_without_overlap( array $spec, float $real, float $grid ) {
			$units  = self::units( $spec );
			$result = self::pack( $units );

			$this->assert_placement_holds( $result['packer'], $units, $result['sides'] );

			$volume = $result['sides'][0] * $result['sides'][1] * $result['sides'][2];
			$this->assertGreaterThanOrEqual( $real - self::EPS, $volume, 'a box can never be smaller than what is in it' );
		}

		/**
		 * @dataProvider case_provider
		 */
		public function test_box_holds_the_largest_item_on_every_axis( array $spec ) {
			$units  = self::units( $spec );
			$result = self::pack( $units );
			$axes   = [ 0.0, 0.0, 0.0 ];

			foreach ( $units as $unit ) {
				$sides = [ $unit->get_length(), $unit->get_width(), $unit->get_height() ];
				rsort( $sides );
				foreach ( $sides as $i => $side ) {
					$axes[ $i ] = max( $axes[ $i ], $side );
				}
			}

			foreach ( $axes as $i => $largest ) {
				$this->assertGreaterThanOrEqual( $largest, $result['sides'][ $i ] + self::EPS, "axis $i" );
			}
		}

		// -------------------------------------------------------------------
		// Objective: the smallest volume, and never worse than the old grid
		// -------------------------------------------------------------------

		/**
		 * @dataProvider case_provider
		 */
		public function test_volume_never_exceeds_the_old_grid_box( array $spec, float $real, float $grid ) {
			$result = self::pack( self::units( $spec ) );
			$volume = $result['sides'][0] * $result['sides'][1] * $result['sides'][2];

			$this->assertLessThanOrEqual( $grid + self::EPS, $volume );
		}

		/**
		 * The headline of #1212: 16 mixed units. Measured 60 x 50 x 40 = 120 000 cm³ against 390 720 for the
		 * grid, 253 800 for the retired single-axis box and 76 230 of real items — ~1.6x the items, no sausage.
		 */
		public function test_sixteen_mixed_units_pack_into_a_compact_box() {
			$case   = self::cases()['a: 16 mixed units, 8 kinds'];
			$result = self::pack( self::units( $case['items'] ) );
			$volume = $result['sides'][0] * $result['sides'][1] * $result['sides'][2];

			$this->assertLessThan( 0.5 * $case['grid'], $volume, 'less than half the grid box' );
			$this->assertLessThan( 253800.0, $volume, 'and below the single-axis box too' );
			$this->assertLessThan( 2.0 * $case['real'], $volume, 'under twice the items\' own volume' );
			$this->assertSame( 60.0, $result['sides'][0], 'the 60 cm tube dictates the longest side — nothing longer' );
		}

		/**
		 * A big item with small ones around it: the small ones go into the gaps, so the box is barely bigger
		 * than the big item (measured 40 x 35 x 25 = 35 000 for 33 600 of items; the grid said 68 400).
		 */
		public function test_small_items_fill_the_gaps_around_a_big_one() {
			$case   = self::cases()['d: 1 big + 9 small'];
			$result = self::pack( self::units( $case['items'] ) );
			$volume = $result['sides'][0] * $result['sides'][1] * $result['sides'][2];

			$this->assertLessThan( 1.15 * $case['real'], $volume );
		}

		/**
		 * Ten identical flat items: the real packing (four flat per layer, two on edge in the strip) is exact,
		 * where the grid needed a fifth more.
		 */
		public function test_identical_items_pack_without_air() {
			$case   = self::cases()['c: 10 identical flat items'];
			$result = self::pack( self::units( $case['items'] ) );
			$volume = $result['sides'][0] * $result['sides'][1] * $result['sides'][2];

			$this->assertEqualsWithDelta( $case['real'], $volume, self::EPS );
		}

		/**
		 * A single item is its own box.
		 */
		public function test_one_item_is_its_own_box() {
			$result = self::pack( self::units( [ [ 20, 15, 10, 1 ] ] ) );

			$this->assertEqualsWithDelta( [ 20.0, 15.0, 10.0 ], $result['sides'], self::EPS );
		}

		// -------------------------------------------------------------------
		// Determinism: the rate cache keys on the box
		// -------------------------------------------------------------------

		/**
		 * @dataProvider case_provider
		 */
		public function test_same_items_in_any_order_give_the_same_box_and_placement( array $spec ) {
			$units = self::units( $spec );
			$first = self::pack( $units );

			mt_srand( 1212 );
			shuffle( $units );
			$second = self::pack( $units );

			$this->assertSame( $first['sides'], $second['sides'] );
			$this->assertSame( $first['packer']->get_placement(), $second['packer']->get_placement() );
		}

		// -------------------------------------------------------------------
		// Bounded work: checkout runs this on every refresh
		// -------------------------------------------------------------------

		/**
		 * Measured on the dev Mac (PHP 8.5): 16 units ~9 ms, 30 units ~15 ms, 100 units ~55 ms. The bound here
		 * is deliberately loose so a slow CI runner does not fail it, yet tight enough to catch a regression
		 * to seconds.
		 *
		 * @dataProvider case_provider
		 */
		public function test_packing_finishes_in_checkout_time( array $spec ) {
			$result = self::pack( self::units( $spec ) );

			$this->assertLessThan( 1.0, $result['seconds'] );
		}

		/**
		 * Above the unit ceiling no placement runs: the old grid box is returned, with no placement to show.
		 * Still a valid box — every unit fits it — and still cheap.
		 */
		public function test_above_the_unit_ceiling_the_grid_box_is_returned() {
			$result = self::pack( self::units( [ [ 10, 10, 5, \Woodev_Packer_Virtual_Box::MAX_UNITS + 1 ] ] ) );

			$this->assertSame( [], $result['packer']->get_placement() );
			$this->assertGreaterThanOrEqual( 500.0 * ( \Woodev_Packer_Virtual_Box::MAX_UNITS + 1 ), $result['sides'][0] * $result['sides'][1] * $result['sides'][2] );
			$this->assertLessThan( 1.0, $result['seconds'] );
		}

		/**
		 * Items without dimensions (WooCommerce products with none set) must not break the packer.
		 */
		public function test_items_without_dimensions_do_not_break_the_packer() {
			$result = self::pack( self::units( [ [ 0, 0, 0, 3 ], [ 10, 8, 5, 2 ] ] ) );

			$this->assertEqualsWithDelta( [ 10.0, 10.0, 8.0 ], $result['sides'], self::EPS, 'the two real items, one on the other' );
		}

		/**
		 * Decimal sizes: the box volume computed from the placement can land a rounding error below the summed
		 * item volume, and the packed-box view compares them strictly. The view must still report every unit
		 * and the full weight (critic of #1213).
		 *
		 * @return array<string, array{0: float, 1: float, 2: float, 3: int}>
		 */
		public static function decimal_cases(): array {
			return [
				'three 10.5 x 7.3 x 2.1' => [ 10.5, 7.3, 2.1, 3 ],
				'twenty 10.5 x 7.3 x 2.1' => [ 10.5, 7.3, 2.1, 20 ],
				'twenty 12.7 x 8.9 x 3.3' => [ 12.7, 8.9, 3.3, 20 ],
			];
		}

		/**
		 * @dataProvider decimal_cases
		 */
		public function test_the_packed_box_reports_every_placed_decimal_unit( float $length, float $width, float $height, int $quantity ) {
			$units = [];

			for ( $i = 0; $i < $quantity; $i++ ) {
				$units[] = new \Woodev_Packer_Item_Implementation( $length, $width, $height, 1.0 );
			}

			$result = self::pack( $units );
			$packed = $result['packer']->get_packages()[0];

			$this->assertCount( $quantity, $packed->get_packed_items() );
			$this->assertSame( [], $packed->get_nofit_items() );
			$this->assertEqualsWithDelta( (float) $quantity, $packed->get_packed_weight(), 1e-9 );
		}

		/**
		 * The three-unit case of the critic is a real placement (not the grid fallback), so the settling is
		 * what makes the packed view complete — and it moves no side visibly.
		 */
		public function test_the_decimal_placed_box_keeps_its_measured_sides() {
			$units = [];

			for ( $i = 0; $i < 3; $i++ ) {
				$units[] = new \Woodev_Packer_Item_Implementation( 10.5, 7.3, 2.1, 1.0 );
			}

			$result = self::pack( $units );

			$this->assertNotSame( [], $result['packer']->get_placement() );
			$this->assertEqualsWithDelta( [ 10.5, 7.3, 6.3 ], $result['sides'], 1e-6 );
		}
	}
}
