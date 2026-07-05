<?php
/**
 * Tests for conditional-field visibility: Woodev_Setting::evaluate_conditions(),
 * show_if resolution, and Woodev_Abstract_Settings::filter_visible_values().
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

	public function test_show_if_accepts_array_directly(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'live_key' );
		$setting->set_show_if( [ 'setting' => 'mode', 'value' => 'live' ] );

		$this->assertSame( [ 'setting' => 'mode', 'value' => 'live' ], $setting->get_show_if_conditions() );
		$this->assertTrue( $setting->is_visible( [ 'mode' => 'live' ] ) );
		$this->assertFalse( $setting->is_visible( [ 'mode' => 'test' ] ) );
	}

	public function test_show_if_accepts_callback_receiving_field_id(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'rate' );
		$setting->set_show_if(
			static function ( string $field_id ): array {
				return 'rate' === $field_id
					? [ 'setting' => 'calc_type', 'value' => 'fixed' ]
					: [];
			}
		);

		$this->assertSame( [ 'setting' => 'calc_type', 'value' => 'fixed' ], $setting->get_show_if_conditions() );
		$this->assertTrue( $setting->is_visible( [ 'calc_type' => 'fixed' ] ) );
	}

	public function test_show_if_defaults_to_empty_visible(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'x' );
		$this->assertSame( [], $setting->get_show_if_conditions() );
		$this->assertTrue( $setting->is_visible( [] ) );
	}

	public function test_show_if_rejects_non_array_non_callable(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'x' );
		$setting->set_show_if( 'nonsense' );
		$this->assertSame( [], $setting->get_show_if_conditions() );
	}

	/**
	 * Builds a handler with two settings: a plain `mode` and a `live_key` that is
	 * visible only when mode = live. `get_value()` returns the given stored map.
	 *
	 * @param array $stored setting_id => stored value.
	 * @return \Woodev_Abstract_Settings
	 */
	private function make_handler( array $stored ): \Woodev_Abstract_Settings {
		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/register-settings/class-register-settings.php';
		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

		// Stub the WP plumbing the abstract handler touches during construction.
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		return new class( $stored ) extends \Woodev_Abstract_Settings {
			/** @var array */
			private $stored;
			public function __construct( array $stored ) {
				$this->stored = $stored;
				parent::__construct( 'cond_test' );
			}
			protected function register_settings() {
				$this->register_setting( 'mode', \Woodev_Setting::TYPE_STRING, [ 'options' => [ 'test' => 'T', 'live' => 'L' ], 'default' => 'test' ] );
				$this->register_setting( 'live_key', \Woodev_Setting::TYPE_STRING, [ 'required' => true, 'show_if' => [ 'setting' => 'mode', 'value' => 'live' ] ] );
			}
			public function get_value( $id, $default = null ) {
				return $this->stored[ $id ] ?? $default;
			}
			public function save( $setting_id = '' ) {}
		};
	}

	public function test_filter_strips_hidden_field(): void {
		$handler = $this->make_handler( [ 'mode' => 'test' ] );
		// live_key is hidden (mode=test) → stripped even though submitted empty.
		$result = $handler->filter_visible_values( [ 'mode' => 'test', 'live_key' => '' ] );
		$this->assertArrayNotHasKey( 'live_key', $result );
		$this->assertArrayHasKey( 'mode', $result );
	}

	public function test_filter_keeps_visible_field(): void {
		$handler = $this->make_handler( [ 'mode' => 'live' ] );
		$result  = $handler->filter_visible_values( [ 'mode' => 'live', 'live_key' => 'abc' ] );
		$this->assertArrayHasKey( 'live_key', $result );
	}

	public function test_filter_uses_stored_when_controller_not_submitted(): void {
		// mode is NOT in the submitted map → resolve against stored (mode=live) → keep.
		$handler = $this->make_handler( [ 'mode' => 'live' ] );
		$result  = $handler->filter_visible_values( [ 'live_key' => 'abc' ] );
		$this->assertArrayHasKey( 'live_key', $result );
	}

	public function test_filter_passes_through_unconditional_fields(): void {
		$handler = $this->make_handler( [ 'mode' => 'test' ] );
		$result  = $handler->filter_visible_values( [ 'mode' => 'test' ] );
		$this->assertSame( [ 'mode' => 'test' ], $result );
	}

	public function test_field_schema_emits_show_if_only_when_present(): void {
		require_once dirname( __DIR__, 3 ) . '/woodev/settings-page/class-field-schema.php';

		$handler = $this->make_handler( [ 'mode' => 'live' ] );
		$schema  = \Woodev\Framework\Settings\Field_Schema::from_handler( $handler );

		$this->assertArrayNotHasKey( 'show_if', $schema['mode'] );
		$this->assertArrayHasKey( 'show_if', $schema['live_key'] );
		$this->assertSame( [ 'setting' => 'mode', 'value' => 'live' ], $schema['live_key']['show_if'] );
	}

	/**
	 * A show_if that references an UNREGISTERED controller id absent from the
	 * submission must not crash: the base get_value() throws on unknown ids, so the
	 * effective-value resolver must guard with get_setting() and treat the missing
	 * controller as the empty string. Uses the REAL base get_value()/get_setting()
	 * (no override) so the guard is genuinely exercised.
	 *
	 * @return void
	 */
	private function make_real_value_handler(): \Woodev_Abstract_Settings {
		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/register-settings/class-register-settings.php';
		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		return new class() extends \Woodev_Abstract_Settings {
			public function __construct() {
				parent::__construct( 'ghost_test' );
			}
			protected function register_settings() {
				$this->register_setting( 'region', \Woodev_Setting::TYPE_STRING, [ 'default' => '', 'show_if' => [ 'setting' => 'ghost', 'value' => 'x' ] ] );
			}
			public function save( $setting_id = '' ) {}
		};
	}

	public function test_filter_does_not_crash_on_unregistered_controller(): void {
		$handler = $this->make_real_value_handler();

		// 'ghost' is not registered and is absent from the submission → the resolver
		// must fall back to '' instead of calling get_value('ghost') (which throws).
		$result = $handler->filter_visible_values( [ 'region' => 'north' ] );

		// '' !== 'x' → region is hidden → stripped, but crucially no exception thrown.
		$this->assertArrayNotHasKey( 'region', $result );
	}
}
