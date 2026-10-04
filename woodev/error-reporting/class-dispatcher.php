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

		public static function run(): int {
			$sent = 0;

			try {
				if ( ! Consent::is_active() ) {
					// Consent withdrawn or the receiver gone since the events were queued.
					Event_Queue::clear();

					return 0;
				}

				$dsn = Consent::get_dsn();

				if ( null === $dsn ) {
					return 0;
				}

				$lock = self::acquire_lock();

				if ( null === $lock ) {
					// WP-Cron has just consumed the event that brought us here: without a new one,
					// the queue would sit until an unrelated error happened to schedule a drain.
					self::schedule_if_queued();

					return 0;
				}

				try {
					$sent = self::drain( $dsn, $lock );
				} finally {
					self::release_lock( $lock );

					// Also when the drain threw (a filter or HTTP hook): WP-Cron has consumed this
					// event, so anything still queued needs a new one.
					self::schedule_if_queued();
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- by design: a cron run must not fail loudly.
				unset( $e );
			}

			return $sent;
		}

		private static function drain( Dsn $dsn, string $lock ): int {
			$limiter   = new Rate_Limiter();
			$transport = new Transport();
			$batch     = Event_Queue::all();
			$ids       = array_map( static fn( array $event ): string => (string) ( $event['event_id'] ?? '' ), $batch );
			$done      = [];
			$sent      = 0;

			foreach ( $batch as $index => $event ) {
				// Before EVERY post: the lock is still ours (a run paused past the TTL may have been
				// taken over) and the merchant has not withdrawn consent meanwhile.
				if ( ! self::owns_lock( $lock ) ) {
					break; // The new owner drains what is left; only what we handled leaves the queue.
				}

				if ( ! Consent::is_active_fresh() ) {
					Event_Queue::clear();

					return $sent;
				}

				$done[] = $ids[ $index ];

				if ( ! $limiter->allow( $limiter->signature( $event ) ) ) {
					continue;
				}

				if ( ! $transport->send( $event, $dsn ) ) {
					$done = $ids; // The receiver is unreachable: do not queue up one timeout per event.

					break;
				}

				++$sent;
			}

			// What leaves the queue — sent, deduped, over the cap or undeliverable — while events
			// queued during this run stay for the next one.
			Event_Queue::remove( $done );

			return $sent;
		}

		private static function acquire_lock(): ?string {
			global $wpdb;

			$now = time();

			// «<acquired at>|<random token>»: the token makes this run's row distinguishable from a
			// successor's, so only the run that wrote it can release it or keep sending under it.
			$value = $now . '|' . bin2hex( random_bytes( 8 ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- an atomic lock cannot go through the options API.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' ) /* LOCK */", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::LOCK_OPTION,
					$value
				)
			);

			if ( $inserted ) {
				return $value;
			}

			$held = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", self::LOCK_OPTION ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			if ( '' === $held || (int) $held > $now - self::LOCK_TTL ) {
				return null;
			}

			// Abandoned lock: compare-and-set, so of two runs finding it stale only one wins.
			$taken = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$value,
					self::LOCK_OPTION,
					$held
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery

			return $taken ? $value : null;
		}

		private static function release_lock( string $lock ): void {
			global $wpdb;

			// Conditional on the value: a run that was paused past the TTL and lost its lock to a
			// successor deletes nothing when it wakes up.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::LOCK_OPTION,
					$lock
				)
			);
		}

		/**
		 * Whether this run still holds the lock it took: the row still carries its token, and the TTL
		 * has not passed on its own clock (after that another run is entitled to take the lock over).
		 *
		 * @param string $lock The value {@see self::acquire_lock()} returned.
		 * @return bool
		 */
		private static function owns_lock( string $lock ): bool {
			global $wpdb;

			if ( (int) $lock <= time() - self::LOCK_TTL ) {
				return false;
			}

			$held = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", self::LOCK_OPTION ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			return $lock === (string) $held;
		}

		/**
		 * Schedules a drain when events are waiting and none is scheduled.
		 *
		 * @return void
		 */
		private static function schedule_if_queued(): void {
			if ( [] !== Event_Queue::all() ) {
				self::schedule();
			}
		}
	}

endif;
