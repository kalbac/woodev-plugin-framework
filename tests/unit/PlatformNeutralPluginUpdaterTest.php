<?php
/**
 * Platform-neutral plugin updater helper regression tests.
 *
 * @package Woodev\Framework\Tests
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

/**
 * Minimal Woodev plugin test double for beta opt-in helper checks.
 */
class Testable_Platform_Neutral_Updater_Plugin extends \Woodev_Plugin {

	/**
	 * Avoid parent construction for isolated helper tests.
	 */
	public function __construct() {
		$property = new \ReflectionProperty( \Woodev_Plugin::class, 'id' );
		$property->setValue( $this, 'platform-neutral-updater' );
	}

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file() {
		return __FILE__;
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Platform Neutral Updater Test Plugin';
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
 * Class PlatformNeutralPluginUpdaterTest.
 */
class PlatformNeutralPluginUpdaterTest extends TestCase {

	/**
	 * Beta opt-in should keep the installed-site yes/no option contract without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_beta_opt_in_keeps_yes_no_contract_without_woocommerce_helpers(): void {
		$plugin       = new Testable_Platform_Neutral_Updater_Plugin();
		$stored_value = 'yes';

		Functions\when( 'get_option' )->alias(
			static function ( string $option_name, string $default = '' ) use ( &$stored_value, $plugin ): string {
				if ( $plugin->get_plugin_option_name( 'beta_version' ) !== $option_name ) {
					return $default;
				}

				return null === $stored_value ? $default : $stored_value;
			}
		);

		$this->assertTrue( $plugin->is_beta_allowed() );

		$stored_value = 'no';
		$this->assertFalse( $plugin->is_beta_allowed() );

		$stored_value = null;
		$this->assertFalse( $plugin->is_beta_allowed() );
	}
}
