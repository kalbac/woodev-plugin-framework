<?php
/**
 * Atomic per-nonce claim store for signed license commands (§9.1 / §9.2).
 *
 * The anti-replay primitive behind the §3.4.1 command dispatcher. One option PER
 * nonce (name = OPTION_PREFIX . first-32-hex of sha256(nonce), autoload 'no') so the
 * claim is ATOMIC: it is a raw `INSERT IGNORE` (NOT add_option(), whose
 * `ON DUPLICATE KEY UPDATE` overwrites a racer's row) and rides MySQL's UNIQUE index on
 * option_name — exactly one concurrent writer sees rows_affected === 1, every loser's
 * insert is ignored (0 rows) and is rejected 'replayed'. A processing → consumed state
 * machine guards the window between the claim and the action; a `processing` record
 * stuck longer than STUCK_TAKEOVER_AFTER is taken over for crash recovery by a
 * compare-and-set `UPDATE … WHERE option_value = <what was read>`, so exactly one
 * contender wins it (the only v1 command is idempotent). Retention is capped at MAX_TTL so a huge signed expires_at cannot pin
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
		 * Atomicity contract (plan §9.1/§9.2; #1485): BOTH paths are single-winner.
		 * The FRESH claim is a raw `INSERT IGNORE` on MySQL's UNIQUE option_name
		 * index — only the contender whose insert affects one row is 'claimed'; add_option()
		 * is not used because its `ON DUPLICATE KEY UPDATE` lets every racer «win». The
		 * stale TAKEOVER is a compare-and-set `UPDATE … WHERE option_value = <the raw
		 * value just read>` — a contender that finds the row already rewritten affects
		 * zero rows and is 'replayed'. The commands stay idempotent regardless (see
		 * Woodev_License_Command_Dispatcher::get_commands()).
		 *
		 * Writes: the INSERT IGNORE claim and the conditional UPDATE takeover (claimed
		 * path), plus — on the at-cap 'store_full' path only — prune() deletions of
		 * EXPIRED rows (the recorded exception, plan §9.2). Every other rejection
		 * path writes nothing; below cap a claim attempt performs NO maintenance
		 * writes (the insert is the first write).
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

					return $this->take_over( $name ) ? 'claimed' : 'replayed';
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

			$record = [
				's' => 'processing',
				'c' => $this->now(),
				'e' => min( $expires_at, $this->now() + self::MAX_TTL ),
			];

			global $wpdb;

			// Atomic claim: INSERT IGNORE on the UNIQUE option_name index affects one row for the
			// single winner and zero for every loser. add_option() cannot be used — it ends in
			// ON DUPLICATE KEY UPDATE, which overwrites a racer's row and reports success (#1485).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic claim cannot go through the options API.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$name,
					maybe_serialize( $record ),
					'no'
				)
			);

			if ( ! $this->affected_one( $result ) ) {
				return 'replayed';
			}

			$this->forget_cached_option( $name );

			return 'claimed';
		}

		/**
		 * Takes over a stuck `processing` record by compare-and-set.
		 *
		 * The row is read raw from the database (never the object cache), re-checked for being
		 * a stale `processing` record, and replaced by `UPDATE … WHERE option_value = <that raw
		 * value>`. Of N requests that read the same stale row exactly one changes it; the rest
		 * affect zero rows and lose.
		 *
		 * @since 2.0.16
		 *
		 * @param string $name The per-nonce option name.
		 * @return bool Whether this caller won the takeover.
		 */
		private function take_over( string $name ): bool {

			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- an atomic compare-and-set cannot go through the options API.
			$raw = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
			);

			$current = is_string( $raw ) ? maybe_unserialize( $raw ) : null;

			// Row gone, rewritten meanwhile, or no longer stale → someone else owns it.
			if (
				! is_array( $current )
				|| 'processing' !== ( $current['s'] ?? null )
				|| ( $this->now() - (int) ( $current['c'] ?? 0 ) ) <= self::STUCK_TAKEOVER_AFTER
			) {
				return false;
			}

			$current['c'] = $this->now();

			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $current ),
					$name,
					$raw
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( ! $this->affected_one( $result ) ) {
				return false;
			}

			$this->forget_cached_option( $name );

			return true;
		}

		/**
		 * Whether the last raw write changed exactly one row (the winner of an atomic claim).
		 *
		 * @since 2.0.16
		 *
		 * @param int|bool $result The `$wpdb->query()` return (false on a database error).
		 * @return bool
		 */
		private function affected_one( $result ): bool {

			global $wpdb;

			return false !== $result && 1 === (int) $wpdb->rows_affected;
		}

		/**
		 * Drops the option from the object cache after a raw write, so later get_option() reads see the database.
		 *
		 * @since 2.0.16
		 *
		 * @param string $name The per-nonce option name.
		 * @return void
		 */
		private function forget_cached_option( string $name ): void {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
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
				$record = [
					'c' => $this->now(),
					'e' => min( $expires_at, $this->now() + self::MAX_TTL ),
				];
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
					++$count;
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
				return [];
			}

			$like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';

			$query = $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
				$like,
				self::PRUNE_SCAN_LIMIT
			);

			$rows = $wpdb->get_results( $query );

			return is_array( $rows ) ? $rows : [];
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
