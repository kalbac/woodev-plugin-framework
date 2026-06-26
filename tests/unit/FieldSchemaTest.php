<?php
namespace Woodev\Tests\Unit;

use Mockery;
use Woodev\Framework\Settings\Field_Schema;

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

		return $setting;
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
}
