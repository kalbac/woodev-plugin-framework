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
	 * is dropped first), of which at most {@see self::BROWSER_LIMIT} may come from the browser —
	 * guests can feed that route, so it can never push a PHP event out. Read-modify-write is not atomic, so two simultaneous failures can lose one
	 * of the two — acceptable for best-effort telemetry, and cheaper than a lock in a request that
	 * is already failing. An event whose signature is already pending is not stored again, so one
	 * broken page hit a thousand times does not rewrite the option a thousand times.
	 *
	 * @since 2.0.2
	 */
	final class Event_Queue {

		/** Option holding the pending events. Never autoloaded. */
		const OPTION = 'woodev_error_reporting_queue';

		/** Most events kept; beyond it a PHP event drops the oldest (a browser event is refused instead). */
		const LIMIT = 20;

		/** Most BROWSER events waiting at once — the rest of {@see self::LIMIT} is reserved for PHP events. */
		const BROWSER_LIMIT = 10;

		/**
		 * Appends an event.
		 *
		 * A PHP event that finds the queue full evicts the oldest BROWSER event first (the browser's
		 * reports are the untrusted, abundant kind), and only then the oldest PHP one. A browser event
		 * never evicts anything: it is refused when the queue is full or its own share
		 * ({@see self::BROWSER_LIMIT}) is used up.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $event Event from {@see Event_Builder} or {@see Browser_Event_Builder}.
		 * @return bool True when the event was stored; false when an identical one is already pending, or a browser event found no room.
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

			if ( self::is_browser( $event ) ) {
				$browser = count( array_filter( $queue, [ self::class, 'is_browser' ] ) );

				if ( count( $queue ) >= self::LIMIT || $browser >= self::BROWSER_LIMIT ) {
					return false;
				}

				$queue[] = $event;
			} else {
				$queue[] = $event;

				$excess = count( $queue ) - self::LIMIT;

				// At most one is over in practice (the queue was within the limit before this push).
				for ( $i = 0; $i < $excess; ++$i ) {
					$evict = array_keys( array_filter( $queue, [ self::class, 'is_browser' ] ) )[0] ?? 0;

					unset( $queue[ $evict ] );

					$queue = array_values( $queue );
				}
			}

			// Third argument: do not autoload — the queue must not ride along on every request.
			update_option( self::OPTION, $queue, false );

			return true;
		}

		/**
		 * Whether an event came from the browser (`tags.source`), i.e. is the untrusted kind.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $event Queued or about-to-be-queued event.
		 * @return bool
		 */
		public static function is_browser( array $event ): bool {
			return 'browser' === ( $event['tags']['source'] ?? '' );
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
