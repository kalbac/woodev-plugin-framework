<?php
/**
 * OB-3 safe-subset regression tests (s15).
 *
 * Covers the three no-contract-impact findings from the OB-3 updater review
 * (2026-06-14, s14):
 *   - F11: tested-shape guard (single-segment version → undefined-index warning)
 *   - F12: visibility hardening (init, get_cached_version_info, set_version_info_cache → private)
 *   - F13: esc_attr() on slug + file in the multisite update-row printf
 *
 * @package Woodev\Framework\Tests
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/plugin-updater/class-plugin-updater.php';

/**
 * Class UpdaterSafeSubsetTest.
 */
class UpdaterSafeSubsetTest extends TestCase {

	// ── F11: tested-guard ─────────────────────────────────────────────────────

	/**
	 * A single-segment tested value (e.g. '6') with the same major as the current
	 * WP version must NOT trigger "Undefined array key 1" — without the guard,
	 * $tested_parts[1] is accessed on a 1-element array when the major matches.
	 *
	 * PHPUnit failOnWarning=true makes this test RED on PHP 8 without the fix.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_single_segment_tested_version_same_major_returns_original(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.5.3' );

		$version_info         = new \stdClass();
		$version_info->tested = '6';

		$updater = $this->make_minimal_updater();
		$result  = $this->call_method( $updater, 'get_tested_version', $version_info );

		$this->assertSame( '6', $result );
	}

	/**
	 * Two-segment tested='6.5' with WP='6.5.3' must still get the patch fixup.
	 * Regression guard: F11 guard must not break the existing fixup path.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_two_segment_tested_version_gets_wp_patch_fixup(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.5.3' );

		$version_info         = new \stdClass();
		$version_info->tested = '6.5';

		$updater = $this->make_minimal_updater();
		$result  = $this->call_method( $updater, 'get_tested_version', $version_info );

		$this->assertSame( '6.5.3', $result );
	}

	/**
	 * Tested version already >= current WP must be returned unchanged (no fixup).
	 * Regression guard: the early-return path must still work after the guard.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_tested_version_gte_wp_returned_unchanged(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.4.1' );

		$version_info         = new \stdClass();
		$version_info->tested = '6.5';

		$updater = $this->make_minimal_updater();
		$result  = $this->call_method( $updater, 'get_tested_version', $version_info );

		$this->assertSame( '6.5', $result );
	}

	// ── F12: visibility hardening ─────────────────────────────────────────────

	/**
	 * init() is only called from __construct() — it must be private.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_init_method_is_private(): void {
		$r = new \ReflectionMethod( \Woodev_Plugin_Updater::class, 'init' );
		$this->assertTrue(
			$r->isPrivate(),
			'init() must be private — it is only ever called from __construct().'
		);
	}

	/**
	 * get_cached_version_info() has no external callers — must be private.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_get_cached_version_info_is_private(): void {
		$r = new \ReflectionMethod( \Woodev_Plugin_Updater::class, 'get_cached_version_info' );
		$this->assertTrue(
			$r->isPrivate(),
			'get_cached_version_info() has no external callers and must be private.'
		);
	}

	/**
	 * set_version_info_cache() has no external callers — must be private.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_set_version_info_cache_is_private(): void {
		$r = new \ReflectionMethod( \Woodev_Plugin_Updater::class, 'set_version_info_cache' );
		$this->assertTrue(
			$r->isPrivate(),
			'set_version_info_cache() has no external callers and must be private.'
		);
	}

	// ── F13: esc_attr ─────────────────────────────────────────────────────────

	/**
	 * The update-row printf must wrap $this->slug in esc_attr() for the id / data-slug attrs.
	 *
	 * Source assertion — fails RED when the printf is missing esc_attr(), passes GREEN after.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_update_row_printf_escapes_slug_with_esc_attr(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/plugin-updater/class-plugin-updater.php'
		);
		$this->assertStringContainsString(
			'esc_attr( $this->slug )',
			$source,
			'F13: $this->slug in show_update_notification() printf must be wrapped in esc_attr().'
		);
	}

	/**
	 * The update-row printf must wrap $file in esc_attr() for the data-plugin attr.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_update_row_printf_escapes_file_with_esc_attr(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/plugin-updater/class-plugin-updater.php'
		);
		$this->assertStringContainsString(
			'esc_attr( $file )',
			$source,
			'F13: $file in show_update_notification() printf must be wrapped in esc_attr().'
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Builds an updater without the constructor, needing no properties beyond
	 * what get_tested_version() reads (none — it only reads its own arg + get_bloginfo).
	 *
	 * @since 2.0.2
	 *
	 * @return \Woodev_Plugin_Updater
	 */
	private function make_minimal_updater(): \Woodev_Plugin_Updater {
		return ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Calls a private/protected method via reflection, forwarding any arguments.
	 *
	 * @since 2.0.2
	 *
	 * @param object $object Target object.
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments to forward.
	 * @return mixed
	 */
	private function call_method( object $object, string $method, ...$args ) {
		$r = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$r->setAccessible( true );
		}
		return $r->invoke( $object, ...$args );
	}
}
