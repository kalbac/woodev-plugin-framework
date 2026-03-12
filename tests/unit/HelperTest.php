<?php
/**
 * Woodev_Helper Unit Tests
 *
 * Тесты для статических утилитных методов класса Woodev_Helper.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Class HelperTest
 */
class HelperTest extends TestCase {

	/** String: str_starts_with ************************************************/

	/**
	 * @dataProvider provider_str_starts_with
	 *
	 * @param string $haystack
	 * @param string $needle
	 * @param bool   $expected
	 */
	public function test_str_starts_with( string $haystack, string $needle, bool $expected ): void {
		$this->assertSame( $expected, \Woodev_Helper::str_starts_with( $haystack, $needle ) );
	}

	/**
	 * Data provider for str_starts_with.
	 *
	 * @return array
	 */
	public function provider_str_starts_with(): array {
		return [
			'simple match'             => [ 'Hello World', 'Hello', true ],
			'no match'                 => [ 'Hello World', 'World', false ],
			'case sensitive mismatch'  => [ 'Hello World', 'hello', false ],
			'empty needle'             => [ 'Hello', '', true ],
			'empty haystack'           => [ '', 'Hello', false ],
			'both empty'               => [ '', '', true ],
			'full string match'        => [ 'abc', 'abc', true ],
			'needle longer'            => [ 'ab', 'abc', false ],
			'unicode match'            => [ "\xC3\xA9toile", "\xC3\xA9", true ],
			'unicode no match'         => [ "\xC3\xA9toile", 'e', false ],
			'cyrillic match'           => [ "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82", "\xD0\x9F\xD1\x80\xD0\xB8", true ],
			'cyrillic no match'        => [ "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82", "\xD0\xB2\xD0\xB5\xD1\x82", false ],
		];
	}

	/** String: str_ends_with **************************************************/

	/**
	 * @dataProvider provider_str_ends_with
	 *
	 * @param string $haystack
	 * @param string $needle
	 * @param bool   $expected
	 */
	public function test_str_ends_with( string $haystack, string $needle, bool $expected ): void {
		$this->assertSame( $expected, \Woodev_Helper::str_ends_with( $haystack, $needle ) );
	}

	/**
	 * Data provider for str_ends_with.
	 *
	 * @return array
	 */
	public function provider_str_ends_with(): array {
		return [
			'simple match'            => [ 'Hello World', 'World', true ],
			'no match'                => [ 'Hello World', 'Hello', false ],
			'case sensitive mismatch' => [ 'Hello World', 'world', false ],
			'empty needle'            => [ 'Hello', '', true ],
			'empty haystack'          => [ '', 'Hello', false ],
			'both empty'              => [ '', '', true ],
			'full string match'       => [ 'abc', 'abc', true ],
			'needle longer'           => [ 'bc', 'abc', false ],
			'unicode match'           => [ "l'\xC3\xA9toile", 'toile', true ],
			'cyrillic match'          => [ "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82", "\xD0\xB2\xD0\xB5\xD1\x82", true ],
		];
	}

	/** String: str_exists *****************************************************/

	/**
	 * @dataProvider provider_str_exists
	 *
	 * @param string $haystack
	 * @param string $needle
	 * @param bool   $expected
	 */
	public function test_str_exists( string $haystack, string $needle, bool $expected ): void {
		$this->assertSame( $expected, \Woodev_Helper::str_exists( $haystack, $needle ) );
	}

	/**
	 * Data provider for str_exists.
	 *
	 * @return array
	 */
	public function provider_str_exists(): array {
		return [
			'found at start'          => [ 'Hello World', 'Hello', true ],
			'found at end'            => [ 'Hello World', 'World', true ],
			'found in middle'         => [ 'Hello World', 'lo Wo', true ],
			'not found'               => [ 'Hello World', 'Goodbye', false ],
			'case sensitive mismatch' => [ 'Hello World', 'hello', false ],
			'empty needle'            => [ 'Hello', '', false ],
			'empty haystack'          => [ '', 'Hello', false ],
			'both empty'              => [ '', '', false ],
			'unicode found'           => [ "\xC3\xA9toile du matin", 'toile', true ],
			'unicode not found'       => [ "\xC3\xA9toile", 'xyz', false ],
			'cyrillic found'          => [ "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xD0\xBC\xD0\xB8\xD1\x80", "\xD0\xB2\xD0\xB5\xD1\x82", true ],
		];
	}

	/** String: str_truncate ***************************************************/

	/**
	 * @dataProvider provider_str_truncate
	 *
	 * @param string $string
	 * @param int    $length
	 * @param string $omission
	 * @param string $expected
	 */
	public function test_str_truncate( string $string, int $length, string $omission, string $expected ): void {
		$this->assertSame( $expected, \Woodev_Helper::str_truncate( $string, $length, $omission ) );
	}

