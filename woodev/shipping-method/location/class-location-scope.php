<?php
/**
 * Woodev Location Scope
 *
 * The lookup scope a caller hands a {@see Location_Provider} for `suggest()`,
 * `list_localities()` and `normalize()` (spec §4.1): which country, at which level,
 * optionally narrowed to "within" a parent locality — "settlements within region X",
 * "addresses within settlement Y".
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Scope' ) ) :

	/**
	 * Immutable value object describing one location lookup scope.
	 *
	 * A scope always names a country and a level. It MAY also carry a parent
	 * constraint, supplied by the caller either as a full {@see Location_Record}
	 * (e.g. the region record the customer already picked) or as raw components
	 * (an array shaped like a record's own component groups — used when the caller
	 * has locality data but no full record, e.g. an admin-typed region name). Both
	 * shapes are exposed through the SAME accessor pair so a provider never has to
	 * `instanceof`-branch on how the scope was built: {@see self::parent_record()}
	 * returns the record when one was given (null otherwise), and
	 * {@see self::parent_components()} ALWAYS returns a components array when any
	 * parent was given — derived from the record when the scope was built from one —
	 * so a provider that only cares about components-shaped data can call that one
	 * accessor unconditionally.
	 *
	 * @since 2.0.2
	 */
	class Location_Scope {

		/**
		 * ISO-3166 alpha-2 country code (upper-case).
		 *
		 * @var string
		 */
		private string $country;

		/**
		 * Level being searched (one of {@see Location_Record::LEVELS}).
		 *
		 * @var string
		 */
		private string $level;

		/**
		 * Parent constraint as a full record, or null when no parent was given, or
		 * when the parent was given as raw components instead.
		 *
		 * @var Location_Record|null
		 */
		private ?Location_Record $parent_record;

		/**
		 * Parent constraint as raw components, or null when no parent was given, or
		 * when the parent was given as a {@see Location_Record} instead.
		 *
		 * An explicitly-supplied empty array (`within_components( $country, $level, [] )`)
		 * is a real, if trivial, parent constraint — it is NOT the same state as "no
		 * parent" and is deliberately distinguishable from it via `null` vs `[]`.
		 *
		 * @var array<string, mixed>|null
		 */
		private ?array $parent_components;

		/**
		 * The provider (#358, {@see Location_Provider::get_id()}) this scope was
		 * minted for via {@see self::for_provider()}, or null when it never was.
		 * {@see self::report_narrowing()} refuses to accept a verdict on a scope
		 * that is null here — a caller must stamp a fresh clone per provider call
		 * first.
		 *
		 * @var string|null
		 */
		private ?string $narrowing_provider_id = null;

		/**
		 * The narrowing verdict reported by the provider this scope was stamped
		 * for via {@see self::for_provider()}, or null when none has been reported
		 * yet — write-once, see {@see self::report_narrowing()}.
		 *
		 * @var string|null
		 */
		private ?string $narrowing = null;

		/**
		 * Constructor. Use one of the named constructors below — they validate.
		 *
		 * @since 2.0.2
		 *
		 * @param string                    $country           Normalized ISO-3166 alpha-2 country code.
		 * @param string                    $level             Validated level.
		 * @param Location_Record|null      $parent_record     Parent constraint as a record, or null.
		 * @param array<string, mixed>|null $parent_components Parent constraint as raw components, or null.
		 */
		private function __construct( string $country, string $level, ?Location_Record $parent_record, ?array $parent_components ) {
			$this->country           = $country;
			$this->level             = $level;
			$this->parent_record     = $parent_record;
			$this->parent_components = $parent_components;
		}

		/**
		 * Builds a scope for a whole country — no parent constraint (e.g. "regions
		 * within RU", or "settlements within RU" when no region field exists — spec
		 * §4.4: "no region field → locality searches country-wide").
		 *
		 * @since 2.0.2
		 *
		 * @param string $country ISO-3166 alpha-2 country code (case-insensitive).
		 * @param string $level   One of {@see Location_Record::LEVELS}.
		 *
		 * @return self
		 *
		 * @throws \InvalidArgumentException When `$country` is not a 2-letter code, or
		 *                                    `$level` is not a known level.
		 */
		public static function for_country( string $country, string $level ): self {
			return new self( self::normalize_country( $country ), self::validate_level( $level ), null, null );
		}

		/**
		 * Builds a scope narrowed to "within" a parent {@see Location_Record} — e.g.
		 * "settlements within this region record", "addresses within this settlement
		 * record". The scope's country is taken from the parent record (a parent
		 * necessarily belongs to one country); there is no separate `$country`
		 * argument to keep the two from disagreeing.
		 *
		 * The parent MUST be at a level strictly shallower than `$level` in the
		 * cascade order region > settlement > address (spec §4.4's own pipeline
		 * direction). An address-level parent constraining a settlement search (or
		 * any parent at the same level as, or deeper than, the search itself) is a
		 * real caller mistake — refused rather than silently accepted, the same
		 * discipline {@see Location_Record::from_array()} applies to a mismatched
		 * key/provider_id namespace. A `region`-level search can therefore never take
		 * a parent at all: no level in {@see Location_Record::LEVELS} is shallower
		 * than `region`, so any parent record supplied here is refused — the only way
		 * to scope a region search is by country via {@see self::for_country()}.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $parent Parent record narrowing the search.
		 * @param string          $level  One of {@see Location_Record::LEVELS} — the
		 *                                level being searched, not the parent's own level.
		 *
		 * @return self
		 *
		 * @throws \InvalidArgumentException When `$level` is not a known level, or the
		 *                                    parent's level is not shallower than `$level`.
		 */
		public static function within( Location_Record $parent, string $level ): self {
			$level = self::validate_level( $level );

			$parent_index = array_search( $parent->level(), Location_Record::LEVELS, true );
			$scope_index  = array_search( $level, Location_Record::LEVELS, true );

			if ( false === $parent_index || false === $scope_index || $parent_index >= $scope_index ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Location_Scope::within(): a parent record at level "%s" cannot constrain a search for level "%s" — the parent must be at a shallower level (%s).',
						$parent->level(),
						$level,
						implode( ' > ', Location_Record::LEVELS )
					)
				);
			}

			return new self( $parent->country(), $level, $parent, null );
		}

		/**
		 * Builds a scope narrowed to "within" raw parent components — used when the
		 * caller has locality data but no full {@see Location_Record} (e.g. an admin
		 * typing a region name for the default-locality picker before any record
		 * exists). Unlike {@see self::within()}, there is no parent level to check the
		 * ordering rule against — raw components carry no level of their own — so no
		 * ordering validation happens here; the caller is trusted to only ever build
		 * this from data that is already known to sit above `$level`.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $country           ISO-3166 alpha-2 country code (case-insensitive).
		 * @param string               $level             One of {@see Location_Record::LEVELS}.
		 * @param array<string, mixed> $parent_components  Raw components narrowing the search
		 *                                                  (e.g. `[ 'region' => [ 'name' => 'X', 'type' => 'г' ] ]`).
		 *
		 * @return self
		 *
		 * @throws \InvalidArgumentException When `$country` is not a 2-letter code, or
		 *                                    `$level` is not a known level.
		 */
		public static function within_components( string $country, string $level, array $parent_components ): self {
			return new self( self::normalize_country( $country ), self::validate_level( $level ), null, $parent_components );
		}

		/**
		 * Normalizes and validates a country code, mirroring
		 * {@see Location_Record::from_array()}'s own discipline: rejected, not
		 * coerced, when malformed.
		 *
		 * @since 2.0.2
		 *
		 * @param string $country Raw country code.
		 *
		 * @return string Upper-case 2-letter code.
		 *
		 * @throws \InvalidArgumentException When not a 2-letter ISO-3166 alpha-2 code.
		 */
		private static function normalize_country( string $country ): string {
			$normalized = strtoupper( trim( $country ) );

			if ( 1 !== preg_match( '/^[A-Z]{2}$/', $normalized ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Location_Scope: "country" must be a 2-letter ISO-3166 alpha-2 code, got "%s".', $country )
				);
			}

			return $normalized;
		}

		/**
		 * Validates a level against {@see Location_Record::LEVELS} — the same list
		 * every other layer of this contract validates against, rather than a second
		 * copy of the three literals.
		 *
		 * @since 2.0.2
		 *
		 * @param string $level Raw level.
		 *
		 * @return string The validated level, unchanged.
		 *
		 * @throws \InvalidArgumentException When not one of {@see Location_Record::LEVELS}.
		 */
		private static function validate_level( string $level ): string {
			if ( ! in_array( $level, Location_Record::LEVELS, true ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Location_Scope: "level" must be one of %s, got "%s".',
						implode( ', ', Location_Record::LEVELS ),
						$level
					)
				);
			}

			return $level;
		}

		/**
		 * Derives a components array from a record — used by
		 * {@see self::parent_components()} when the scope was built from a
		 * {@see Location_Record} via {@see self::within()}, so that accessor returns a
		 * components-shaped array regardless of which named constructor built the scope.
		 * Empty component groups/scalars are omitted, the same "absent, not empty"
		 * discipline {@see Location_Record::from_array()} itself applies.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record Parent record.
		 *
		 * @return array<string, mixed>
		 */
		private static function components_from_record( Location_Record $record ): array {
			$components = [];

			if ( null !== $record->region() ) {
				$components['region'] = $record->region();
			}

			if ( null !== $record->district() ) {
				$components['district'] = $record->district();
			}

			if ( null !== $record->settlement() ) {
				$components['settlement'] = $record->settlement();
			}

			if ( null !== $record->street() ) {
				$components['street'] = $record->street();
			}

			if ( '' !== $record->house() ) {
				$components['house'] = $record->house();
			}

			if ( '' !== $record->block() ) {
				$components['block'] = $record->block();
			}

			if ( '' !== $record->flat() ) {
				$components['flat'] = $record->flat();
			}

			if ( '' !== $record->postcode() ) {
				$components['postcode'] = $record->postcode();
			}

			return $components;
		}

		/**
		 * Gets the ISO-3166 alpha-2 country code (upper-case).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function country(): string {
			return $this->country;
		}

		/**
		 * Gets the level being searched.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function level(): string {
			return $this->level;
		}

		/**
		 * Whether this scope carries a parent constraint, in either shape.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function has_parent(): bool {
			return null !== $this->parent_record || null !== $this->parent_components;
		}


		/**
		 * Returns a CLONE of this scope, stamped for one {@see Location_Provider}
		 * call (#358).
		 *
		 * A fresh, unfilled narrowing slot per call is deliberate: in a D15
		 * fallback chain the SAME scope can be handed to more than one provider,
		 * and a shared verdict slot would leave "whose verdict is this?" exactly
		 * as ambiguous as the problem {@see self::report_narrowing()} exists to
		 * fix. Call this immediately before invoking the provider, once per call.
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id The provider ({@see Location_Provider::get_id()})
		 *                             this scope is being handed to.
		 *
		 * @return self A clone stamped for `$provider_id`, with no narrowing
		 *              verdict reported yet.
		 */
		public function for_provider( string $provider_id ): self {
			$clone                         = clone $this;
			$clone->narrowing_provider_id  = $provider_id;
			$clone->narrowing              = null;

			return $clone;
		}

		/**
		 * Records how the provider currently answering this scope narrowed the
		 * search using its parent constraint (#358) — the missing second half of
		 * `within_status` (see `Location_Controller::perform_suggest()`'s own
		 * docblock on why the two fields are independent).
		 *
		 * WRITE-ONCE, and NEVER THROWS: the first accepted call is the one that
		 * counts. Every rejected call — a second report, an unrecognised verdict,
		 * a call on a scope with no parent to narrow by, or a call on a scope
		 * never stamped via {@see self::for_provider()} — is silently a no-op
		 * apart from a `_doing_it_wrong()` notice. This deliberately mirrors
		 * {@see Location_Provider::suggest()}'s own contract: `Location_Controller`
		 * catches any `\Throwable` escaping a provider call and reports it to the
		 * customer as "источник подсказок недоступен" (502) — a provider's own
		 * bookkeeping mistake here must read as a programming-error notice, never
		 * as a false upstream-outage report.
		 *
		 * @since 2.0.2
		 *
		 * @param string $verdict One of {@see Location_Provider::NARROWING_REPORTABLE}.
		 *
		 * @return void
		 */
		public function report_narrowing( string $verdict ): void {
			if ( ! in_array( $verdict, Location_Provider::NARROWING_REPORTABLE, true ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Unknown narrowing verdict "%s" — ignored.', $verdict ),
					'2.0.2'
				);

				return;
			}

			if ( null === $this->narrowing_provider_id ) {
				_doing_it_wrong(
					__METHOD__,
					'Called on a scope never stamped via Location_Scope::for_provider() — ignored.',
					'2.0.2'
				);

				return;
			}

			if ( ! $this->has_parent() ) {
				_doing_it_wrong(
					__METHOD__,
					'Called on a scope with no parent constraint to narrow by — ignored.',
					'2.0.2'
				);

				return;
			}

			if ( null !== $this->narrowing ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Narrowing already reported as "%s" for this scope — ignored.', $this->narrowing ),
					'2.0.2'
				);

				return;
			}

			$this->narrowing = $verdict;
		}

		/**
		 * Gets the narrowing verdict reported by the provider this scope was
		 * stamped for (#358), or {@see Location_Provider::NARROWING_UNREPORTED}
		 * when none has been reported yet.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function narrowing(): string {
			return $this->narrowing ?? Location_Provider::NARROWING_UNREPORTED;
		}

		/**
		 * Gets the parent constraint as a full record, or null when no parent was
		 * given, or when it was given as raw components instead (use
		 * {@see self::parent_components()} for the shape-agnostic accessor).
		 *
		 * A provider that can narrow more precisely with a native id/key (e.g. the
		 * owning provider's own region FIAS id) should prefer this accessor when it
		 * is non-null; otherwise fall back to {@see self::parent_components()}.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Record|null
		 */
		public function parent_record(): ?Location_Record {
			return $this->parent_record;
		}

		/**
		 * Gets the parent constraint as a components array, or null when no parent
		 * was given.
		 *
		 * Always returns an array when {@see self::has_parent()} is true, regardless
		 * of which named constructor built this scope — when the scope was built from
		 * a {@see Location_Record} via {@see self::within()}, this derives the
		 * components from that record. A provider that only wants components-shaped
		 * data (never a native id/key) can therefore call this ONE accessor
		 * unconditionally and never has to check which shape was supplied.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>|null
		 */
		public function parent_components(): ?array {
			if ( null !== $this->parent_components ) {
				return $this->parent_components;
			}

			if ( null !== $this->parent_record ) {
				return self::components_from_record( $this->parent_record );
			}

			return null;
		}

		/**
		 * Returns an array representation — useful for REST request/response bodies
		 * and logging. Not consumed by {@see self::for_country()}/{@see self::within()}/
		 * {@see self::within_components()} — those are the constructors; this is a
		 * one-way serialization only.
		 *
		 * @since 2.0.2
		 *
		 * @return array{country: string, level: string, parent: array<string, mixed>|null}
		 */
		public function to_array(): array {
			return [
				'country' => $this->country,
				'level'   => $this->level,
				'parent'  => null !== $this->parent_record ? $this->parent_record->to_array() : $this->parent_components,
			];
		}
	}

endif;
