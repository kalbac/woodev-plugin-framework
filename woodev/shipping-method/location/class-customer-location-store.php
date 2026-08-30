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
 * caching layer and no custom object type, only a `{ records, current, implicit,
 * saved_at }` chain slot (location-chain design, spec
 * `docs-internal/specs/2026-08-15-location-chain-design.md`) under ONE
 * framework-owned key on each side.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Customer_Location_Store' ) ) :

	/**
	 * Reads, writes and migrates the store-wide customer location CHAIN.
	 *
	 * Stored shape, identical on both sides (session key and meta key are both
	 * {@see self::STORAGE_KEY}) — spec `docs-internal/specs/2026-08-15-location-chain-design.md`
	 * §3:
	 *
	 * ```
	 * [
	 *     'records'  => [ <level> => <Location_Record::to_array()>, … ],
	 *     'current'  => <level>,
	 *     'implicit' => bool,
	 *     'saved_at' => int (unix timestamp),
	 * ]
	 * ```
	 *
	 * A LEGACY blob — the pre-chain single-`record` shape `{ record, implicit,
	 * saved_at }` — is still accepted on read (see {@see self::parse_stored_chain()})
	 * and parsed as a one-entry chain whose `current` is that record's own level:
	 * not because any installed site depends on the old shape (the layer is
	 * unreleased), but because a customer's live session/meta written minutes
	 * before a deploy must not evaporate.
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
		 * Fires after {@see self::forget()} actually erases a stored chain — the
		 * counterpart to {@see self::ACTION_SAVED}. A `forget()` call that finds
		 * nothing to erase (nothing stored in either store it was allowed to touch)
		 * does NOT fire this, mirroring `ACTION_SAVED`'s own "a refused/no-op write
		 * does not fire" discipline. Left in place even though nothing in this
		 * codebase consumes it yet (project preference: extension hooks are not
		 * gated on having a consumer).
		 *
		 * @since 2.0.2
		 *
		 * @param int $user_id The logged-in customer's user id whose chain was
		 *                      erased, or `0` for a guest's session-only erasure.
		 */
		public const ACTION_FORGOTTEN = 'woodev_customer_location_forgotten';

		/**
		 * Filters the opaque `raw` provider payload (see
		 * {@see Location_Record::raw()}) immediately before
		 * {@see self::export_personal_data()} writes it into a WP Privacy export
		 * file.
		 *
		 * `raw` is exported by default because it is normally just the
		 * customer's own address, verbatim from the provider (see
		 * {@see self::export_personal_data()}'s own docblock) — but
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider} is a
		 * PUBLIC extension point, and this framework cannot vouch for what a
		 * THIRD-PARTY provider's response actually contains. A provider author
		 * whose `raw` payload MAY carry a secret (an echoed API key/token) or
		 * another person's data (e.g. a shared-account/organization lookup) has
		 * an OBLIGATION — not merely the option — to redact it here before it
		 * ever reaches a customer's export file. The same "we don't own this
		 * content, so it needs a cleanup seam" principle already applied to
		 * logged API responses (#594) and to header masking in
		 * `Woodev\Framework\Api\Api_Base`.
		 *
		 * Default: unfiltered, `$raw` passed through unchanged — the bundled
		 * DaData provider's `raw` is a plain address-component object with
		 * nothing beyond what {@see self::export_personal_data()} already
		 * exports as named fields (see gotcha
		 * `a-cross-provider-within-is-handed-over-as-components`), so there is
		 * nothing to redact for it.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed           $raw     The provider's raw payload, exactly as
		 *                                 {@see Location_Record::raw()} returns
		 *                                 it. Return `null` to omit the raw
		 *                                 field from the export entirely.
		 * @param Location_Record $record  The record `$raw` came from.
		 * @param int             $user_id The user whose data is being exported.
		 */
		public const FILTER_EXPORT_RAW = 'woodev_customer_location_export_raw';

		/**
		 * Reads the customer's CURRENT location record — the record at the
		 * chain's `current` level, exactly the same shape and contract this
		 * method has always had.
		 *
		 * Thin projection over {@see self::get_chain()}: every read-path detail
		 * (session-preferred read, meta fallback + repopulation, corrupt/legacy
		 * blob degrading to `null`) lives there now, once, rather than being
		 * duplicated between the two accessors.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Reimplemented as a projection of {@see self::get_chain()}
		 *              (location-chain design, §3) — the stored shape now holds
		 *              the whole chain, not one record, but this method's own
		 *              signature and return shape are UNCHANGED.
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}|null
		 */
		public function get(): ?array {
			$chain = $this->get_chain();

			return null === $chain ? null : self::chain_to_entry( $chain );
		}

		/**
		 * Reads the customer's whole location CHAIN (location-chain design §3):
		 * every level the customer has ever picked that {@see self::set()} has
		 * not since dropped, plus which level is `current`.
		 *
		 * Same read paths as {@see self::get()} (guest: session only;
		 * logged-in: session-preferred with a meta fallback that repopulates the
		 * session), and the same "corrupt/legacy blob degrades to `null`, never
		 * throws" discipline — both are implemented once, here, in
		 * {@see self::parse_stored_chain()} and its two callers below.
		 *
		 * @since 2.0.2
		 *
		 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null
		 */
		public function get_chain(): ?array {
			if ( is_user_logged_in() ) {
				return $this->get_chain_for_logged_in_user();
			}

			$session = $this->session();

			if ( null === $session ) {
				return null;
			}

			return $this->parse_stored_chain( $session->get( self::STORAGE_KEY ) );
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
		 * nothing at all, proceeds normally. Unchanged by the location-chain
		 * design — the precedence gate looks only at the chain's own `implicit`
		 * flag, exactly as it always looked at the single entry's.
		 *
		 * Chain rebuild (location-chain design §3): starting from whatever chain
		 * is already stored, {@see self::rebuild_chain()}
		 * - drops every level DEEPER than `$record`'s own level
		 *   ({@see Location_Record::LEVELS} cascade order — region > settlement >
		 *   address);
		 * - keeps every remaining SHALLOWER stored record only when it is in the
		 *   same country AND `$record` `is_within()` it — a record whose ancestry
		 *   cannot be PROVEN is dropped, never kept on the benefit of the doubt
		 *   (see {@see self::rebuild_chain()} for why that draft was reversed);
		 * - writes the new record at its own level and makes it `current`.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Rebuilds the whole chain rather than overwriting the one
		 *              stored record (location-chain design §3, #334/#330).
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
			$current_chain = $this->get_chain();

			if ( ! self::may_overwrite( $current_chain, $implicit ) ) {
				return false;
			}

			$records = self::rebuild_chain( $current_chain, $record );

			if ( is_user_logged_in() ) {
				$this->persist( get_current_user_id(), $records, $record->level(), $implicit, time() );

				return true;
			}

			$session = $this->session();

			if ( null === $session ) {
				return false;
			}

			$this->persist( null, $records, $record->level(), $implicit, time() );

			return self::session_will_survive( $session );
		}

		/**
		 * Clears the `implicit` flag on the chain that is already stored, leaving
		 * the chain itself — every record, which level is `current`, and
		 * `saved_at` — exactly as it was.
		 *
		 * This exists because {@see self::set()} cannot express the operation
		 * (issue #518). An explicit `set()` does drop the flag, but it also
		 * REBUILDS the chain: `rebuild_chain()` discards every level deeper than
		 * the record it is given. Re-writing the settlement record to promote it
		 * would therefore delete the customer's `address` level — which, at the
		 * one moment this is called, is the pickup point's address that was just
		 * written. The flag is the only thing that should move.
		 *
		 * `saved_at` is deliberately preserved rather than restamped: it records
		 * when the customer's LOCATION was last decided, and promotion does not
		 * decide a new one — it records that an existing one is no longer a
		 * guess. Restamping it would make a promoted record look freshly picked
		 * to {@see self::is_customer_record_stale()}'s callers.
		 *
		 * Refuses (returns `false`, touching nothing) when there is no stored
		 * chain, or when the stored chain is already explicit — so a caller may
		 * fire this unconditionally.
		 *
		 * @since 2.0.2
		 *
		 * @return bool `true` when the flag was actually cleared.
		 */
		public function promote_chain_to_explicit(): bool {
			$chain = $this->get_chain();

			if ( null === $chain || false === $chain['implicit'] ) {
				return false;
			}

			if ( ! isset( $chain['records'][ $chain['current'] ] ) ) {
				// A chain whose `current` level names no record is corrupt. Reads
				// already degrade such a blob to `null` (see self::parse_stored_chain());
				// this guard keeps the WRITE side equally incapable of persisting one.
				return false;
			}

			if ( is_user_logged_in() ) {
				$this->persist( get_current_user_id(), $chain['records'], $chain['current'], false, $chain['saved_at'] );

				return true;
			}

			$session = $this->session();

			if ( null === $session ) {
				return false;
			}

			$this->persist( null, $chain['records'], $chain['current'], false, $chain['saved_at'] );

			return self::session_will_survive( $session );
		}

		/**
		 * Erases the customer's stored location CHAIN — the counterpart
		 * {@see self::set()} has never had (issue #356 part 1). Today
		 * {@see self::persist()} is the only write primitive and cannot express
		 * "nothing" (it unconditionally dereferences `$records[ $current_level ]`
		 * when firing {@see self::ACTION_SAVED}), so before this method there was
		 * no way to satisfy a WP "erase my personal data" request, nor any way
		 * for a "forget me" control to clear the store at all.
		 *
		 * Store contract — deliberately ASYMMETRIC with {@see self::get_chain()}'s
		 * own dual-store read, because the two callers of this method run in
		 * different security contexts:
		 *
		 * - `$user_id === null` (a VISITOR forgetting their OWN location): acts on
		 *   the CURRENT request's session, and — if that visitor happens to be
		 *   logged in — their own user meta too. Both stores belong to the same
		 *   person making the request.
		 * - `$user_id` given explicitly (the WP Privacy "erase personal data"
		 *   tool, run from wp-admin against SOMEONE ELSE's account via
		 *   {@see self::erase_personal_data()}): touches ONLY that user's meta.
		 *   `$this->session()` in that request is the ADMINISTRATOR's session, not
		 *   the erased customer's — clearing it would erase a location belonging
		 *   to a different person than the one the request is about. The erased
		 *   customer's OWN session copy, if any, is unreachable from an admin
		 *   request by construction: it lives in that customer's own browser
		 *   cookie/session store, not in anything the server can address by user
		 *   id. That is a WordPress privacy-mechanism limitation, not an omission
		 *   here — see {@see self::erase_personal_data()}'s own docblock.
		 *
		 * A logged-in visitor forgetting their OWN location (the `null` branch)
		 * MUST clear both stores even though {@see self::get_chain()} prefers the
		 * session: {@see self::get_chain_for_logged_in_user()} falls back to meta
		 * on an empty session and REPOPULATES the session from it — so a
		 * session-only clear would have the meta blob resurrect the "forgotten"
		 * chain on the very next read within the same request.
		 *
		 * Meta is erased with `delete_user_meta()` — never by writing an empty
		 * value, which {@see self::parse_stored_chain()}'s lenient guards could
		 * still read back as SOMETHING, and which would leave
		 * {@see self::ACTION_FORGOTTEN} and {@see self::erase_personal_data()}'s
		 * own `items_removed` report with no honest way to tell "erased" from
		 * "already empty".
		 *
		 * @since 2.0.2
		 *
		 * @param int|null $user_id `null` to forget the CURRENT visitor's own
		 *                          location (their session, and their own meta if
		 *                          logged in); an explicit user id to erase ONLY
		 *                          that user's meta, leaving any session
		 *                          untouched.
		 *
		 * @return void
		 */
		public function forget( ?int $user_id = null ): void {
			if ( null !== $user_id ) {
				$this->forget_user_meta( $user_id );

				return;
			}

			$session      = $this->session();
			$session_had  = null !== $session && null !== $session->get( self::STORAGE_KEY );
			$current_id   = is_user_logged_in() ? get_current_user_id() : 0;
			$meta_removed = 0 !== $current_id && $this->delete_meta_chain( $current_id );

			if ( ! $session_had && ! $meta_removed ) {
				return;
			}

			if ( $session_had ) {
				$session->set( self::STORAGE_KEY, null );
			}

			$this->fire_forgotten( $current_id );
		}

		/**
		 * Deletes `$user_id`'s stored chain from user meta, reporting whether it
		 * was ACTUALLY deleted — the shared primitive behind both branches of
		 * {@see self::forget()} and behind {@see self::erase_personal_data()}'s
		 * honest `items_removed`/`items_retained` report.
		 *
		 * Returns `delete_user_meta()`'s OWN result, not a blanket `true` once a
		 * row was found — Codex review, round 2: a fixed `true` here would make
		 * {@see self::forget()} fire {@see self::ACTION_FORGOTTEN} and clear the
		 * session, and {@see self::erase_personal_data()} report
		 * `items_removed: true`, even on a failed database write that left the
		 * row in place. On the very next request
		 * {@see self::get_chain_for_logged_in_user()}'s meta fallback would then
		 * repopulate the session from that still-present row — the exact
		 * resurrection bug this whole forget-path exists to prevent — while
		 * having ALREADY told a WP Privacy erasure requester their data was
		 * gone.
		 *
		 * @since 2.0.2
		 *
		 * @param int $user_id The user whose meta chain should be deleted.
		 *
		 * @return bool `true` only when a chain was stored AND
		 *              `delete_user_meta()` reports it actually removed it;
		 *              `false` when there was nothing to delete, OR when the
		 *              delete itself failed.
		 */
		private function delete_meta_chain( int $user_id ): bool {
			if ( '' === get_user_meta( $user_id, self::STORAGE_KEY, true ) ) {
				return false;
			}

			return (bool) delete_user_meta( $user_id, self::STORAGE_KEY );
		}

		/**
		 * The explicit-`$user_id` half of {@see self::forget()}, split out as
		 * its own bool-returning primitive so {@see self::erase_personal_data()}
		 * can report `items_removed`/`items_retained` honestly instead of
		 * guessing from a pre-check that cannot see whether the delete itself
		 * actually succeeded.
		 *
		 * @since 2.0.2
		 *
		 * @param int $user_id The user whose meta chain should be forgotten.
		 *
		 * @return bool `true` when a chain was stored and is now actually gone;
		 *              `false` when there was nothing to forget, or the delete
		 *              itself failed.
		 */
		private function forget_user_meta( int $user_id ): bool {
			$removed = $this->delete_meta_chain( $user_id );

			if ( $removed ) {
				$this->fire_forgotten( $user_id );
			}

			return $removed;
		}

		/**
		 * Fires {@see self::ACTION_FORGOTTEN} — split out so {@see self::forget()}
		 * can decide ONCE, after both stores it is allowed to touch have been
		 * considered, whether anything was actually erased, rather than each
		 * branch firing (and potentially double-firing) independently.
		 *
		 * @since 2.0.2
		 *
		 * @param int $user_id Forwarded to {@see self::ACTION_FORGOTTEN} as-is.
		 *
		 * @return void
		 */
		private function fire_forgotten( int $user_id ): void {
			/** This action is documented above, on {@see self::ACTION_FORGOTTEN}. */
			do_action( self::ACTION_FORGOTTEN, $user_id );
		}

		/**
		 * WP Privacy "export personal data" callback for this store — see
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::register_data_exporters()}
		 * for how this is wired onto the `wp_privacy_personal_data_exporters`
		 * filter, and why the removal discipline that filter registration follows
		 * matters.
		 *
		 * Reads user META directly (not {@see self::get_chain()}, which is
		 * session-preferred for the CURRENT visitor) — an export request names an
		 * arbitrary EMAIL address, not "the current visitor", so there is no
		 * session to prefer; meta is this store's only address-independent,
		 * authoritative source (spec: meta is authoritative for a logged-in
		 * customer).
		 *
		 * Exports every level of the chain, not only `current` — a settlement the
		 * customer picked before refining to an address is still THEIR data even
		 * once superseded. `raw` — the opaque, provider-supplied payload behind
		 * each {@see Location_Record} (see that class's own docblock: "never
		 * inspected by the framework") — is exported too: it is the provider's
		 * verbatim response for an address/settlement/region THE CUSTOMER
		 * selected, so it can (and for most providers' address-level responses,
		 * does) carry MORE identifying detail than the handful of fields this
		 * store normalizes out of it (e.g. a provider's own internal
		 * identifiers, unrestricted address strings, or geocoding metadata) —
		 * confirmed by reading {@see Location_Record::from_array()}'s own
		 * handling of `raw` ("stored and returned untouched, never inspected")
		 * rather than assumed. A personal-data export must be complete, so
		 * "probably nothing sensitive in there" is not a basis to omit it. `raw`
		 * passes through {@see self::FILTER_EXPORT_RAW} first — see that
		 * constant's own docblock for the redaction OBLIGATION it places on a
		 * third-party provider author, since this framework has no way to know
		 * what a provider it does not own actually put in there.
		 *
		 * Never paginates beyond page 1: one customer holds at most one chain
		 * (a handful of levels), which always fits on a single page — every
		 * later page honestly reports `done => true` with no data, the same
		 * shape WordPress core's own exporters use once they run out of rows.
		 *
		 * @since 2.0.2
		 *
		 * @param string $email_address The requester's email address.
		 * @param int    $page          1-based page number.
		 *
		 * @return array{data: array<int, array{group_id: string, group_label: string, group_description: string, item_id: string, data: array<int, array{name: string, value: mixed}>}>, done: bool}
		 */
		public function export_personal_data( string $email_address, int $page = 1 ): array {
			if ( $page > 1 ) {
				return [
					'data' => [],
					'done' => true,
				];
			}

			$user = get_user_by( 'email', $email_address );

			if ( false === $user ) {
				return [
					'data' => [],
					'done' => true,
				];
			}

			$user_id = (int) $user->ID;
			$chain   = $this->parse_stored_chain( get_user_meta( $user_id, self::STORAGE_KEY, true ) );

			if ( null === $chain ) {
				return [
					'data' => [],
					'done' => true,
				];
			}

			$items = [];

			foreach ( $chain['records'] as $level => $record ) {
				$fields = [
					[
						'name' => __( 'Ключ локации', 'woodev-plugin-framework' ),
						'value' => $record->key(),
					],
					[
						'name' => __( 'Уровень', 'woodev-plugin-framework' ),
						'value' => $level,
					],
					[
						'name' => __( 'Страна', 'woodev-plugin-framework' ),
						'value' => $record->country(),
					],
					[
						'name' => __( 'Название', 'woodev-plugin-framework' ),
						'value' => $record->label(),
					],
					[
						'name' => __( 'Сохранено', 'woodev-plugin-framework' ),
						'value' => gmdate( 'Y-m-d H:i:s', $chain['saved_at'] ),
					],
					[
						'name'  => __( 'Определено автоматически (не выбор покупателя)', 'woodev-plugin-framework' ),
						'value' => $chain['implicit'] ? __( 'Да', 'woodev-plugin-framework' ) : __( 'Нет', 'woodev-plugin-framework' ),
					],
				];

				/** This filter is documented above, on {@see self::FILTER_EXPORT_RAW}. */
				$raw = apply_filters( self::FILTER_EXPORT_RAW, $record->raw(), $record, $user_id );

				if ( null !== $raw ) {
					$fields[] = [
						'name'  => __( 'Необработанный ответ провайдера', 'woodev-plugin-framework' ),
						'value' => wp_json_encode( $raw ),
					];
				}

				$items[] = [
					'group_id'          => 'woodev-customer-location',
					'group_label'       => __( 'Локация покупателя', 'woodev-plugin-framework' ),
					'group_description' => __( 'Локация доставки, которую покупатель выбрал или которая была определена автоматически при оформлении заказа.', 'woodev-plugin-framework' ),
					'item_id'           => "woodev-customer-location-{$level}",
					'data'              => $fields,
				];
			}

			return [
				'data' => $items,
				'done' => true,
			];
		}

		/**
		 * WP Privacy "erase personal data" callback for this store — see
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::register_data_erasers()}
		 * for how this is wired onto the `wp_privacy_personal_data_erasers`
		 * filter.
		 *
		 * Erases via {@see self::forget_user_meta()} — {@see self::forget()}'s
		 * own explicit-`$user_id` primitive — for the SAME meta-only,
		 * session-untouched reason {@see self::forget()}'s own docblock
		 * explains: this callback runs in wp-admin, against an arbitrary
		 * customer's email, so `$this->session()` here would be the
		 * ADMINISTRATOR's session, never the erased customer's. The customer's
		 * own session copy (if any) is left alone; it lives in that customer's
		 * own browser and is unreachable from this request. That is a
		 * WordPress-privacy-mechanism limitation (an eraser has no way to reach
		 * a different person's session), not an omission — the erasure is still
		 * COMPLETE for every store this method can actually reach.
		 *
		 * `items_removed`/`items_retained` reflect what actually happened in
		 * the database, not merely whether a row was found beforehand (Codex
		 * review, round 2): a row that existed but whose `delete_user_meta()`
		 * call itself failed is reported as RETAINED, with a message, rather
		 * than as removed — see {@see self::delete_meta_chain()}'s own docblock
		 * for why silently reporting success there would be worse than a
		 * cosmetic bug.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 `items_removed`/`items_retained` now come from
		 *              {@see self::forget_user_meta()}'s actual result, not a
		 *              pre-check that could not see whether the delete itself
		 *              succeeded (Codex review, round 2, must-fix 1).
		 *
		 * @param string $email_address The requester's email address.
		 * @param int    $page          1-based page number.
		 *
		 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
		 */
		public function erase_personal_data( string $email_address, int $page = 1 ): array {
			if ( $page > 1 ) {
				return self::eraser_result( false, false );
			}

			$user = get_user_by( 'email', $email_address );

			if ( false === $user ) {
				return self::eraser_result( false, false );
			}

			$user_id = (int) $user->ID;
			$had     = '' !== get_user_meta( $user_id, self::STORAGE_KEY, true );
			$removed = $this->forget_user_meta( $user_id );

			return self::eraser_result( $removed, $had && ! $removed );
		}

		/**
		 * Builds {@see self::erase_personal_data()}'s WP Privacy return shape —
		 * `messages` carries a human-readable explanation whenever
		 * `$items_retained` is `true`, since a bare `true` gives the customer no
		 * indication of WHY their data is still there.
		 *
		 * @since 2.0.2
		 *
		 * @param bool $items_removed  Whether a chain was actually erased.
		 * @param bool $items_retained Whether a chain existed but could not be
		 *                             erased.
		 *
		 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
		 */
		private static function eraser_result( bool $items_removed, bool $items_retained ): array {
			return [
				'items_removed'  => $items_removed,
				'items_retained' => $items_retained,
				'messages'       => $items_retained
					? [ __( 'Не удалось удалить сохранённую локацию покупателя — запись осталась в базе данных.', 'woodev-plugin-framework' ) ]
					: [],
				'done'           => true,
			];
		}

		/**
		 * Rebuilds the chain (`level => Location_Record`) that {@see self::set()}
		 * should persist: `$new_record` at its own level, plus whichever
		 * previously-stored SHALLOWER records survive the ancestor-compatibility
		 * check — see {@see self::set()}'s own docblock for the full rule (spec
		 * `docs-internal/specs/2026-08-15-location-chain-design.md` §3).
		 *
		 * A SHALLOWER RECORD IS KEPT ONLY WHEN THE NEW ONE PROVES IT IS STILL AN
		 * ANCESTOR. An earlier draft also kept it when the new record published NO
		 * ancestor set at all, reasoning that "no information is not negative
		 * information" and that a provider which has not implemented ancestors
		 * should not silently lose the chain. Adversarial review killed that: a
		 * Moscow settlement survived a Saint-Petersburg address, and what survives
		 * is exactly what
		 * {@see \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope::current_locality()}
		 * answers — so the customer's pickup point would be filed under a city they
		 * are no longer in, silently. That is the very failure #334 is about, and
		 * this layer's own discipline settles it: refusing to answer beats a
		 * plausible wrong answer (gotcha `an-empty-domain-key-is-not-a-key`).
		 *
		 * The cost is deliberate and one-sided: a provider that publishes no
		 * ancestors gets a one-entry chain, so after a page reload its customers
		 * lose parent SCOPING (a search runs country-wide instead of within the
		 * settlement — visible, self-correcting) and lose pickup PERSISTENCE
		 * (`current_locality()` answers `''`, so nothing is remembered — degraded,
		 * never wrong). Publishing `ancestors` is therefore a real provider
		 * obligation, not an optional extra; the bundled DaData provider does.
		 *
		 * The COUNTRY check in front of it is not redundant: it is the one
		 * compatibility fact the framework owns itself. `country` is required and
		 * framework-validated on every record (never provider dictionary), and
		 * {@see \Woodev\Framework\Shipping\Location\Location_Scope::within()} takes
		 * the scope's country FROM THE PARENT — so a cross-country survivor would
		 * silently move the customer's next search to another country.
		 *
		 * @since 2.0.2
		 *
		 * @param array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null $current_chain The chain already stored, if any.
		 * @param Location_Record                                                                                     $new_record    The record being written.
		 *
		 * @return array<string, Location_Record>
		 */
		private static function rebuild_chain( ?array $current_chain, Location_Record $new_record ): array {
			$records        = [];
			$new_level      = $new_record->level();
			$new_level_rank = array_search( $new_level, Location_Record::LEVELS, true );

			if ( null !== $current_chain ) {
				foreach ( $current_chain['records'] as $level => $stored_record ) {
					if ( $level === $new_level ) {
						continue; // Superseded by $new_record below.
					}

					$level_rank = array_search( $level, Location_Record::LEVELS, true );

					if ( false === $level_rank || false === $new_level_rank || $level_rank > $new_level_rank ) {
						continue; // Deeper than (or an unranked/unknown level relative to) the new record — drop.
					}

					if ( $stored_record->country() !== $new_record->country() ) {
						continue; // Another country entirely — never an ancestor.
					}

					if ( $new_record->is_within( $stored_record->key() ) ) {
						$records[ $level ] = $stored_record;
					}
				}
			}

			$records[ $new_level ] = $new_record;

			return $records;
		}

		/**
		 * Whether a write into `$session` will actually outlive this request.
		 *
		 * `WC_Session_Handler::save_data()` writes nothing unless
		 * `$this->_dirty && $this->has_session()`, and for a GUEST `has_session()`
		 * is the cart cookie's presence (`class-wc-session-handler.php:380`, `:555`)
		 * — so a session OBJECT existing is not the same fact as a write surviving.
		 *
		 * The distinction only became reachable with issue #324: before it,
		 * `/location/select` reached a guest with no session at all and honestly
		 * answered `persisted: false`; once the route bridges the session, the
		 * object always exists, and reporting on its mere existence would make
		 * `persisted` a constant `true` — turning an honest signal into a lie and
		 * making `location-cascade.js`'s «не удалось сохранить» notice unreachable
		 * for the one case it was built for.
		 *
		 * Unknown is NOT false: the abstract `\WC_Session` declares no
		 * `has_session()`, and a custom handler installed through
		 * `woocommerce_session_handler` need not either. A store running such a
		 * handler must not show every customer a failure notice for a write that
		 * probably succeeded.
		 *
		 * @since 2.0.2
		 *
		 * @param object $session the session handler to inspect.
		 *
		 * @return bool
		 */
		private static function session_will_survive( $session ): bool {
			if ( ! method_exists( $session, 'has_session' ) ) {
				return true;
			}

			return (bool) $session->has_session();
		}

		/**
		 * Migrates a guest's session-held CHAIN onto the account the customer
		 * just logged into — the whole chain, not only the current record, so a
		 * settlement pick made while browsing as a guest is not stranded once the
		 * address the guest also typed becomes the account's `current`. Hook this
		 * onto `wp_login` (2 args) — matches that hook's own callback signature,
		 * so `add_action( 'wp_login', [ $store, 'handle_wp_login' ], 10, 2 )`
		 * wires it directly.
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
		 * design decision in this class): the session CHAIN and the existing meta
		 * CHAIN are reconciled through the EXACT SAME implicit/explicit gate
		 * {@see self::set()} uses ({@see self::may_overwrite()}), with the session
		 * chain playing the role of the "new" write and the existing meta chain
		 * playing the role of "current" — the gate itself only ever looks at the
		 * chain's own `implicit` flag, so this reconciliation is unaffected by how
		 * many levels either chain holds:
		 * - no session chain at all (guest picked nothing this session) → no-op,
		 *   the account's own chain (if any) is left untouched;
		 * - session chain is EXPLICIT → it always wins WHOLE (a real choice made
		 *   while logged out — during THIS session — is the customer's freshest
		 *   actual answer, exactly as a normal explicit {@see self::set()} call
		 *   always overwrites whatever was there before, explicit or implicit);
		 * - session chain is IMPLICIT and the account already holds an EXPLICIT
		 *   chain → the account chain wins WHOLE, unchanged (an implicit guest
		 *   guess must never clobber a real saved choice — same rule
		 *   {@see self::set()} enforces for a plain implicit write);
		 * - session chain is IMPLICIT and the account has no chain, or only an
		 *   implicit one → the session chain wins WHOLE (nothing worth protecting
		 *   was there).
		 *
		 * The winning chain is taken WHOLE — every level it holds, not merely its
		 * `current` record — so a settlement the guest picked before refining to
		 * an address is not stranded the moment the address becomes `current` on
		 * the account.
		 *
		 * Whichever side wins, BOTH stores are resynced to hold it: if the meta
		 * side wins, the session (now this user's fast path) is overwritten with
		 * the meta chain too, so a subsequent session-preferred {@see self::get()}
		 * cannot surface the losing guest guess.
		 *
		 * A missing session (no WooCommerce / no session available) is a silent
		 * no-op — there is nothing to migrate FROM.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Migrates the WHOLE chain, not only the current record
		 *              (location-chain design §3).
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

			$session_chain = $this->parse_stored_chain( $session->get( self::STORAGE_KEY ) );

			if ( null === $session_chain ) {
				return;
			}

			$user_id    = (int) $user->ID;
			$meta_chain = $this->parse_stored_chain( get_user_meta( $user_id, self::STORAGE_KEY, true ) );

			$winner = self::may_overwrite( $meta_chain, $session_chain['implicit'] ) ? $session_chain : $meta_chain;

			$this->persist( $user_id, $winner['records'], $winner['current'], $winner['implicit'], $winner['saved_at'] );
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
		 * The logged-in half of {@see self::get_chain()} — session-preferred read
		 * with a meta fallback that repopulates the session.
		 *
		 * @since 2.0.2
		 *
		 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null
		 */
		private function get_chain_for_logged_in_user(): ?array {
			$session = $this->session();

			if ( null !== $session ) {
				$from_session = $this->parse_stored_chain( $session->get( self::STORAGE_KEY ) );

				if ( null !== $from_session ) {
					return $from_session;
				}
			}

			$raw_meta  = get_user_meta( get_current_user_id(), self::STORAGE_KEY, true );
			$from_meta = $this->parse_stored_chain( $raw_meta );

			if ( null !== $from_meta && null !== $session ) {
				$session->set( self::STORAGE_KEY, $raw_meta );
			}

			return $from_meta;
		}

		/**
		 * Projects a parsed chain down to {@see self::get()}'s own entry shape —
		 * the record AT the chain's `current` level, plus the chain-wide
		 * `implicit`/`saved_at` bookkeeping.
		 *
		 * @since 2.0.2
		 *
		 * @param array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int} $chain A parsed chain.
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}
		 */
		private static function chain_to_entry( array $chain ): array {
			return [
				'record'   => $chain['records'][ $chain['current'] ],
				'implicit' => $chain['implicit'],
				'saved_at' => $chain['saved_at'],
			];
		}

		/**
		 * Writes a chain to whichever stores apply and announces the change.
		 *
		 * @since 2.0.2
		 *
		 * @param int|null                       $user_id       The logged-in customer's
		 *                                                       user id, or `null` for a
		 *                                                       guest (meta is skipped).
		 * @param array<string, Location_Record> $records       The chain to store, `level => record`.
		 * @param string                         $current_level Which level is `current`.
		 * @param bool                           $implicit      Whether it is a default guess.
		 * @param int                            $saved_at      Unix timestamp to stamp
		 *                                                       (preserved as-is by a
		 *                                                       migration reconciliation
		 *                                                       rather than always "now").
		 *
		 * @return void
		 */
		private function persist( ?int $user_id, array $records, string $current_level, bool $implicit, int $saved_at ): void {
			$entry = [
				'records'  => array_map(
					static function ( Location_Record $record ): array {
						return $record->to_array();
					},
					$records
				),
				'current'  => $current_level,
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
			do_action( self::ACTION_SAVED, $records[ $current_level ], $implicit, $user_id ?? 0 );
		}

		/**
		 * The implicit/explicit precedence gate shared by {@see self::set()} and
		 * {@see self::handle_wp_login()}: a candidate marked implicit may never
		 * overwrite an existing EXPLICIT chain. Anything else — no existing chain,
		 * an existing chain that is itself implicit, or an explicit candidate —
		 * is allowed through. Only the chain-wide `implicit` flag is consulted;
		 * how many levels the chain holds is irrelevant to this gate.
		 *
		 * @since 2.0.2
		 *
		 * @param array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null $current             The chain already stored, if any.
		 * @param bool                                                                                                $candidate_implicit Whether the candidate write is implicit.
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
		 * Validates and parses a raw stored value into the chain shape, or `null`
		 * when it is not usable — never throws.
		 *
		 * Accepts TWO shapes:
		 * - the current chain shape, `{ records: { level => record }, current,
		 *   implicit, saved_at }`;
		 * - the legacy pre-chain shape, `{ record, implicit, saved_at }`, read as
		 *   a one-entry chain whose `current` is that record's own
		 *   {@see Location_Record::level()} (location-chain design §3).
		 *
		 * Guards: the raw value must be an array; every entry under `records`
		 * (or the single legacy `record`) must build a valid
		 * {@see Location_Record} — empty/missing `key`, an invalid `level`, etc.
		 * are exactly what {@see Location_Record::from_array()} already refuses,
		 * and ANY entry failing that refuses the WHOLE stored blob (never a
		 * partial chain silently missing a level) — this is the read-side half
		 * of the empty-key discipline: Task 1 refuses construction, this method
		 * refuses to let that refusal become a fatal on read. Each parsed record
		 * is indexed by its OWN {@see Location_Record::level()}, never by the
		 * raw array's key, so a corrupted/mismatched outer key cannot smuggle a
		 * record in under the wrong level. `current` must name a level actually
		 * present in `records`. `implicit` and `saved_at` are read leniently
		 * (absent/malformed become `false` / `0`) since they are this class's own
		 * bookkeeping, not part of any record's own validated contract.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Parses the chain shape (location-chain design §3),
		 *              accepting the legacy single-`record` shape as a one-entry
		 *              chain.
		 *
		 * @param mixed $raw The raw session/meta value (a WC session getter
		 *                    returns `null` when absent; `get_user_meta( …, true )`
		 *                    returns `''`).
		 *
		 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null
		 */
		private function parse_stored_chain( $raw ): ?array {
			if ( ! is_array( $raw ) ) {
				return null;
			}

			if ( isset( $raw['records'] ) ) {
				return $this->parse_chain_shape( $raw );
			}

			return $this->parse_legacy_record_shape( $raw );
		}

		/**
		 * Parses the current `{ records, current, implicit, saved_at }` shape —
		 * see {@see self::parse_stored_chain()} for the full contract.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $raw The raw stored value; already known to be an array.
		 *
		 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null
		 */
		private function parse_chain_shape( array $raw ): ?array {
			if ( ! is_array( $raw['records'] ) || ! isset( $raw['current'] ) || ! is_string( $raw['current'] ) ) {
				return null;
			}

			$records = [];

			foreach ( $raw['records'] as $record_data ) {
				if ( ! is_array( $record_data ) ) {
					return null;
				}

				try {
					$record = Location_Record::from_array( $record_data );
				} catch ( \InvalidArgumentException $exception ) {
					return null;
				}

				if ( isset( $records[ $record->level() ] ) ) {
					// TWO records at the SAME level — refused, not silently resolved
					// (adversarial review). Re-indexing by the record's own level is
					// what stops a corrupted outer key smuggling a record in under the
					// wrong one, but it also means a duplicate would be decided by
					// serialization ORDER: the later entry would win, so the customer's
					// pickup point could be restored under a settlement they never
					// picked, and merely reordering the blob would change the answer.
					// The whole-blob refusal below is this class's existing discipline
					// for anything it cannot read unambiguously.
					return null;
				}

				$records[ $record->level() ] = $record;
			}

			if ( '' === $raw['current'] || ! isset( $records[ $raw['current'] ] ) ) {
				return null;
			}

			return [
				'records'  => $records,
				'current'  => $raw['current'],
				'implicit' => ! empty( $raw['implicit'] ),
				'saved_at' => isset( $raw['saved_at'] ) && is_numeric( $raw['saved_at'] ) ? (int) $raw['saved_at'] : 0,
			];
		}

		/**
		 * Parses the legacy pre-chain `{ record, implicit, saved_at }` shape as a
		 * one-entry chain — see {@see self::parse_stored_chain()} for the full
		 * contract.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $raw The raw stored value; already known to be an array.
		 *
		 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null
		 */
		private function parse_legacy_record_shape( array $raw ): ?array {
			if ( ! isset( $raw['record'] ) || ! is_array( $raw['record'] ) ) {
				return null;
			}

			try {
				$record = Location_Record::from_array( $raw['record'] );
			} catch ( \InvalidArgumentException $exception ) {
				return null;
			}

			return [
				'records'  => [ $record->level() => $record ],
				'current'  => $record->level(),
				'implicit' => ! empty( $raw['implicit'] ),
				'saved_at' => isset( $raw['saved_at'] ) && is_numeric( $raw['saved_at'] ) ? (int) $raw['saved_at'] : 0,
			];
		}
	}

endif;
