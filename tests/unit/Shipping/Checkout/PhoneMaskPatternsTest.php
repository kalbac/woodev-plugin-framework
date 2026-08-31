<?php
/**
 * Unit tests for Phone_Mask_Patterns — card #503's country → mask template table.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Phone_Mask_Patterns;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-phone-mask-patterns.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Phone_Mask_Patterns
 */
final class PhoneMaskPatternsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_ru_and_cis_countries_are_present(): void {
		$patterns = Phone_Mask_Patterns::get();

		foreach ( [ 'RU', 'KZ', 'BY', 'UA', 'AM', 'AZ', 'GE', 'KG', 'TJ', 'TM', 'UZ', 'MD' ] as $code ) {
			$this->assertArrayHasKey( $code, $patterns, "Expected a mask template for {$code}." );
			$this->assertStringContainsString( '#', $patterns[ $code ], "{$code}'s template has no digit placeholder." );
		}
	}

	public function test_a_country_without_a_template_is_absent(): void {
		$patterns = Phone_Mask_Patterns::get();

		$this->assertArrayNotHasKey( 'DE', $patterns );
		$this->assertArrayNotHasKey( 'US', $patterns );
	}

	public function test_filter_can_add_a_country(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) {
				if ( Phone_Mask_Patterns::FILTER_PATTERNS === $tag ) {
					$value['DE'] = '+49 ### #######';
				}
				return $value;
			}
		);

		$patterns = Phone_Mask_Patterns::get();

		$this->assertSame( '+49 ### #######', $patterns['DE'] );
		// The built-in table is still there — the filter EXTENDS, it doesn't replace.
		$this->assertArrayHasKey( 'RU', $patterns );
	}

	public function test_filter_can_override_a_built_in_country(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) {
				if ( Phone_Mask_Patterns::FILTER_PATTERNS === $tag ) {
					$value['RU'] = '+7 ##########';
				}
				return $value;
			}
		);

		$this->assertSame( '+7 ##########', Phone_Mask_Patterns::get()['RU'] );
	}
}
