<?php
/**
 * #1214: the merchant-boxes packer (Woodev_Packer_Boxes) takes a set of items into a box only when the items
 * can really be PLACED into it — Woodev_Packer_Free_Space, the placement machinery of the virtual box (#1212)
 * with a fixed container — not merely when each item's sides fit and the summed volume stays under the box's.
 *
 * Above Woodev_Packer_Free_Space::MAX_UNITS items the placement is skipped and the old sides + volume rule
 * decides alone, so the work stays bounded.
 *
 * No WooCommerce or WordPress required.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class BoxPackerBoxesPlacementTest_WC_Product_Stub {}

		class_alias( BoxPackerBoxesPlacementTest_WC_Product_Stub::class, 'WC_Product' );
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
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-boxes.php';
}

namespace Woodev\Tests\Unit {

	/**
	 * @covers \Woodev_Packer_Boxes
	 * @covers \Woodev_Box_Packer_Packed_Box
	 * @covers \Woodev_Packer_Free_Space
	 */
	class BoxPackerBoxesPlacementTest extends TestCase {

		const EPS = 1e-6;

		/**
		 * @param  array<int, array<int, float|int>>       $kinds [length, width, height, quantity] per kind
		 * @return \Woodev_Packer_Item_Implementation[]
		 */
		private static function units( array $kinds ): array {
			$units = [];

			foreach ( $kinds as [ $length, $width, $height, $quantity ] ) {
				for ( $i = 0; $i < $quantity; $i++ ) {
					$units[] = new \Woodev_Packer_Item_Implementation( $length, $width, $height, 0.2 );
				}
			}

			return $units;
		}

		/**
		 * @param  array<int, array<int, float|int>> $kinds
		 * @param  array<int, array<int, float|int>> $boxes [length, width, height] each
		 */
		private static function pack( array $kinds, array $boxes ): \Woodev_Packer_Boxes {
			$packer = new \Woodev_Packer_Boxes();

			foreach ( $boxes as $i => [ $length, $width, $height ] ) {
				$packer->add_box( new \Woodev_Packer_Box_Implementation( $length, $width, $height, 0.1, null, 'box-' . $i ) );
			}

			foreach ( self::units( $kinds ) as $unit ) {
				$packer->add_item( $unit );
			}

			$packer->pack();

			return $packer;
		}

		/**
		 * @return array<int, int> items per package
		 */
		private static function counts( \Woodev_Packer_Boxes $packer ): array {
			return array_map( static fn( $package ) => count( $package->get_packed_items() ), $packer->get_packages() );
		}

		// -------------------------------------------------------------------
		// Volume allows it, the geometry does not
		// -------------------------------------------------------------------

		public function test_three_cubes_of_20_do_not_share_a_30_cube_although_the_volume_allows_it(): void {
			// 3 x 8000 = 24000 of 27000 cm3, but 20 + 20 > 30 on every axis: one cube per box
			$packer = self::pack( [ [ 20, 20, 20, 3 ] ], [ [ 30, 30, 30 ] ] );

			$this->assertSame( [ 1, 1, 1 ], self::counts( $packer ) );
			$this->assertSame( [], $packer->get_items_cannot_pack() );
		}

		public function test_two_thick_plates_do_not_share_a_box_that_has_the_volume_for_both(): void {
			// 2 x 29 x 29 x 16 = 26896 of 27000 cm3, but the two stacked are 32 high in a 30 box
			$packer = self::pack( [ [ 29, 29, 16, 2 ] ], [ [ 30, 30, 30 ] ] );

			$this->assertSame( [ 1, 1 ], self::counts( $packer ) );
		}

		public function test_every_package_is_a_set_that_can_be_placed_into_its_box(): void {
			$packer = self::pack( [ [ 29, 29, 16, 2 ], [ 20, 20, 20, 3 ], [ 12, 12, 12, 4 ] ], [ [ 30, 30, 30 ], [ 45, 30, 25 ] ] );

			foreach ( $packer->get_packages() as $package ) {
				$box   = $package->get_box();
				$space = new \Woodev_Packer_Free_Space( $box->get_length(), $box->get_width(), $box->get_height(), 1.0 );

				foreach ( $package->get_packed_items() as $item ) {
					$this->assertNotNull( $space->place( [ $item->get_length(), $item->get_width(), $item->get_height() ] ) );
				}
			}
		}

		// -------------------------------------------------------------------
		// Volume and geometry agree
		// -------------------------------------------------------------------

		public function test_three_plates_stack_to_the_height_of_the_box(): void {
			// 3 x 29 x 29 x 10: the stack is exactly 30 high — they lie flat on top of each other
			$packer = self::pack( [ [ 29, 29, 10, 3 ] ], [ [ 30, 30, 30 ] ] );

			$this->assertSame( [ 3 ], self::counts( $packer ) );
		}

		public function test_a_fourth_plate_has_no_volume_left_and_goes_to_the_next_package(): void {
			$packer = self::pack( [ [ 29, 29, 10, 4 ] ], [ [ 30, 30, 30 ] ] );

			$this->assertSame( [ 3, 1 ], self::counts( $packer ) );
		}

		public function test_a_full_uniform_load_fills_one_box(): void {
			$packer = self::pack( [ [ 10, 10, 10, 27 ] ], [ [ 30, 30, 30 ] ] );

			$this->assertSame( [ 27 ], self::counts( $packer ) );
		}

		public function test_small_items_go_into_the_gaps_around_a_big_one(): void {
			$packer = self::pack( [ [ 20, 20, 20, 1 ], [ 10, 10, 10, 10 ] ], [ [ 30, 30, 30 ] ] );

			$this->assertSame( [ 11 ], self::counts( $packer ) );
		}

		// -------------------------------------------------------------------
		// The shape of the result is unchanged
		// -------------------------------------------------------------------

		public function test_weights_and_leftovers_keep_their_meaning(): void {
			$packer = self::pack( [ [ 20, 20, 20, 3 ], [ 40, 40, 40, 1 ] ], [ [ 30, 30, 30 ] ] );

			// the 40 cube fits no box at all: it is a leftover, not a package
			$this->assertCount( 1, $packer->get_items_cannot_pack() );
			$this->assertCount( 3, $packer->get_packages() );

			foreach ( $packer->get_packages() as $package ) {
				$this->assertEqualsWithDelta( 0.1 + 0.2, $package->get_packed_weight(), self::EPS );
				$this->assertSame( 'box-0', $package->get_box()->get_unique_id() );
			}
		}

		public function test_the_check_is_off_by_default_so_other_packers_keep_the_volume_rule(): void {
			$box    = new \Woodev_Packer_Box_Implementation( 30, 30, 30, 0.1, null, 'b' );
			$packed = new \Woodev_Box_Packer_Packed_Box( $box, self::units( [ [ 20, 20, 20, 3 ] ] ) );

			$this->assertCount( 3, $packed->get_packed_items() );

			$checked = new \Woodev_Box_Packer_Packed_Box( $box, self::units( [ [ 20, 20, 20, 3 ] ] ), true );

			$this->assertCount( 1, $checked->get_packed_items() );
			$this->assertCount( 2, $checked->get_nofit_items() );
		}

		// -------------------------------------------------------------------
		// Bounded work: above the threshold the old rule decides
		// -------------------------------------------------------------------

		public function test_above_the_unit_threshold_the_sides_and_volume_rule_decides(): void {
			// seven cubes of 51 cm: 928557 of 1000000 cm3, but only ONE fits a 100 cube (51 + 51 > 100)
			$limit = \Woodev_Packer_Free_Space::MAX_UNITS;

			$at    = self::pack( [ [ 51, 51, 51, 7 ], [ 1, 1, 1, $limit - 7 ] ], [ [ 100, 100, 100 ] ] );
			$above = self::pack( [ [ 51, 51, 51, 7 ], [ 1, 1, 1, $limit - 6 ] ], [ [ 100, 100, 100 ] ] );

			// at the threshold the placement runs: one big cube per package
			$this->assertGreaterThanOrEqual( 7, count( $at->get_packages() ) );
			// one unit above, no placement: the volume rule packs everything into one box
			$this->assertSame( [ $limit + 1 ], self::counts( $above ) );
		}

		public function test_a_cart_at_the_threshold_packs_within_a_checkout_sized_time(): void {
			$kinds = [ [ 21, 14, 3, 30 ], [ 33, 20, 12, 20 ], [ 12, 12, 12, 30 ], [ 70, 8, 8, 10 ], [ 25, 25, 3, 30 ] ];
			$start = microtime( true );
			$pack  = self::pack( $kinds, [ [ 26, 17, 10 ], [ 45, 30, 25 ], [ 80, 50, 40 ] ] );
			$secs  = microtime( true ) - $start;

			$this->assertSame( \Woodev_Packer_Free_Space::MAX_UNITS, array_sum( self::counts( $pack ) ) + count( $pack->get_items_cannot_pack() ) );
			$this->assertLessThan( 2.0, $secs );
		}

		// -------------------------------------------------------------------
		// Determinism
		// -------------------------------------------------------------------

		public function test_the_same_cart_always_gives_the_same_packages(): void {
			$kinds = [ [ 29, 29, 16, 3 ], [ 20, 20, 20, 2 ], [ 14, 9, 4, 11 ], [ 40, 5, 5, 3 ] ];
			$boxes = [ [ 30, 30, 30 ], [ 45, 30, 25 ], [ 60, 40, 40 ] ];

			$signature = static function ( \Woodev_Packer_Boxes $packer ): array {
				return array_map(
					static fn( $package ) => $package->get_box()->get_unique_id() . ':' . count( $package->get_packed_items() ),
					$packer->get_packages()
				);
			};

			$first = $signature( self::pack( $kinds, $boxes ) );

			for ( $i = 0; $i < 3; $i++ ) {
				$this->assertSame( $first, $signature( self::pack( $kinds, $boxes ) ) );
			}
		}

		// -------------------------------------------------------------------
		// Woodev_Packer_Free_Space itself
		// -------------------------------------------------------------------

		public function test_the_free_space_refuses_what_does_not_fit_and_stays_unchanged(): void {
			$space = new \Woodev_Packer_Free_Space( 30, 30, 30, 5.0 );

			$this->assertNull( $space->place( [ 31, 5, 5 ] ) );
			$this->assertNotNull( $space->place( [ 30, 30, 30 ] ) );
			$this->assertNull( $space->place( [ 5, 5, 5 ] ) );
		}

		public function test_a_unit_lies_flat_when_it_can(): void {
			$space  = new \Woodev_Packer_Free_Space( 30, 20, 10, 2.0 );
			$placed = $space->place( [ 2, 30, 10 ] );

			// a 2 x 30 x 10 slab goes in as 30 long, 10 deep, 2 high — not standing on its edge
			$this->assertEqualsWithDelta( [ 0.0, 0.0, 0.0, 30.0, 10.0, 2.0 ], $placed, self::EPS );
		}

		public function test_seeded_random_loads_are_placed_inside_the_box_without_overlap(): void {
			mt_srand( 1214 );

			for ( $round = 0; $round < 40; $round++ ) {
				$space  = new \Woodev_Packer_Free_Space( 40, 30, 25, 2.0 );
				$boxes  = [];
				$volume = 0.0;

				for ( $i = 0; $i < 30; $i++ ) {
					$unit   = [ mt_rand( 2, 25 ), mt_rand( 2, 25 ), mt_rand( 2, 25 ) ];
					$placed = $space->place( $unit );

					if ( null === $placed ) {
						continue;
					}

					$sides = [ $placed[3] - $placed[0], $placed[4] - $placed[1], $placed[5] - $placed[2] ];
					sort( $sides );
					sort( $unit );

					$this->assertEqualsWithDelta( $unit, $sides, self::EPS, 'a placed unit is a rotation of the unit' );
					$this->assertGreaterThanOrEqual( -self::EPS, min( $placed[0], $placed[1], $placed[2] ) );
					$this->assertLessThanOrEqual( 40 + self::EPS, $placed[3] );
					$this->assertLessThanOrEqual( 30 + self::EPS, $placed[4] );
					$this->assertLessThanOrEqual( 25 + self::EPS, $placed[5] );

					foreach ( $boxes as $other ) {
						$apart = $placed[0] >= $other[3] - self::EPS || $placed[3] <= $other[0] + self::EPS
							|| $placed[1] >= $other[4] - self::EPS || $placed[4] <= $other[1] + self::EPS
							|| $placed[2] >= $other[5] - self::EPS || $placed[5] <= $other[2] + self::EPS;
						$this->assertTrue( $apart, 'placed units do not overlap' );
					}

					$boxes[] = $placed;
					$volume += $unit[0] * $unit[1] * $unit[2];
				}

				$this->assertLessThanOrEqual( 40 * 30 * 25 + self::EPS, $volume );
			}
		}
	}
}
