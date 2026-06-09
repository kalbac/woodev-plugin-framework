<?php
/**
 * S2 validation gate — proves P1 (WC-neutral Single_Box) and P2 (minimal
 * virtual-box axis-assignment algorithm) are correct.
 *
 * No WooCommerce or WordPress required: items are built straight from
 * Woodev_Packer_Item_Implementation (float-only constructor) and packed via
 * Woodev_Packer_Virtual_Box / Woodev_Packer_Single_Box. Brain Monkey is set up
 * by the base TestCase; WC functions are intentionally NOT stubbed so a stray
 * wc_list_pluck() call would fatal the test.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	// Item_Implementation references WC_Product only in lazy method signatures
	// (never called here), but a stub keeps parity with the other packer tests.
	if ( ! class_exists( '\WC_Product', false ) ) {
		class BoxPackerMinimalVirtualBox_WC_Product_Stub {}

		class_alias( BoxPackerMinimalVirtualBox_WC_Product_Stub::class, 'WC_Product' );
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item-with-product.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-item-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-box-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packed-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/abstract-class-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-single-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-virtual-box.php';
}

namespace Woodev\Tests\Unit {

	class BoxPackerMinimalVirtualBoxTest extends TestCase {

		/**
		 * Packs the given items into a Virtual_Box and returns the resulting box.
		 *
		 * @param \Woodev_Packer_Item_Implementation[] $items
		 *
		 * @return \Woodev_Box_Packer_Box
		 */
		private function pack_virtual( array $items ): \Woodev_Box_Packer_Box {
			$packer = new \Woodev_Packer_Virtual_Box();

			foreach ( $items as $item ) {
				$packer->add_item( $item );
			}

			$packer->pack();

			return $packer->get_packages()[0]->get_box();
		}

		/**
		 * P2: a single item must not be inflated — the box equals the item.
		 */
		public function test_single_item_returns_item_dimensions() {
			$box = $this->pack_virtual( array( new \Woodev_Packer_Item_Implementation( 20, 15, 10 ) ) );

			$this->assertEqualsWithDelta( 20.0, $box->get_length(), 0.001 );
			$this->assertEqualsWithDelta( 15.0, $box->get_width(), 0.001 );
			$this->assertEqualsWithDelta( 10.0, $box->get_height(), 0.001 );
		}

		/**
		 * P2: PLANS.md 3.5.1 worked example — stacking along height wins.
		 */
		public function test_two_items_plans_md_example() {
			$box = $this->pack_virtual(
				array(
					new \Woodev_Packer_Item_Implementation( 10, 10, 5 ),
					new \Woodev_Packer_Item_Implementation( 20, 15, 10 ),
				)
			);

			$this->assertEqualsWithDelta( 20.0, $box->get_length(), 0.001 );
			$this->assertEqualsWithDelta( 15.0, $box->get_width(), 0.001 );
			$this->assertEqualsWithDelta( 15.0, $box->get_height(), 0.001 );
			$this->assertLessThanOrEqual( 4500.0, $box->get_volume() );
		}

		/**
		 * P2: three items — the 3-option search beats the naive bounding box, and
		 * the result is never smaller than the items it must contain.
		 */
		public function test_three_items_volume_less_than_naive() {
			$box = $this->pack_virtual(
				array(
					new \Woodev_Packer_Item_Implementation( 10, 10, 5 ),
					new \Woodev_Packer_Item_Implementation( 20, 15, 10 ),
					new \Woodev_Packer_Item_Implementation( 10, 10, 5 ),
				)
			);

			// Minimum achievable by the 3-option axis search.
			$this->assertLessThanOrEqual( 6000.0, $box->get_volume() );

			// Physically possible: never below the summed item volume (500+3000+500).
			$this->assertGreaterThanOrEqual( 4000.0, $box->get_volume() );
		}

		/**
		 * P2: ten flat identical items — grid search produces a cube-like result.
		 * Old linear stacking: 10×10×50 (sausage, max_dim=50).
		 * Grid search: 20×20×15 (cube-like, max_dim=20).
		 */
		public function test_ten_identical_items_physically_possible() {
			$items = array();
			for ( $i = 0; $i < 10; $i++ ) {
				$items[] = new \Woodev_Packer_Item_Implementation( 10, 10, 5 );
			}

			$box = $this->pack_virtual( $items );

			// Must hold all 10 items — volume >= sum of item volumes (10 × 500).
			$this->assertGreaterThanOrEqual( 5000.0, $box->get_volume() );

			// Must be cube-like: no single dimension should be a sausage (old result: 50).
			$max_dim = max( $box->get_length(), $box->get_width(), $box->get_height() );
			$this->assertLessThanOrEqual( 20.0, $max_dim );
		}

		/**
		 * P2: every box axis must accommodate the largest item on that axis.
		 */
		public function test_result_dimensions_axis_aligned() {
			$items = array(
				new \Woodev_Packer_Item_Implementation( 30, 20, 10 ),
				new \Woodev_Packer_Item_Implementation( 15, 15, 15 ),
				new \Woodev_Packer_Item_Implementation( 40, 5, 5 ),
				new \Woodev_Packer_Item_Implementation( 10, 10, 10 ),
			);

			$max_length = max( array_map( fn( $item ) => $item->get_length(), $items ) );
			$max_width  = max( array_map( fn( $item ) => $item->get_width(), $items ) );
			$max_height = max( array_map( fn( $item ) => $item->get_height(), $items ) );

			$box = $this->pack_virtual( $items );

			$this->assertGreaterThanOrEqual( $max_length, $box->get_length() );
			$this->assertGreaterThanOrEqual( $max_width, $box->get_width() );
			$this->assertGreaterThanOrEqual( $max_height, $box->get_height() );
		}

		/**
		 * P1: Single_Box::pack() must run without WooCommerce — proves the
		 * wc_list_pluck() dependency was removed. WC functions are not stubbed,
		 * so any WC call here would fatal.
		 */
		public function test_single_box_pack_without_woocommerce() {
			$packer = new \Woodev_Packer_Single_Box( 'test-box' );
			$packer->add_item( new \Woodev_Packer_Item_Implementation( 10, 10, 5 ) );
			$packer->add_item( new \Woodev_Packer_Item_Implementation( 10, 10, 5 ) );
			$packer->add_item( new \Woodev_Packer_Item_Implementation( 10, 10, 5 ) );

			$packer->pack();

			$this->assertCount( 1, $packer->get_packages() );
		}
	}
}
