<?php
/**
 * Tests for Woodev_Control type constants and new property accessors.
 *
 * @package Woodev\Tests\Unit\SettingsApi
 */

namespace Woodev\Tests\Unit\SettingsApi;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';

use Woodev\Tests\Unit\TestCase;

/**
 * Class ControlTest.
 *
 * @covers \Woodev_Control
 */
class ControlTest extends TestCase {

	// ---------------------------------------------------------------
	// Type constants
	// ---------------------------------------------------------------

	/**
	 * TYPE_TOGGLE constant must equal the string 'toggle'.
	 *
	 * @return void
	 */
	public function test_type_toggle_constant_value(): void {
		$this->assertSame( 'toggle', \Woodev_Control::TYPE_TOGGLE );
	}

	/**
	 * TYPE_RICHTEXT constant must equal the string 'richtext'.
	 *
	 * @return void
	 */
	public function test_type_richtext_constant_value(): void {
		$this->assertSame( 'richtext', \Woodev_Control::TYPE_RICHTEXT );
	}

	/**
	 * TYPE_MULTISELECT constant must equal the string 'multiselect'.
	 *
	 * @return void
	 */
	public function test_type_multiselect_constant_value(): void {
		$this->assertSame( 'multiselect', \Woodev_Control::TYPE_MULTISELECT );
	}

	// ---------------------------------------------------------------
	// min / max / step
	// ---------------------------------------------------------------

	/**
	 * get_min() returns null when no value has been set.
	 *
	 * @return void
	 */
	public function test_get_min_returns_null_when_unset(): void {
		$control = new \Woodev_Control();
		$this->assertNull( $control->get_min() );
	}

	/**
	 * set_min() stores a float and get_min() returns it.
	 *
	 * @return void
	 */
	public function test_set_min_and_get_min_roundtrip(): void {
		$control = new \Woodev_Control();
		$control->set_min( 1.5 );
		$this->assertSame( 1.5, $control->get_min() );
	}

	/**
	 * set_min() casts an integer to float.
	 *
	 * @return void
	 */
	public function test_set_min_casts_integer_to_float(): void {
		$control = new \Woodev_Control();
		$control->set_min( 0 );
		$this->assertSame( 0.0, $control->get_min() );
	}

	/**
	 * set_min() with a non-numeric value stores null.
	 *
	 * @return void
	 */
	public function test_set_min_with_non_numeric_stores_null(): void {
		$control = new \Woodev_Control();
		$control->set_min( 'foo' );
		$this->assertNull( $control->get_min() );
	}

	/**
	 * get_max() returns null when no value has been set.
	 *
	 * @return void
	 */
	public function test_get_max_returns_null_when_unset(): void {
		$control = new \Woodev_Control();
		$this->assertNull( $control->get_max() );
	}

	/**
	 * set_max() stores a float and get_max() returns it.
	 *
	 * @return void
	 */
	public function test_set_max_and_get_max_roundtrip(): void {
		$control = new \Woodev_Control();
		$control->set_max( 100.0 );
		$this->assertSame( 100.0, $control->get_max() );
	}

	/**
	 * set_max() with a non-numeric value stores null.
	 *
	 * @return void
	 */
	public function test_set_max_with_non_numeric_stores_null(): void {
		$control = new \Woodev_Control();
		$control->set_max( [] );
		$this->assertNull( $control->get_max() );
	}

	/**
	 * get_step() returns null when no value has been set.
	 *
	 * @return void
	 */
	public function test_get_step_returns_null_when_unset(): void {
		$control = new \Woodev_Control();
		$this->assertNull( $control->get_step() );
	}

	/**
	 * set_step() stores a float and get_step() returns it.
	 *
	 * @return void
	 */
	public function test_set_step_and_get_step_roundtrip(): void {
		$control = new \Woodev_Control();
		$control->set_step( 0.5 );
		$this->assertSame( 0.5, $control->get_step() );
	}

	/**
	 * set_step() casts an integer to float.
	 *
	 * @return void
	 */
	public function test_set_step_casts_integer_to_float(): void {
		$control = new \Woodev_Control();
		$control->set_step( 5 );
		$this->assertSame( 5.0, $control->get_step() );
	}

	/**
	 * set_step() with a non-numeric value stores null.
	 *
	 * @return void
	 */
	public function test_set_step_with_non_numeric_stores_null(): void {
		$control = new \Woodev_Control();
		$control->set_step( null );
		$this->assertNull( $control->get_step() );
	}

	// ---------------------------------------------------------------
	// tooltip
	// ---------------------------------------------------------------

	/**
	 * get_tooltip() returns an empty string when no value has been set.
	 *
	 * @return void
	 */
	public function test_get_tooltip_returns_empty_string_when_unset(): void {
		$control = new \Woodev_Control();
		$this->assertSame( '', $control->get_tooltip() );
	}

	/**
	 * set_tooltip() stores a string and get_tooltip() returns it.
	 *
	 * @return void
	 */
	public function test_set_tooltip_and_get_tooltip_roundtrip(): void {
		$control = new \Woodev_Control();
		$control->set_tooltip( 'helpful hint' );
		$this->assertSame( 'helpful hint', $control->get_tooltip() );
	}
}
