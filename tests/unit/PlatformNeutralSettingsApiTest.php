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
 * Settings handler that registers a boolean multi-value setting for load tests.
 */
class Boolean_Multi_Load_Settings extends \Woodev_Abstract_Settings {

	/**
	 * Registers the legacy-compatible boolean multi-value setting.
	 *
	 * @return void
	 */
	protected function register_settings() {
		$this->register_setting(
			'flags',
			\Woodev_Setting::TYPE_BOOLEAN,
			[
				'is_multi' => true,
			]
		);
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
	 * Boolean multi-value settings must preserve the installed-site yes/no shape
	 * for every element when saved.
	 *
	 * @return void
	 */
	public function test_boolean_multi_setting_persists_every_value_to_yes_no_contract(): void {
		$settings = new Testable_Platform_Neutral_Settings( 'test-plugin' );
		$setting  = new \Woodev_Setting();

		$setting->set_type( \Woodev_Setting::TYPE_BOOLEAN );
		$setting->set_is_multi( true );
		$setting->set_value( [ true, false ] );

		$this->assertSame( [ 'yes', 'no' ], $settings->convert_for_database( $setting ) );
	}

	/**
	 * A previously stored boolean multi-value setting must load without a type
	 * error and retain one native boolean for every stored element.
	 *
	 * @return void
	 */
	public function test_boolean_multi_setting_loads_stored_values(): void {
		Functions\when( 'get_option' )->justReturn( [ 'yes', 'no' ] );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		$settings = new Boolean_Multi_Load_Settings( 'test-plugin' );

		$this->assertSame( [ true, false ], $settings->get_value( 'flags' ) );
	}

	/**
	 * Numeric multi-value settings must receive the same element-wise database
	 * conversion instead of discarding the complete stored array.
	 *
	 * @return void
	 */
	public function test_integer_multi_setting_restores_each_stored_value(): void {
		$settings = new Testable_Platform_Neutral_Settings( 'test-plugin' );
		$setting  = new \Woodev_Setting();

		$setting->set_type( \Woodev_Setting::TYPE_INTEGER );
		$setting->set_is_multi( true );

		$this->assertSame( [ 7, 42 ], $settings->convert_from_database( [ '7', '42' ], $setting ) );
	}

	/**
	 * Malformed object data for a boolean setting must not reach strtolower().
	 *
	 * @return void
	 */
	public function test_boolean_setting_treats_stored_object_as_false(): void {
		$settings = new Testable_Platform_Neutral_Settings( 'test-plugin' );
		$setting  = new \Woodev_Setting();

		$setting->set_type( \Woodev_Setting::TYPE_BOOLEAN );

		$this->assertFalse( $settings->convert_from_database( new \stdClass(), $setting ) );
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