	/**
	 * Data provider for str_truncate.
	 *
	 * @return array
	 */
	public function provider_str_truncate(): array {
		return [
			'no truncation needed'      => [ 'Hello', 10, '...', 'Hello' ],
			'exact length no truncation' => [ 'Hello', 5, '...', 'Hello' ],
			'truncate with default'      => [ 'Hello World', 8, '...', 'Hello...' ],
			'truncate with custom'       => [ 'Hello World', 7, '--', 'Hello--' ],
			'truncate to omission only'  => [ 'Hello World', 3, '...', '...' ],
			'empty string'               => [ '', 5, '...', '' ],
			'single char omission'       => [ 'abcdef', 4, '.', 'abc.' ],
			'unicode truncate'           => [ "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82 \xD0\xBC\xD0\xB8\xD1\x80", 6, '...', "\xD0\x9F\xD1\x80\xD0\xB8..." ],
		];
	}

	/**
	 * Test str_truncate with default omission argument.
	 */
	public function test_str_truncate_default_omission(): void {
		$this->assertSame( 'Hello...', \Woodev_Helper::str_truncate( 'Hello World', 8 ) );
	}

	/** String: str_to_sane_utf8 ***********************************************/

	/**
	 * @dataProvider provider_str_to_sane_utf8
	 *
	 * @param string $input
	 * @param string $expected
	 */
	public function test_str_to_sane_utf8( string $input, string $expected ): void {
		$this->assertSame( $expected, \Woodev_Helper::str_to_sane_utf8( $input ) );
	}

	/**
	 * Data provider for str_to_sane_utf8.
	 *
	 * @return array
	 */
	public function provider_str_to_sane_utf8(): array {
		return [
			'plain ascii'              => [ 'Hello World', 'Hello World' ],
			'letters and numbers'      => [ 'abc123', 'abc123' ],
			'punctuation preserved'    => [ 'Hello, World! How are you?', 'Hello, World! How are you?' ],
			'math symbols preserved'   => [ '2 + 2 = 4', '2 + 2 = 4' ],
			'currency preserved'       => [ '$100 or 50EUR', '$100 or 50EUR' ],  // Replaced euro sign with EUR to avoid encoding issues
			'accented chars preserved' => [ "\xC3\xA9\xC3\xA0\xC3\xBC", "\xC3\xA9\xC3\xA0\xC3\xBC" ],
			'cyrillic preserved'       => [ "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82", "\xD0\x9F\xD1\x80\xD0\xB8\xD0\xB2\xD0\xB5\xD1\x82" ],
			'control chars stripped'   => [ "Hello\x00World", 'HelloWorld' ],
			'empty string'             => [ '', '' ],
		];
	}

	/** Number: format_percentage **********************************************/

	/**
	 * @dataProvider provider_format_percentage
	 *
	 * @param float|int|string $fraction
	 * @param int|string|false $decimal_points
	 * @param bool             $trim_zeros
	 * @param mixed            $wc_format_return The value wc_format_decimal should return.
	 * @param string           $expected
	 */
	public function test_format_percentage( $fraction, $decimal_points, bool $trim_zeros, $wc_format_return, string $expected ): void {

		Functions\expect( 'wc_format_decimal' )
			->once()
			->with( $fraction * 100, $decimal_points, $trim_zeros )
			->andReturn( $wc_format_return );

		$this->assertSame( $expected, \Woodev_Helper::format_percentage( $fraction, $decimal_points, $trim_zeros ) );
	}

	/**
	 * Data provider for format_percentage.
	 *
	 * @return array
	 */
	public function provider_format_percentage(): array {
		return [
			'50 percent no decimals'     => [ 0.5, false, false, '50', '50%' ],
			'100 percent'                => [ 1.0, false, false, '100', '100%' ],
			'0 percent'                  => [ 0.0, false, false, '0', '0%' ],
			'33.33 percent 2 decimals'   => [ 0.3333, 2, false, '33.33', '33.33%' ],
			'50 percent trim zeros'      => [ 0.5, 2, true, '50', '50%' ],
			'12.50 percent with decimal' => [ 0.125, 2, false, '12.50', '12.50%' ],
			'fractional input'           => [ 0.007, 1, false, '0.7', '0.7%' ],
		];
	}

	/**
	 * Test format_percentage with default arguments.
	 */
	public function test_format_percentage_defaults(): void {

		Functions\expect( 'wc_format_decimal' )
			->once()
			->with( 75.0, false, false )
			->andReturn( '75' );

		$this->assertSame( '75%', \Woodev_Helper::format_percentage( 0.75 ) );
	}

	/** Array: array_insert_after **********************************************/

	/**
	 * Test inserting an element after a given key.
	 */
	public function test_array_insert_after_middle(): void {

		$array  = [ 'item_1' => 'foo', 'item_2' => 'bar', 'item_3' => 'baz' ];
		$result = \Woodev_Helper::array_insert_after( $array, 'item_1', [ 'item_1_5' => 'inserted' ] );

		$this->assertSame(
			[ 'item_1' => 'foo', 'item_1_5' => 'inserted', 'item_2' => 'bar', 'item_3' => 'baz' ],
			$result
		);
	}

