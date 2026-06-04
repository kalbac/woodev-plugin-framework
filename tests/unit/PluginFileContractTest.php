<?php
/**
 * Plugin file contract tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

/**
 * Test plugin with a main file that intentionally differs from its directory slug.
 */
class Testable_Plugin_File_Contract_Plugin extends \Woodev_Plugin {

	/**
	 * Returns the plugin main file.
	 *
	 * @return string
	 */
	protected function get_file() {
		return '/var/www/html/wp-content/plugins/custom-plugin/custom-main.php';
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Custom Plugin';
	}

	/**
	 * Gets the download ID.
	 *
	 * @return int
	 */
	public function get_download_id() {
		return 0;
	}
}

/**
 * Class PluginFileContractTest.
 */
class PluginFileContractTest extends TestCase {

	/**
	 * get_plugin_file() should preserve the actual installed main-file basename.
	 *
	 * @return void
	 */
	public function test_get_plugin_file_returns_actual_main_file_basename(): void {
		Functions\when( 'plugin_basename' )->alias(
			static function ( string $file ): string {
				return str_replace( '/var/www/html/wp-content/plugins/', '', $file );
			}
		);

		$reflection = new \ReflectionClass( Testable_Plugin_File_Contract_Plugin::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();

		$this->assertSame( 'custom-plugin/custom-main.php', $plugin->get_plugin_file() );
	}
}
