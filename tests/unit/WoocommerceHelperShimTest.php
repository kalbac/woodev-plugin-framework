<?php
/**
 * Verifies the Woodev_Helper shim behavior for the two methods whose return
 * value can be asserted without a WooCommerce runtime:
 *
 *   - shop_has_virtual_products() returns false in any state when wc_get_products()
 *     is not a function (the helper's own function_exists guard) or when the
 *     helper class itself is not loaded (the shim's class_exists guard).
 *
 * The other two shims (get_order_line_items, is_order_virtual, render_select2_ajax)
 * either require a WC_Order argument or call WP runtime functions that are not
 * stubbed in this test file. Their deprecation messages and FQCN references are
 * covered by the regex test below; their behavior is covered by
 * WoocommerceHelperLocationTest when the helper class is loaded.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';

}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	class WoocommerceHelperShimTest extends TestCase {

		/**
		 * The shop_has_virtual_products() shim must return false in any state:
		 *   - If the helper class is loaded, the helper's own function_exists()
		 *     guard short-circuits to false (wc_get_products is not a function
		 *     in the test environment).
		 *   - If the helper class is not loaded, the shim's class_exists( ..., false )
		 *     guard short-circuits to false.
		 *
		 * Either way, the shim must emit _deprecated_function with the right
		 * (function, version, replacement) tuple.
		 *
		 * @return void
		 */
		public function test_shop_has_virtual_products_shim_returns_false(): void {
			Functions\expect( '_deprecated_function' )
				->once()
				->with(
					\Woodev_Helper::class . '::shop_has_virtual_products',
					'2.0.0',
					'Woodev_Woocommerce_Helper::shop_has_virtual_products()'
				);

			$this->assertFalse( \Woodev_Helper::shop_has_virtual_products() );
		}

		/**
		 * Every deprecated shim on Woodev_Helper must reference the FQCN
		 * \Woodev\Framework\Woocommerce_Helper so that the delegate call
		 * resolves to the actual WC class. A bare short name
		 * `Woocommerce_Helper::class` would resolve to the global-namespace
		 * \Woocommerce_Helper (which does not exist), causing the shim to
		 * silently fall through.
		 *
		 * Same trap as the B-2 polish on get_woocommerce_uploads_path().
		 *
		 * @return void
		 */
		public function test_base_shims_use_fqcn_for_woocommerce_helper(): void {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-helper.php' );

			$this->assertNotFalse( $source, 'class-helper.php must be readable.' );

			$matches = array();
			preg_match_all(
				'/\\\\Woodev\\\\Framework\\\\Woocommerce_Helper::(get_order_line_items|is_order_virtual|shop_has_virtual_products|render_select2_ajax)/',
				$source,
				$matches
			);

			$this->assertGreaterThanOrEqual(
				4,
				count( $matches[0] ),
				'Each of the 4 deprecated shims on Woodev_Helper must reference the FQCN \\Woodev\\Framework\\Woocommerce_Helper so the delegate call actually reaches the new class. A bare short name Woocommerce_Helper::class would resolve to the global namespace \\Woocommerce_Helper (which does not exist).'
			);
		}
	}

}
