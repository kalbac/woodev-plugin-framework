<?php
/**
 * Woodev Popular Settlement Verification
 *
 * Immutable outcome of applying spec D6 to one popular-settlement row (#488 slice
 * 3): what {@see Popular_Settlement_Verifier::verify_entry()} learned from asking
 * the owning provider, via `resolve_key()`, whether a stored settlement is still
 * current, and what it did about it. Carries enough for a caller (the `/select`
 * route's D5 step, the D8 merchant sweep) to react without re-deriving anything
 * from the store itself.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Popular_Settlement_Verification' ) ) :

	/**
	 * Immutable result of verifying one popular-settlement entry (spec D6).
	 *
	 * Built only by {@see Popular_Settlement_Verifier} via the named constructors
	 * below — nothing else produces one.
	 *
	 * @since 2.0.2
	 */
	class Popular_Settlement_Verification {

		/**
		 * Outcome: the provider confirmed the stored record is still current (spec
		 * D6) — only `last_verified_at` was bumped.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const OUTCOME_UNCHANGED = 'unchanged';

		/**
		 * Outcome: the provider returned a CHANGED record for the same entry (spec
		 * D6, incl. a changed key) — the stored `record`/`locality_key`/`country`
		 * were overwritten in place; `order_count`/`last_ordered_at` were not
		 * touched.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const OUTCOME_UPDATED = 'updated';

		/**
		 * Outcome: the provider confirmed the key no longer resolves (spec D6) —
		 * the row is ALREADY DELETED by the time this is returned.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const OUTCOME_GONE = 'gone';

		/**
		 * Outcome: the provider could not be asked at all (spec D6) — the row was
		 * left COMPLETELY UNTOUCHED: no delete, no clock bump. `failed` is not
		 * `gone`.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const OUTCOME_FAILED = 'failed';

		/** @var string one of the self::OUTCOME_* constants */
		private string $outcome;

		/** @var Location_Record|null the provider's fresh record for `unchanged`/`updated`; null otherwise */
		private ?Location_Record $record;

		/** @var \Throwable|null the caught failure for `failed`; null otherwise */
		private ?\Throwable $error;

		/**
		 * Constructor. Use the named constructors below — they enforce which
		 * members are meaningful for which outcome.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $outcome One of self::OUTCOME_*.
		 * @param Location_Record|null $record  The provider's fresh record, or null.
		 * @param \Throwable|null      $error   The caught failure, or null.
		 */
		private function __construct( string $outcome, ?Location_Record $record, ?\Throwable $error ) {
			$this->outcome = $outcome;
			$this->record  = $record;
			$this->error   = $error;
		}

		/**
		 * The provider confirmed the stored record is still current (spec D6):
		 * only `last_verified_at` was bumped. Still carries the provider's fresh
		 * record — it was fetched to make the comparison — for a caller that wants
		 * it (e.g. a sweep reporting what it saw); the `/select` route's own D5
		 * step deliberately does NOT use it here, persisting the customer's
		 * already-posted record instead (spec: "the customer's record, as today").
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record The provider's fresh (confirmed unchanged) record.
		 *
		 * @return self
		 */
		public static function unchanged( Location_Record $record ): self {
			return new self( self::OUTCOME_UNCHANGED, $record, null );
		}

		/**
		 * The provider returned a changed record for the same entry (spec D6).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record The provider's fresh record.
		 *
		 * @return self
		 */
		public static function updated( Location_Record $record ): self {
			return new self( self::OUTCOME_UPDATED, $record, null );
		}

		/**
		 * The provider confirmed the key no longer resolves (spec D6) — the row
		 * was already deleted by the time this is returned.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function gone(): self {
			return new self( self::OUTCOME_GONE, null, null );
		}

		/**
		 * The provider could not be asked at all (spec D6) — the row was left
		 * completely untouched: no delete, no clock bump. `failed` is not `gone`.
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable $error The caught failure.
		 *
		 * @return self
		 */
		public static function failed( \Throwable $error ): self {
			return new self( self::OUTCOME_FAILED, null, $error );
		}

		/**
		 * Gets the outcome (one of self::OUTCOME_*).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function outcome(): string {
			return $this->outcome;
		}

		/**
		 * Gets the provider's fresh record for `unchanged`/`updated`, or null.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Record|null
		 */
		public function record(): ?Location_Record {
			return $this->record;
		}

		/**
		 * Gets the caught failure for `failed`, or null.
		 *
		 * @since 2.0.2
		 *
		 * @return \Throwable|null
		 */
		public function error(): ?\Throwable {
			return $this->error;
		}
	}

endif;
