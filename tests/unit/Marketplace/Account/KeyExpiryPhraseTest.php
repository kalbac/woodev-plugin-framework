<?php
/**
 * The days-left phrase of the key expiry lines.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Account;

use Woodev\Framework\Marketplace\Account\Key_Expiry;
use Woodev\Tests\Unit\TestCase;

/**
 * «ещё 1 день», «ещё 3 дня», «ещё 77 дней»: the Russian plural, whatever the site's locale.
 *
 * @covers \Woodev\Framework\Marketplace\Account\Key_Expiry::days_left_phrase
 */
final class KeyExpiryPhraseTest extends TestCase {

	/**
	 * @return array<string,array{0:int,1:string}>
	 */
	public function provide_days(): array {
		return [
			'one'            => [ 1, 'ещё 1 день' ],
			'two'            => [ 2, 'ещё 2 дня' ],
			'five'           => [ 5, 'ещё 5 дней' ],
			'eleven'         => [ 11, 'ещё 11 дней' ],
			'twenty one'     => [ 21, 'ещё 21 день' ],
			'twenty two'     => [ 22, 'ещё 22 дня' ],
			'seventy seven'  => [ 77, 'ещё 77 дней' ],
			'a hundred four' => [ 104, 'ещё 104 дня' ],
		];
	}

	/**
	 * @dataProvider provide_days
	 *
	 * @param int    $days     Days left.
	 * @param string $expected The phrase.
	 * @return void
	 */
	public function test_the_phrase_takes_the_russian_plural( int $days, string $expected ): void {
		$this->assertSame( $expected, Key_Expiry::days_left_phrase( $days ) );
	}
}
