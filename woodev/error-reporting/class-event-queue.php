<?php
/**
 * Bounded store of events waiting to be sent.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Event_Queue' ) ) :

	/**
	 * Where an error handler leaves its event. Enqueueing is the ONLY thing that happens in the
	 * failing request — one option write, no network; {@see Dispatcher} sends from WP-Cron.
	 *
	 * The queue is one non-autoloaded option holding at most {@see self::LIMIT} events (the oldest
	 * is dropped first). Read-modify-write is not atomic, so two simultaneous failures can lose one
	 * of the two — acceptable for best-effort telemetry, and cheaper than a lock in a request that
	 * is already failing. An event whose signature is already pending is not stored again, so one
	 * broken page hit a thousand times does not rewrite the option a thousand times.
	 *
	 * @since 2.0.2
	 */
	final class Event_Queue {

		/** Option holding the pending events. Never autoloaded. */
		const OPTION = 'woodev_error_reporting_queue';

		/** Most events kept; beyond it the oldest is dropped. */
		const LIMIT = 20;

		/**
		 * Appends an event.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $event Event from {@see Event_Builder}.
		 * @return bool True when the event was stored; false when an identical one is already pending.
		 */
		public static function push( array $event ): bool {
			$queue     = self::all();
			$limiter   = new Rate_Limiter();
			$signature = $limiter->signature( $event );

			foreach ( $queue as $pending ) {
				if ( $limiter->signature( $pending ) === $signature ) {
					return false;
				}
			}

			$queue[] = $event;
			$queue   = array_slice( $queue, -self::LIMIT );

			// Third argument: do not autoload — the queue must not ride along on every request.
			update_option( self::OPTION, $queue, false );

			return true;
		}

		/**
		 * All pending events, oldest first.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function all(): array {
			$queue = get_option( self::OPTION, [] );

			return is_array( $queue ) ? array_values( array_filter( $queue, 'is_array' ) ) : [];
		}

		/**
		 * Removes the events with these ids and keeps the ones that arrived meanwhile.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $event_ids `event_id` values.
		 * @return void
		 */
		public static function remove( array $event_ids ): void {
			$left = [];

			foreach ( self::all() as $event ) {
				if ( ! in_array( (string) ( $event['event_id'] ?? '' ), $event_ids, true ) ) {
					$left[] = $event;
				}
			}

			if ( [] === $left ) {
				delete_option( self::OPTION );

				return;
			}

			update_option( self::OPTION, $left, false );
		}

		/**
		 * Drops everything pending (the merchant withdrew consent).
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function clear(): void {
			delete_option( self::OPTION );
		}
	}

endif;
