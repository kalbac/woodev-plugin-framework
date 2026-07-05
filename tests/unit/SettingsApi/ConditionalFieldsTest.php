<?php
/**
 * Tests for conditional-field visibility: Woodev_Setting::evaluate_conditions(),
 * show_if resolution, and Woodev_Abstract_Settings::filter_visible_values().
 *
 * @package Woodev\Tests\Unit\SettingsApi
 */

namespace Woodev\Tests\Unit\SettingsApi;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';

/**
 * @covers \Woodev_Setting::evaluate_conditions
 */
class ConditionalFieldsTest extends TestCase {

	public function test_empty_conditions_are_visible(): void {
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( [], [ 'mode' => 'test' ] ) );
	}

	public function test_bare_single_condition_equals(): void {
		$c = [ 'setting' => 'mode', 'value' => 'live' ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'live' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'test' ] ) );
	}

	public function test_default_operator_is_equals(): void {
		$c = [ [ 'setting' => 'mode', 'value' => 'live' ] ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'live' ] ) );
	}

	public function test_not_equals_matches_empty_controlling(): void {
		$c = [ [ 'setting' => 'mode', 'operator' => '!=', 'value' => 'test' ] ];
		// unset controlling value is the empty string → '' !== 'test' → visible.
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'test' ] ) );
	}

	public function test_in_and_not_in(): void {
		$in = [ [ 'setting' => 'm', 'operator' => 'in', 'value' => [ 'a', 'b' ] ] ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $in, [ 'm' => 'b' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $in, [ 'm' => 'c' ] ) );

		$not = [ [ 'setting' => 'm', 'operator' => 'not_in', 'value' => [ 'a', 'b' ] ] ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $not, [ 'm' => 'c' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $not, [ 'm' => 'a' ] ) );
	}

	public function test_relation_and_requires_all(): void {
		$c = [
			'relation' => 'AND',
			[ 'setting' => 'mode', 'value' => 'live' ],
			[ 'setting' => 'auth', 'value' => 'key' ],
		];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'live', 'auth' => 'key' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'live', 'auth' => 'oauth' ] ) );
	}

	public function test_relation_or_requires_any(): void {
		$c = [
			'relation' => 'OR',
			[ 'setting' => 'mode', 'value' => 'live' ],
			[ 'setting' => 'auth', 'value' => 'key' ],
		];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'test', 'auth' => 'key' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'test', 'auth' => 'oauth' ] ) );
	}

	public function test_string_comparison_of_int_keys(): void {
		// enum option keys can be zero-based ints; comparison is by string.
		$c = [ [ 'setting' => 'n', 'value' => 0 ] ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'n' => '0' ] ) );
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'n' => 0 ] ) );
	}

	public function test_unknown_operator_fails_closed(): void {
		$c = [ [ 'setting' => 'm', 'operator' => 'regex', 'value' => '.*' ] ];
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'm' => 'anything' ] ) );
	}

	public function test_array_controlling_value_coerces_to_empty(): void {
		// controlling fields are scalar in v1; an array value is treated as empty.
		$c = [ [ 'setting' => 'm', 'value' => 'x' ] ];
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'm' => [ 'x' ] ] ) );
	}
}
