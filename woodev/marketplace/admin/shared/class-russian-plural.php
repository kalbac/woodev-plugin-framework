<?php
/**
 * The Russian plural form of a counted noun, whatever the site's locale.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Picks «заказ» / «заказа» / «заказов» by the number. `_n()` cannot do it alone: it follows the plural rule of the site's locale, so a
 * site that is not set to Russian gets the English one (one form for 1, another for everything else) and shows «4 заказов». The
 * marketplace screens are written in Russian whatever the locale, so the rule is Russian too.
 *
 * @since 2.1.13
 */
final class Russian_Plural {
	/**
	 * @since 2.1.13
	 *
	 * @param int    $number Counted number.
	 * @param string $one    Form for 1, 21, 31… («заказ»).
	 * @param string $few    Form for 2–4, 22–24… («заказа»).
	 * @param string $many   Form for 0, 5–20, 25–30… («заказов»).
	 * @return string
	 */
	public static function pick( int $number, string $one, string $few, string $many ): string {
		$number   = abs( $number );
		$last     = $number % 10;
		$last_two = $number % 100;

		if ( 1 === $last && 11 !== $last_two ) {
			return $one;
		}

		if ( $last >= 2 && $last <= 4 && ( $last_two < 12 || $last_two > 14 ) ) {
			return $few;
		}

		return $many;
	}

	/**
	 * The days left of a key as a phrase with the right plural: «ещё 1 день», «ещё 3 дня», «ещё 77 дней».
	 *
	 * @since 2.1.13
	 *
	 * @param int $days Whole days left.
	 * @return string
	 */
	public static function days_left( int $days ): string {
		return sprintf(
			/* translators: 1: number of days, 2: the word «день» in the form that goes with it. */
			__( 'ещё %1$d %2$s', 'woodev-plugin-framework' ),
			$days,
			self::pick( $days, __( 'день', 'woodev-plugin-framework' ), __( 'дня', 'woodev-plugin-framework' ), __( 'дней', 'woodev-plugin-framework' ) )
		);
	}
}
