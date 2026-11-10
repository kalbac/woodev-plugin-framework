<?php
/**
 * Credential expiry arithmetic.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Turns "this key stops working at T" into whole days left and a warning tier. Pure: the clock is an argument, so the status line, the
 * admin notice and the tests agree on one rule.
 *
 * The tiers are 30, 7 and 1 days (spec 8.1). `days_left()` counts whole days, so 0 means "less than a day" and the 1-day tier covers it.
 *
 * @since 2.0.29
 */
final class Key_Expiry {

	/**
	 * Warning tiers in days, widest first.
	 *
	 * @var int[]
	 */
	public const TIERS = [ 30, 7, 1 ];

	/**
	 * Tier value of a key that has already expired.
	 *
	 * @var int
	 */
	public const TIER_EXPIRED = 0;

	/**
	 * Whole days left until the expiry; negative once it has passed.
	 *
	 * @since 2.0.29
	 *
	 * @param \DateTimeImmutable $expires_at When the key stops working.
	 * @param \DateTimeImmutable $now        Current time.
	 * @return int
	 */
	public static function days_left( \DateTimeImmutable $expires_at, \DateTimeImmutable $now ): int {
		$seconds = $expires_at->getTimestamp() - $now->getTimestamp();

		return (int) floor( $seconds / 86400 );
	}

	/**
	 * Tells whether the key has already stopped working.
	 *
	 * @since 2.0.29
	 *
	 * @param \DateTimeImmutable $expires_at When the key stops working.
	 * @param \DateTimeImmutable $now        Current time.
	 * @return bool
	 */
	public static function is_expired( \DateTimeImmutable $expires_at, \DateTimeImmutable $now ): bool {
		return $expires_at->getTimestamp() <= $now->getTimestamp();
	}

	/**
	 * Returns the warning tier a key is in: the narrowest of 30, 7 and 1 that still covers the days left, {@see self::TIER_EXPIRED}
	 * once it has expired, or null while there is more than 30 days (or the expiry is unknown).
	 *
	 * @since 2.0.29
	 *
	 * @param \DateTimeImmutable|null $expires_at When the key stops working; null when unknown.
	 * @param \DateTimeImmutable      $now        Current time.
	 * @return int|null
	 */
	public static function tier( ?\DateTimeImmutable $expires_at, \DateTimeImmutable $now ): ?int {
		if ( null === $expires_at ) {
			return null;
		}

		if ( self::is_expired( $expires_at, $now ) ) {
			return self::TIER_EXPIRED;
		}

		$days = self::days_left( $expires_at, $now );
		$tier = null;

		foreach ( self::TIERS as $candidate ) {
			if ( $days <= $candidate ) {
				$tier = $candidate;
			}
		}

		return $tier;
	}
}
