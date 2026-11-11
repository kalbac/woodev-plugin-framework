<?php
/**
 * Loop guard of the `from_marketplace` price write.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * A price written from the marketplace is saved under a guard flag so the save does not re-trigger the push. The guard is one
 * static flag for the duration of one PHP request — not a stored lock, which a crashed request would leave behind: while
 * {@see self::run()} executes, {@see Price_Intents} ignores the product-saved hook.
 *
 * @since 2.0.30
 */
final class Price_Refresh_Guard {
	/**
	 * Nesting depth of running guarded work.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Runs work with the guard raised; the guard drops when the work ends, however it ends.
	 *
	 * @since 2.0.30
	 *
	 * @param callable $work `function (): mixed`.
	 * @return mixed What the work returned.
	 */
	public static function run( callable $work ) {
		++self::$depth;

		try {
			return call_user_func( $work );
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Tells whether a guarded write is running in this request.
	 *
	 * @since 2.0.30
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$depth > 0;
	}
}
