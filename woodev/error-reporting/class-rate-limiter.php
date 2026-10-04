<?php
/**
 * Client-side dedupe and daily cap for error reports.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Rate_Limiter' ) ) :

	/**
	 * Keeps one broken page from hammering the receiver: the same error is sent at most once
	 * per window, and a site sends at most N reports per UTC day. State lives in transients.
	 *
	 * Read-check-write here is not atomic, and does not need to be: the only caller is
	 * {@see Dispatcher}, which runs under its drain lock, so two senders never interleave.
	 *
	 * @since 2.0.2
	 */
	final class Rate_Limiter {

		const DEFAULT_DEDUPE_HOURS = 6;
		const DEFAULT_DAILY_CAP    = 20;

		/**
		 * A stable identity for an event: type + throw-site file + line + message hash.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $event Event from {@see Event_Builder}.
		 * @return string
		 */
		public function signature( array $event ): string {
			$value  = $event['exception']['values'][0] ?? [];
			$frames = $value['stacktrace']['frames'] ?? [];
			$throw  = [] === $frames ? [] : $frames[ count( $frames ) - 1 ]; // Oldest first, so the throw site is last.

			return sha1(
				implode(
					'|',
					[
						(string) ( $value['type'] ?? '' ),
						(string) ( $throw['filename'] ?? '' ),
						(string) ( $throw['lineno'] ?? '' ),
						md5( (string) ( $value['value'] ?? '' ) ),
					]
				)
			);
		}

		/**
		 * Decides whether a report with this signature may be sent now, and books it if so.
		 *
		 * @since 2.0.2
		 *
		 * @param string $signature From {@see self::signature()}.
		 * @return bool
		 */
		public function allow( string $signature ): bool {
			/**
			 * Filters how many hours the same error is suppressed after being sent.
			 *
			 * @since 2.0.2
			 *
			 * @param int $hours Default 6.
			 */
			$hours = max( 1, (int) apply_filters( 'woodev_error_reporting_dedupe_hours', self::DEFAULT_DEDUPE_HOURS ) );

			/**
			 * Filters the most reports a site sends per UTC day; 0 sends nothing.
			 *
			 * @since 2.0.2
			 *
			 * @param int $cap Default 20.
			 */
			$cap = max( 0, (int) apply_filters( 'woodev_error_reporting_daily_cap', self::DEFAULT_DAILY_CAP ) );

			$seen_key = 'woodev_er_sig_' . substr( $signature, 0, 32 );
			$day_key  = 'woodev_er_day_' . gmdate( 'Ymd' );

			if ( false !== get_transient( $seen_key ) ) {
				return false;
			}

			$sent_today = (int) get_transient( $day_key );

			if ( $sent_today >= $cap ) {
				return false;
			}

			set_transient( $seen_key, 1, $hours * HOUR_IN_SECONDS );
			set_transient( $day_key, $sent_today + 1, 2 * DAY_IN_SECONDS );

			return true;
		}
	}

endif;
