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
