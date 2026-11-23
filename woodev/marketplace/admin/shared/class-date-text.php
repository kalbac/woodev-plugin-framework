<?php
/**
 * The one way a marketplace screen writes a date.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * «19.02.2027» and «19.02.2027 14:05», in the site's timezone, whatever the site's locale and its own date settings. A month's name
 * follows the locale («22 Nov 2026» on an English site, «22 ноября 2026» on a Russian one) and the site's date format is a sentence
 * a table cell cannot hold, so the marketplace screens write the numbers: every date of the plugin reads the same on every screen.
 * The browser counterpart is `DATE_FORMAT` / `DATETIME_FORMAT` in `src/admin-records/cells.tsx`.
 *
 * @since 2.1.16
 */
final class Date_Text {
	/**
	 * A date: day, month, year.
	 */
	public const DATE = 'd.m.Y';

	/**
	 * A date with the time of day.
	 */
	public const DATETIME = 'd.m.Y H:i';

	/**
	 * The time of day alone.
	 */
	public const TIME = 'H:i';

	/**
	 * A date, like «19.02.2027».
	 *
	 * @since 2.1.16
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function date( int $timestamp ): string {
		return (string) wp_date( self::DATE, $timestamp );
	}

	/**
	 * A date with the time, like «19.02.2027 14:05».
	 *
	 * @since 2.1.16
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function datetime( int $timestamp ): string {
		return (string) wp_date( self::DATETIME, $timestamp );
	}

	/**
	 * The time of day, like «14:05».
	 *
	 * @since 2.1.16
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function time( int $timestamp ): string {
		return (string) wp_date( self::TIME, $timestamp );
	}
}