	/**
	 * Test inserting after the last key.
	 */
	public function test_array_insert_after_last_key(): void {

		$array  = [ 'a' => 1, 'b' => 2 ];
		$result = \Woodev_Helper::array_insert_after( $array, 'b', [ 'c' => 3 ] );

		$this->assertSame( [ 'a' => 1, 'b' => 2, 'c' => 3 ], $result );
	}

	/**
	 * Test inserting when key is not found -- element is not added.
	 */
	public function test_array_insert_after_key_not_found(): void {

		$array  = [ 'a' => 1, 'b' => 2 ];
		$result = \Woodev_Helper::array_insert_after( $array, 'nonexistent', [ 'c' => 3 ] );

		// When key not found, the element is never inserted (loop completes without matching).
		$this->assertSame( [ 'a' => 1, 'b' => 2 ], $result );
	}

	/**
	 * Test inserting multiple elements at once.
	 */
	public function test_array_insert_after_multiple_elements(): void {

		$array  = [ 'a' => 1, 'c' => 3 ];
		$result = \Woodev_Helper::array_insert_after( $array, 'a', [ 'a1' => 'x', 'a2' => 'y' ] );

		$this->assertSame( [ 'a' => 1, 'a1' => 'x', 'a2' => 'y', 'c' => 3 ], $result );
	}

	/**
	 * Test inserting into an empty array.
	 */
	public function test_array_insert_after_empty_array(): void {

		$result = \Woodev_Helper::array_insert_after( [], 'key', [ 'a' => 1 ] );

		$this->assertSame( [], $result );
	}

	/**
	 * Test that key order is preserved after insertion.
	 */
	public function test_array_insert_after_preserves_order(): void {

		$array  = [ 'first' => 'a', 'second' => 'b', 'third' => 'c' ];
		$result = \Woodev_Helper::array_insert_after( $array, 'second', [ 'between' => 'x' ] );

		$this->assertSame(
			[ 'first', 'second', 'between', 'third' ],
			array_keys( $result )
		);
	}

	/** Array: list_array_items ************************************************/

	/**
	 * Test listing three items with default conjunction.
	 */
	public function test_list_array_items_three_items(): void {

		$result = \Woodev_Helper::list_array_items( [ 'one', 'two', 'three' ] );

		$this->assertSame( 'one, two, and three', $result );
	}

	/**
	 * Test listing two items (no comma, just conjunction).
	 */
	public function test_list_array_items_two_items(): void {

		$result = \Woodev_Helper::list_array_items( [ 'one', 'two' ] );

		$this->assertSame( 'one and two', $result );
	}

	/**
	 * Test listing a single item.
	 */
	public function test_list_array_items_single_item(): void {

		$result = \Woodev_Helper::list_array_items( [ 'only' ] );

		$this->assertSame( 'only', $result );
	}

	/**
	 * Test listing with empty array.
	 */
	public function test_list_array_items_empty_array(): void {

		$result = \Woodev_Helper::list_array_items( [] );

		$this->assertSame( '', $result );
	}

	/**
	 * Test listing with custom conjunction.
	 */
	public function test_list_array_items_custom_conjunction(): void {

		$result = \Woodev_Helper::list_array_items( [ 'cats', 'dogs', 'birds' ], 'or' );

		$this->assertSame( 'cats, dogs, or birds', $result );
	}

	/**
	 * Test listing two items with custom conjunction.
	 */
	public function test_list_array_items_two_items_custom_conjunction(): void {

		$result = \Woodev_Helper::list_array_items( [ 'left', 'right' ], 'or' );

		$this->assertSame( 'left or right', $result );
	}

	/**
	 * Test listing with custom separator.
	 */
	public function test_list_array_items_custom_separator(): void {

		$result = \Woodev_Helper::list_array_items( [ 'a', 'b', 'c' ], 'and', '; ' );

		$this->assertSame( 'a; b; and c', $result );
	}

	/** Number: number_format **************************************************/

	/**
	 * @dataProvider provider_number_format
	 *
	 * @param float  $number
	 * @param string $expected
	 */
	public function test_number_format( float $number, string $expected ): void {
		$this->assertSame( $expected, \Woodev_Helper::number_format( $number ) );
	}

	/**
	 * Data provider for number_format.
	 *
	 * @return array
	 */
	public function provider_number_format(): array {
		return [
			'whole number'           => [ 100.0, '100.00' ],
			'with decimals'          => [ 19.99, '19.99' ],
			'zero'                   => [ 0.0, '0.00' ],
			'large number no commas' => [ 1234567.89, '1234567.89' ],
			'rounds to 2 decimals'   => [ 9.999, '10.00' ],
			'single decimal padded'  => [ 5.5, '5.50' ],
			'negative number'        => [ -42.5, '-42.50' ],
			'very small number'      => [ 0.01, '0.01' ],
			'negative zero'          => [ -0.0, '0.00' ],
		];
	}
}
