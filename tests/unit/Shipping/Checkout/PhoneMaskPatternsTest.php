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

	/**
	 * The bug this pins actually shipped. Card #503's twelve templates were typed by hand and one
	 * was wrong: Turkmenistan accepted SEVEN national digits where the real length is EIGHT, so a
	 * valid TM number could not be entered at all. It was found by checking the table against
	 * libphonenumber metadata on 31.08.2026, and the table is generated from that metadata now
	 * (`bin/generate-phone-masks.mjs`).
	 *
	 * The expected lengths below are a COMMITTED FIXTURE rather than a live library call: the unit
	 * suite must not depend on a devDependency being installed, and a number plan changing under us
	 * should fail loudly here rather than silently re-deriving itself.
	 *
	 * A cosmetic override (RU/KZ carry the bracket grouping the operator asked for) may re-group and
	 * re-punctuate freely; it may not change the calling code or the digit count. That is exactly
	 * what this asserts.
	 */
	public function test_every_template_matches_its_country_number_plan(): void {
		$expected = [
			'RU' => [ '7', 10 ],
			'KZ' => [ '7', 10 ],
			'BY' => [ '375', 9 ],
			'UA' => [ '380', 9 ],
			'AM' => [ '374', 8 ],
			'AZ' => [ '994', 9 ],
			'GE' => [ '995', 9 ],
			'KG' => [ '996', 9 ],
			'TJ' => [ '992', 9 ],
			'TM' => [ '993', 8 ],
			'UZ' => [ '998', 9 ],
			'MD' => [ '373', 8 ],
		];

		$patterns = Phone_Mask_Patterns::get();

		$this->assertSame(
			array_keys( $expected ),
			array_keys( $patterns ),
			'the shipped country list drifted from the one this test knows the number plans for'
		);

		foreach ( $expected as $iso => [ $calling_code, $digits ] ) {
			$template = $patterns[ $iso ];

			$this->assertSame(
				1,
				preg_match( '/^\+(\d+)/', $template, $m ),
				"{$iso}: template does not start with a literal calling code"
			);
			$this->assertSame( $calling_code, $m[1], "{$iso}: wrong calling code" );

			$national = substr_count( substr( $template, strlen( $m[0] ) ), '#' );

			$this->assertSame(
				$digits,
				$national,
				"{$iso}: template takes {$national} national digits, the number plan is {$digits}"
			);
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
