<?php
/**
 * Woodev Popular Settlement Verifier
 *
 * Applies spec D6 to one (or every) popular-settlement row: asks the owning
 * provider, via `resolve_key()`, whether a stored settlement is still current,
 * and reconciles the store accordingly. Never throws for a provider-side
 * failure — every outcome, including a caught `\Throwable`, is reported through
 * {@see Popular_Settlement_Verification} so a caller (the `/select` route's D5
 * step, the D8 merchant sweep) can react without a try/catch of its own.
 *
 * THE INVARIANT THIS CLASS EXISTS TO PROTECT (spec D4/D6): `resolve_key()`
 * returning `null` means EXACTLY "the provider was asked and confirmed it does
 * not know this key" — only THAT deletes the row. Every other outcome — a
 * thrown `\Throwable`, for ANY reason (unconfigured, transport failure,
 * malformed payload, a derived key) — leaves the row COMPLETELY untouched: no
 * delete, no clock bump. `failed` is not `gone`.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Popular_Settlement_Verifier' ) ) :

	/**
	 * Applies popular-settlements spec D6 to one or every row of a provider.
	 *
	 * @since 2.0.2
	 */
	class Popular_Settlement_Verifier {

		/** @var Popular_Settlement_Store */
		private Popular_Settlement_Store $store;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Popular_Settlement_Store $store The store to reconcile against.
		 */
		public function __construct( Popular_Settlement_Store $store ) {
			$this->store = $store;
		}

		/**
		 * Applies D6 to ONE entry.
		 *
		 * Never throws for a provider-side failure — see the class docblock. The
		 * caller is responsible for making sure `$provider` actually OWNS `$entry`
		 * (i.e. `$provider->get_id() === $entry->provider_id()`) — this method
		 * does not re-check that itself, mirroring
		 * {@see Location_Provider::resolve_key()}'s own "same provider that
		 * produced this key" precondition.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Provider        $provider The provider that owns `$entry`.
		 * @param Popular_Settlement_Entry $entry    The entry to verify.
		 *
		 * @return Popular_Settlement_Verification
		 */
		public function verify_entry( Location_Provider $provider, Popular_Settlement_Entry $entry ): Popular_Settlement_Verification {
			try {
				$fresh = $provider->resolve_key( $entry->record()->key() );
			} catch ( \Throwable $exception ) {
				// Every outcome except a confirmed miss THROWS (spec D4/D6) —
				// unconfigured, a transport failure, a malformed payload, a
				// derived key. The row is left completely untouched: no delete,
				// no clock bump. `failed` is not `gone`.
				return Popular_Settlement_Verification::failed( $exception );
			}

			if ( null === $fresh ) {
				// The ONE outcome allowed to mean "gone" (spec D4/D6) — the
				// provider was asked and confirmed it does not know this key.
				$this->store->delete_entry( $entry->id() );

				return Popular_Settlement_Verification::gone();
			}

			if ( $fresh->to_array() === $entry->record()->to_array() ) {
				$this->store->touch_verified( $entry->id() );

				return Popular_Settlement_Verification::unchanged( $fresh );
			}

			// A rename, a new postcode, or even a changed key must land — search
			// would have returned the new record (spec D1/D6 equivalence).
			// order_count/last_ordered_at are separate clocks (spec D2), left
			// untouched by a plain rename — replace_record() only overwrites
			// record/locality_key/country/last_verified_at in that case. When the
			// new key converges onto a DIFFERENT row (two historical popular rows
			// the provider has since merged into one settlement, #499),
			// replace_record() itself folds that row's order_count/
			// last_ordered_at into the survivor and deletes this one, and still
			// reports success — this call site does not need to know which of
			// the two happened.
			if ( ! $this->store->replace_record( $entry->id(), $fresh ) ) {
				// A `false` here is neither a plain rename nor a mergeable
				// collision — both are handled inside replace_record() now — but
				// some OTHER failure, e.g. a genuinely concurrent delete racing
				// the write. Report `failed`, never `updated`: the row was NOT
				// actually reconciled, so returning `updated` would be a lie a
				// caller (the `/select` route's D5 step) would persist as fact.
				return Popular_Settlement_Verification::failed(
					new \RuntimeException(
						sprintf(
							'Popular_Settlement_Store::replace_record() failed for entry id %d.',
							$entry->id()
						)
					)
				);
			}

			return Popular_Settlement_Verification::updated( $fresh );
		}

		/**
		 * Applies D6 to every row of one provider (D8's "Проверить актуальность
		 * популярных городов").
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Provider $provider The provider whose rows to sweep.
		 *
		 * @return array{checked: int, unchanged: int, updated: int, deleted: int, failed: int}
		 */
		public function sweep( Location_Provider $provider ): array {
			$counts = [
				'checked'   => 0,
				'unchanged' => 0,
				'updated'   => 0,
				'deleted'   => 0,
				'failed'    => 0,
			];

			foreach ( $this->store->all_for_provider( $provider->get_id() ) as $entry ) {
				++$counts['checked'];

				$verification = $this->verify_entry( $provider, $entry );

				switch ( $verification->outcome() ) {
					case Popular_Settlement_Verification::OUTCOME_UNCHANGED:
						++$counts['unchanged'];
						break;
					case Popular_Settlement_Verification::OUTCOME_UPDATED:
						++$counts['updated'];
						break;
					case Popular_Settlement_Verification::OUTCOME_GONE:
						++$counts['deleted'];
						break;
					case Popular_Settlement_Verification::OUTCOME_FAILED:
						++$counts['failed'];
						break;
				}
			}

			return $counts;
		}
	}

endif;
