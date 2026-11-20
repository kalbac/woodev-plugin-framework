<?php
/**
 * How long ago something happened, in Russian, whatever the site's locale.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * «только что», «5 минут назад», «1 день назад», «3 месяца назад». `human_time_diff()` follows the site's locale, so on an English
 * site a Russian sentence got «4 days назад»; the marketplace screens are Russian whatever the locale, so the words are ours, the
 * plural rule is {@see Russian_Plural}'s, and every screen that says an age says it the same way.
 *
 * @since 2.1.13
 */
final class Russian_Age {
	private const MINUTE = 60;
	private const HOUR   = 3600;
	private const DAY    = 86400;
	private const MONTH  = 2592000; // 30 days.
	private const YEAR   = 31536000; // 365 days.

	/**
	 * The age of something that happened `$seconds` ago.
	 *
	 * @since 2.1.13
	 *
	 * @param int $seconds Whole seconds since it happened; a negative number counts as none.
	 * @return string
	 */
	public static function ago( int $seconds ): string {
		$seconds = max( 0, $seconds );

		if ( $seconds < self::MINUTE ) {
			return __( 'только что', 'woodev-plugin-framework' );
		}

		if ( $seconds < self::HOUR ) {
			$minutes = intdiv( $seconds, self::MINUTE );

			return self::sentence( $minutes, __( 'минуту', 'woodev-plugin-framework' ), __( 'минуты', 'woodev-plugin-framework' ), __( 'минут', 'woodev-plugin-framework' ) );
		}

		if ( $seconds < self::DAY ) {
			$hours = intdiv( $seconds, self::HOUR );

			return self::sentence( $hours, __( 'час', 'woodev-plugin-framework' ), __( 'часа', 'woodev-plugin-framework' ), __( 'часов', 'woodev-plugin-framework' ) );
		}

		if ( $seconds < self::MONTH ) {
			$days = intdiv( $seconds, self::DAY );

			return self::sentence( $days, __( 'день', 'woodev-plugin-framework' ), __( 'дня', 'woodev-plugin-framework' ), __( 'дней', 'woodev-plugin-framework' ) );
		}

		if ( $seconds < self::YEAR ) {
			$months = intdiv( $seconds, self::MONTH );

			return self::sentence( $months, __( 'месяц', 'woodev-plugin-framework' ), __( 'месяца', 'woodev-plugin-framework' ), __( 'месяцев', 'woodev-plugin-framework' ) );
		}

		$years = intdiv( $seconds, self::YEAR );

		return self::sentence( $years, __( 'год', 'woodev-plugin-framework' ), __( 'года', 'woodev-plugin-framework' ), __( 'лет', 'woodev-plugin-framework' ) );
	}

	/**
	 * «5 минут назад»: the number, the noun in its form, «назад».
	 *
	 * @param int    $number Counted number.
	 * @param string $one    Form for 1, 21…
	 * @param string $few    Form for 2–4, 22–24…
	 * @param string $many   Form for 0, 5–20…
	 * @return string
	 */
	private static function sentence( int $number, string $one, string $few, string $many ): string {
		/* translators: 1: number, 2: unit in its Russian form («минут», «дня»). */
		return sprintf( __( '%1$d %2$s назад', 'woodev-plugin-framework' ), $number, Russian_Plural::pick( $number, $one, $few, $many ) );
	}
}
