<?php
/**
 * Woodev Pickup Schedule
 *
 * The structured weekly opening hours of a pickup point: validation of the carrier-supplied
 * shape and its single human-readable rendering.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Schedule' ) ) :

	/**
	 * Static helpers for the pickup-point weekly schedule (issue #152, variant (a): structure only).
	 *
	 * THE SHAPE. A schedule is a map from a lower-case English day key (`mon` … `sun`) to a
	 * list of `[ from, to ]` pairs of `HH:MM` strings:
	 *
	 *     [
	 *         'mon' => [ [ '09:00', '13:00' ], [ '14:00', '18:00' ] ],  // two intervals (a break)
	 *         'sat' => [],                                              // CLOSED that day
	 *         // 'sun' is absent                                        // UNKNOWN — the carrier did not say
	 *     ]
	 *
	 * The three states are deliberately distinct. An empty list is a claim ("closed"); an
	 * absent day is the lack of one ("unknown"). A flat string cannot tell them apart, and
	 * collapsing them is exactly the information loss this value exists to stop.
	 *
	 * OVERNIGHT INTERVALS. An interval whose end is not after its start (`[ '22:00', '02:00' ]`)
	 * is kept and means «from that time until that time on the NEXT day». It belongs to the
	 * day it is listed under (it starts there) and is rendered verbatim, `22:00–02:00`;
	 * nothing splits it across midnight or adds it to the following day. Real carriers
	 * publish such hours (late pickup lockers), and a schedule here is display-only, so no
	 * code needs to know where an interval ends. The one exception is an interval whose
	 * start and end are IDENTICAL (`[ '09:00', '09:00' ]`): that is neither «open for 0
	 * minutes» nor «open for 24 hours» with any certainty, so it is dropped as unreadable.
	 * `24:00` is accepted only as an end (`[ '00:00', '24:00' ]` is the whole day).
	 *
	 * NO TIME ZONE ARITHMETIC. The point's time zone, when a source has one, is carried
	 * alongside (see {@see self::normalize_time_zone()}) but never used to compute anything:
	 * Почта России gives no zone for a point, so an «open now» badge would be right for one
	 * carrier and a guess for the other (operator decision, 01.10.2026).
	 *
	 * @since 2.0.2
	 */
	final class Pickup_Schedule {

		/**
		 * Day keys in display order (Monday first).
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		public const DAYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];

		/**
		 * English fallbacks for the day labels, used only when WordPress's own locale object is
		 * not available (unit tests, a non-WP context). On a real site the labels come from
		 * `$wp_locale`, which is already translated — so this renderer adds no msgids.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private const FALLBACK_LABELS = [
			'mon' => 'Mon',
			'tue' => 'Tue',
			'wed' => 'Wed',
			'thu' => 'Thu',
			'fri' => 'Fri',
			'sat' => 'Sat',
			'sun' => 'Sun',
		];

		/**
		 * Index of each day key in WordPress's `WP_Locale::get_weekday()` (0 is SUNDAY there).
		 *
		 * @since 2.0.2
		 * @var array<string, int>
		 */
		private const WP_WEEKDAY_INDEX = [
			'mon' => 1,
			'tue' => 2,
			'wed' => 3,
			'thu' => 4,
			'fri' => 5,
			'sat' => 6,
			'sun' => 0,
		];

		/**
		 * Validates a raw weekly schedule and returns it in canonical form, or null.
		 *
		 * Defensive by design, never fatal on carrier data — the same rule
		 * {@see Pickup_Point::from_array()} applies to every optional field:
		 *
		 * - a non-array schedule, or one with no usable day, is `null` ("unknown");
		 * - an unknown day key, or a day whose value is not a list, is dropped;
		 * - an interval that is not a `[ from, to ]` pair of `HH:MM` strings (`from` and `to`
		 *   equal is also refused, `24:00` is accepted as an end only) is dropped; an OVERNIGHT
		 *   interval (end before start, e.g. `22:00`–`02:00`) is kept — see the class docblock;
		 * - a day that was given a NON-empty list of which nothing survived is dropped too —
		 *   reporting it as an empty list would turn "we could not read it" into "closed";
		 * - intervals within a day are sorted by start and de-duplicated;
		 * - days come out in {@see self::DAYS} order, whatever order the carrier used.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw Raw schedule from a carrier payload.
		 *
		 * @return array<string, array<int, array{0: string, 1: string}>>|null
		 */
		public static function normalize( $raw ): ?array {
			if ( ! is_array( $raw ) ) {
				return null;
			}

			$schedule = [];

			foreach ( self::DAYS as $day ) {
				if ( ! isset( $raw[ $day ] ) || ! is_array( $raw[ $day ] ) ) {
					continue;
				}

				$intervals = [];

				foreach ( $raw[ $day ] as $interval ) {
					$clean = self::normalize_interval( $interval );

					if ( null !== $clean ) {
						// Keyed by the pair so a repeated interval collapses to one.
						$intervals[ $clean[0] . '|' . $clean[1] ] = $clean;
					}
				}

				if ( [] === $intervals && [] !== $raw[ $day ] ) {
					continue;
				}

				ksort( $intervals, SORT_STRING );

				$schedule[ $day ] = array_values( $intervals );
			}

			return [] === $schedule ? null : $schedule;
		}

		/**
		 * Validates one `[ from, to ]` interval.
		 *
		 * Each clock is checked on its own; the ORDER of the two is deliberately not. An end
		 * that is earlier than the start is an overnight interval — «until that time on the
		 * next day» — and is returned as given. Only `from === to` is refused, because that
		 * pair says nothing (empty or round-the-clock?). `24:00` is valid as `to` only.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $interval Raw interval.
		 *
		 * @return array{0: string, 1: string}|null
		 */
		private static function normalize_interval( $interval ): ?array {
			if ( ! is_array( $interval ) || 2 !== count( $interval ) ) {
				return null;
			}

			$interval = array_values( $interval );
			$from     = $interval[0];
			$to       = $interval[1];

			if ( ! is_string( $from ) || ! is_string( $to ) ) {
				return null;
			}

			// `\z`, not `$`: PCRE's `$` also matches before a trailing newline.
			if ( 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d\z/', $from ) ) {
				return null;
			}

			// No `$to > $from` check on purpose: an earlier end is an overnight interval (class docblock).
			if ( 1 !== preg_match( '/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)\z/', $to ) || $from === $to ) {
				return null;
			}

			return [ $from, $to ];
		}

		/**
		 * Validates a point time zone and returns it, or null.
		 *
		 * Stored as given by the carrier and NEVER interpreted — so this only guards the shape:
		 * a short string of the characters a zone identifier or offset uses (`Europe/Moscow`,
		 * `UTC+3`, `+03:00`). Anything else (arrays, free text, blanks) is `null`.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw Raw time zone.
		 *
		 * @return string|null
		 */
		public static function normalize_time_zone( $raw ): ?string {
			if ( is_int( $raw ) ) {
				// Yandex's live payload sends a bare UTC offset in hours (`time_zone: 3`).
				return ( $raw >= -12 && $raw <= 14 ) ? sprintf( 'UTC%+d', $raw ) : null;
			}

			if ( ! is_string( $raw ) || 1 !== preg_match( '/^[A-Za-z0-9_+\-\/:]{1,64}\z/', $raw ) ) {
				return null;
			}

			return $raw;
		}

		/**
		 * Groups a canonical schedule into display rows.
		 *
		 * Consecutive days with identical opening intervals share one row (`Mon–Fri`). Closed
		 * and unknown days produce no row — the card shows when the point is open, and there is
		 * deliberately no «Closed» wording to translate. The labels are raw, UNESCAPED text.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, array<int, array{0: string, 1: string}>> $schedule Canonical schedule from {@see self::normalize()}.
		 *
		 * @return array<int, array{days: string, hours: string}>
		 */
		public static function rows( array $schedule ): array {
			$groups = [];

			foreach ( self::DAYS as $index => $day ) {
				if ( empty( $schedule[ $day ] ) || ! is_array( $schedule[ $day ] ) ) {
					continue;
				}

				$hours = implode(
					', ',
					array_map(
						static function ( array $interval ): string {
							return $interval[0] . '–' . $interval[1];
						},
						$schedule[ $day ]
					)
				);

				$last = count( $groups ) - 1;

				if ( $last >= 0 && $groups[ $last ]['end'] === $index - 1 && $groups[ $last ]['hours'] === $hours ) {
					$groups[ $last ]['end'] = $index;
					continue;
				}

				$groups[] = [
					'start' => $index,
					'end'   => $index,
					'hours' => $hours,
				];
			}

			return array_map(
				static function ( array $group ): array {
					$first = self::day_label( self::DAYS[ $group['start'] ] );

					return [
						'days'  => $group['start'] === $group['end']
							? $first
							: $first . '–' . self::day_label( self::DAYS[ $group['end'] ] ),
						'hours' => $group['hours'],
					];
				},
				$groups
			);
		}

		/**
		 * Renders a canonical schedule as the flat one-line string `work_time` has always been.
		 *
		 * The ONE place that string is derived from a schedule (e.g. `Mon–Fri 09:00–18:00; Sat
		 * 10:00–14:00`): {@see Pickup_Point::from_array()} calls it when a source gave a
		 * schedule and no `work_time` of its own, so every renderer that only knows the flat
		 * string keeps working. Unescaped.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, array<int, array{0: string, 1: string}>> $schedule Canonical schedule from {@see self::normalize()}.
		 *
		 * @return string
		 */
		public static function format( array $schedule ): string {
			return implode(
				'; ',
				array_map(
					static function ( array $row ): string {
						return $row['days'] . ' ' . $row['hours'];
					},
					self::rows( $schedule )
				)
			);
		}

		/**
		 * Abbreviated day name in the site's locale.
		 *
		 * `$wp_locale` is WordPress's own translated weekday table, so a Russian site gets
		 * «Пн» with no catalogue entry of ours; the English table is only the fallback when
		 * that object is absent.
		 *
		 * @since 2.0.2
		 *
		 * @param string $day A {@see self::DAYS} key.
		 *
		 * @return string
		 */
		private static function day_label( string $day ): string {
			global $wp_locale;

			if ( is_object( $wp_locale ) && method_exists( $wp_locale, 'get_weekday' ) && method_exists( $wp_locale, 'get_weekday_abbrev' ) ) {
				$label = $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( self::WP_WEEKDAY_INDEX[ $day ] ) );

				if ( is_string( $label ) && '' !== $label ) {
					return $label;
				}
			}

			return self::FALLBACK_LABELS[ $day ];
		}
	}

endif;
