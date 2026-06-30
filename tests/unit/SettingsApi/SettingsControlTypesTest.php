<?php
/**
 * Tests for new control types registered in Woodev_Abstract_Settings
 * and the min/max/step/tooltip args forwarded via register_control().
 *
 * @package Woodev\Tests\Unit\SettingsApi
 */

namespace Woodev\Tests\Unit\SettingsApi;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

/**
 * Minimal concrete implementation of Woodev_Abstract_Settings for testing.
 *
 * Defined here (not in PlatformNeutralSettingsApiTest.php) so each file
 * can be run independently without class-already-declared errors.
 */
class Testable_Settings_Control_Types extends \Woodev_Abstract_Settings {

	/**
	 * Registers no settings by default — tests add their own.
	 *
	 * @return void
	 */
	protected function register_settings() {
	}
}

/**
 * Class SettingsControlTypesTest.
 *
 * @covers \Woodev_Abstract_Settings::get_control_types
 * @covers \Woodev_Abstract_Settings::register_control
 */
class SettingsControlTypesTest extends TestCase {

	/** @var Testable_Settings_Control_Types */
	private $settings;

	/**
	 * Sets up a fresh settings instance before every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// get_option is called inside load_settings() — stub it to return null.
		Functions\when( 'get_option' )->justReturn( null );
		// apply_filters is used by get_control_types() and get_setting_control_types().
		// The second argument (index 2 in Brain Monkey's 1-based counting) is the value to pass through.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// wp_parse_args is used in register_setting() and register_control().
		Functions\when( 'wp_parse_args' )->alias(
			function ( array $args, array $defaults ): array {
				return array_merge( $defaults, $args );
			}
		);

		$this->settings = new Testable_Settings_Control_Types( 'test' );
	}

	// ---------------------------------------------------------------
	// get_control_types()
	// ---------------------------------------------------------------

	/**
	 * get_control_types() must include the new toggle type.
	 *
	 * @return void
	 */
	public function test_get_control_types_includes_toggle(): void {
		$this->assertContains( 'toggle', $this->settings->get_control_types() );
	}

	/**
	 * get_control_types() must include the new richtext type.
	 *
	 * @return void
	 */
	public function test_get_control_types_includes_richtext(): void {
		$this->assertContains( 'richtext', $this->settings->get_control_types() );
	}

	/**
	 * get_control_types() must include the new multiselect type.
	 *
	 * @return void
	 */
	public function test_get_control_types_includes_multiselect(): void {
		$this->assertContains( 'multiselect', $this->settings->get_control_types() );
	}

	/**
	 * get_control_types() must include the new tel type.
	 *
	 * @return void
	 */
	public function test_get_control_types_includes_tel(): void {
		$this->assertContains( 'tel', $this->settings->get_control_types() );
	}

	/**
	 * get_control_types() must include the new url type.
	 *
	 * @return void
	 */
	public function test_get_control_types_includes_url(): void {
		$this->assertContains( 'url', $this->settings->get_control_types() );
	}

	// ---------------------------------------------------------------
	// register_control() with min/max/step/tooltip args
	// ---------------------------------------------------------------

	/**
	 * register_control() forwards min, max, step, and tooltip to the control object.
	 *
	 * @return void
	 */
	public function test_register_control_forwards_range_metadata_to_control(): void {
		$this->settings->register_setting( 'markup', 'float' );
		$this->settings->register_control(
			'markup',
			'range',
			[
				'min'     => 0,
				'max'     => 100,
				'step'    => 5,
				'tooltip' => 'help',
			]
		);

		$control = $this->settings->get_setting( 'markup' )->get_control();

		$this->assertSame( 100.0, $control->get_max() );
		$this->assertSame( 0.0, $control->get_min() );
		$this->assertSame( 5.0, $control->get_step() );
		$this->assertSame( 'help', $control->get_tooltip() );
	}

	/**
	 * Omitting min/max/step/tooltip args leaves the control in its default (null/empty) state.
	 *
	 * @return void
	 */
	public function test_register_control_without_range_metadata_leaves_defaults(): void {
		$this->settings->register_setting( 'markup', 'float' );
		$this->settings->register_control( 'markup', 'range' );

		$control = $this->settings->get_setting( 'markup' )->get_control();

		$this->assertNull( $control->get_min() );
		$this->assertNull( $control->get_max() );
		$this->assertNull( $control->get_step() );
		$this->assertSame( '', $control->get_tooltip() );
	}

	/**
	 * A multi-value setting keeps its array default — register_setting must set
	 * is_multi BEFORE default, else the array is validated as a scalar and nulled.
	 *
	 * @return void
	 */
	public function test_multi_setting_retains_array_default(): void {
		$this->settings->register_setting(
			'methods',
			'string',
			[
				'is_multi' => true,
				'options'  => [
					'courier' => 'Курьер',
					'pickup'  => 'ПВЗ',
					'locker'  => 'Постамат',
				],
				'default'  => [ 'courier', 'pickup', 'locker' ],
			]
		);

		$this->assertSame( [ 'courier', 'pickup', 'locker' ], $this->settings->get_setting( 'methods' )->get_default() );
		$this->assertSame( [ 'courier', 'pickup', 'locker' ], $this->settings->get_value( 'methods' ) );
	}
}
