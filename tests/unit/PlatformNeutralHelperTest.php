<?php
/**
 * Platform-neutral helper tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';

/**
 * Class PlatformNeutralHelperTest.
 */
class PlatformNeutralHelperTest extends TestCase {

	/**
	 * Percentage formatting should work without WooCommerce decimal helpers.
	 *
	 * @return void
	 */
	public function test_format_percentage_falls_back_without_woocommerce_helper(): void {
		$this->assertSame( '33.33%', \Woodev_Helper::format_percentage( 0.3333, 2, false ) );
		$this->assertSame( '50%', \Woodev_Helper::format_percentage( 0.5, 2, true ) );
		$this->assertSame( '75%', \Woodev_Helper::format_percentage( 0.75 ) );
	}

	/**
	 * Virtual-product detection should safely return false without WooCommerce.
	 *
	 * @return void
	 */
	public function test_shop_has_virtual_products_returns_false_without_woocommerce(): void {
		$this->assertFalse( \Woodev_Helper::shop_has_virtual_products() );
	}

	/**
	 * Early-hook diagnostics should use WordPress doing_it_wrong() without WooCommerce.
	 *
	 * @return void
	 */
	public function test_maybe_doing_it_early_uses_wordpress_doing_it_wrong(): void {
		Functions\expect( 'did_action' )
			->once()
			->with( 'init' )
			->andReturn( 0 );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with(
				'Woodev_Helper::maybe_doing_it_early',
				"This should only be called after 'init'",
				'2.0.0'
			);

		\Woodev_Helper::maybe_doing_it_early( 'init', 'Woodev_Helper::maybe_doing_it_early', '2.0.0' );
	}

	/**
	 * Early-hook diagnostics should no-op once the hook has fired.
	 *
	 * @return void
	 */
	public function test_maybe_doing_it_early_is_noop_after_hook_runs(): void {
		Functions\expect( 'did_action' )
			->once()
			->with( 'init' )
			->andReturn( 1 );

		Functions\expect( '_doing_it_wrong' )
			->never();

		\Woodev_Helper::maybe_doing_it_early( 'init', 'Woodev_Helper::maybe_doing_it_early', '2.0.0' );
	}
}
