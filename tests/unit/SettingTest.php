<?php
/**
 * Woodev_Setting value-object tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';

/**
 * Class SettingTest.
 */
class SettingTest extends TestCase {

	/**
	 * The sensitive flag defaults to false and round-trips through its setter.
	 *
	 * @return void
	 */
	public function test_sensitive_flag_defaults_false_and_roundtrips() {
		$setting = new \Woodev_Setting();
		$this->assertFalse( $setting->is_sensitive() );
		$setting->set_sensitive( true );
		$this->assertTrue( $setting->is_sensitive() );
	}

	/**
	 * The constant name defaults to null and round-trips through its setter.
	 *
	 * @return void
	 */
	public function test_constant_name_defaults_null_and_roundtrips() {
		$setting = new \Woodev_Setting();
		$this->assertNull( $setting->get_constant_name() );
		$setting->set_constant_name( 'MY_CARRIER_KEY' );
		$this->assertSame( 'MY_CARRIER_KEY', $setting->get_constant_name() );
	}

	/**
	 * A defined backing constant takes precedence over the stored DB value.
	 *
	 * @return void
	 */
	public function test_get_value_prefers_a_defined_constant_over_stored() {
		if ( ! defined( 'WOODEV_TEST_SECRET_CONST' ) ) {
			define( 'WOODEV_TEST_SECRET_CONST', 'from-config' );
		}
		$setting = new \Woodev_Setting();
		$setting->set_id( 'api_key' );
		$setting->set_type( \Woodev_Setting::TYPE_STRING );
		$setting->set_value( 'from-db' );
		$setting->set_constant_name( 'WOODEV_TEST_SECRET_CONST' );
		$this->assertSame( 'from-config', $setting->get_value() );
	}

	/**
	 * The required flag defaults to false and round-trips through its setter.
	 *
	 * @return void
	 */
	public function test_required_flag_defaults_false_and_roundtrips() {
		$setting = new \Woodev_Setting();
		$this->assertFalse( $setting->is_required() );
		$setting->set_required( true );
		$this->assertTrue( $setting->is_required() );
	}

	/**
	 * The stored DB value is returned when the backing constant is undefined.
	 *
	 * @return void
	 */
	public function test_get_value_returns_stored_when_constant_undefined() {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'api_key' );
		$setting->set_type( \Woodev_Setting::TYPE_STRING );
		$setting->set_value( 'from-db' );
		$setting->set_constant_name( 'WOODEV_UNDEFINED_CONST_XYZ' );
		$this->assertSame( 'from-db', $setting->get_value() );
	}
}
