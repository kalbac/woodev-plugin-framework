<?php
/**
 * Tests for Woodev_Setting::get_validation_error() — required, format, range.
 *
 * @package Woodev\Tests\Unit\SettingsApi
 */

namespace Woodev\Tests\Unit\SettingsApi;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';

/**
 * @covers \Woodev_Setting::get_validation_error
 */
class SettingValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return is_string( $email ) && (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);
	}

	/**
	 * Builds a setting with a control of the given type.
	 *
	 * @param string $type         setting type.
	 * @param string $control_type control type.
	 * @param bool   $required     required flag.
	 * @param array  $range        optional [min,max].
	 * @return \Woodev_Setting
	 */
	private function make( string $type, string $control_type, bool $required = false, array $range = [] ): \Woodev_Setting {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'f' );
		$setting->set_type( $type );
		$setting->set_required( $required );

		$control = new \Woodev_Control();
		$control->set_type( $control_type );
		if ( isset( $range[0] ) ) {
			$control->set_min( $range[0] );
		}
		if ( isset( $range[1] ) ) {
			$control->set_max( $range[1] );
		}
		$setting->set_control( $control );

		return $setting;
	}

	public function test_required_empty_returns_message(): void {
		$setting = $this->make( 'string', 'text', true );
		$this->assertSame( 'Обязательное поле.', $setting->get_validation_error( '' ) );
		$this->assertSame( 'Обязательное поле.', $setting->get_validation_error( '   ' ) );
	}

	public function test_optional_empty_is_valid(): void {
		$setting = $this->make( 'string', 'text', false );
		$this->assertNull( $setting->get_validation_error( '' ) );
	}

	public function test_required_is_noop_for_toggle_and_range(): void {
		$this->assertNull( $this->make( 'boolean', 'toggle', true )->get_validation_error( false ) );
		$this->assertNull( $this->make( 'integer', 'range', true )->get_validation_error( 0 ) );
	}

	public function test_email_format(): void {
		$setting = $this->make( 'email', 'email' );
		$this->assertNull( $setting->get_validation_error( 'a@b.com' ) );
		$this->assertSame( 'Введите корректный email.', $setting->get_validation_error( 'nope' ) );
	}

	public function test_url_format(): void {
		$setting = $this->make( 'string', 'url' );
		$this->assertNull( $setting->get_validation_error( 'https://woodev.ru' ) );
		$this->assertSame(
			'Введите корректный URL (с http:// или https://).',
			$setting->get_validation_error( 'woodev.ru' )
		);
	}

	public function test_tel_format(): void {
		$setting = $this->make( 'string', 'tel' );
		$this->assertNull( $setting->get_validation_error( '+7 (999) 123-45-67' ) );
		$this->assertSame( 'Введите корректный номер телефона.', $setting->get_validation_error( 'abc' ) );
		$this->assertSame( 'Введите корректный номер телефона.', $setting->get_validation_error( '12' ) );
	}

	public function test_number_range(): void {
		$setting = $this->make( 'integer', 'number', false, [ 0, 100 ] );
		$this->assertNull( $setting->get_validation_error( '50' ) );
		$this->assertSame( 'Значение не меньше 0.', $setting->get_validation_error( '-1' ) );
		$this->assertSame( 'Значение не больше 100.', $setting->get_validation_error( '101' ) );
		$this->assertSame( 'Введите число.', $setting->get_validation_error( 'x' ) );
	}

	public function test_required_null_input(): void {
		$setting = $this->make( 'string', 'text', true );
		$this->assertSame( 'Обязательное поле.', $setting->get_validation_error( null ) );
	}

	public function test_enum_rejection(): void {
		$setting = $this->make( 'string', 'text' );
		$setting->set_options( [ 'a', 'b' ] );
		$this->assertStringStartsWith( 'Значение должно быть одним из:', (string) $setting->get_validation_error( 'c' ) );
	}

	public function test_checkbox_required_is_noop(): void {
		$setting = $this->make( 'boolean', 'checkbox', true );
		$this->assertNull( $setting->get_validation_error( false ) );
	}

	public function test_select_zero_is_not_empty(): void {
		$setting = $this->make( 'string', 'select', true );
		$this->assertNull( $setting->get_validation_error( '0' ) );
	}

	public function test_tel_digit_boundary(): void {
		$setting = $this->make( 'string', 'tel' );
		$this->assertSame( 'Введите корректный номер телефона.', $setting->get_validation_error( '1234' ) );
		$this->assertNull( $setting->get_validation_error( '12345' ) );
	}

	public function test_no_control_type_skips_format(): void {
		$optional = new \Woodev_Setting();
		$optional->set_id( 'f' );
		$optional->set_type( 'string' );
		$optional->set_required( false );
		$this->assertNull( $optional->get_validation_error( 'anything' ) );

		$required = new \Woodev_Setting();
		$required->set_id( 'f' );
		$required->set_type( 'string' );
		$required->set_required( true );
		$this->assertSame( 'Обязательное поле.', $required->get_validation_error( '' ) );
	}

	public function test_handler_validate_values_collects_field_errors(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_merge( $defaults, $args );
			}
		);

		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

		$handler = new class( 'test' ) extends \Woodev_Abstract_Settings {
			protected function register_settings() {
				$this->register_setting( 'email', 'email', [ 'required' => true ] );
				$this->register_control( 'email', 'email' );
				$this->register_setting( 'name', 'string', [] );
				$this->register_control( 'name', 'text' );
			}
		};

		$errors = $handler->validate_values( [ 'email' => 'nope', 'name' => 'ok' ] );

		$this->assertSame( [ 'email' => 'Введите корректный email.' ], $errors );
	}

	public function test_validate_values_skips_unknown_id(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_merge( $defaults, $args );
			}
		);

		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

		$handler = new class( 'test' ) extends \Woodev_Abstract_Settings {
			protected function register_settings() {
				$this->register_setting( 'name', 'string', [] );
				$this->register_control( 'name', 'text' );
			}
		};

		$errors = $handler->validate_values( [ 'ghost' => 'whatever', 'name' => 'ok' ] );

		$this->assertArrayNotHasKey( 'ghost', $errors );
		$this->assertSame( [], $errors );
	}

	public function test_validate_values_skips_defined_constant_setting(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_merge( $defaults, $args );
			}
		);

		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

		define( 'WOODEV_TEST_VALIDATE_CONST_EMAIL', 'managed@example.com' );

		$handler = new class( 'test' ) extends \Woodev_Abstract_Settings {
			protected function register_settings() {
				$this->register_setting( 'managed_email', 'email', [ 'required' => true, 'constant_name' => 'WOODEV_TEST_VALIDATE_CONST_EMAIL' ] );
				$this->register_control( 'managed_email', 'email' );
			}
		};

		// Submit a bad value: it must be ignored because the setting is code-managed.
		$errors = $handler->validate_values( [ 'managed_email' => 'nope' ] );

		$this->assertArrayNotHasKey( 'managed_email', $errors );
		$this->assertSame( [], $errors );
	}

	public function test_validate_values_accepts_valid_multi_values(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_merge( $defaults, $args );
			}
		);

		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

		$handler = new class( 'test' ) extends \Woodev_Abstract_Settings {
			protected function register_settings() {
				$this->register_setting( 'emails', 'email', [ 'is_multi' => true ] );
				$this->register_control( 'emails', 'email' );
			}
		};

		// All elements valid → no false email error.
		$this->assertSame( [], $handler->validate_values( [ 'emails' => [ 'a@b.com', 'c@d.com' ] ] ) );

		// A bad element surfaces the per-field error.
		$this->assertSame(
			[ 'emails' => 'Введите корректный email.' ],
			$handler->validate_values( [ 'emails' => [ 'a@b.com', 'nope' ] ] )
		);
	}
}
