<?php
/**
 * The one date format of the marketplace screens.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Shared\Date_Text;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Date_Text
 */
final class DateTextTest extends TestCase {
	/**
	 * 19.02.2027 14:05:00 UTC.
	 */
	private const TIME = 1803045900;

	/**
	 * @var array<int,string> The formats `wp_date()` was asked for.
	 */
	private $formats = [];

	protected function setUp(): void {
		parent::setUp();

		$this->formats = [];
		Functions\when( 'wp_date' )->alias(
			function ( string $format, int $time ): string {
				$this->formats[] = $format;

				return gmdate( $format, $time );
			}
		);
	}

	public function test_a_date_is_day_month_year_with_dots(): void {
		$this->assertSame( '19.02.2027', Date_Text::date( self::TIME ) );
	}

	public function test_a_date_with_time_adds_hours_and_minutes(): void {
		$this->assertSame( '19.02.2027 14:05', Date_Text::datetime( self::TIME ) );
	}

	public function test_a_time_is_hours_and_minutes(): void {
		$this->assertSame( '14:05', Date_Text::time( self::TIME ) );
	}

	public function test_no_format_carries_a_month_name_that_follows_the_locale(): void {
		Date_Text::date( self::TIME );
		Date_Text::datetime( self::TIME );
		Date_Text::time( self::TIME );

		$this->assertSame( [ 'd.m.Y', 'd.m.Y H:i', 'H:i' ], $this->formats );
		$this->assertDoesNotMatchRegularExpression( '/[FMlDS]/', implode( '', $this->formats ), 'F, M, l, D and S are words of the site locale' );
	}
}
