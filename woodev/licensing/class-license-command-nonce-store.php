<?php
/**
 * Atomic per-nonce claim store for signed license commands (§9.1 / §9.2).
 *
 * The anti-replay primitive behind the §3.4.1 command dispatcher. One option PER
 * nonce (name = OPTION_PREFIX . first-32-hex of sha256(nonce), autoload 'no') so the
 * claim is ATOMIC: add_option() relies on MySQL's UNIQUE index on option_name —
 * exactly one concurrent writer wins, the loser's INSERT fails (add_option() returns
 * false) and is rejected 'replayed'. A processing → consumed state machine guards the
 * window between the claim and the action; a `processing` record stuck longer than
 * STUCK_TAKEOVER_AFTER is taken over for crash recovery (the only v1 command is
 * idempotent). Retention is capped at MAX_TTL so a huge signed expires_at cannot pin
 * entries, and the live-entry cap (MAX_NONCE_ENTRIES) is a correctness bound — only
 * authentically-signed envelopes ever reach the store (the dispatcher verifies the
 * signature first), so the cap is never an attacker-growth surface.
 *
 * @package Woodev\Framework\Licensing
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_License_Command_Nonce_Store' ) ) :

	/**
	 * Stores and claims command nonces atomically.
	 *
	 * Not final: the unit tests subclass it to override the now() time seam so the
	 * skew / stuck / expiry assertions are deterministic without sleeping.
	 *
	 * @since 2.0.0
	 */
	class Woodev_License_Command_Nonce_Store {

		/**
		 * Maximum retention for a claimed nonce (caps an attacker-controlled expires_at).
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const MAX_TTL = 14 * DAY_IN_SECONDS;

		/**
		 * Age (seconds) after which a `processing` record may be taken over (crash recovery).
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const STUCK_TAKEOVER_AFTER = 300;

		/**
		 * Maximum number of live nonce entries; at the cap a fresh claim is 'store_full'.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const MAX_NONCE_ENTRIES = 100;

		/**
		 * Option-name prefix for the per-nonce records.
		 *
		 * @since 2.0.0
		 *
		 * @var string
		 */
		const OPTION_PREFIX = 'woodev_license_command_nonces_';

		/**
		 * Maximum rows the prune query scans in one pass.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const PRUNE_SCAN_LIMIT = 200;

		/**
		 * Optional clock override (returns a Unix timestamp). Null = wall clock.
		 *
		 * Lets the dispatcher share its single now() seam with the store so the
		 * window / takeover / retention math is consistent across both in tests.
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
		 * @param callable|null $clock Optional clock returning a Unix timestamp.
		 */
		public function __construct( ?callable $clock = null ) {
			$this->clock = $clock;
		}

		/**
		 * Claims a nonce for execution.
		 *
		 * Returns 'claimed' for a fresh nonce (or a takeover of a stuck processing
		 * record), 'replayed' for a consumed nonce / an in-flight processing record /
		 * the concurrent loser, and 'store_full' when the live-entry cap is reached.
		 *
		 * Atomicity contract (plan §9.1/§9.2, amended 2026-06-11): the atomic
		 * guarantee covers the FRESH-claim path ONLY — add_option() rides MySQL's
		 * UNIQUE option_name index, so exactly one concurrent writer wins. The stale
		 * TAKEOVER path is explicitly BEST-EFFORT, not atomic: two requests can both
		 * pass the stale check and both re-execute. Accepted because the command
		 * registry contract requires idempotent commands (see
		 * Woodev_License_Command_Dispatcher::register_command()).
		 *
		 * Writes: the add_option() claim and the update_option() takeover (claimed
		 * path), plus — on the at-cap 'store_full' path only — prune() deletions of
		 * EXPIRED rows (the recorded exception, plan §9.2). Every other rejection
		 * path writes nothing; below cap a claim attempt performs NO maintenance
		 * writes (add_option is the first write).
		 *
		 * @since 2.0.0
		 *
		 * @param string $nonce      The 32-hex command nonce.
		 * @param int    $expires_at The signed expiry (retention is capped at MAX_TTL).
		 * @return string 'claimed' | 'replayed' | 'store_full'.
		 */
		public function claim( string $nonce, int $expires_at ): string {

			$name     = $this->option_name( $nonce );
			$existing = get_option( $name );

			if ( is_array( $existing ) && isset( $existing['s'] ) ) {

				// A consumed record always rejects, regardless of any later target state.
				if ( 'consumed' === $existing['s'] ) {
					return 'replayed';
				}

				// A processing record: in-flight (young) → replayed; stuck (old) → takeover.
				if ( 'processing' === $existing['s'] ) {

					$claimed_at = (int) ( $existing['c'] ?? 0 );

					if ( ( $this->now() - $claimed_at ) <= self::STUCK_TAKEOVER_AFTER ) {
						return 'replayed';
					}

					$existing['c'] = $this->now();
					update_option( $name, $existing, false );

					return 'claimed';
				}

				// An unknown state shape → treat as in-flight (safe default, no write).
				return 'replayed';
			}

			// Fresh nonce — LAZY cap enforcement (plan §9.2, amended 2026-06-11):
			// below cap a claim attempt performs NO maintenance writes (add_option is
			// the first write). Only an at-cap attempt prunes expired rows and
			// recounts. The at-cap 'store_full' rejection having deleted EXPIRED rows
			// is the RECORDED EXCEPTION to the zero-side-effect rejection rule — it
			// never creates or mutates live state (plan §9.2).
			if ( $this->count_live_entries() >= self::MAX_NONCE_ENTRIES ) {

				$this->prune();

				if ( $this->count_live_entries() >= self::MAX_NONCE_ENTRIES ) {
					return 'store_full';
				}
			}

			$record = array(
				's' => 'processing',
				'c' => $this->now(),
				'e' => min( $expires_at, $this->now() + self::MAX_TTL ),
			);

			// Atomic insert: the UNIQUE option_name index makes the concurrent loser fail.
			if ( false === add_option( $name, $record, '', 'no' ) ) {
				return 'replayed';
			}

			return 'claimed';
		}

		/**
		 * Marks a claimed nonce consumed with its terminal status.
		 *
		 * Retention rule (plan §9.2, amended 2026-06-11): an EXISTING row keeps its
		 * `e` untouched — consumption never extends (or shortens) the retention set
		 * at claim time. A row pruned mid-action is re-created with the SAME rule as
		 * claim(): e = min( signed expires_at, now + MAX_TTL ) — never past the
		 * original signed expiry.
		 *
		 * @since 2.0.0
		 *
		 * @param string $nonce      The 32-hex command nonce.
		 * @param string $status     The terminal ack status (executed/already/...).
		 * @param int    $expires_at The signed expiry (caps a re-created row's retention).
		 * @return void
		 */
		public function mark_consumed( string $nonce, string $status, int $expires_at ): void {

			$name   = $this->option_name( $nonce );
			$record = get_option( $name );

			if ( ! is_array( $record ) ) {
				// Row pruned mid-action: re-create, capped by the signed expiry.
				$record = array(
					'c' => $this->now(),
					'e' => min( $expires_at, $this->now() + self::MAX_TTL ),
				);
			}

			$record['s'] = 'consumed';
			$record['r'] = $status;

			update_option( $name, $record, false );
		}

		/**
		 * Deletes per-nonce option rows whose retention expiry has passed.
		 *
		 * Scans up to PRUNE_SCAN_LIMIT rows via a prepared LIKE query and deletes the
		 * ones whose decoded `e` is in the past. Called lazily by claim() only when
		 * the live count is at/over cap (§9.2 amended), and on the weekly cron
		 * (Cron_Handler).
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function prune(): void {

			foreach ( $this->fetch_rows() as $row ) {

				$record = maybe_unserialize( $row->option_value );

				if ( ! is_array( $record ) ) {
					continue;
				}

				if ( (int) ( $record['e'] ?? 0 ) < $this->now() ) {
					delete_option( $row->option_name );
				}
			}
		}

		/**
		 * Counts the currently-live (unexpired) nonce rows.
		 *
		 * @since 2.0.0
		 *
		 * @return int
		 */
		private function count_live_entries(): int {

			$count = 0;

			foreach ( $this->fetch_rows() as $row ) {

				$record = maybe_unserialize( $row->option_value );

				if ( ! is_array( $record ) ) {
					continue;
				}

				if ( (int) ( $record['e'] ?? 0 ) >= $this->now() ) {
					$count++;
				}
			}

			return $count;
		}

		/**
		 * Fetches the per-nonce option rows via a prepared LIKE query.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int, object> Rows with option_name / option_value.
		 */
		private function fetch_rows(): array {

			global $wpdb;

			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return array();
			}

			$like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';

			$query = $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
				$like,
				self::PRUNE_SCAN_LIMIT
			);

			$rows = $wpdb->get_results( $query );

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Derives the per-nonce option name.
		 *
		 * @since 2.0.0
		 *
		 * @param string $nonce The command nonce.
		 * @return string
		 */
		protected function option_name( string $nonce ): string {
			return self::OPTION_PREFIX . substr( hash( 'sha256', $nonce ), 0, 32 );
		}

		/**
		 * Returns the current Unix time. Overridable for deterministic tests.
		 *
		 * @since 2.0.0
		 *
		 * @return int
		 */
		protected function now(): int {
			return null !== $this->clock ? (int) ( $this->clock )() : time();
		}
	}

endif;
