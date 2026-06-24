<?php
/**
 * Tests for Woodev_Setting::update_value() — enum key acceptance and is_multi per-element validation.
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
 * Class SettingUpdateValueTest.
 *
 * @covers \Woodev_Setting::update_value
 */
class SettingUpdateValueTest extends TestCase {

	/**
	 * Builds a fresh Woodev_Setting with the given type, is_multi flag, and options.
	 *
	 * @param string $type     Setting type constant (e.g. 'string', 'integer').
	 * @param bool   $is_multi Whether the setting accepts multiple values.
	 * @param array  $options  Associative or plain array of valid option values.
	 * @return \Woodev_Setting
	 */
	private function make_setting( string $type, bool $is_multi = false, array $options = [] ): \Woodev_Setting {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'test_setting' );
		$setting->set_type( $type );
		$setting->set_is_multi( $is_multi );
		if ( ! empty( $options ) ) {
			// set_options() validates values via validate_value() — for string options
			// the values (labels) are strings so they pass. Keys are preserved.
			$setting->set_options( $options );
		}
		return $setting;
	}

	// -----------------------------------------------------------------------
	// 1. Enum by KEY (assoc options: key => label)
	// -----------------------------------------------------------------------

	/**
	 * update_value() with an associative options map must accept the option KEY.
	 *
	 * Previously in_array('api', ['api'=>'По тарифам', ...]) checked against the
	 * VALUES (labels) and wrongly rejected the canonical stored key 'api'.
	 *
	 * @return void
	 */
	public function test_update_value_accepts_enum_key_in_assoc_options(): void {
		$setting = $this->make_setting(
			'string',
			false,
			[
				'api'       => 'По тарифам перевозчика',
				'fixed'     => 'Фиксированная ставка',
				'freefrom'  => 'Бесплатно от суммы заказа',
			]
		);

		$setting->update_value( 'api' );

		$this->assertSame( 'api', $setting->get_value() );
	}

	/**
	 * update_value() accepts every defined key in an assoc options map.
	 *
	 * @return void
	 */
	public function test_update_value_accepts_second_enum_key(): void {
		$setting = $this->make_setting(
			'string',
			false,
			[
				'api'   => 'По тарифам перевозчика',
				'fixed' => 'Фиксированная ставка',
			]
		);

		$setting->update_value( 'fixed' );

		$this->assertSame( 'fixed', $setting->get_value() );
	}

	// -----------------------------------------------------------------------
	// 2. Enum by VALUE (plain list: [0 => 'Курьер', 1 => 'ПВЗ'])
	// -----------------------------------------------------------------------

	/**
	 * update_value() must accept an option VALUE when options are a plain list.
	 *
	 * @return void
	 */
	public function test_update_value_accepts_enum_value_in_plain_list_options(): void {
		$setting = $this->make_setting( 'string', false, [ 'Курьер', 'ПВЗ' ] );

		$setting->update_value( 'Курьер' );

		$this->assertSame( 'Курьер', $setting->get_value() );
	}

	// -----------------------------------------------------------------------
	// 3. Enum invalid value
	// -----------------------------------------------------------------------

	/**
	 * update_value() must throw Woodev_Plugin_Exception when value is not a valid key or value.
	 *
	 * @return void
	 */
	public function test_update_value_throws_for_invalid_enum_value(): void {
		$this->expectException( \Woodev_Plugin_Exception::class );

		$setting = $this->make_setting(
			'string',
			false,
			[
				'api'   => 'По тарифам',
				'fixed' => 'Фиксированная',
			]
		);

		$setting->update_value( 'nonsense' );
	}

	/**
	 * The exception thrown for an invalid enum value carries HTTP 400.
	 *
	 * @return void
	 */
	public function test_update_value_invalid_enum_throws_with_400_code(): void {
		$setting = $this->make_setting( 'string', false, [ 'api' => 'По тарифам' ] );

		try {
			$setting->update_value( 'nonsense' );
			$this->fail( 'Expected Woodev_Plugin_Exception was not thrown.' );
		} catch ( \Woodev_Plugin_Exception $e ) {
			$this->assertSame( 400, $e->getCode() );
		}
	}

	// -----------------------------------------------------------------------
	// 4. is_multi: update_value() with valid array of keys
	// -----------------------------------------------------------------------

	/**
	 * update_value() must accept an array of valid option keys when is_multi is true.
	 *
	 * Previously the whole array was passed to validate_string_value() which called
	 * is_string($array) → false → threw "not valid for type".
	 *
	 * @return void
	 */
	public function test_update_value_is_multi_accepts_valid_key_array(): void {
		$setting = $this->make_setting(
			'string',
			true,
			[
				'courier' => 'Курьер',
				'pickup'  => 'ПВЗ',
				'locker'  => 'Постамат',
			]
		);

		$setting->update_value( [ 'courier', 'pickup' ] );

		$this->assertSame( [ 'courier', 'pickup' ], $setting->get_value() );
	}

	/**
	 * update_value() with is_multi stores the values as a re-indexed array.
	 *
	 * @return void
	 */
	public function test_update_value_is_multi_stores_reindexed_array(): void {
		$setting = $this->make_setting(
			'string',
			true,
			[
				'courier' => 'Курьер',
				'pickup'  => 'ПВЗ',
				'locker'  => 'Постамат',
			]
		);

		$setting->update_value( [ 'locker' ] );

		$this->assertSame( [ 'locker' ], $setting->get_value() );
	}

	// -----------------------------------------------------------------------
	// 5. is_multi: invalid element throws
	// -----------------------------------------------------------------------

	/**
	 * update_value() must throw when any element in the array is not a valid option key/value.
	 *
	 * @return void
	 */
	public function test_update_value_is_multi_throws_for_invalid_element(): void {
		$this->expectException( \Woodev_Plugin_Exception::class );

		$setting = $this->make_setting(
			'string',
			true,
			[
				'courier' => 'Курьер',
				'pickup'  => 'ПВЗ',
				'locker'  => 'Постамат',
			]
		);

		$setting->update_value( [ 'courier', 'bogus' ] );
	}

	/**
	 * Exception for invalid multi element carries HTTP 400.
	 *
	 * @return void
	 */
	public function test_update_value_is_multi_invalid_element_throws_with_400_code(): void {
		$setting = $this->make_setting(
			'string',
			true,
			[
				'courier' => 'Курьер',
				'pickup'  => 'ПВЗ',
			]
		);

		try {
			$setting->update_value( [ 'courier', 'bogus' ] );
			$this->fail( 'Expected Woodev_Plugin_Exception was not thrown.' );
		} catch ( \Woodev_Plugin_Exception $e ) {
			$this->assertSame( 400, $e->getCode() );
		}
	}

	// -----------------------------------------------------------------------
	// 6. Non-enum scalar types unchanged
	// -----------------------------------------------------------------------

	/**
	 * update_value() with type 'integer' and no options must accept an int and store it.
	 *
	 * @return void
	 */
	public function test_update_value_non_enum_integer_accepts_valid_value(): void {
		$setting = $this->make_setting( 'integer' );

		$setting->update_value( 42 );

		$this->assertSame( 42, $setting->get_value() );
	}

	/**
	 * update_value() with type 'integer' must throw for a non-integer (type error).
	 *
	 * @return void
	 */
	public function test_update_value_non_enum_integer_throws_for_string(): void {
		$this->expectException( \Woodev_Plugin_Exception::class );

		$setting = $this->make_setting( 'integer' );
		$setting->update_value( 'not-int' );
	}

	/**
	 * The type-mismatch exception carries HTTP 400.
	 *
	 * @return void
	 */
	public function test_update_value_type_mismatch_throws_with_400_code(): void {
		$setting = $this->make_setting( 'integer' );

		try {
			$setting->update_value( 'not-int' );
			$this->fail( 'Expected Woodev_Plugin_Exception was not thrown.' );
		} catch ( \Woodev_Plugin_Exception $e ) {
			$this->assertSame( 400, $e->getCode() );
		}
	}

	// -----------------------------------------------------------------------
	// 7. Associative enum on a NON-string setting type (integer keys, string labels)
	//    Regression: set_options() validated the LABEL against the type, dropped the
	//    whole enum, and assert_valid_value() then accepted ANY value (validation bypass).
	// -----------------------------------------------------------------------

	/**
	 * An integer enum registered as [ int_key => string_label ] keeps its options
	 * (the submittable token is the integer KEY, not the free-text label).
	 *
	 * @return void
	 */
	public function test_set_options_retains_integer_enum_with_string_labels(): void {
		$setting = $this->make_setting( 'integer', false, [ 1 => 'One', 2 => 'Two' ] );

		$this->assertSame( [ 1 => 'One', 2 => 'Two' ], $setting->get_options() );
	}

	/**
	 * update_value() accepts a valid integer enum key.
	 *
	 * @return void
	 */
	public function test_update_value_accepts_integer_enum_key(): void {
		$setting = $this->make_setting( 'integer', false, [ 1 => 'One', 2 => 'Two' ] );

		$setting->update_value( 2 );

		$this->assertSame( 2, $setting->get_value() );
	}

	/**
	 * update_value() REJECTS an integer outside the enum — the bypass is closed
	 * (previously the emptied options map let any integer through).
	 *
	 * @return void
	 */
	public function test_update_value_rejects_integer_outside_enum(): void {
		$this->expectException( \Woodev_Plugin_Exception::class );

		$setting = $this->make_setting( 'integer', false, [ 1 => 'One', 2 => 'Two' ] );

		$setting->update_value( 99 );
	}

	/**
	 * A ZERO-BASED integer enum ([ 0 => 'Zero', 1 => 'One' ]) keeps its options.
	 *
	 * Regression guard: a key-range heuristic would misread 0-based integer keys as
	 * a plain list, validate the string labels against the integer type, drop every
	 * option, and reopen the empty-enum bypass. Validating key-OR-value avoids it.
	 *
	 * @return void
	 */
	public function test_set_options_retains_zero_based_integer_enum(): void {
		$setting = $this->make_setting( 'integer', false, [ 0 => 'Zero', 1 => 'One' ] );

		$this->assertSame( [ 0 => 'Zero', 1 => 'One' ], $setting->get_options() );
	}

	/**
	 * A zero-based integer enum accepts the key 0 and rejects values outside it.
	 *
	 * @return void
	 */
	public function test_update_value_zero_based_integer_enum_accepts_key_and_rejects_others(): void {
		$setting = $this->make_setting( 'integer', false, [ 0 => 'Zero', 1 => 'One' ] );

		$setting->update_value( 0 );
		$this->assertSame( 0, $setting->get_value() );

		$this->expectException( \Woodev_Plugin_Exception::class );
		$setting->update_value( 7 );
	}

	// -----------------------------------------------------------------------
	// 8. Numeric-string coercion (HTML number inputs submit strings)
	// -----------------------------------------------------------------------

	/**
	 * A numeric string is coerced to int for an integer setting.
	 *
	 * @return void
	 */
	public function test_update_value_coerces_numeric_string_to_integer(): void {
		$setting = $this->make_setting( 'integer' );

		$setting->update_value( '5000' );

		$this->assertSame( 5000, $setting->get_value() );
	}

	/**
	 * A numeric string is coerced to float for a float setting.
	 *
	 * @return void
	 */
	public function test_update_value_coerces_numeric_string_to_float(): void {
		$setting = $this->make_setting( 'float' );

		$setting->update_value( '5.5' );

		$this->assertSame( 5.5, $setting->get_value() );
	}

	/**
	 * A fractional string is NOT silently truncated into an integer setting — it
	 * must fail validation rather than become a wrong value.
	 *
	 * @return void
	 */
	public function test_update_value_integer_rejects_fractional_string(): void {
		$this->expectException( \Woodev_Plugin_Exception::class );

		$setting = $this->make_setting( 'integer' );

		$setting->update_value( '5.5' );
	}

	// -----------------------------------------------------------------------
	// 9. Richtext sanitization (stored-XSS guard)
	// -----------------------------------------------------------------------

	/**
	 * A richtext-controlled setting runs its value through wp_kses_post() on save,
	 * stripping script-capable markup before persistence.
	 *
	 * @return void
	 */
	public function test_update_value_sanitizes_richtext_via_kses(): void {
		Functions\when( 'wp_kses_post' )->alias(
			static function ( $html ) {
				// Minimal stand-in: strip <script>…</script> like kses would.
				return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
			}
		);

		$setting = new \Woodev_Setting();
		$setting->set_id( 'notice' );
		$setting->set_type( 'string' );

		$control = new \Woodev_Control();
		$control->set_type( \Woodev_Control::TYPE_RICHTEXT );
		$setting->set_control( $control );

		$setting->update_value( '<p>Hello</p><script>alert(1)</script>' );

		$this->assertSame( '<p>Hello</p>', $setting->get_value() );
	}

	/**
	 * A plain (non-richtext) string control stores its value verbatim — sanitization
	 * is scoped to HTML-bearing controls only.
	 *
	 * @return void
	 */
	public function test_update_value_plain_string_not_sanitized(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'plain' );
		$setting->set_type( 'string' );

		$control = new \Woodev_Control();
		$control->set_type( \Woodev_Control::TYPE_TEXT );
		$setting->set_control( $control );

		$value = '<p>kept as-is</p>';
		$setting->update_value( $value );

		$this->assertSame( $value, $setting->get_value() );
	}
}
