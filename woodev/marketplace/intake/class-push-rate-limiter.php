<?php
/**
 * Per-URL rate limit for the notification endpoint.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * 600 requests a minute per account token: a runaway retry storm or a leaked URL must not fill the events table. The budget is
 * per token, not per address, so it rides the framework trait's atomic counter
 * ({@see \Woodev\Framework\Http\Rest_Rate_Limit_Trait::increment_rate_limit_counter()}) and keeps the fixed-window key
 * discipline of the trait: the window id is part of the key, so a window closes on schedule however busy it was.
 *
 * Not final: a test replaces the counter and the clock, the two seams the trait leaves protected.
 *
 * @since 2.1.0
 */
class Push_Rate_Limiter {
	use \Woodev\Framework\Http\Rest_Rate_Limit_Trait;

	public const BUDGET = 600;
	public const WINDOW = 60;

	/**
	 * Spends one request of a token's budget.
	 *
	 * @since 2.1.0
	 *
	 * @param string $token Token from the URL.
	 * @return bool True when the token has already used up this minute.
	 */
	public function exceeded( string $token ): bool {
		$key = 'woodev_mp_push_' . md5( $token ) . '_' . (int) floor( $this->rate_limit_now() / self::WINDOW );

		return $this->increment_rate_limit_counter( $key, self::WINDOW * 2 ) > self::BUDGET;
	}
}
