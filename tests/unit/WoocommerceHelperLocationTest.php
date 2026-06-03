<?php
/**
 * Verifies the Woocommerce_Helper split: the four WC-coupled helper
 * methods live on the new namespaced \Woodev\Framework\Woocommerce_Helper class.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-helper.php';

}

namespace Woodev\Tests\Unit {

	class WoocommerceHelperLocationTest extends TestCase {

		/**
		 * The 4 WC-coupled helper methods must be declared on the namespaced
		 * \Woodev\Framework\Woocommerce_Helper, not inherited from the
		 * platform-neutral \Woodev_Helper.
		 *
		 * @return void
		 */
		public function test_woocommerce_helper_declares_all_four_methods(): void {
			$reflection = new \ReflectionClass( \Woodev\Framework\Woocommerce_Helper::class );

			foreach ( array( 'get_order_line_items', 'is_order_virtual', 'shop_has_virtual_products', 'render_select2_ajax' ) as $method_name ) {
				$this->assertTrue(
					$reflection->hasMethod( $method_name ),
					"Woodev\\Framework\\Woocommerce_Helper must declare {$method_name}()."
				);
				$this->assertSame(
					\Woodev\Framework\Woocommerce_Helper::class,
					$reflection->getMethod( $method_name )->getDeclaringClass()->getName(),
					"{$method_name}() must be declared on Woodev\\Framework\\Woocommerce_Helper, not inherited from Woodev_Helper."
				);
			}
		}
	}

}
