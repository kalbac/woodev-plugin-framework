<?php
/**
 * Setup Wizard field-schema enrichment tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

/**
 * Concrete wizard that exposes get_field_schema() for isolated testing.
 */
class Field_Schema_Test_Wizard extends Setup_Wizard {

	/** @var \Woodev_Plugin */
	private $plugin_mock;

	/**
	 * Inject mock, skip parent wiring.
	 *
	 * @param \Woodev_Plugin $plugin plugin mock.
	 */
	public function __construct( $plugin ) {
		$this->plugin      = $plugin;
		$this->plugin_mock = $plugin;
	}

	/** @return void */
	protected function register_steps(): void {}

	/** @return string */
	public function get_id(): string {
		return 'test-plugin';
	}

	/**
	 * Proxy to the protected method.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function public_get_field_schema(): array {
		return $this->get_field_schema();
	}
}

/**
 * Tests for B1: enriched field schema emitting controlType/description/tooltip/range bounds.
 */
class SetupWizardFieldSchemaTest extends TestCase {

	/**
	 * Returns empty array when plugin has no settings handler.
	 *
	 * @return void
	 */
	public function test_empty_schema_when_no_handler(): void {
		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( null );

		$wizard = new Field_Schema_Test_Wizard( $plugin );
		$this->assertSame( [], $wizard->public_get_field_schema() );
	}

	/**
	 * A range setting with control min/max/step/tooltip emits all those keys
	 * plus controlType and description.
	 *
	 * @return void
	 */
	public function test_range_control_emits_all_enriched_keys(): void {
		// Build the control mock.
		$control = Mockery::mock( 'Woodev_Control' );
		$control->shouldReceive( 'get_type' )->andReturn( 'range' );
		$control->shouldReceive( 'get_description' )->andReturn( 'Speed description' );
		$control->shouldReceive( 'get_tooltip' )->andReturn( 'Hover tip' );
		$control->shouldReceive( 'get_min' )->andReturn( 0.0 );
		$control->shouldReceive( 'get_max' )->andReturn( 100.0 );
		$control->shouldReceive( 'get_step' )->andReturn( 1.0 );

		// Build the setting mock.
		$setting = Mockery::mock( 'Woodev_Setting' );
		$setting->shouldReceive( 'get_id' )->andReturn( 'speed' );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_type' )->andReturn( 'integer' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Speed' );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'get_control' )->andReturn( $control );
		$setting->shouldReceive( 'get_description' )->andReturn( 'Setting-level description' );

		// Build the handler mock.
		$handler = Mockery::mock( 'Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'speed' )->andReturn( 50 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );

		$wizard = new Field_Schema_Test_Wizard( $plugin );
		$schema = $wizard->public_get_field_schema();

		$this->assertArrayHasKey( 'speed', $schema );
		$entry = $schema['speed'];

		// Base fields preserved.
		$this->assertSame( 'integer', $entry['type'] );
		$this->assertSame( 'Speed', $entry['name'] );
		$this->assertSame( [], $entry['options'] );
		$this->assertSame( 50, $entry['value'] );

		// Enriched fields.
		$this->assertSame( 'range', $entry['controlType'] );
		$this->assertSame( 'Speed description', $entry['description'] );
		$this->assertSame( 'Hover tip', $entry['tooltip'] );

		// Range bounds present.
		$this->assertArrayHasKey( 'min', $entry );
		$this->assertArrayHasKey( 'max', $entry );
		$this->assertArrayHasKey( 'step', $entry );
		$this->assertSame( 0.0, $entry['min'] );
		$this->assertSame( 100.0, $entry['max'] );
		$this->assertSame( 1.0, $entry['step'] );
	}

	/**
	 * When the control description is empty, falls back to the setting description.
	 *
	 * @return void
	 */
	public function test_description_falls_back_to_setting_description(): void {
		$control = Mockery::mock( 'Woodev_Control' );
		$control->shouldReceive( 'get_type' )->andReturn( 'text' );
		$control->shouldReceive( 'get_description' )->andReturn( '' );
		$control->shouldReceive( 'get_tooltip' )->andReturn( '' );
		$control->shouldReceive( 'get_min' )->andReturn( null );
		$control->shouldReceive( 'get_max' )->andReturn( null );
		$control->shouldReceive( 'get_step' )->andReturn( null );

		$setting = Mockery::mock( 'Woodev_Setting' );
		$setting->shouldReceive( 'get_id' )->andReturn( 'api_key' );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'API Key' );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'get_control' )->andReturn( $control );
		$setting->shouldReceive( 'get_description' )->andReturn( 'Fallback description' );

		$handler = Mockery::mock( 'Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'api_key' )->andReturn( 'abc' );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );

		$wizard = new Field_Schema_Test_Wizard( $plugin );
		$schema = $wizard->public_get_field_schema();

		$this->assertSame( 'Fallback description', $schema['api_key']['description'] );
		$this->assertArrayNotHasKey( 'min', $schema['api_key'] );
		$this->assertArrayNotHasKey( 'max', $schema['api_key'] );
		$this->assertArrayNotHasKey( 'step', $schema['api_key'] );
	}

	/**
	 * When the setting has no control, controlType is null and no range keys present.
	 *
	 * @return void
	 */
	public function test_no_control_emits_null_controltype(): void {
		$setting = Mockery::mock( 'Woodev_Setting' );
		$setting->shouldReceive( 'get_id' )->andReturn( 'label' );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Label' );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'get_control' )->andReturn( null );
		$setting->shouldReceive( 'get_description' )->andReturn( 'No control' );

		$handler = Mockery::mock( 'Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'label' )->andReturn( '' );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );

		$wizard = new Field_Schema_Test_Wizard( $plugin );
		$schema = $wizard->public_get_field_schema();

		$this->assertNull( $schema['label']['controlType'] );
		$this->assertSame( 'No control', $schema['label']['description'] );
		$this->assertSame( '', $schema['label']['tooltip'] );
		$this->assertArrayNotHasKey( 'min', $schema['label'] );
	}

	/**
	 * The schema emits is_multi so the React control-field can resolve a multiselect
	 * for a multi setting even without an explicit multiselect control type.
	 *
	 * @return void
	 */
	public function test_schema_emits_is_multi_flag(): void {
		$setting = Mockery::mock( 'Woodev_Setting' );
		$setting->shouldReceive( 'get_id' )->andReturn( 'methods' );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( true );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Methods' );
		$setting->shouldReceive( 'get_options' )->andReturn( [ 'a' => 'A', 'b' => 'B' ] );
		$setting->shouldReceive( 'get_control' )->andReturn( null );
		$setting->shouldReceive( 'get_description' )->andReturn( '' );

		$handler = Mockery::mock( 'Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'methods' )->andReturn( [] );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );

		$wizard = new Field_Schema_Test_Wizard( $plugin );
		$schema = $wizard->public_get_field_schema();

		$this->assertTrue( $schema['methods']['is_multi'] );
	}
}
