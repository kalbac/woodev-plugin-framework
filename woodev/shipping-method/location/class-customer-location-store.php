<?php
/**
 * Woodev Customer Location Store
 *
 * The dual customer-location store behind the Location Provider layer (Task 4;
 * spec D10): a guest's record lives in `WC()->session`; a logged-in customer's
 * record is authoritative in user meta, mirrored into the session as a fast path.
 * `WC_Edostavka_Customer_Location_Data`'s own dual store — user meta when
 * `get_id() > 0`, session otherwise — is the precedent this generalizes into the
 * framework, minus its WC_Data machinery: this class needs no data store, no
 * caching layer and no custom object type, only a `{ record, implicit, saved_at }`
 * slot under ONE framework-owned key on each side.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Customer_Location_Store' ) ) :

	/**
	 * Reads, writes and migrates the store-wide customer location record.
	 *
	 * Stored shape, identical on both sides (session key and meta key are both
	 * {@see self::STORAGE_KEY}):
	 *
	 * ```
	 * [
	 *     'record'   => <Location_Record::to_array()>,
	 *     'implicit' => bool,
	 *     'saved_at' => int (unix timestamp),
	 * ]
	 * ```
	 *
	 * Unlike {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection} (one map per
	 * plugin, keyed by a plugin-supplied {@see \Woodev\Framework\Shipping\Pickup\Selection_Scope}),
	 * there is exactly ONE active location provider per store (spec §4.1), so this
	 * class owns one fixed pair of keys rather than taking a scope.
	 *
	 * @since 2.0.2
	 */
	class Customer_Location_Store {

		/**
		 * The single framework-owned key used on BOTH sides: the `WC()->session`
		 * key for a guest (or a logged-in customer's fast path) and the
		 * `get_user_meta()`/`update_user_meta()` key for a logged-in customer's
		 * authoritative record. A NEW key (installed-site data contracts untouched
		 * — CLAUDE.md "never break" list).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const STORAGE_KEY = 'woodev_customer_location';

		/**
		 * Fires after {@see self::set()} or {@see self::handle_wp_login()} actually
		 * writes a record (a refused implicit-over-explicit write does NOT fire
		 * this). Lets a plugin react to the customer's location changing — e.g. to
		 * eagerly warm its own adapter cache — without polling the store on every
		 * request. Left in place even though nothing in this codebase consumes it
		 * yet (project preference: extension hooks are not gated on having a
		 * consumer).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record    The record that was written.
		 * @param bool            $implicit  Whether it was written as a default guess (spec D11).
		 * @param int             $user_id   The logged-in customer's user id, or `0` for a guest.
		 */
		public const ACTION_SAVED = 'woodev_customer_location_saved';

		/**
		 * Reads the customer's current location record.
		 *
		 * For a guest, the session is the only store. For a logged-in customer,
		 * the session is tried first (fast path); on a miss, user meta (the
		 * authoritative store) is read and, when it holds a usable record, the
		 * session is repopulated from it. This makes the session a genuine
		 * read-through cache across requests rather than a value that only exists
		 * in the same request that called {@see self::set()}: a page that renders
		 * before any explicit selection this session (cart page, a returning
		 * customer's first checkout view) still gets the fast path from its very
		 * first read on. Repopulating is safe unconditionally here — unlike a
		 * guest's session write, a logged-in write always persists regardless of
		 * the cart-cookie gotcha (`guest-session-write-needs-the-cart-cookie`),
		 * because `WC_Session_Handler::has_session()` short-circuits on
		 * `is_user_logged_in()`.
		 *
		 * A corrupt or legacy stored blob (not an array, a missing/non-array
		 * `record`, an empty/missing `key`, an invalid `level` — anything
		 * {@see Location_Record::from_array()} would refuse) degrades to `null`
		 * rather than throwing; this method never lets an exception escape.
		 *
		 * @since 2.0.2
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}|null
		 */
		public function get(): ?array {
			if ( is_user_logged_in() ) {
				return $this->get_for_logged_in_user();
			}

			$session = $this->session();

			if ( null === $session ) {
				return null;
			}

			return $this->parse_stored( $session->get( self::STORAGE_KEY ) );
		}

		/**
		 * Writes the customer's location record.
		 *
		 * Guest: written to the session only; a missing/uninitialized session (no
		 * WooCommerce, or a guest whose cart is empty so no session cookie exists
		 * yet — `guest-session-write-needs-the-cart-cookie`) is a silent no-op
		 * returning `false`, never a fatal.
		 *
		 * Logged-in: written to user meta (authoritative, always attempted —
		 * `get_user_meta()`/`update_user_meta()` need WordPress, not WooCommerce,
		 * so this still works with no WC session available) and, when a session
		 * IS available, mirrored there too as the fast path.
		 *
		 * Implicit/explicit precedence (spec D11): an explicit write (`$implicit
		 * === false`) always proceeds, overwriting whatever was stored — including
		 * dropping a previously-implicit flag. An implicit write is refused
		 * (returns `false`, nothing is touched) when a record already exists AND
		 * that existing record is explicit: a default guess must never clobber a
		 * real customer choice. An implicit write over an implicit record, or over
		 * nothing at all, proceeds normally.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record   The record to store. Its `key` is
		 *                                  already guaranteed non-empty by
		 *                                  {@see Location_Record::from_array()} —
		 *                                  the empty-key discipline is enforced by
		 *                                  construction, this method never sees an
		 *                                  invalid record.
		 * @param bool            $implicit Whether this is a default guess (spec
		 *                                  D11) rather than a real customer choice.
		 *
		 * @return bool `true` when the write happened, `false` when it was refused
		 *              or no store was available to write to.
		 */
		public function set( Location_Record $record, bool $implicit = false ): bool {
			$current = $this->get();

			if ( ! self::may_overwrite( $current, $implicit ) ) {
				return false;
			}

			if ( is_user_logged_in() ) {
				$this->persist( get_current_user_id(), $record, $implicit, time() );

				return true;
			}

			$session = $this->session();

			if ( null === $session ) {
				return false;
			}

			$this->persist( null, $record, $implicit, time() );

			return true;
		}

		/**
		 * Migrates a guest's session-held record onto the account the customer
		 * just logged into. Hook this onto `wp_login` (2 args) — matches that
		 * hook's own callback signature, so `add_action( 'wp_login', [ $store,
		 * 'handle_wp_login' ], 10, 2 )` wires it directly.
		 *
		 * `$user` is deliberately left untyped rather than type-hinted `\WP_User`
		 * — the same precedent as
		 * `Woodev_Payment_Gateway_Admin_User_Handler::add_profile_section( $user )`
		 * (`woodev/payment-gateway/admin/class-payment-gateway-admin-user-handler.php`),
		 * which keeps `WP_User` in the docblock only so the method stays callable
		 * (and unit-testable with a duck-typed double) without requiring the real
		 * WordPress class to be loaded.
		 *
		 * Conflict rule (both directions, both flags — this is the one genuine
		 * design decision in this class): the session record and the existing meta
		 * record are reconciled through the EXACT SAME implicit/explicit gate
		 * {@see self::set()} uses ({@see self::may_overwrite()}), with the session
		 * record playing the role of the "new" write and the existing meta record
		 * playing the role of "current":
		 * - no session record at all (guest picked nothing this session) → no-op,
		 *   the account's own record (if any) is left untouched;
		 * - session record is EXPLICIT → it always wins (a real choice made while
		 *   logged out — during THIS session — is the customer's freshest actual
		 *   answer, exactly as a normal explicit {@see self::set()} call always
		 *   overwrites whatever was there before, explicit or implicit);
		 * - session record is IMPLICIT and the account already holds an EXPLICIT
		 *   record → the account record wins, unchanged (an implicit guest guess
		 *   must never clobber a real saved choice — same rule {@see self::set()}
		 *   enforces for a plain implicit write);
		 * - session record is IMPLICIT and the account has no record, or only an
		 *   implicit one → the session record wins (nothing worth protecting was
		 *   there).
		 *
		 * Whichever side wins, BOTH stores are resynced to hold it: if the meta
		 * side wins, the session (now this user's fast path) is overwritten with
		 * the meta record too, so a subsequent session-preferred {@see self::get()}
		 * cannot surface the losing guest guess.
		 *
		 * A missing session (no WooCommerce / no session available) is a silent
		 * no-op — there is nothing to migrate FROM.
		 *
		 * @since 2.0.2
		 *
		 * @param string $user_login The user's login name (unused; part of the
		 *                           `wp_login` hook signature).
		 * @param object $user       The `\WP_User` who just logged in; only `->ID`
		 *                           is read.
		 *
		 * @return void
		 *
		 * @internal
		 */
		public function handle_wp_login( string $user_login, $user ): void {
			$session = $this->session();

			if ( null === $session ) {
				return;
			}

			$session_entry = $this->parse_stored( $session->get( self::STORAGE_KEY ) );

			if ( null === $session_entry ) {
				return;
			}

			$user_id    = (int) $user->ID;
			$meta_entry = $this->parse_stored( get_user_meta( $user_id, self::STORAGE_KEY, true ) );

			$winner = self::may_overwrite( $meta_entry, $session_entry['implicit'] ) ? $session_entry : $meta_entry;

			$this->persist( $user_id, $winner['record'], $winner['implicit'], $winner['saved_at'] );
		}

		/**
		 * Reads the live `WC()->session`, or `null` when WooCommerce is
		 * unavailable or no session has been started yet.
		 *
		 * `protected` as a test seam — same shape and reasoning as
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection::session()}: a
		 * probe overrides this single line rather than `WC()` needing to be a real
		 * function in the unit-test process (Brain Monkey's Patchwork-based
		 * `Functions\when( 'WC' )` redefinition would leak `function_exists( 'WC'
		 * )` as permanently `true` for the rest of that PHPUnit process).
		 *
		 * @since 2.0.2
		 *
		 * @return \WC_Session|null
		 */
		protected function session() {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return null;
			}

			return WC()->session;
		}

		/**
		 * The logged-in half of {@see self::get()} — session-preferred read with a
		 * meta fallback that repopulates the session.
		 *
		 * @since 2.0.2
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}|null
		 */
		private function get_for_logged_in_user(): ?array {
			$session = $this->session();

			if ( null !== $session ) {
				$from_session = $this->parse_stored( $session->get( self::STORAGE_KEY ) );

				if ( null !== $from_session ) {
					return $from_session;
				}
			}

			$raw_meta  = get_user_meta( get_current_user_id(), self::STORAGE_KEY, true );
			$from_meta = $this->parse_stored( $raw_meta );

			if ( null !== $from_meta && null !== $session ) {
				$session->set( self::STORAGE_KEY, $raw_meta );
			}

			return $from_meta;
		}

		/**
		 * Writes an entry to whichever stores apply and announces the change.
		 *
		 * @since 2.0.2
		 *
		 * @param int|null        $user_id  The logged-in customer's user id, or
		 *                                  `null` for a guest (meta is skipped).
		 * @param Location_Record $record   The record to store.
		 * @param bool            $implicit Whether it is a default guess.
		 * @param int             $saved_at Unix timestamp to stamp (preserved
		 *                                  as-is by a migration reconciliation
		 *                                  rather than always "now").
		 *
		 * @return void
		 */
		private function persist( ?int $user_id, Location_Record $record, bool $implicit, int $saved_at ): void {
			$entry = [
				'record'   => $record->to_array(),
				'implicit' => $implicit,
				'saved_at' => $saved_at,
			];

			if ( null !== $user_id ) {
				update_user_meta( $user_id, self::STORAGE_KEY, $entry );
			}

			$session = $this->session();

			if ( null !== $session ) {
				$session->set( self::STORAGE_KEY, $entry );
			}

			/** This action is documented above, on {@see self::ACTION_SAVED}. */
			do_action( self::ACTION_SAVED, $record, $implicit, $user_id ?? 0 );
		}

		/**
		 * The implicit/explicit precedence gate shared by {@see self::set()} and
		 * {@see self::handle_wp_login()}: a candidate marked implicit may never
		 * overwrite an existing EXPLICIT entry. Anything else — no existing entry,
		 * an existing entry that is itself implicit, or an explicit candidate —
		 * is allowed through.
		 *
		 * @since 2.0.2
		 *
		 * @param array{record: Location_Record, implicit: bool, saved_at: int}|null $current             The
		 *                                                                                                  entry
		 *                                                                                                  already
		 *                                                                                                  stored,
		 *                                                                                                  if
		 *                                                                                                  any.
		 * @param bool                                                               $candidate_implicit Whether the candidate write is implicit.
		 *
		 * @return bool
		 */
		private static function may_overwrite( ?array $current, bool $candidate_implicit ): bool {
			if ( ! $candidate_implicit ) {
				return true;
			}

			return null === $current || $current['implicit'];
		}

		/**
		 * Validates and parses a raw stored value into the entry shape, or `null`
		 * when it is not usable — never throws.
		 *
		 * Guards, in order: the raw value must be an array; it must carry a
		 * `record` sub-array; that sub-array must build a valid
		 * {@see Location_Record} (empty/missing `key`, an invalid `level`, etc. are
		 * exactly what {@see Location_Record::from_array()} already refuses — this
		 * is the read-side half of the empty-key discipline: Task 1 refuses
		 * construction, this method refuses to let that refusal become a fatal on
		 * read). `implicit` and `saved_at` are read leniently (absent/malformed
		 * become `false` / `0`) since they are this class's own bookkeeping, not
		 * part of the record's own validated contract.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw The raw session/meta value (a WC session getter
		 *                    returns `null` when absent; `get_user_meta( …, true )`
		 *                    returns `''`).
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}|null
		 */
		private function parse_stored( $raw ): ?array {
			if ( ! is_array( $raw ) || ! isset( $raw['record'] ) || ! is_array( $raw['record'] ) ) {
				return null;
			}

			try {
				$record = Location_Record::from_array( $raw['record'] );
			} catch ( \InvalidArgumentException $exception ) {
				return null;
			}

			return [
				'record'   => $record,
				'implicit' => ! empty( $raw['implicit'] ),
				'saved_at' => isset( $raw['saved_at'] ) && is_numeric( $raw['saved_at'] ) ? (int) $raw['saved_at'] : 0,
			];
		}
	}

endif;
