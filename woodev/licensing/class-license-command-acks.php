<?php
/**
 * Durable structured pending-ack store (§9.5/§9.6/§9.7).
 *
 * Holds the site-level queue of dispatched-command acks that are waiting to be
 * sent to the Woodev server on the next scheduled request (check_license or
 * get_version). Any surviving framework plugin drains the store — this covers
 * §9.5 "deactivated plugin" scenario because a peer plugin's next scheduled
 * call carries the acks for the freshly-deactivated one.
 *
 * Design constraints (plan §9.5/§9.6):
 * - Site-level option `woodev_license_command_acks`, autoload 'no'.
 * - Ack entry schema (§9.6):
 *   { nonce: <32-hex>, status: <string>, terminal: <bool>, protocol: 1, ts: <int> }
 *   terminal = true for every status except 'failed' (retryable).
 * - FIFO cap: MAX_PENDING_ACKS = 50 (drop-oldest on overflow).
 * - Retention: entries older than RETENTION_SECONDS = 30 * DAY_IN_SECONDS are
 *   dropped on every write (record/confirm_received) AND every get_pending().
 * - confirm_received( array $nonces ) deletes EXACTLY the named entries; any
 *   entry NOT listed SURVIVES for the next request (§9.9 lost-ack redelivery).
 *
 * §9.7 client-side note: ack authenticity is enforced server-side by binding
 * the ack to the request's `url` (raw home_url()) and license key when present.
 * The client sends nothing extra — the existing request fields already provide
 * the binding. This class is intentionally unaware of that server-side rule.
 *
 * @package Woodev\Framework\Licensing
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_License_Command_Acks' ) ) :

	/**
	 * Site-level store for pending command ack records.
	 *
	 * @since 2.0.0
	 */
	class Woodev_License_Command_Acks {

		/**
		 * WP option name for the pending ack queue.
		 *
		 * @since 2.0.0
		 *
		 * @var string
		 */
		const OPTION_NAME = 'woodev_license_command_acks';

		/**
		 * Maximum number of pending ack entries (FIFO, drop-oldest on overflow).
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const MAX_PENDING_ACKS = 50;

		/**
		 * Retention window in seconds (30 days). Entries older than this are dropped.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const RETENTION_SECONDS = 30 * DAY_IN_SECONDS;

		/**
		 * Optional clock callable returning a Unix timestamp. Null = wall clock.
		 *
		 * @since 2.0.0
		 *
		 * @var callable|null
		 */
		private $clock;

		/**
		 * Constructor.
		 *
		 * @since 2.0.0
		 *
		 * @param callable|null $clock Optional clock returning a Unix timestamp (test seam).
		 */
		public function __construct( ?callable $clock = null ) {
			$this->clock = $clock;
		}

		/**
		 * Appends a §9.6 ack entry for the given nonce + status.
		 *
		 * terminal flag is false ONLY when status === 'failed' (retryable);
		 * every other status is terminal (§9.6).
		 *
		 * Expired entries (by RETENTION_SECONDS) are dropped on every write.
		 * FIFO overflow: when adding the new entry would exceed MAX_PENDING_ACKS,
		 * the oldest entry (index 0) is dropped first.
		 *
		 * RMW race — ACCEPTED + RECORDED (plan "Holistic-round rulings",
		 * 2026-06-11): this is a single-option read-modify-write; two CONCURRENT
		 * distinct commands may interleave and one ack may be lost. Bounded
		 * consequence: the lost ack degrades to server-side queue-until-expiry
		 * redelivery (`replayed` rejections are non-terminal), so it only delays
		 * queue clearing — no security or correctness loss. Atomic per-ack rows
		 * are deliberately NOT introduced in v1.
		 *
		 * @since 2.0.0
		 *
		 * @param string $nonce  The 32-hex command nonce.
		 * @param string $status The terminal ack status (executed/already/failed/...).
		 * @return void
		 */
		public function record( string $nonce, string $status ): void {

			$entries = $this->load_and_prune();

			$entry = array(
				'nonce'    => $nonce,
				'status'   => $status,
				'terminal' => ( 'failed' !== $status ),
				'protocol' => 1,
				'ts'       => $this->now(),
			);

			$entries[] = $entry;

			// FIFO: drop oldest entries if we're over the cap.
			$count = count( $entries );
			while ( $count > self::MAX_PENDING_ACKS ) {
				array_shift( $entries );
				$count--;
			}

			$this->save( $entries );
		}

		/**
		 * Returns all non-expired pending ack entries for attaching to outgoing requests.
		 *
		 * Expired entries are dropped and the store is updated as a side effect (every
		 * drain pass prunes stale records so they are never redelivered).
		 *
		 * @since 2.0.0
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function get_pending(): array {
			$entries = $this->load_and_prune();

			// If pruning removed entries, persist the cleaned list.
			$original = get_option( self::OPTION_NAME );
			if ( is_array( $original ) && count( $original ) !== count( $entries ) ) {
				$this->save( $entries );
			}

			return array_values( $entries );
		}

		/**
		 * Removes EXACTLY the named nonces from the store.
		 *
		 * Entries whose nonce is NOT in $nonces SURVIVE (§9.9 lost-ack redelivery:
		 * the server may not acknowledge every delivered nonce in a single response;
		 * unconfirmed entries stay in the queue and are re-sent on the next request).
		 *
		 * Expired entries are also pruned on this pass.
		 *
		 * @since 2.0.0
		 *
		 * @param array<int, string> $nonces Nonces confirmed received by the server.
		 * @return void
		 */
		public function confirm_received( array $nonces ): void {

			if ( array() === $nonces ) {
				// Nothing to remove — but still prune retention.
				$entries = $this->load_and_prune();
				$this->save( $entries );
				return;
			}

			$confirmed = array_flip( $nonces );
			$entries   = $this->load_and_prune();

			$remaining = array();
			foreach ( $entries as $entry ) {
				if ( ! isset( $confirmed[ $entry['nonce'] ] ) ) {
					$remaining[] = $entry;
				}
			}

			$this->save( array_values( $remaining ) );
		}

		// -------------------------------------------------------------------
		// Private helpers
		// -------------------------------------------------------------------

		/**
		 * Loads the raw option, filters out expired entries, and returns the list.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function load_and_prune(): array {

			$raw = get_option( self::OPTION_NAME );

			if ( ! is_array( $raw ) ) {
				return array();
			}

			$cutoff  = $this->now() - self::RETENTION_SECONDS;
			$entries = array();

			foreach ( $raw as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				// Drop entries beyond the 30-day retention window.
				if ( isset( $entry['ts'] ) && (int) $entry['ts'] <= $cutoff ) {
					continue;
				}
				$entries[] = $entry;
			}

			return $entries;
		}

		/**
		 * Persists the entry list to the WP options table (autoload 'no').
		 *
		 * @since 2.0.0
		 *
		 * @param array<int, array<string, mixed>> $entries The entries to persist.
		 * @return void
		 */
		private function save( array $entries ): void {
			update_option( self::OPTION_NAME, array_values( $entries ), 'no' );
		}

		/**
		 * Returns the current Unix time. Overridable via the constructor clock seam.
		 *
		 * @since 2.0.0
		 *
		 * @return int
		 */
		private function now(): int {
			return null !== $this->clock ? (int) ( $this->clock )() : time();
		}
	}

endif;
