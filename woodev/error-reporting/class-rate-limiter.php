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

		/** Browser reports sent per UTC day — a budget of their own, so guests cannot spend the PHP one. */
		const DEFAULT_BROWSER_DAILY_CAP = 10;

		/** Browser reports the whole site may queue per UTC hour. */
		const DEFAULT_BROWSER_HOURLY_CAP = 30;

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
		 * Browser events have their OWN daily budget: guests can feed them, so they must not be able to
		 * spend the budget the PHP events need.
		 *
		 * @since 2.0.2
		 *
		 * @param string $signature From {@see self::signature()}.
		 * @param bool   $browser   Whether the event came from the browser ({@see Event_Queue::is_browser()}).
		 * @return bool
		 */
		public function allow( string $signature, bool $browser = false ): bool {
			/**
			 * Filters how many hours the same error is suppressed after being sent.
			 *
			 * @since 2.0.2
			 *
			 * @param int $hours Default 6.
			 */
			$hours = max( 1, (int) apply_filters( 'woodev_error_reporting_dedupe_hours', self::DEFAULT_DEDUPE_HOURS ) );

			if ( $browser ) {
				/**
				 * Filters the most reports a site sends per UTC day that came from the visitors' BROWSERS
				 * (kept apart from the PHP budget); 0 sends none.
				 *
				 * @since 2.0.2
				 *
				 * @param int $cap Default 10.
				 */
				$cap     = max( 0, (int) apply_filters( 'woodev_error_reporting_browser_daily_cap', self::DEFAULT_BROWSER_DAILY_CAP ) );
				$day_key = 'woodev_er_day_js_' . gmdate( 'Ymd' );
			} else {
				/**
				 * Filters the most reports a site sends per UTC day; 0 sends nothing.
				 *
				 * @since 2.0.2
				 *
				 * @param int $cap Default 20.
				 */
				$cap     = max( 0, (int) apply_filters( 'woodev_error_reporting_daily_cap', self::DEFAULT_DAILY_CAP ) );
				$day_key = 'woodev_er_day_' . gmdate( 'Ymd' );
			}

			$seen_key = 'woodev_er_sig_' . substr( $signature, 0, 32 );

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

		/**
		 * Site-wide throughput cap for browser reports, checked BEFORE one is queued.
		 *
		 * The per-client limit of the REST route is a fairness bucket that a caller with several addresses
		 * (or a forged forwarding header) can widen; this counts every browser event the whole site accepts
		 * for queueing in the current UTC hour, whoever sent it. Read-check-write is not atomic and does not
		 * need to be: a race can let a few extra reports in, never an unbounded number — the queue's own
		 * browser share ({@see Event_Queue::BROWSER_LIMIT}) and the daily budget bound the rest.
		 *
		 * @since 2.0.2
		 *
		 * @return bool True when the report may be queued (and is counted).
		 */
		public function allow_browser_intake(): bool {
			/**
			 * Filters the most browser reports one site queues per UTC hour; 0 queues none.
			 *
			 * @since 2.0.2
			 *
			 * @param int $cap Default 30.
			 */
			$cap = max( 0, (int) apply_filters( 'woodev_error_reporting_browser_hourly_cap', self::DEFAULT_BROWSER_HOURLY_CAP ) );
			$key = 'woodev_er_js_in_' . gmdate( 'YmdH' );

			$taken = (int) get_transient( $key );

			if ( $taken >= $cap ) {
				return false;
			}

			set_transient( $key, $taken + 1, 2 * HOUR_IN_SECONDS );

			return true;
		}
	}

endif;
