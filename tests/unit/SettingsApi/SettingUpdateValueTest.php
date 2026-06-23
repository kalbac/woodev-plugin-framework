<?php
/**
 * Tests for Woodev_Setting::update_value() — enum key acceptance and is_multi per-element validation.
 *
 * @package Woodev\Tests\Unit\SettingsApi
 */

namespace Woodev\Tests\Unit\SettingsApi;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-helper.php';
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
}
