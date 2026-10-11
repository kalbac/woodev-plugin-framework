<?php
/**
 * #1138: the `boxes` algorithm of Woodev_Packer_Dispatcher, and the per-package item allocation every
 * algorithm now reports.
 *
 * - `boxes` packs into the supplied boxes; a unit that fits no box (too big, too heavy) is NEVER dropped — it
 *   travels in a parcel of its own, sized by the item; no boxes at all means every unit travels so.
 * - Whatever the algorithm, `Woodev_Packer_Package_Result::get_items()` says which input items went into the
 *   package, and across a result the quantities of one item add up to its input quantity.
 *
 * No WooCommerce or WordPress required (Brain Monkey stubs `__()`).
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class BoxPackerBoxesAlgorithmTest_WC_Product_Stub {}
		class_alias( BoxPackerBoxesAlgorithmTest_WC_Product_Stub::class, 'WC_Product' );
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item-with-product.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-item-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-box-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-free-space.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packed-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/abstract-class-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-single-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-virtual-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-boxes.php';

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-input-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-package-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-dispatcher.php';
}

namespace Woodev\Tests\Unit {

	/**
	 * @covers \Woodev_Packer_Dispatcher
	 * @covers \Woodev_Packer_Package_Result
	 * @covers \Woodev_Packer_Input_Item
	 * @covers \Woodev_Packer_Boxes
	 */
	class BoxPackerBoxesAlgorithmTest extends TestCase {

		/** A small box: 20 x 15 x 10, holds 2 kg gross, weighs 0.1 kg. */
		private function small_box(): \Woodev_Packer_Box_Implementation {
			return new \Woodev_Packer_Box_Implementation( 20, 15, 10, 0.1, 2.0, 'small', 'Small' );
		}

		/** A big box: 40 x 30 x 20, no weight limit, weighs 0.4 kg. */
		private function big_box(): \Woodev_Packer_Box_Implementation {
			return new \Woodev_Packer_Box_Implementation( 40, 30, 20, 0.4, null, 'big', 'Big' );
		}

		/**
		 * @param \Woodev_Packer_Result $result
		 * @return array<string, int> input item key => units across every package of the result
		 */
		private function units_by_key( \Woodev_Packer_Result $result ): array {
			$units = [];

			foreach ( $result->get_packages() as $package ) {
				foreach ( $package->get_items() as $entry ) {
					$units[ $entry['key'] ] = ( $units[ $entry['key'] ] ?? 0 ) + $entry['quantity'];
				}
			}

			ksort( $units );

			return $units;
		}

		// -------------------------------------------------------------------
		// Algorithm registration
		// -------------------------------------------------------------------

		public function test_boxes_is_a_registered_algorithm_with_a_label(): void {
			$this->assertSame( 'boxes', \Woodev_Packer_Dispatcher::ALGORITHM_BOXES );
			$this->assertArrayHasKey( 'boxes', \Woodev_Packer_Dispatcher::get_algorithms() );
		}

		// -------------------------------------------------------------------
		// boxes: packing into the supplied set
		// -------------------------------------------------------------------

		public function test_items_that_fit_one_box_are_one_package_of_the_box(): void {
			$items  = [
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 2, 'line-a', 11 ),
				new \Woodev_Packer_Input_Item( 5, 5, 5, 0.1, 1, 'line-b', 12 ),
			];
			$result = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, [ $this->small_box() ] );

			$this->assertSame( 'boxes', $result->get_algorithm() );
			$this->assertSame( 1, $result->get_package_count() );

			$package = $result->get_packages()[0];

			// the box's own size (sorted longest first), not the items'
			$this->assertEqualsWithDelta( [ 20.0, 15.0, 10.0 ], [ $package->get_length(), $package->get_width(), $package->get_height() ], 0.0001 );
			// 3 units: 2 x 0.3 + 0.1, plus the box's own 0.1
			$this->assertEqualsWithDelta( 0.8, $package->get_weight(), 0.0001 );
			$this->assertSame( 3, $package->get_item_count() );
			$this->assertSame( 'small', $package->get_box_id() );
			$this->assertSame( 'Small', $package->get_box_name() );
			$this->assertSame(
				[
					[ 'key' => 'line-a', 'product_id' => 11, 'quantity' => 2 ],
					[ 'key' => 'line-b', 'product_id' => 12, 'quantity' => 1 ],
				],
				$package->get_items()
			);
		}

		public function test_items_that_do_not_fit_one_box_spill_into_a_second_package(): void {
			// 20 x 15 x 10 = 3000 cm3 per box; each item is 2000 cm3 — one per box
			$items  = [ new \Woodev_Packer_Input_Item( 20, 10, 10, 0.5, 2, 'line-a', 11 ) ];
			$result = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, [ $this->small_box() ] );

			$this->assertSame( 2, $result->get_package_count() );
			$this->assertSame( [ 'line-a' => 2 ], $this->units_by_key( $result ) );

			foreach ( $result->get_packages() as $package ) {
				$this->assertSame( 'small', $package->get_box_id() );
				$this->assertSame( 1, $package->get_item_count() );
			}
		}

		public function test_the_smallest_box_that_holds_the_items_is_used(): void {
			$items  = [ new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 1, 'line-a', 11 ) ];
			$result = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, [ $this->big_box(), $this->small_box() ] );

			$this->assertSame( 1, $result->get_package_count() );
			$this->assertSame( 'small', $result->get_packages()[0]->get_box_id() );
		}

		// -------------------------------------------------------------------
		// boxes: what fits no box is never dropped
		// -------------------------------------------------------------------

		public function test_an_item_too_big_for_every_box_travels_in_a_parcel_of_its_own(): void {
			$items  = [
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 1, 'fits', 11 ),
				new \Woodev_Packer_Input_Item( 60, 10, 10, 4.0, 2, 'oversize', 12 ),
			];
			$result = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, [ $this->small_box() ] );

			// the box + one parcel per oversize unit
			$this->assertSame( 3, $result->get_package_count() );
			$this->assertSame( [ 'fits' => 1, 'oversize' => 2 ], $this->units_by_key( $result ) );

			[ $box_package, $first_loose, $second_loose ] = $result->get_packages();

			$this->assertSame( 'small', $box_package->get_box_id() );
			$this->assertSame( [ [ 'key' => 'fits', 'product_id' => 11, 'quantity' => 1 ] ], $box_package->get_items() );

			foreach ( [ $first_loose, $second_loose ] as $loose ) {
				$this->assertSame( '', $loose->get_box_id(), 'a loose unit is in none of the merchant\'s boxes' );
				$this->assertSame( '', $loose->get_box_name() );
				$this->assertEqualsWithDelta( [ 60.0, 10.0, 10.0 ], [ $loose->get_length(), $loose->get_width(), $loose->get_height() ], 0.0001 );
				$this->assertEqualsWithDelta( 4.0, $loose->get_weight(), 0.0001, 'the item alone, no box weight' );
				$this->assertSame( [ [ 'key' => 'oversize', 'product_id' => 12, 'quantity' => 1 ] ], $loose->get_items() );
			}
		}

		public function test_an_item_heavier_than_any_box_allows_travels_separately(): void {
			// the small box holds 2 kg gross
			$items  = [ new \Woodev_Packer_Input_Item( 5, 5, 5, 2.5, 1, 'heavy', 11 ) ];
			$result = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, [ $this->small_box() ] );

			$this->assertSame( 1, $result->get_package_count() );
			$this->assertSame( '', $result->get_packages()[0]->get_box_id() );
			$this->assertEqualsWithDelta( 2.5, $result->get_packages()[0]->get_weight(), 0.0001 );
			$this->assertSame( [ 'heavy' => 1 ], $this->units_by_key( $result ) );
		}

		/**
		 * @return array<string, array{0: array<int, mixed>|null}>
		 */
		public static function no_boxes_provider(): array {
			return [
				'null'        => [ null ],
				'empty'       => [ [] ],
				'not boxes'   => [ [ 'a string', new \stdClass() ] ],
			];
		}

		/**
		 * @dataProvider no_boxes_provider
		 *
		 * @param array<int, mixed>|null $boxes
		 */
		public function test_with_no_boxes_every_unit_is_a_parcel_of_its_own( ?array $boxes ): void {
			$items  = [
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 2, 'line-a', 11 ),
				new \Woodev_Packer_Input_Item( 30, 20, 10, 1.0, 1, 'line-b', 12 ),
			];
			$result = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, $boxes );

			$this->assertSame( 'boxes', $result->get_algorithm() );
			$this->assertSame( 3, $result->get_package_count() );
			$this->assertSame( [ 'line-a' => 2, 'line-b' => 1 ], $this->units_by_key( $result ) );
			$this->assertEqualsWithDelta( 1.6, $result->get_total_weight(), 0.0001 );

			foreach ( $result->get_packages() as $package ) {
				$this->assertSame( '', $package->get_box_id() );
			}
		}

		public function test_the_boxes_are_ignored_by_the_other_algorithms(): void {
			$items = [ new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 2, 'line-a', 11 ) ];

			$result = \Woodev_Packer_Dispatcher::pack( 'separately', $items, [ $this->small_box() ] );

			$this->assertSame( 2, $result->get_package_count() );
			$this->assertSame( '', $result->get_packages()[0]->get_box_id() );
		}

		// -------------------------------------------------------------------
		// Allocation: every algorithm says which items went into which package
		// -------------------------------------------------------------------

		/**
		 * @return array<string, array{0: string}>
		 */
		public static function every_algorithm_provider(): array {
			return [
				'virtual'    => [ 'virtual' ],
				'separately' => [ 'separately' ],
				'single'     => [ 'single' ],
				'boxes'      => [ 'boxes' ],
			];
		}

		/**
		 * No unit is dropped or invented: the quantities of one item across the result are its input quantity.
		 *
		 * @dataProvider every_algorithm_provider
		 */
		public function test_the_allocation_accounts_for_every_unit_of_every_item( string $algorithm ): void {
			$items = [
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 3, 'line-a', 11 ),
				new \Woodev_Packer_Input_Item( 30, 20, 10, 1.0, 1, 'line-b', 12 ),
				new \Woodev_Packer_Input_Item( 70, 10, 10, 2.0, 2, 'line-c', 13 ), // fits no box below
				new \Woodev_Packer_Input_Item( 5, 5, 5, 0.1, 4, 'line-d', 14 ),
			];

			$result = \Woodev_Packer_Dispatcher::pack( $algorithm, $items, [ $this->small_box(), $this->big_box() ] );

			$this->assertSame(
				[ 'line-a' => 3, 'line-b' => 1, 'line-c' => 2, 'line-d' => 4 ],
				$this->units_by_key( $result )
			);

			foreach ( $result->get_packages() as $package ) {
				$this->assertSame( $package->get_item_count(), array_sum( array_column( $package->get_items(), 'quantity' ) ), 'item_count agrees with the allocation' );
			}
		}

		/**
		 * #1212: what fits no box and is packed «together» goes into the virtual box, never the single-axis one.
		 */
		public function test_leftovers_together_are_packed_in_the_virtual_box_not_the_single_one(): void {
			// none of these fits the boxes below (the longest side is 70 / 60 / 90 cm), so all of them are leftovers
			$items = [
				new \Woodev_Packer_Input_Item( 70, 10, 10, 2.0, 2, 'line-a', 11 ),
				new \Woodev_Packer_Input_Item( 60, 30, 20, 1.0, 1, 'line-b', 12 ),
				new \Woodev_Packer_Input_Item( 90, 5, 5, 0.5, 1, 'line-c', 13 ),
			];
			$boxes = [ $this->small_box(), $this->big_box() ];

			$virtual = \Woodev_Packer_Dispatcher::pack( 'virtual', $items )->get_packages()[0];
			$single  = \Woodev_Packer_Dispatcher::pack( 'single', $items )->get_packages()[0];
			$this->assertNotEquals(
				[ $single->get_length(), $single->get_width(), $single->get_height() ],
				[ $virtual->get_length(), $virtual->get_width(), $virtual->get_height() ],
				'the fixture tells the two algorithms apart'
			);

			// the retired `single` still means the same thing for a caller that passes it
			foreach ( [ 'virtual', 'single' ] as $leftovers ) {
				$result = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, $boxes, $leftovers );

				$this->assertSame( 1, $result->get_package_count(), $leftovers );
				$package = $result->get_packages()[0];
				$this->assertEqualsWithDelta(
					[ $virtual->get_length(), $virtual->get_width(), $virtual->get_height() ],
					[ $package->get_length(), $package->get_width(), $package->get_height() ],
					0.0001,
					$leftovers
				);
				$this->assertSame( 4, $package->get_item_count() );
				$this->assertEqualsWithDelta( 5.5, $package->get_weight(), 0.0001 );
				$this->assertSame( [ 'line-a' => 2, 'line-b' => 1, 'line-c' => 1 ], $this->units_by_key( $result ) );
			}
		}

		public function test_virtual_reports_every_item_in_its_single_package(): void {
			$items  = [
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 2, 'line-a', 11 ),
				new \Woodev_Packer_Input_Item( 5, 5, 5, 0.1, 1, 'line-b', 12 ),
			];
			$result = \Woodev_Packer_Dispatcher::pack( 'virtual', $items );

			$this->assertSame( 1, $result->get_package_count() );
			$this->assertSame(
				[
					[ 'key' => 'line-a', 'product_id' => 11, 'quantity' => 2 ],
					[ 'key' => 'line-b', 'product_id' => 12, 'quantity' => 1 ],
				],
				$result->get_packages()[0]->get_items()
			);
		}

		public function test_single_reports_every_item_in_its_single_package(): void {
			$items  = [
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 2, 'line-a', 11 ),
				new \Woodev_Packer_Input_Item( 5, 5, 5, 0.1, 3, 'line-b', 12 ),
			];
			$result = \Woodev_Packer_Dispatcher::pack( 'single', $items );

			$this->assertSame( 1, $result->get_package_count() );
			$this->assertSame(
				[
					[ 'key' => 'line-a', 'product_id' => 11, 'quantity' => 2 ],
					[ 'key' => 'line-b', 'product_id' => 12, 'quantity' => 3 ],
				],
				$result->get_packages()[0]->get_items()
			);
		}

		public function test_separately_reports_one_unit_per_package_in_input_order(): void {
			$items  = [
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 2, 'line-a', 11 ),
				new \Woodev_Packer_Input_Item( 5, 5, 5, 0.1, 1, 'line-b', 12 ),
			];
			$result = \Woodev_Packer_Dispatcher::pack( 'separately', $items );

			$this->assertSame( 3, $result->get_package_count() );
			$this->assertSame(
				[ 'line-a', 'line-a', 'line-b' ],
				array_map( static fn( $p ) => $p->get_items()[0]['key'], $result->get_packages() )
			);

			foreach ( $result->get_packages() as $package ) {
				$this->assertSame( 1, $package->get_items()[0]['quantity'] );
			}
		}

		/**
		 * An item that carries no key of its own (a plain Packable_Item, or an Input_Item built without one) is
		 * reported by its position in the input list.
		 *
		 * @dataProvider every_algorithm_provider
		 */
		public function test_an_item_without_a_key_is_reported_by_its_position( string $algorithm ): void {
			$items = [
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 1 ),
				new \Woodev_Packer_Input_Item( 5, 5, 5, 0.1, 2 ),
			];

			$result = \Woodev_Packer_Dispatcher::pack( $algorithm, $items, [ $this->big_box() ] );

			$this->assertSame( [ '0' => 1, '1' => 2 ], $this->units_by_key( $result ) );

			foreach ( $result->get_packages() as $package ) {
				foreach ( $package->get_items() as $entry ) {
					$this->assertSame( 0, $entry['product_id'] );
				}
			}
		}

		public function test_the_position_survives_an_input_list_with_gaps_in_its_keys(): void {
			$items  = [
				7  => new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 1 ),
				12 => new \Woodev_Packer_Input_Item( 5, 5, 5, 0.1, 1 ),
			];
			$result = \Woodev_Packer_Dispatcher::pack( 'separately', $items );

			$this->assertSame( [ '0' => 1, '1' => 1 ], $this->units_by_key( $result ) );
		}

		// -------------------------------------------------------------------
		// The public result object
		// -------------------------------------------------------------------

		public function test_the_package_array_carries_items_and_the_box(): void {
			$items  = [ new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 1, 'line-a', 11 ) ];
			$result = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, [ $this->small_box() ] );
			$data   = $result->to_array();

			$this->assertSame( 'boxes', $data['algorithm'] );
			$this->assertSame( 'small', $data['packages'][0]['box_id'] );
			$this->assertSame( 'Small', $data['packages'][0]['box_name'] );
			$this->assertSame( [ [ 'key' => 'line-a', 'product_id' => 11, 'quantity' => 1 ] ], $data['packages'][0]['items'] );
		}

		public function test_a_package_built_the_old_way_has_no_items_and_no_box(): void {
			$package = new \Woodev_Packer_Package_Result( 20.0, 15.0, 10.0, 1.5, 3 );

			$this->assertSame( [], $package->get_items() );
			$this->assertSame( '', $package->get_box_id() );
			$this->assertSame( '', $package->get_box_name() );
		}

		public function test_an_input_item_built_the_old_way_has_no_key_and_no_product(): void {
			$item = new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3, 2 );

			$this->assertSame( '', $item->get_key() );
			$this->assertSame( 0, $item->get_product_id() );
		}
	}
}
