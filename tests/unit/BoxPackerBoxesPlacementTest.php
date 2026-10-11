<?php
/**
 * #1214: the merchant-boxes packer (Woodev_Packer_Boxes) takes a set of items into a box only when the items
 * can really be PLACED into it — Woodev_Packer_Free_Space, the placement machinery of the virtual box (#1212)
 * with a fixed container — not merely when each item's sides fit and the summed volume stays under the box's.
 *
 * Each box is tried in every order of its axes and the best result is kept; the work per parcel is bounded by
 * Woodev_Packer_Free_Space::MAX_UNITS placement attempts, and items past that wait for a later parcel — a set is
 * never taken without a placement.
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
		 * The package's items placed one by one into a fresh container of its box, the box turned every way.
		 */
		private static function placeable( \Woodev_Box_Packer_Packed_Box $package ): bool {
			$box = $package->get_box();
			$d   = [ $box->get_length(), $box->get_width(), $box->get_height() ];

			foreach ( [ [ 0, 1, 2 ], [ 1, 0, 2 ], [ 0, 2, 1 ], [ 2, 0, 1 ], [ 1, 2, 0 ], [ 2, 1, 0 ] ] as [ $x, $y, $z ] ) {
				$space = new \Woodev_Packer_Free_Space( $d[ $x ], $d[ $y ], $d[ $z ], 1.0 );
				$ok    = true;

				foreach ( $package->get_packed_items() as $item ) {
					if ( null === $space->place( [ $item->get_length(), $item->get_width(), $item->get_height() ] ) ) {
						$ok = false;
						break;
					}
				}

				if ( $ok ) {
					return true;
				}
			}

			return false;
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
		// Rectangular loads that tile the box in another orientation (round 2, P1)
		// -------------------------------------------------------------------

		public function test_four_items_that_tile_the_box_turned_share_one_package(): void {
			// 4 x 40x25x10 in 50x40x20: each turned to 25x40x10, laid 2 x 1 x 2
			$packer = self::pack( [ [ 40, 25, 10, 4 ] ], [ [ 50, 40, 20 ] ] );

			$this->assertSame( [ 4 ], self::counts( $packer ) );
		}

		public function test_nine_items_in_a_three_by_three_grid_share_one_package(): void {
			// 9 x 25x20x15 in 60x50x25: a 3 x 3 x 1 grid of 20x15x25
			$packer = self::pack( [ [ 25, 20, 15, 9 ] ], [ [ 60, 50, 25 ] ] );

			$this->assertSame( [ 9 ], self::counts( $packer ) );
		}

		public function test_the_axes_of_the_box_as_given_do_not_decide_the_result(): void {
			foreach ( [ [ 50, 40, 20 ], [ 20, 40, 50 ], [ 40, 20, 50 ] ] as $box ) {
				$this->assertSame( [ 4 ], self::counts( self::pack( [ [ 40, 25, 10, 4 ] ], [ $box ] ) ) );
			}
		}

		// -------------------------------------------------------------------
		// Bounded work counts the placements a parcel attempts (round 2, P2)
		// -------------------------------------------------------------------

		public function test_unrelated_oversized_items_do_not_change_the_parcels_of_the_rest(): void {
			// three 20 cubes cannot share a 30 box; neither can they when 117 or 118 oversized items ride along
			foreach ( [ 117, 118 ] as $oversized ) {
				$packer = self::pack( [ [ 20, 20, 20, 3 ], [ 40, 40, 40, $oversized ] ], [ [ 30, 30, 30 ] ] );

				$this->assertSame( [ 1, 1, 1 ], self::counts( $packer ), $oversized . ' oversized items' );
				$this->assertCount( $oversized, $packer->get_items_cannot_pack() );
			}
		}

		public function test_a_long_cart_of_small_items_never_puts_two_big_ones_into_one_box(): void {
			// 3 x 20 cubes + 118 one-centimetre cubes (121 units): no 30 box holds two 20 cubes
			$packer = self::pack( [ [ 20, 20, 20, 3 ], [ 1, 1, 1, 118 ] ], [ [ 30, 30, 30 ] ] );

			$this->assertSame( 121, array_sum( self::counts( $packer ) ) + count( $packer->get_items_cannot_pack() ) );

			foreach ( $packer->get_packages() as $package ) {
				$big = array_filter( $package->get_packed_items(), static fn( $item ) => $item->get_length() > 10 );

				$this->assertLessThanOrEqual( 1, count( $big ) );
				$this->assertTrue( self::placeable( $package ) );
			}
		}

		public function test_a_cart_past_the_placement_budget_is_split_into_parcels_that_all_fit(): void {
			// 200 cubes of 10 cm in a 100 cm cube: the volume allows all, but a parcel tries at most MAX_UNITS placements
			$packer = self::pack( [ [ 10, 10, 10, 200 ] ], [ [ 100, 100, 100 ] ] );

			$this->assertSame( 200, array_sum( self::counts( $packer ) ) );
			$this->assertGreaterThanOrEqual( 2, count( $packer->get_packages() ) );

			foreach ( $packer->get_packages() as $package ) {
				$this->assertLessThanOrEqual( \Woodev_Packer_Free_Space::MAX_UNITS, count( $package->get_packed_items() ) );
				$this->assertTrue( self::placeable( $package ) );
			}
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

		public function test_the_early_stops_leave_the_packages_exactly_as_the_full_search_made_them(): void {
			// 24 seeded carts of 7-61 units through six boxes; the digest was taken from 2384b22b, before the
			// passes and boxes that cannot win were given up early (#1214, round 3) — any change of a package,
			// of its items or of the leftovers changes it
			mt_srand( 1214 );

			$boxes  = [ [ 26, 17, 10 ], [ 30, 30, 30 ], [ 45, 30, 25 ], [ 60, 40, 40 ], [ 80, 60, 40 ], [ 100, 60, 50 ] ];
			$digest = [];

			for ( $c = 0; $c < 24; $c++ ) {
				$packer = new \Woodev_Packer_Boxes();

				foreach ( $boxes as $i => [ $length, $width, $height ] ) {
					$packer->add_box( new \Woodev_Packer_Box_Implementation( $length, $width, $height, 0.1, null, 'box-' . $i ) );
				}

				$index = [];
				$units = [ 7, 19, 34, 58, 61 ][ $c % 5 ];

				for ( $left = $units; $left > 0; ) {
					$quantity = min( $left, mt_rand( 1, 6 ) );
					$length   = mt_rand( 1, 45 );
					$width    = mt_rand( 1, 35 );
					$height   = mt_rand( 1, 25 );

					for ( $i = 0; $i < $quantity; $i++ ) {
						$item                            = new \Woodev_Packer_Item_Implementation( $length, $width, $height, 0.2 );
						$index[ spl_object_id( $item ) ] = count( $index );
						$packer->add_item( $item );
					}

					$left -= $quantity;
				}

				$packer->pack();

				$ids = static fn( array $items ) => array_map( static fn( $item ) => $index[ spl_object_id( $item ) ], $items );
				$out = [];

				foreach ( $packer->get_packages() as $package ) {
					$box   = $package->get_box();
					$out[] = [ [ $box->get_length(), $box->get_width(), $box->get_height() ], $ids( $package->get_packed_items() ), $ids( $package->get_nofit_items() ) ];
				}

				$out[]    = $ids( $packer->get_items_cannot_pack() );
				$digest[] = sha1( json_encode( $out ) );
			}

			$this->assertSame( 'f86c73850f5f970268f0a55b56fafc271af71b7e', sha1( implode( ',', $digest ) ) );
		}

		private static function packed_box( array $kinds, array $box ): \Woodev_Box_Packer_Packed_Box {
			return new \Woodev_Box_Packer_Packed_Box(
				new \Woodev_Packer_Box_Implementation( $box[0], $box[1], $box[2], 0.1, null, 'box' ),
				self::units( $kinds ),
				true
			);
		}

		public function test_a_box_that_cannot_reach_the_floor_is_given_up_but_still_packs_in_full_when_asked(): void {
			$kinds = [ [ 20, 20, 20, 3 ], [ 9, 9, 9, 10 ] ];
			$box   = [ 30, 30, 30 ];

			$full    = self::packed_box( $kinds, $box );
			$percent = $full->get_success_percent();

			$this->assertGreaterThan( 0.0, $percent );
			$this->assertLessThan( 100.0, $percent );

			$given_up = self::packed_box( $kinds, $box );

			$this->assertFalse( $given_up->try_to_beat( 100.0 ) );

			// asked for its real packing afterwards, it is the same one: nothing partial leaks out
			$this->assertSame( $percent, $given_up->get_success_percent() );
			$this->assertSame( count( $full->get_packed_items() ), count( $given_up->get_packed_items() ) );
			$this->assertSame( count( $full->get_nofit_items() ), count( $given_up->get_nofit_items() ) );
		}

		public function test_a_box_that_can_reach_the_floor_is_not_given_up_and_a_tie_counts(): void {
			$kinds = [ [ 20, 20, 20, 3 ], [ 9, 9, 9, 10 ] ];
			$box   = [ 30, 30, 30 ];

			$percent = self::packed_box( $kinds, $box )->get_success_percent();

			$this->assertTrue( self::packed_box( $kinds, $box )->try_to_beat( \Woodev_Box_Packer_Packed_Box::NO_FLOOR ) );
			$this->assertTrue( self::packed_box( $kinds, $box )->try_to_beat( 0.0 ) );
			$this->assertTrue( self::packed_box( $kinds, $box )->try_to_beat( $percent ) );
			$this->assertFalse( self::packed_box( $kinds, $box )->try_to_beat( $percent + 0.01 ) );
		}

		public function test_a_box_is_packed_once_however_often_it_is_asked(): void {
			$package = self::packed_box( [ [ 20, 20, 20, 3 ], [ 9, 9, 9, 10 ] ], [ 30, 30, 30 ] );

			$first = $package->get_packed_items();

			$this->assertSame( $first, $package->get_packed_items() );
			$this->assertSame( $package->get_success_percent(), $package->get_success_percent() );
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
