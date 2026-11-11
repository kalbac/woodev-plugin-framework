<?php
/**
 * The retry ladder of the jobs.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * How long a job waits after a failed attempt: 1, 3, 7, 15, then 30 minutes. One ladder for every job, so a merchant (and
 * support) can predict when a failed send is tried again whatever it was.
 *
 * @since 2.1.0
 */
final class Backoff {
	/**
	 * Minutes to wait after the first, second … failed attempt.
	 *
	 * @var int[]
	 */
	public const MINUTES = [ 1, 3, 7, 15, 30 ];

	/**
	 * Returns the wait after a failed attempt.
	 *
	 * @since 2.1.0
	 *
	 * @param int $failures Failed attempts so far, counting the one that just failed; anything past the ladder waits its last step.
	 * @return int Seconds.
	 */
	public static function seconds_after( int $failures ): int {
		$step = max( 0, min( $failures, count( self::MINUTES ) ) - 1 );

		return self::MINUTES[ $step ] * 60;
	}
}
