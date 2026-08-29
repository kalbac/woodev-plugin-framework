<?php
/**
 * Unit tests for `Woodev_Admin_Pages::is_woodev_page()` (#410).
 *
 * The predicate backs the page-scoping gate for the "fixed default locality is
 * stale" admin notice ({@see \Woodev\Framework\Shipping\Shipping_Plugin::add_default_locality_stale_notice()}):
 * the operator's deliberate middle-loudness choice was Woodev admin pages only,
 * never every wp-admin screen. It derives the answer from the LIVE menu
 * registry (`$GLOBALS['submenu']`) rather than a hardcoded slug list, so this
 * suite exercises the top-level slug, a registered submenu slug, an
 * unregistered slug, and the no-`page`-param case.
 *
 * `is_woodev_page()` is `static` and touches no instance state, so it is
 * called directly — no `newInstanceWithoutConstructor()` needed.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-admin-pages.php';

/**
 * @covers \Woodev_Admin_Pages::is_woodev_page
 */
final class AdminPagesWoodevPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );

		unset( $_GET['page'], $GLOBALS['submenu'] );
	}

	protected function tearDown(): void {
		unset( $_GET['page'], $GLOBALS['submenu'] );

		parent::tearDown();
	}

	public function test_true_for_the_top_level_woodev_page(): void {
		$_GET['page'] = 'woodev';

		$this->assertTrue( \Woodev_Admin_Pages::is_woodev_page() );
	}

	public function test_true_for_a_registered_woodev_submenu_page(): void {
		$GLOBALS['submenu']['woodev'] = [
			[ 'Licenses', 'manage_options', 'woodev-licenses' ],
			[ 'Extensions', 'manage_options', 'woodev-extensions' ],
		];

		$_GET['page'] = 'woodev-licenses';

		$this->assertTrue( \Woodev_Admin_Pages::is_woodev_page(), 'mutant: dropping the submenu loop entirely' );
	}

	public function test_false_for_an_unregistered_page_slug(): void {
		$GLOBALS['submenu']['woodev'] = [
			[ 'Licenses', 'manage_options', 'woodev-licenses' ],
		];

		$_GET['page'] = 'wc-settings';

		$this->assertFalse( \Woodev_Admin_Pages::is_woodev_page(), 'mutant: always returning true once a submenu array exists' );
	}

	public function test_false_when_no_page_param_is_present_at_all(): void {
		$this->assertFalse( \Woodev_Admin_Pages::is_woodev_page(), 'mutant: treating an empty/missing page as a match' );
	}
}
