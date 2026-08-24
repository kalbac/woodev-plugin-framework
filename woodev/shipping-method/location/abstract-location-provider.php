<?php
/**
 * Woodev Abstract Location Provider
 *
 * Base implementation of the OPTIONAL parts of {@see Location_Provider}: the three
 * optional methods (`list_localities()`, `locate()`, `normalize()`) default to
 * throwing `\BadMethodCallException`, and `get_capabilities()` is computed by
 * REFLECTION so a provider cannot claim a capability it did not actually implement.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Abstract_Location_Provider' ) ) :

	/**
	 * Base class for {@see Location_Provider} implementations.
	 *
	 * Capability discovery (design — this is the load-bearing part of this class):
	 *
	 * `get_capabilities()` walks {@see self::CAPABILITY_METHODS} and, for each
	 * optional method, checks — via `\ReflectionMethod::getDeclaringClass()` — whether
	 * the method's declaring class is `self::class` (i.e. THIS abstract class, meaning
	 * nobody further down the hierarchy overrode it) or something else (meaning some
	 * subclass DID override it). Comparing against `self::class` — which in a method
	 * body written inside `Abstract_Location_Provider` always resolves to
	 * `Abstract_Location_Provider::class`, never `static::class` — is what makes this
	 * correct across a multi-level hierarchy: if `Child extends Abstract_Location_Provider`
	 * overrides `list_localities()` and `Grandchild extends Child` overrides nothing,
	 * `ReflectionMethod::getDeclaringClass()` for a `Grandchild` instance still resolves
	 * to `Child` (PHP resolves to the nearest class that actually defines the method) —
	 * which is `!== Abstract_Location_Provider::class`, so the capability is correctly
	 * reported present. A `static::class` comparison would have broken this: it would
	 * only ever match the leaf class of the actual object, never noticing the capability
	 * was implemented by an ancestor further up.
	 *
	 * A subclass can NARROW the reflected set — declare it implements a method but is
	 * not currently configured to use it (e.g. Task 7's DaData provider reports
	 * `normalize` only when its "clean" secret is configured) — by overriding
	 * {@see self::narrow_capabilities()}. It can NEVER widen it: `get_capabilities()`
	 * intersects whatever {@see self::narrow_capabilities()} returns against the
	 * reflected set, so a subclass returning a capability it did not actually override
	 * (a bug, or an attempt to fake support) is silently dropped, not honored.
	 *
	 * The reflected set is cached on the INSTANCE (not a static/class-keyed cache) —
	 * `get_capabilities()` runs on every checkout render path, and reflection is not
	 * free — but the cache is a plain instance property, so it cannot leak into a
	 * different provider instance the way a `static::class`-keyed cache could if two
	 * instances of the same subclass ever needed different reflected results (they
	 * never do, since reflection depends only on the class, not the instance — but a
	 * per-instance property still costs nothing extra and keeps the invariant obvious
	 * without relying on that fact).
	 *
	 * @since 2.0.2
	 */
	abstract class Abstract_Location_Provider implements Location_Provider {

		/**
		 * Maps each optional capability name to the interface method that implements
		 * it — the single source {@see self::reflect_capabilities()} walks.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private const CAPABILITY_METHODS = [
			self::CAPABILITY_LIST        => 'list_localities',
			self::CAPABILITY_LOCATE      => 'locate',
			self::CAPABILITY_NORMALIZE   => 'normalize',
			self::CAPABILITY_RESOLVE_KEY => 'resolve_key',
		];

		/**
		 * Reflection-derived capability set, cached per instance. Null until first
		 * computed by {@see self::get_capabilities()}.
		 *
		 * @since 2.0.2
		 * @var string[]|null
		 */
		private ?array $reflected_capabilities = null;

		/**
		 * {@inheritDoc}
		 *
		 * See the class docblock for the full reflection + narrowing design. This
		 * method itself only wires the two pieces together: compute (and cache) the
		 * reflected set, hand it to {@see self::narrow_capabilities()}, and intersect
		 * the result back against the reflected set so narrowing can only ever
		 * SUBTRACT.
		 *
		 * @since 2.0.2
		 */
		final public function get_capabilities(): array {
			if ( null === $this->reflected_capabilities ) {
				$this->reflected_capabilities = $this->reflect_capabilities();
			}

			$declared = $this->narrow_capabilities( $this->reflected_capabilities );

			// array_intersect() preserves the order/keys of its FIRST argument — using
			// the reflected set first (not $declared) keeps the result in the fixed
			// CAPABILITY_METHODS order regardless of what order a subclass's
			// narrow_capabilities() returns, and — the actual point — means anything
			// in $declared that is NOT also in the reflected set (an attempted widen)
			// is silently dropped rather than passed through.
			return array_values( array_intersect( $this->reflected_capabilities, $declared ) );
		}

		/**
		 * Narrowing hook: a subclass overrides this to REMOVE a capability it
		 * implements (so it is reflected present) but is not currently configured to
		 * offer — e.g. a provider needing a secondary secret for `normalize()` that is
		 * not set. Returning the input unchanged (the default) keeps every reflected
		 * capability as-is.
		 *
		 * This can only ever narrow: whatever this returns is intersected against the
		 * reflected set by {@see self::get_capabilities()}, so adding an entry this
		 * provider did not actually override has no effect.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $capabilities The reflection-derived capability set.
		 *
		 * @return string[] The capabilities to keep — a subset of `$capabilities`
		 *                  (anything outside that subset is ignored).
		 */
		protected function narrow_capabilities( array $capabilities ): array {
			return $capabilities;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: no store-level settings fields. Unlike the three optional
		 * capabilities above, this is a PLAIN overridable default, not reflected /
		 * capability-gated — a provider needing no credential (matching
		 * {@see \Woodev\Framework\Shipping\Map\Embedded_Map_Provider}) simply never
		 * overrides this method.
		 *
		 * @since 2.0.2
		 */
		public function get_settings_fields(): array {
			return [];
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default derived HONESTLY from {@see self::get_settings_fields()}
		 * (Task 6) rather than hardcoded `true`: when NONE of the declared
		 * fields are marked `required` — including the common case of zero
		 * declared fields at all, a provider needing no credential, matching
		 * {@see \Woodev\Framework\Shipping\Map\Embedded_Map_Provider} — there
		 * is nothing to configure, so this reports `true`. The moment ANY
		 * declared field is `required`, this default reports `false` — FAILS
		 * CLOSED, not open — because a subclass that forgot to override this
		 * method with a real check of the actual stored value (e.g. "is the
		 * token option non-empty") must never silently pass
		 * {@see Location_Service::is_active()} and the D15 fallback chain
		 * gate: serving suggestions through a provider that has no real
		 * credentials is worse than leaving the field native. A provider WITH
		 * a required field (Task 7's DaData: a required token) MUST override
		 * this to check the actual stored value — this default can only ever
		 * tell "does this provider's SHAPE require configuration", never "HAS
		 * it actually been configured".
		 *
		 * @since 2.0.2
		 */
		public function is_configured(): bool {
			foreach ( $this->get_settings_fields() as $field ) {
				if ( ! empty( $field['required'] ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Computes the reflection-derived capability set: an optional method is
		 * "implemented" when its declaring class is not this abstract class itself.
		 * See the class docblock for why comparing against `self::class` (not
		 * `static::class`) is what makes this correct for a multi-level hierarchy.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		private function reflect_capabilities(): array {
			$capabilities = [];

			foreach ( self::CAPABILITY_METHODS as $capability => $method ) {
				$declaring_class = ( new \ReflectionMethod( $this, $method ) )->getDeclaringClass()->getName();

				if ( self::class !== $declaring_class ) {
					$capabilities[] = $capability;
				}
			}

			return $capabilities;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: not implemented. Overridden by a provider that declares
		 * {@see Location_Provider::CAPABILITY_LIST}.
		 *
		 * @since 2.0.2
		 */
		public function list_localities( Location_Scope $scope ): array {
			throw new \BadMethodCallException(
				sprintf(
					'Location provider "%s" does not implement the "%s" capability (list_localities()).',
					$this->get_id(),
					self::CAPABILITY_LIST
				)
			);
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: not implemented. Overridden by a provider that declares
		 * {@see Location_Provider::CAPABILITY_LOCATE}.
		 *
		 * @since 2.0.2
		 */
		public function locate( string $ip ): ?Location_Record {
			throw new \BadMethodCallException(
				sprintf(
					'Location provider "%s" does not implement the "%s" capability (locate()).',
					$this->get_id(),
					self::CAPABILITY_LOCATE
				)
			);
		}

		/**
		 * {@inheritDoc}
		 *
		 * Default: not implemented. Overridden by a provider that declares
		 * {@see Location_Provider::CAPABILITY_NORMALIZE}.
		 *
		 * @since 2.0.2
		 */
		public function normalize( string $free_form, Location_Scope $scope ): ?Location_Record {
			throw new \BadMethodCallException(
				sprintf(
					'Location provider "%s" does not implement the "%s" capability (normalize()).',
					$this->get_id(),
					self::CAPABILITY_NORMALIZE
				)
			);
		}


		/**
		 * {@inheritDoc}
		 *
		 * Default: not implemented. Overridden by a provider that declares
		 * {@see Location_Provider::CAPABILITY_RESOLVE_KEY}.
		 *
		 * @since 2.0.2
		 */
		public function resolve_key( string $key ): ?Location_Record {
			throw new \BadMethodCallException(
				sprintf(
					'Location provider "%s" does not implement the "%s" capability (resolve_key()).',
					$this->get_id(),
					self::CAPABILITY_RESOLVE_KEY
				)
			);
		}

		/**
		 * {@inheritDoc}
		 *
		 * FINAL template method — enforces that every declared suggest level is a
		 * real level from {@see Location_Record::LEVELS} on every call, for every
		 * provider, unconditionally. A typo like `'city'` (not a real level string)
		 * would otherwise silently disable a whole checkout field: the D15 provider
		 * chain would never match that level for ANY provider and the field would
		 * quietly stay native with no error anywhere. Being `final` (rather than a
		 * plain default a subclass could skip past by overriding this method
		 * directly) makes that validation impossible to bypass — a subclass declares
		 * its levels through {@see self::declare_suggest_levels()} instead, which
		 * PHP itself will refuse to leave unimplemented (a subclass that forgets it
		 * entirely is a fatal "abstract method not implemented" error, not a silently
		 * empty/undeclared level set).
		 *
		 * `$country` (optional) additionally NARROWS the validated set through
		 * {@see self::narrow_suggest_levels_for_country()} — the same
		 * intersect-only-narrows shape {@see self::get_capabilities()} already
		 * uses for the three optional capabilities, applied here to levels
		 * instead. Omitted (the default `null`): returns the UNNARROWED set
		 * unchanged — every pre-existing country-blind call site (e.g.
		 * {@see Location_Service::get_supported_countries()}) keeps its original
		 * meaning ("every level this provider can EVER answer, for ANY country")
		 * without modification. A provider that never overrides
		 * {@see self::narrow_suggest_levels_for_country()} behaves identically
		 * whether or not a caller passes `$country` — the per-country nuance is
		 * opt-in per provider, not a new obligation on every implementation.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$country` parameter (per-country
		 *              suggest-level narrowing — DaData genuinely serves
		 *              `address` in RU/BY/KZ/UZ but not in AM/AZ/KG/TJ/TM).
		 *
		 * @throws \UnexpectedValueException When {@see self::declare_suggest_levels()}
		 *                                    returns a value that is not one of
		 *                                    {@see Location_Record::LEVELS}.
		 */
		final public function get_suggest_levels( ?string $country = null ): array {
			$levels = $this->declare_suggest_levels();

			foreach ( $levels as $level ) {
				if ( ! is_string( $level ) || ! in_array( $level, Location_Record::LEVELS, true ) ) {
					throw new \UnexpectedValueException(
						sprintf(
							'%s::declare_suggest_levels() returned an unknown level (%s); must be one of %s.',
							static::class,
							is_scalar( $level ) ? var_export( $level, true ) : gettype( $level ),
							implode( ', ', Location_Record::LEVELS )
						)
					);
				}
			}

			if ( null === $country ) {
				return $levels;
			}

			$narrowed = $this->narrow_suggest_levels_for_country( $levels, $country );

			// Same discipline as get_capabilities(): intersecting against the
			// VALIDATED set (not $narrowed) means an attempted WIDEN (a level
			// narrow_suggest_levels_for_country() invents that was not already
			// declared) is silently dropped, never honored.
			return array_values( array_intersect( $levels, $narrowed ) );
		}

		/**
		 * Narrowing hook (per-country suggest levels): a subclass overrides this
		 * to REMOVE a level it declares in {@see self::declare_suggest_levels()}
		 * when `$country` specifically does not support it — e.g. Task 7's
		 * DaData provider serves `address` in RU/BY/KZ/UZ (ФИАС/ГАР, OpenStreetMap)
		 * but NOT in AM/AZ/KG/TJ/TM (GeoNames — city granularity only, measured
		 * empty for a street-bounded query).
		 *
		 * This can only ever narrow: whatever this returns is intersected against
		 * the validated set by {@see self::get_suggest_levels()}, so returning a
		 * level this provider does not otherwise declare has no effect — the same
		 * "can subtract, can never add" discipline {@see self::narrow_capabilities()}
		 * already applies to the three optional capabilities.
		 *
		 * Default: no narrowing (identity) — a provider that has not been taught
		 * the per-country nuance behaves exactly as before: level support is
		 * uniform across every country it covers.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $levels  The validated declared level set.
		 * @param string   $country ISO-3166 alpha-2 country code, as passed to
		 *                          {@see self::get_suggest_levels()} (not yet
		 *                          normalized — a provider needing normalization
		 *                          does its own, mirroring
		 *                          {@see Location_Record::from_array()}'s own
		 *                          trim + upper-case discipline).
		 *
		 * @return string[] The levels to keep — a subset of `$levels` (anything
		 *                  outside that subset is ignored).
		 */
		protected function narrow_suggest_levels_for_country( array $levels, string $country ): array {
			return $levels;
		}

		/**
		 * Declares the levels this provider can answer `suggest()` for. Every
		 * concrete provider MUST implement this — see {@see self::get_suggest_levels()}
		 * for why it is the indirection point instead of the interface method itself.
		 *
		 * @since 2.0.2
		 *
		 * @return string[] Subset of {@see Location_Record::LEVELS}.
		 */
		abstract protected function declare_suggest_levels(): array;
	}

endif;
