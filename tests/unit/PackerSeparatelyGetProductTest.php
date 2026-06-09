<?php
/**
 * Regression test for V-2 WC-free fix: Woodev_Packer_Separately no longer requires
 * get_product() (WC_Product) on items. Any Woodev_Box_Packer_Item implementation
 * (the documented extension contract) must pack without error.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Product', false ) ) {
		class BoxPackerTest_WC_Product_Stub {}

		class_alias( BoxPackerTest_WC_Product_Stub::class, 'WC_Product' );
	}

}

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-box-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-item-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packed-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/abstract-class-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-separatly.php';

	if ( ! class_exists( 'Woodev_Test_BoxPacker_Item_Without_Product', false ) ) {
		class Woodev_Test_BoxPacker_Item_Without_Product implements \Woodev_Box_Packer_Item {
			public function get_name() { return 'test-item'; }
			public function get_volume() { return 1.0; }
			public function get_height() { return 1.0; }
			public function get_width() { return 1.0; }
			public function get_length() { return 1.0; }
			public function get_weight() { return 1.0; }
			public function get_value() { return 0.0; }
			public function get_internal_data() { return null; }
		}
	}
}

namespace Woodev\Tests\Unit {

	class PackerSeparatelyGetProductTest extends TestCase {

		/**
		 * Verifies the WC-free fix: Woodev_Box_Packer_Item without get_product()
		 * must pack successfully (no fatal, no exception).
		 */
		public function test_pack_accepts_plugin_item_without_get_product() {
			$packer = new \Woodev_Packer_Separately( 'Test Package' );
			$item   = new \Woodev_Test_BoxPacker_Item_Without_Product();

			$reflection = new \ReflectionClass( $packer );
			$prop       = $reflection->getProperty( 'items' );
			if ( PHP_VERSION_ID < 80100 ) {
				$prop->setAccessible( true );
			}
			$prop->setValue( $packer, [ $item ] );

			// Must not throw — WC dependency is gone.
			$packer->pack();

			$packages = $packer->get_packages();
			$this->assertCount( 1, $packages );
		}
	}

}
