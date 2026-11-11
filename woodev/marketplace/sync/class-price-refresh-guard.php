<?php
/**
 * Loop guard of the `from_marketplace` price write.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * A price written from a marketplace is saved under a guard flag so the save does not re-trigger the push to **that**
 * marketplace. The guard is a flag per marketplace for the duration of one PHP request — not a stored lock, which a crashed
 * request would leave behind: while {@see self::run()} executes, the {@see Price_Intents} of the same marketplace ignores the
 * product-saved hook.
 *
 * It is scoped to the marketplace on purpose. The class is shared by every marketplace plugin on the site, and a shop may take
 * its prices from one marketplace and push them to another: that other plugin must see the save.
 *
 * @since 2.0.30
 */
final class Price_Refresh_Guard {
	/**
	 * Nesting depth of running guarded work, by marketplace id.
	 *
	 * @var array<string,int>
	 */
	private static $depth = [];

	/**
	 * Runs work with the guard of a marketplace raised; the guard drops when the work ends, however it ends.
	 *
	 * @since 2.0.30
	 *
	 * @param string   $marketplace_id The adapter's id of the marketplace the price comes from.
	 * @param callable $work           `function (): mixed`.
	 * @return mixed What the work returned.
	 */
	public static function run( string $marketplace_id, callable $work ) {
		self::$depth[ $marketplace_id ] = ( self::$depth[ $marketplace_id ] ?? 0 ) + 1;

		try {
			return call_user_func( $work );
		} finally {
			--self::$depth[ $marketplace_id ];
		}
	}

	/**
	 * Tells whether a guarded write of a marketplace is running in this request.
	 *
	 * @since 2.0.30
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return bool
	 */
	public static function is_active( string $marketplace_id ): bool {
		return ( self::$depth[ $marketplace_id ] ?? 0 ) > 0;
	}
}
