<?php
/**
 * Verifies the Woodev_Woocommerce_Helper split: the four WC-coupled helper
 * methods live on the new namespaced class, and Woodev_Helper keeps only
 * deprecated shims that delegate.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-helper-alias.php';

}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

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

		/**
		 * The global-namespace alias Woodev_Woocommerce_Helper must be a true
		 * alias of the namespaced class. This is the entry point that
		 * existing 10+ plugins will use after migration.
		 *
		 * Note: with class_alias, `Alias::class` returns the alias name string
		 * (not the target), so the assertion uses is_a() which understands
		 * class_alias semantics.
		 *
		 * @return void
		 */
		public function test_global_alias_resolves_to_namespaced_class(): void {
			$this->assertTrue(
				class_exists( \Woodev_Woocommerce_Helper::class ),
				'Woodev_Woocommerce_Helper (the global-namespace alias) must be loadable.'
			);
			$this->assertTrue(
				is_a( \Woodev_Woocommerce_Helper::class, \Woodev\Framework\Woocommerce_Helper::class, true ),
				'Woodev_Woocommerce_Helper must be a class_alias of \\Woodev\\Framework\\Woocommerce_Helper.'
			);
			$this->assertTrue(
				method_exists( \Woodev_Woocommerce_Helper::class, 'shop_has_virtual_products' ),
				'Woodev_Woocommerce_Helper must expose shop_has_virtual_products().'
			);
		}

		/**
		 * The base-class shim for shop_has_virtual_products() must still
		 * return false in a no-WooCommerce context (wc_get_products is not
		 * a function in the test environment) and must fire _deprecated_function.
		 *
		 * This proves the shim is a route-through to the new class location.
		 *
		 * @return void
		 */
		public function test_base_shim_shop_has_virtual_products_delegates_and_returns_false_without_woocommerce(): void {
			Functions\expect( '_deprecated_function' )
				->once()
				->with(
					\Woodev_Helper::class . '::shop_has_virtual_products',
					'2.0.0',
					'Woodev_Woocommerce_Helper::shop_has_virtual_products()'
				);

			$this->assertFalse( \Woodev_Helper::shop_has_virtual_products() );
		}
	}

}
