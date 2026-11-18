<?php
/**
 * The Russian plural form of a counted noun.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Admin\Shared\Russian_Plural;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Russian_Plural
 */
final class RussianPluralTest extends TestCase {

	/**
	 * @return array<string,array{0:int,1:string}>
	 */
	public function counts(): array {
		return [
			'0'   => [ 0, 'заказов' ],
			'1'   => [ 1, 'заказ' ],
			'2'   => [ 2, 'заказа' ],
			'4'   => [ 4, 'заказа' ],
			'5'   => [ 5, 'заказов' ],
			'11'  => [ 11, 'заказов' ],
			'12'  => [ 12, 'заказов' ],
			'14'  => [ 14, 'заказов' ],
			'21'  => [ 21, 'заказ' ],
			'22'  => [ 22, 'заказа' ],
			'25'  => [ 25, 'заказов' ],
			'101' => [ 101, 'заказ' ],
			'111' => [ 111, 'заказов' ],
		];
	}

	/**
	 * @dataProvider counts
	 *
	 * @param int    $number   Number.
	 * @param string $expected Form.
	 */
	public function test_the_form_follows_the_russian_rule_whatever_the_locale( int $number, string $expected ): void {
		$this->assertSame( $expected, Russian_Plural::pick( $number, 'заказ', 'заказа', 'заказов' ) );
	}
}
