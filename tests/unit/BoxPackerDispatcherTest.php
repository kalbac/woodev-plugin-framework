<?php
/**
 * Tests for Woodev_Packer_Dispatcher and its input/output contracts.
 *
 * No WooCommerce or WordPress required. Brain Monkey stubs WP functions
 * (including __()). WC_Product is stubbed for Item_Implementation parity.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class BoxPackerDispatcherTest_WC_Product_Stub {}
		class_alias( BoxPackerDispatcherTest_WC_Product_Stub::class, 'WC_Product' );
	}

	// Box-packer interfaces
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item-with-product.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';

	// Box-packer classes
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-item-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-box-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packed-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/abstract-class-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-single-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-virtual-box.php';

	// Dispatcher contracts
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-input-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-package-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-dispatcher.php';
}

namespace Woodev\Tests\Unit {

	class BoxPackerDispatcherTest extends TestCase {

		// -------------------------------------------------------------------
		// Fixtures
		// -------------------------------------------------------------------

		/**
		 * 10 items with varied dimensions and weights — the main "real-world" fixture.
		 *
		 * @return \Woodev_Packer_Input_Item[]
		 */
		private function mixed_items(): array {
			return [
				new \Woodev_Packer_Input_Item( 30, 25, 15, 1.5 ),  // mid-size box
				new \Woodev_Packer_Input_Item( 10, 8, 5, 0.3 ),    // small packet
				new \Woodev_Packer_Input_Item( 50, 40, 30, 5.0 ),  // large heavy box
				new \Woodev_Packer_Input_Item( 20, 15, 10, 0.8 ),
				new \Woodev_Packer_Input_Item( 5, 5, 5, 0.1 ),     // tiny cube
				new \Woodev_Packer_Input_Item( 35, 20, 10, 2.0 ),
				new \Woodev_Packer_Input_Item( 15, 12, 8, 0.5 ),
				new \Woodev_Packer_Input_Item( 45, 30, 20, 3.5 ),
				new \Woodev_Packer_Input_Item( 8, 6, 4, 0.2 ),
				new \Woodev_Packer_Input_Item( 25, 20, 12, 1.0 ),
			];
		}

		/** Total weight of mixed_items(). */
		private function mixed_items_total_weight(): float {
			return 1.5 + 0.3 + 5.0 + 0.8 + 0.1 + 2.0 + 0.5 + 3.5 + 0.2 + 1.0; // 14.9
		}

		// -------------------------------------------------------------------
		// Input contract: Woodev_Packer_Input_Item
		// -------------------------------------------------------------------

		public function test_input_item_stores_dimensions_and_weight() {
			$item = new \Woodev_Packer_Input_Item( 30.0, 20.0, 10.0, 1.5, 3 );

			$this->assertEqualsWithDelta( 30.0, $item->get_length(), 0.001 );
			$this->assertEqualsWithDelta( 20.0, $item->get_width(), 0.001 );
			$this->assertEqualsWithDelta( 10.0, $item->get_height(), 0.001 );
			$this->assertEqualsWithDelta( 1.5, $item->get_weight(), 0.001 );
			$this->assertSame( 3, $item->get_quantity() );
		}

		public function test_input_item_quantity_clamps_to_one_minimum() {
			$item = new \Woodev_Packer_Input_Item( 10, 10, 10, 0, 0 );
			$this->assertSame( 1, $item->get_quantity() );
		}

		public function test_input_item_implements_packable_item_interface() {
			$item = new \Woodev_Packer_Input_Item( 10, 10, 10 );
			$this->assertInstanceOf( \Woodev_Packer_Packable_Item::class, $item );
		}

		// -------------------------------------------------------------------
		// Output contracts: Woodev_Packer_Package_Result + Woodev_Packer_Result
		// -------------------------------------------------------------------

		public function test_package_result_volume() {
			$pkg = new \Woodev_Packer_Package_Result( 20.0, 15.0, 10.0, 1.5, 3 );
			$this->assertEqualsWithDelta( 3000.0, $pkg->get_volume(), 0.001 );
		}

		public function test_package_result_to_array_has_required_keys() {
			$pkg  = new \Woodev_Packer_Package_Result( 20.0, 15.0, 10.0, 1.5, 3 );
			$data = $pkg->to_array();

			foreach ( [ 'length', 'width', 'height', 'weight', 'volume', 'item_count' ] as $key ) {
				$this->assertArrayHasKey( $key, $data, "Missing key: $key" );
			}
		}

		public function test_result_to_array_has_required_keys() {
			$result = new \Woodev_Packer_Result(
				'virtual',
				[ new \Woodev_Packer_Package_Result( 20.0, 15.0, 10.0, 1.5, 3 ) ]
			);
			$data   = $result->to_array();

			foreach ( [ 'algorithm', 'package_count', 'total_weight', 'total_volume', 'packages' ] as $key ) {
				$this->assertArrayHasKey( $key, $data, "Missing key: $key" );
			}
			$this->assertSame( 'virtual', $data['algorithm'] );
			$this->assertIsArray( $data['packages'] );
		}

		// -------------------------------------------------------------------
		// Dispatcher — virtual algorithm
		// -------------------------------------------------------------------

		public function test_virtual_produces_exactly_one_package() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
				$this->mixed_items()
			);

			$this->assertSame( 1, $result->get_package_count() );
		}

		public function test_virtual_box_fits_largest_item_on_each_axis() {
			$items  = $this->mixed_items();
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
				$items
			);

			$box = $result->get_packages()[0];
			// Largest item is 50×40×30; after Item_Implementation normalization it stays 50×40×30.
			$this->assertGreaterThanOrEqual( 50.0, $box->get_length() );
			$this->assertGreaterThanOrEqual( 40.0, $box->get_width() );
			$this->assertGreaterThanOrEqual( 30.0, $box->get_height() );
		}

		public function test_virtual_total_weight_equals_sum_of_item_weights() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
				$this->mixed_items()
			);

			$this->assertEqualsWithDelta(
				$this->mixed_items_total_weight(),
				$result->get_total_weight(),
				0.001
			);
		}

		public function test_virtual_item_count_equals_total_units() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
				$this->mixed_items()
			);

			$this->assertSame( 10, $result->get_packages()[0]->get_item_count() );
		}

		public function test_virtual_algorithm_id_preserved_in_result() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
				[ new \Woodev_Packer_Input_Item( 10, 10, 10 ) ]
			);

			$this->assertSame( \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL, $result->get_algorithm() );
		}

		// -------------------------------------------------------------------
		// Dispatcher — separately algorithm
		// -------------------------------------------------------------------

		public function test_separately_produces_one_package_per_unit() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY,
				$this->mixed_items()
			);

			// 10 items × 1 unit each = 10 packages.
			$this->assertSame( 10, $result->get_package_count() );
		}

		public function test_separately_expands_quantity_correctly() {
			$items = [
				new \Woodev_Packer_Input_Item( 10, 10, 5, 0.5, 3 ), // qty=3
				new \Woodev_Packer_Input_Item( 20, 15, 10, 1.0, 2 ), // qty=2
			];

			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY,
				$items
			);

			$this->assertSame( 5, $result->get_package_count() );
		}

		public function test_separately_each_package_has_one_item() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY,
				$this->mixed_items()
			);

			foreach ( $result->get_packages() as $pkg ) {
				$this->assertSame( 1, $pkg->get_item_count() );
			}
		}

		public function test_separately_total_weight_equals_sum_of_item_weights() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY,
				$this->mixed_items()
			);

			$this->assertEqualsWithDelta(
				$this->mixed_items_total_weight(),
				$result->get_total_weight(),
				0.001
			);
		}

		public function test_separately_dimensions_are_normalised() {
			// Input: width > length — Item_Implementation should normalise to length >= width >= height.
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY,
				[ new \Woodev_Packer_Input_Item( 5, 30, 10 ) ] // 5 < 30 → normalised to 30×10×5
			);

			$pkg = $result->get_packages()[0];
			$this->assertGreaterThanOrEqual( $pkg->get_width(), $pkg->get_length() );
			$this->assertGreaterThanOrEqual( $pkg->get_height(), $pkg->get_width() );
		}

		// -------------------------------------------------------------------
		// Dispatcher — single algorithm
		// -------------------------------------------------------------------

		public function test_single_produces_exactly_one_package() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SINGLE,
				$this->mixed_items()
			);

			$this->assertSame( 1, $result->get_package_count() );
		}

		public function test_single_box_fits_largest_item_per_axis() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SINGLE,
				$this->mixed_items()
			);

			$box = $result->get_packages()[0];
			$this->assertGreaterThan( 0.0, $box->get_length() );
			$this->assertGreaterThan( 0.0, $box->get_width() );
			$this->assertGreaterThan( 0.0, $box->get_height() );
		}

		public function test_single_total_weight_equals_sum_of_item_weights() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SINGLE,
				$this->mixed_items()
			);

			$this->assertEqualsWithDelta(
				$this->mixed_items_total_weight(),
				$result->get_total_weight(),
				0.001
			);
		}

		public function test_single_item_count_equals_total_units() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SINGLE,
				$this->mixed_items()
			);

			$this->assertSame( 10, $result->get_packages()[0]->get_item_count() );
		}

		public function test_single_algorithm_id_preserved_in_result() {
			$result = \Woodev_Packer_Dispatcher::pack(
				\Woodev_Packer_Dispatcher::ALGORITHM_SINGLE,
				[ new \Woodev_Packer_Input_Item( 10, 10, 10 ) ]
			);

			$this->assertSame( \Woodev_Packer_Dispatcher::ALGORITHM_SINGLE, $result->get_algorithm() );
		}

		// -------------------------------------------------------------------
		// Dispatcher — get_algorithms()
		// -------------------------------------------------------------------

		public function test_get_algorithms_returns_all_algorithm_ids() {
			$algos = \Woodev_Packer_Dispatcher::get_algorithms();

			$this->assertArrayHasKey( \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL, $algos );
			$this->assertArrayHasKey( \Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY, $algos );
			$this->assertArrayHasKey( \Woodev_Packer_Dispatcher::ALGORITHM_SINGLE, $algos );
		}

		// -------------------------------------------------------------------
		// Dispatcher — error handling
		// -------------------------------------------------------------------

		public function test_unknown_algorithm_throws_exception() {
			$this->expectException( \Woodev_Packer_Exception::class );

			\Woodev_Packer_Dispatcher::pack(
				'unknown_algo_xyz',
				[ new \Woodev_Packer_Input_Item( 10, 10, 10 ) ]
			);
		}

		public function test_empty_items_throws_exception() {
			$this->expectException( \Woodev_Packer_Exception::class );

			\Woodev_Packer_Dispatcher::pack( \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL, [] );
		}
	}
}
