<?php
/**
 * Sends the queued error events from WP-Cron.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Dispatcher' ) ) :

	/**
	 * The one place that talks to the network. A failing request only enqueues; this runs later,
	 * in a cron request nobody is waiting on, and applies — in this one place — consent and DSN
	 * (re-read now, not at enqueue time), the per-error dedupe and the daily cap.
	 *
	 * Two cron runs must not both send, so a drain first takes a short lock by an atomic
	 * `INSERT IGNORE` of an option row (the pattern WordPress core uses for its upgrade lock). A
	 * lock older than {@see self::LOCK_TTL} belongs to a crashed run and is taken over — again
	 * atomically, by a compare-and-set on the timestamp.
	 *
	 * Delivery is best effort: a failed post stops the batch and the batch is dropped.
	 *
	 * @since 2.0.2
	 */
	final class Dispatcher {

		/** Single-event cron hook that drains the queue. */
		const HOOK = 'woodev_error_reporting_dispatch';

		/** Option row that is the drain lock. */
		const LOCK_OPTION = 'woodev_error_reporting_lock';

		/** Seconds after which a lock is considered abandoned. */
		const LOCK_TTL = 300;

		/** Seconds between the first enqueue and the cron run. */
		const DELAY = 60;

		/**
		 * Schedules a drain unless one is already waiting.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function schedule(): void {
			if ( false === wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + self::DELAY, self::HOOK );
			}
		}

		/**
		 * The cron callback: send what is queued.
		 *
		 * @since 2.0.2
		 *
		 * @return int Reports handed to the receiver.
		 */
		public static function run(): int {
			$sent = 0;

			try {
				if ( ! Consent::is_active() ) {
					// Consent withdrawn or the receiver gone since the events were queued.
					Event_Queue::clear();

					return 0;
				}

				$dsn = Consent::get_dsn();

				if ( null === $dsn || ! self::acquire_lock() ) {
					return 0;
				}

				try {
					$sent = self::drain( $dsn );
				} finally {
					self::release_lock();
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- by design: a cron run must not fail loudly.
				unset( $e );
			}

			return $sent;
		}

		private static function drain( Dsn $dsn ): int {
			$limiter   = new Rate_Limiter();
			$transport = new Transport();
			$batch     = Event_Queue::all();
			$sent      = 0;

			foreach ( $batch as $event ) {
				if ( ! $limiter->allow( $limiter->signature( $event ) ) ) {
					continue;
				}

				if ( ! $transport->send( $event, $dsn ) ) {
					break; // The receiver is unreachable: do not queue up one timeout per event.
				}

				++$sent;
			}

			// The whole batch leaves the queue — sent, deduped, over the cap or undeliverable — while
			// events queued during this run stay for the next one.
			Event_Queue::remove( array_map( static fn( array $event ): string => (string) ( $event['event_id'] ?? '' ), $batch ) );

			return $sent;
		}

		/**
		 * Takes the drain lock.
		 *
		 * @return bool True when this run owns the lock.
		 */
		private static function acquire_lock(): bool {
			global $wpdb;

			$now = time();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- an atomic lock cannot go through the options API.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' ) /* LOCK */", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::LOCK_OPTION,
					(string) $now
				)
			);

			if ( $inserted ) {
				return true;
			}

			$held = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", self::LOCK_OPTION ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			if ( '' === $held || (int) $held > $now - self::LOCK_TTL ) {
				return false;
			}

			// Abandoned lock: compare-and-set, so of two runs finding it stale only one wins.
			$taken = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(string) $now,
					self::LOCK_OPTION,
					$held
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery

			return (bool) $taken;
		}

		/**
		 * @return void
		 */
		private static function release_lock(): void {
			global $wpdb;

			$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK_OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

endif;
