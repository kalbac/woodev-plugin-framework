<?php
/**
 * How long ago, in Russian, whatever the site's locale.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Admin\Shared\Russian_Age;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Russian_Age
 */
final class RussianAgeTest extends TestCase {
	/**
	 * @return array<string,array{0:int,1:string}>
	 */
	public function ages(): array {
		return [
			'a few seconds'      => [ 30, 'только что' ],
			'a negative age'     => [ -5, 'только что' ],
			'one minute'         => [ 60, '1 минуту назад' ],
			'two minutes'        => [ 125, '2 минуты назад' ],
			'eleven minutes'     => [ 11 * 60, '11 минут назад' ],
			'twenty-one minutes' => [ 21 * 60, '21 минуту назад' ],
			'one hour'           => [ 3600, '1 час назад' ],
			'three hours'        => [ 3 * 3600 + 10, '3 часа назад' ],
			'five hours'         => [ 5 * 3600, '5 часов назад' ],
			'one day'            => [ 86400, '1 день назад' ],
			'four days'          => [ 4 * 86400 + 100, '4 дня назад' ],
			'twelve days'        => [ 12 * 86400, '12 дней назад' ],
			'two months'         => [ 65 * 86400, '2 месяца назад' ],
			'five months'        => [ 160 * 86400, '5 месяцев назад' ],
			'one year'           => [ 366 * 86400, '1 год назад' ],
			'three years'        => [ 3 * 366 * 86400, '3 года назад' ],
			'seven years'        => [ 7 * 366 * 86400, '7 лет назад' ],
		];
	}

	/**
	 * @dataProvider ages
	 */
	public function test_it_says_the_age_in_one_russian_form(int $seconds, string $expected): void {
		$this->assertSame( $expected, Russian_Age::ago( $seconds ) );
	}
}
