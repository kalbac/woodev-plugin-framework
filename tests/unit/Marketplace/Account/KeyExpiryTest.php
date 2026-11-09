<?php
/**
 * Warning tiers of an API key that is about to expire.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Account;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Key_Expiry;

/**
 * 30, 7 and 1 days (spec 8.1), counted in whole days.
 */
final class KeyExpiryTest extends TestCase {
	private const NOW = 1794139200; // 2026-11-08 12:00:00 UTC.

	/**
	 * Days left and tier for a key that ends a given number of seconds from now.
	 *
	 * @return array<string,array{0:int,1:int|null,2:int}>
	 */
	public function provide_expiries(): array {
		$day = 86400;

		return array(
			'100 days'             => array( 100 * $day, null, 100 ),
			'31 days'              => array( 31 * $day, null, 31 ),
			'30 days exactly'      => array( 30 * $day, 30, 30 ),
			'8 days'               => array( 8 * $day, 30, 8 ),
			'7 days exactly'       => array( 7 * $day, 7, 7 ),
			'2 days'               => array( 2 * $day, 7, 2 ),
			'1 day exactly'        => array( $day, 1, 1 ),
			'a day and a half'     => array( 129600, 1, 1 ),
			'half a day'           => array( 43200, 1, 0 ),
			'a second'             => array( 1, 1, 0 ),
			'ends this second'     => array( 0, Key_Expiry::TIER_EXPIRED, 0 ),
			'expired a second ago' => array( -1, Key_Expiry::TIER_EXPIRED, -1 ),
			'expired long ago'     => array( -90 * $day, Key_Expiry::TIER_EXPIRED, -90 ),
		);
	}

	/**
	 * Each expiry lands in the narrowest tier that covers it.
	 *
	 * @dataProvider provide_expiries
	 *
	 * @param int      $seconds  Seconds from now to the expiry.
	 * @param int|null $tier     Expected tier.
	 * @param int      $days     Expected whole days left.
	 * @return void
	 */
	public function test_the_tier_and_the_days_left( int $seconds, ?int $tier, int $days ): void {
		$now     = new \DateTimeImmutable( '@' . self::NOW );
		$expires = new \DateTimeImmutable( '@' . ( self::NOW + $seconds ) );

		$this->assertSame( $tier, Key_Expiry::tier( $expires, $now ) );
		$this->assertSame( $days, Key_Expiry::days_left( $expires, $now ) );
		$this->assertSame( $seconds <= 0, Key_Expiry::is_expired( $expires, $now ) );
	}

	/**
	 * An unknown expiry has no tier.
	 *
	 * @return void
	 */
	public function test_an_unknown_expiry_has_no_tier(): void {
		$this->assertNull( Key_Expiry::tier( null, new \DateTimeImmutable( '@' . self::NOW ) ) );
	}
}
