<?php
/**
 * Platform-neutral settings API helper tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';

/**
 * Test settings API implementation exposing protected conversion helpers.
 */
class Testable_Platform_Neutral_Settings extends \Woodev_Abstract_Settings {

	/**
	 * Registers no settings for the test double.
	 *
	 * @return void
	 */
	protected function register_settings() {
	}

	/**
	 * Converts a setting value to its database representation.
	 *
	 * @param \Woodev_Setting $setting Setting to convert.
	 * @return mixed
	 */
	public function convert_for_database( \Woodev_Setting $setting ) {
		return $this->get_value_for_database( $setting );
	}

	/**
	 * Converts a stored value back to its runtime representation.
	 *
	 * @param mixed            $value Stored value.
	 * @param \Woodev_Setting  $setting Setting metadata.
	 * @return mixed
	 */
	public function convert_from_database( $value, \Woodev_Setting $setting ) {
		return $this->get_value_from_database( $value, $setting );
	}
}

/**
 * Class PlatformNeutralSettingsApiTest.
 */
class PlatformNeutralSettingsApiTest extends TestCase {

	/**
	 * Boolean settings should persist to the installed-site yes/no contract without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_boolean_setting_persists_to_yes_no_contract(): void {
		$settings = new Testable_Platform_Neutral_Settings( 'test-plugin' );
		$setting  = new \Woodev_Setting();

		$setting->set_type( \Woodev_Setting::TYPE_BOOLEAN );
		$setting->set_value( true );

		$this->assertSame( 'yes', $settings->convert_for_database( $setting ) );

		$setting->set_value( false );

		$this->assertSame( 'no', $settings->convert_for_database( $setting ) );
	}

	/**
	 * Boolean settings should restore WooCommerce-compatible truthy and falsy values.
	 *
	 * @return void
	 */
	public function test_boolean_setting_restores_woocommerce_compatible_values(): void {
		$settings = new Testable_Platform_Neutral_Settings( 'test-plugin' );
		$setting  = new \Woodev_Setting();

		$setting->set_type( \Woodev_Setting::TYPE_BOOLEAN );

		$this->assertTrue( $settings->convert_from_database( 'yes', $setting ) );
		$this->assertTrue( $settings->convert_from_database( 'true', $setting ) );
		$this->assertTrue( $settings->convert_from_database( '1', $setting ) );
		$this->assertFalse( $settings->convert_from_database( 'no', $setting ) );
		$this->assertFalse( $settings->convert_from_database( 'false', $setting ) );
		$this->assertFalse( $settings->convert_from_database( '0', $setting ) );
		$this->assertNull( $settings->convert_from_database( null, $setting ) );
	}

	/**
	 * URL settings should keep the previous http/https-only validation contract without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_url_setting_validation_keeps_http_https_contract(): void {
		$setting = new \Woodev_Setting();

		$setting->set_type( \Woodev_Setting::TYPE_URL );

		$this->assertTrue( $setting->validate_value( 'http://example.com' ) );
		$this->assertTrue( $setting->validate_value( 'https://example.com/path?foo=bar' ) );
		$this->assertFalse( $setting->validate_value( 'ftp://example.com' ) );
		$this->assertFalse( $setting->validate_value( 'example.com' ) );
	}

	/**
	 * Invalid setting registrations should use WordPress doing_it_wrong() without WooCommerce.
	 *
	 * @return void
	 */
	public function test_register_setting_error_path_uses_wordpress_doing_it_wrong(): void {
		$settings = new Testable_Platform_Neutral_Settings( 'test-plugin' );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with(
				'Woodev_Abstract_Settings::register_setting',
				'Could not register setting: invalid-type is not a valid setting type',
				'1.1.2'
			);

		$this->assertFalse( $settings->register_setting( 'example', 'invalid-type' ) );
	}

	/**
	 * Invalid setting control registrations should use WordPress doing_it_wrong() without WooCommerce.
	 *
	 * @return void
	 */
	public function test_register_control_error_path_uses_wordpress_doing_it_wrong(): void {
		$settings = new Testable_Platform_Neutral_Settings( 'test-plugin' );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with(
				'Woodev_Abstract_Settings::register_control',
				'Could not register setting control: invalid-control is not a valid control type',
				'1.1.2'
			);

		$this->assertFalse( $settings->register_control( 'missing-setting', 'invalid-control' ) );
	}
}
