<?php
/**
 * OB-3 Step 4 (F8) consumer tests (s18).
 *
 * Woodev_Plugins_License::plugin_row_license_missing() is the in-repo consumer of
 * the in_plugin_update_message-{$file} hook. Per the WP contract the downloadable
 * `package` and `new_version` live on the RESPONSE object (2nd arg), NOT on the
 * plugin-header array (1st arg). The consumer used to read `package` off the 1st
 * arg, so its "backup before updating" notice never rendered. These tests pin the
 * corrected behavior: everything is read from the 2nd (response) argument.
 *
 * @package Woodev\Framework\Tests
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';

/**
 * Class PluginLicenseUpdateRowTest.
 */
class PluginLicenseUpdateRowTest extends TestCase {

	/**
	 * When the RESPONSE object (2nd arg) carries a package + a new_version whose
	 * minor differs from the installed version, the upgrade notice renders — even
	 * though the plugin-data array (1st arg) carries no `package`.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_renders_upgrade_notice_from_response_object(): void {
		Functions\when( 'wp_kses_post' )->returnArg();

		$engine = $this->make_engine( '8.5.0' );

		$response              = new \stdClass();
		$response->package     = 'https://woodev.ru/x.zip';
		$response->new_version = '9.0.0';

		ob_start();
		$engine->plugin_row_license_missing( array(), $response );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'Backup your site before updating', $out );
	}

	/**
	 * No `package` on the response → no notice (early return). Proves the gate now
	 * reads `package` from the response, not from the plugin-data array.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_no_notice_when_response_has_no_package(): void {
		Functions\when( 'wp_kses_post' )->returnArg();

		$engine = $this->make_engine( '8.5.0' );

		// Package lives ONLY on the 1st arg (old bug shape) — must be ignored now.
		$plugin_data            = array( 'package' => 'https://woodev.ru/x.zip' );
		$response               = new \stdClass();
		$response->new_version  = '9.0.0';

		ob_start();
		$engine->plugin_row_license_missing( $plugin_data, $response );
		$out = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Backup your site before updating', $out );
	}

	/**
	 * Same minor version → the minor-skip guard suppresses the notice.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_no_notice_when_new_version_same_minor(): void {
		Functions\when( 'wp_kses_post' )->returnArg();

		$engine = $this->make_engine( '8.5.0' );

		$response              = new \stdClass();
		$response->package     = 'https://woodev.ru/x.zip';
		$response->new_version = '8.5.7';

		ob_start();
		$engine->plugin_row_license_missing( array(), $response );
		$out = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Backup your site before updating', $out );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Builds a license engine without its constructor, wired with a plugin mock.
	 *
	 * @since 2.0.2
	 *
	 * @param string $installed_version Installed plugin version.
	 * @return \Woodev_Plugins_License
	 */
	private function make_engine( string $installed_version ): \Woodev_Plugins_License {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );
		$plugin->shouldReceive( 'get_version' )->andReturn( $installed_version );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test Plugin' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );

		$engine = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();

		$p = new \ReflectionProperty( $engine, 'plugin' );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		$p->setValue( $engine, $plugin );

		return $engine;
	}
}
