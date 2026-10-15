<?php
/**
 * Woodev_Plugin::get_assets_suffix() — the single `.min` / unminified switch (card #247).
 *
 * `SCRIPT_DEBUG` cannot be un-defined once set, so each constant state runs in its own process
 * (same discipline as AdminNoticeHandlerContractTest's WP_DEBUG case). The default state — no
 * constant at all — is what every other unit test in the suite runs under.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

/**
 * @covers \Woodev_Plugin::get_assets_suffix
 */
class AssetsSuffixTest extends TestCase {

	/**
	 * Without SCRIPT_DEBUG a shop loads the minified siblings.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_it_is_min_when_script_debug_is_not_defined(): void {
		$this->assertFalse( defined( 'SCRIPT_DEBUG' ) );
		$this->assertSame( '.min', \Woodev_Plugin::get_assets_suffix() );
	}

	/**
	 * SCRIPT_DEBUG on selects the readable sources.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_it_is_empty_when_script_debug_is_true(): void {
		define( 'SCRIPT_DEBUG', true );

		$this->assertSame( '', \Woodev_Plugin::get_assets_suffix() );
	}

	/**
	 * A constant defined as false is the same as no constant.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_it_is_min_when_script_debug_is_false(): void {
		define( 'SCRIPT_DEBUG', false );

		$this->assertSame( '.min', \Woodev_Plugin::get_assets_suffix() );
	}

	/**
	 * WP_DEBUG alone does NOT select the readable file — only SCRIPT_DEBUG does, like WooCommerce.
	 * (`get_assets_version()` keeps its own wider test; that is a cache-bust, not a file swap.)
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_debug_alone_keeps_the_minified_files(): void {
		define( 'WP_DEBUG', true );

		$this->assertFalse( defined( 'SCRIPT_DEBUG' ) );
		$this->assertSame( '.min', \Woodev_Plugin::get_assets_suffix() );
	}
}
