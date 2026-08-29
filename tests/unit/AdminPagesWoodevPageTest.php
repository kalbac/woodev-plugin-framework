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

	/**
	 * Overrides the class-wide identity stub with real `sanitize_key()`
	 * semantics (lowercase; strip everything but `[a-z0-9_-]`) so a mutant
	 * that re-adds the `sanitize_key()` call in `is_woodev_page()` is
	 * actually exercised — the default identity stub from `setUp()` would
	 * silently absorb that mutant otherwise, since it never transforms the
	 * value either way.
	 */
	private function stub_real_sanitize_key(): void {
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				$key = strtolower( (string) $key );

				return preg_replace( '/[^a-z0-9_\-]/', '', $key );
			}
		);
	}

	public function test_false_for_a_slug_differing_only_in_case(): void {
		$this->stub_real_sanitize_key();

		// Collides with a REGISTERED submenu slug only after lowercasing —
		// exactly the false-positive direction the critic flagged as the
		// expensive one, since this notice is non-dismissible.
		$GLOBALS['submenu']['woodev'] = [
			[ 'Settings', 'manage_options', 'woodev-settings' ],
		];

		$_GET['page'] = 'Woodev-Settings';

		$this->assertFalse( \Woodev_Admin_Pages::is_woodev_page(), 'sanitize_key() would lowercase this into a false-positive match against a differently-cased page (critic finding, PR #661)' );
	}

	public function test_true_for_a_registered_submenu_slug_containing_slash_and_dot(): void {
		$this->stub_real_sanitize_key();

		$GLOBALS['submenu']['woodev'] = [
			[ 'Orders', 'manage_options', 'edit.php?post_type=shop_order' ],
		];

		$_GET['page'] = 'edit.php?post_type=shop_order';

		$this->assertTrue( \Woodev_Admin_Pages::is_woodev_page(), 'sanitize_key() strips "/", "?", "=" and "." and would turn this into a false negative (critic finding, PR #661)' );
	}
}
