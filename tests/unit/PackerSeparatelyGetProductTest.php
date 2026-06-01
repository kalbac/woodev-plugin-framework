<?php
/**
 * Failing test for V-2: Woodev_Box_Packer_Item interface does not declare get_product(),
 * but Woodev_Packer_Separately::pack() calls $item->get_product() unconditionally.
 *
 * Reproduces the bug at woodev/box-packer/class-packer-separatly.php:38 — a plugin that
 * implements Woodev_Box_Packer_Item without get_product() (the documented extension
 * contract) fatals at pack() time.
 *
 * Test currently FAILS with a fatal Error. After B-1b fix (split interface or add
 * get_product() to base), it PASSES.
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

		public function test_pack_rejects_plugin_item_without_get_product_with_clear_exception() {
			$packer = new \Woodev_Packer_Separately( '{product_name}' );
			$item   = new \Woodev_Test_BoxPacker_Item_Without_Product();

			$reflection = new \ReflectionClass( $packer );
			$prop       = $reflection->getProperty( 'items' );
			$prop->setValue( $packer, array( $item ) );

			$this->expectException( \Woodev_Packer_Exception::class );
			$this->expectExceptionMessage( 'Woodev_Box_Packer_Item_With_Product' );

			$packer->pack();
		}
	}

}
