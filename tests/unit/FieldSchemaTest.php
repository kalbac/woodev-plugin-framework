<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\Field_Schema;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-field-schema.php';

class FieldSchemaTest extends TestCase {

	private function make_setting( string $id, string $type, $control ) {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( $id );
		$setting->shouldReceive( 'get_type' )->andReturn( $type );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Имя ' . $id );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_description' )->andReturn( 'desc ' . $id );
		$setting->shouldReceive( 'get_control' )->andReturn( $control );
		$setting->shouldReceive( 'is_sensitive' )->andReturn( false );
		$setting->shouldReceive( 'get_constant_name' )->andReturn( null );

		return $setting;
	}

	/**
	 * Builds an anonymous settings handler whose register_settings() runs the
	 * supplied closure, so settings can be registered and have values set
	 * purely in memory (no WordPress DB).
	 *
	 * @param callable $register closure receiving the handler to register settings on.
	 * @return \Woodev_Abstract_Settings
	 */
	private function make_handler( callable $register ): \Woodev_Abstract_Settings {

		// Stub the WP plumbing the abstract handler touches during construction.
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		return new class( 'test-plugin', $register ) extends \Woodev_Abstract_Settings {

			/** @var callable */
			private $register_cb;

			/**
			 * @param string   $id       handler ID.
			 * @param callable $register closure registering settings.
			 */
			public function __construct( string $id, callable $register ) {
				$this->register_cb = $register;
				parent::__construct( $id );
			}

			/**
			 * Runs the supplied registration closure.
			 *
			 * @return void
			 */
			protected function register_settings() {
				( $this->register_cb )( $this );
			}

			/**
			 * No-op save() — tests operate purely in memory.
			 *
			 * @param string $setting_id setting ID.
			 * @return void
			 */
			public function save( $setting_id = '' ) {}
		};
	}

	public function test_builds_entry_with_control_metadata(): void {
		$control = Mockery::mock();
		$control->shouldReceive( 'get_type' )->andReturn( 'range' );
		$control->shouldReceive( 'get_description' )->andReturn( 'control desc' );
		$control->shouldReceive( 'get_tooltip' )->andReturn( 'tip' );
		$control->shouldReceive( 'get_min' )->andReturn( 1.0 );
		$control->shouldReceive( 'get_max' )->andReturn( 10.0 );
		$control->shouldReceive( 'get_step' )->andReturn( 0.5 );

		$setting = $this->make_setting( 'weight', 'integer', $control );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_settings' )->with( [ 'weight' ] )->andReturn( [ 'weight' => $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'weight' )->andReturn( 5 );

		$schema = Field_Schema::from_handler( $handler, [ 'weight' ] );

		$this->assertArrayHasKey( 'weight', $schema );
		$this->assertSame( 'range', $schema['weight']['controlType'] );
		$this->assertSame( 'control desc', $schema['weight']['description'] );
		$this->assertSame( 'tip', $schema['weight']['tooltip'] );
		$this->assertSame( 5, $schema['weight']['value'] );
		$this->assertSame( 1.0, $schema['weight']['min'] );
		$this->assertSame( 10.0, $schema['weight']['max'] );
		$this->assertSame( 0.5, $schema['weight']['step'] );
	}

	public function test_omits_range_bounds_when_control_returns_null(): void {
		$control = Mockery::mock();
		$control->shouldReceive( 'get_type' )->andReturn( 'text' );
		$control->shouldReceive( 'get_description' )->andReturn( '' );
		$control->shouldReceive( 'get_tooltip' )->andReturn( '' );
		$control->shouldReceive( 'get_min' )->andReturn( null );
		$control->shouldReceive( 'get_max' )->andReturn( null );
		$control->shouldReceive( 'get_step' )->andReturn( null );

		$setting = $this->make_setting( 'api_key', 'string', $control );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_settings' )->with( [] )->andReturn( [ 'api_key' => $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'api_key' )->andReturn( 'k' );

		$schema = Field_Schema::from_handler( $handler );

		$this->assertArrayNotHasKey( 'min', $schema['api_key'] );
		$this->assertArrayNotHasKey( 'max', $schema['api_key'] );
		$this->assertArrayNotHasKey( 'step', $schema['api_key'] );
		// Control description empty → falls back to setting description.
		$this->assertSame( 'desc api_key', $schema['api_key']['description'] );
	}

	public function test_handles_missing_control(): void {
		$setting = $this->make_setting( 'plain', 'string', null );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_settings' )->with( [] )->andReturn( [ 'plain' => $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'plain' )->andReturn( 'v' );

		$schema = Field_Schema::from_handler( $handler );

		$this->assertNull( $schema['plain']['controlType'] );
		$this->assertSame( 'desc plain', $schema['plain']['description'] );
	}

	/**
	 * A sensitive field must be masked but still report it has a stored value.
	 *
	 * @return void
	 */
	public function test_sensitive_field_is_masked_with_is_set_flag(): void {
		$handler = $this->make_handler(
			static function ( $h ): void {
				$h->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Токен', 'sensitive' => true ] );
			}
		);

		// Stored AFTER construction: load_settings() runs inside the ctor and would
		// otherwise clobber an in-closure value with the (null) get_option result.
		$handler->get_setting( 'token' )->set_value( 's3cr3t' );

		// Guard: the secret WAS stored — it just must not be emitted.
		$this->assertSame( 's3cr3t', $handler->get_value( 'token' ) );

		$schema = Field_Schema::from_handler( $handler, [ 'token' ] );

		$this->assertSame( '', $schema['token']['value'], 'secret must not be emitted' );
		$this->assertTrue( $schema['token']['sensitive'] );
		$this->assertTrue( $schema['token']['is_set'] );
	}

	/**
	 * An unset sensitive field reports is_set = false.
	 *
	 * @return void
	 */
	public function test_unset_sensitive_field_reports_is_set_false(): void {
		$handler = $this->make_handler(
			static function ( $h ): void {
				$h->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Токен', 'sensitive' => true, 'default' => '' ] );
			}
		);

		$schema = Field_Schema::from_handler( $handler, [ 'token' ] );

		$this->assertSame( '', $schema['token']['value'] );
		$this->assertFalse( $schema['token']['is_set'] );
	}

	/**
	 * A constant-backed field is masked, flagged constant_managed and read-only.
	 *
	 * @return void
	 */
	public function test_constant_backed_field_is_masked_and_read_only(): void {
		if ( ! defined( 'WOODEV_FS_CONST' ) ) {
			define( 'WOODEV_FS_CONST', 'from-config' );
		}
		$handler = $this->make_handler(
			static function ( $h ): void {
				$h->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Токен', 'sensitive' => true, 'constant_name' => 'WOODEV_FS_CONST' ] );
			}
		);

		$schema = Field_Schema::from_handler( $handler, [ 'token' ] );

		$this->assertSame( '', $schema['token']['value'], 'constant value must not be emitted' );
		$this->assertTrue( $schema['token']['constant_managed'] );
		$this->assertSame( 'WOODEV_FS_CONST', $schema['token']['constant_name'] );
		$this->assertTrue( $schema['token']['is_set'] );
	}
}
