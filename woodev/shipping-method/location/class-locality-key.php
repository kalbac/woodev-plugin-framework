<?php
/**
 * Woodev Locality Key
 *
 * Namespaced primary key for a location record: `provider_id:native_id` (spec D5).
 * The namespace is always attached so a stale key read under a different active
 * provider MISSES rather than being misread as a valid foreign key.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Locality_Key' ) ) :

	/**
	 * Static helper composing, parsing, and deterministically deriving locality keys.
	 *
	 * Not instantiable — every method is a pure static function of its arguments, so a
	 * shared instance would carry no state worth having (spec D5: "ONE shared helper so
	 * every provider derives identically").
	 *
	 * @since 2.0.2
	 */
	class Locality_Key {

		/**
		 * Length, in hex characters, of the derived native-id segment.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		private const DERIVED_ID_LENGTH = 20;

		/**
		 * Marks the native-id segment of a key {@see self::derive()} minted, so
		 * "this key was derived, not issued by the provider" is a FACT readable off
		 * the key itself — never something a caller has to infer from the segment's
		 * shape.
		 *
		 * This exists because a shape guess was tried first and was wrong: recognising
		 * a derived key by "20 lowercase hex characters" (round 2 of #488) is the same
		 * class of mistake `docs-internal/gotchas/the-classic-adapter-reverts-a-select-the-location-cascade-owns.md`
		 * already cost this project a session over — "a name heuristic, not an
		 * ownership fact." A real native id happening to be shaped exactly like a
		 * derived hash would have been wrongly refused; a derivation change (a length
		 * change, a second derive() call site, an encoding change) would have made the
		 * shape check silently stop matching and the wrongly-"verified" key would fall
		 * straight back to being read as "gone" — the very bug the check existed to
		 * prevent. Marking at the ONE point a derived key is ever minted removes the
		 * guess entirely: {@see self::is_derived()} answers from the marker, not a
		 * prediction about what the marker looks like.
		 *
		 * Safe under {@see self::parse()}'s existing "split on the FIRST colon only"
		 * contract without any change to that splitting rule — a real DaData native id
		 * already legitimately contains colons of its own (`relation:59195`,
		 * `way:1247091839`, measured OSM ids), so a marker segment containing one more
		 * is nothing new to that rule, and this marker's OWN colon is likewise inert to it.
		 *
		 * A bare prefix is STILL a prediction, though — one about what a provider's own
		 * native id looks like, and a provider id beginning with this exact marker is not
		 * implausible given the above (#494). {@see self::compose()} therefore ESCAPES a
		 * native id that begins with the marker by doubling it (`derived:` becomes
		 * `derived:derived:`) rather than refusing it outright — refusing was tried and
		 * reverted (commit `0848029`) because it makes such an id UNREPRESENTABLE: DaData's
		 * own mapper composes every record it maps, so the refusal would degrade a real
		 * locality into an unmappable one, trading a classification bug for data loss on
		 * ingest. {@see self::parse()} reverses the doubling so every caller still gets
		 * back exactly the id it was given; see that method and {@see self::is_derived()}
		 * for how a single marker (derived) is told apart from a doubled one (an escaped
		 * provider id) — correct at any depth of accidental doubling in source data.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const DERIVED_MARKER = 'derived:';

		/**
		 * Composes a namespaced key from a provider id and that provider's native id.
		 *
		 * Both parts are refused when empty or whitespace-only — an empty domain key is
		 * not a key (gotcha `an-empty-domain-key-is-not-a-key`), and the same discipline
		 * applies to a component of a key, not only to a whole key: a blank provider_id
		 * would produce a key with no real namespace, and a blank native_id would produce
		 * a namespace pointing at nothing.
		 *
		 * A native id that itself begins with {@see self::DERIVED_MARKER} is ESCAPED —
		 * see {@see self::escape_native_id()} — rather than refused, so every provider
		 * native id stays representable (#494); {@see self::parse()} reverses the
		 * escaping so a caller never sees the doubled marker.
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id Unique id of the owning provider.
		 * @param string $native_id   The provider's own identifier for the locality.
		 *
		 * @return string
		 *
		 * @throws \InvalidArgumentException When either part is empty or whitespace-only.
		 */
		public static function compose( string $provider_id, string $native_id ): string {
			if ( '' === trim( $provider_id ) ) {
				throw new \InvalidArgumentException( 'Locality_Key::compose() requires a non-empty provider_id.' );
			}

			if ( '' === trim( $native_id ) ) {
				throw new \InvalidArgumentException( 'Locality_Key::compose() requires a non-empty native_id.' );
			}

			return self::join( $provider_id, self::escape_native_id( $native_id ) );
		}

		/**
		 * Concatenates the two segments with the separator, and validates nothing.
		 *
		 * Exists so {@see self::derive()} can mint its single-marker native-id segment
		 * through the same separator {@see self::compose()} uses, without either method
		 * duplicating the other's separator — `derive()`'s marker never passes through
		 * {@see self::escape_native_id()}, because `derive()` MINTS the marker, it is
		 * not escaping a provider-issued value.
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id Unique id of the owning provider.
		 * @param string $native_id   The native-id segment, already validated by the caller.
		 *
		 * @return string
		 */
		private static function join( string $provider_id, string $native_id ): string {
			return $provider_id . ':' . $native_id;
		}

		/**
		 * Splits a namespaced key into `[ provider_id, native_id ]`, on the FIRST colon
		 * only, WITHOUT reversing any {@see self::escape_native_id()} escaping — the RAW
		 * segment exactly as stored. {@see self::parse()} is the public, unescaping entry
		 * point; this exists so {@see self::is_derived()} can read the raw marker instead
		 * of a value `parse()` may already have unescaped — an escaped provider id
		 * unescapes to a single leading marker too, one level shallower than its stored
		 * form, so reading ownership off the unescaped value would misclassify it as
		 * derived (#494, the bug the escaping scheme exists to close).
		 *
		 * A malformed key (no colon at all, or an empty OR WHITESPACE-ONLY provider/native
		 * part either side of the first colon) is refused rather than returning a
		 * best-effort guess: the same "an empty/absent namespace is not a namespace"
		 * discipline `compose()` enforces on the way in (it refuses a whitespace-only
		 * part, not only a fully-empty one) must hold on the way out symmetrically, or a
		 * key `compose()` would have refused to CREATE could still be silently accepted
		 * by a caller trusting `parse()` — a non-unique blank identifier is exactly the
		 * empty-key discipline (gotcha `an-empty-domain-key-is-not-a-key`) broken through
		 * the back door.
		 *
		 * Only the WHITESPACE-ONLY check is symmetric with `compose()`; the returned parts
		 * are NOT trimmed (same as `compose()`, which concatenates its own inputs
		 * verbatim) — a part containing incidental internal whitespace alongside real
		 * content is a much narrower, pre-existing imperfection this fix does not reach.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key A key produced by {@see self::compose()} or {@see self::derive()}.
		 *
		 * @return array{0: string, 1: string} `[ provider_id, native_id ]`, native_id RAW.
		 *
		 * @throws \InvalidArgumentException When the key has no colon, or either resulting
		 *                                   part is empty or whitespace-only.
		 */
		private static function split_key( string $key ): array {
			$position = strpos( $key, ':' );

			if ( false === $position ) {
				throw new \InvalidArgumentException(
					sprintf( 'Locality_Key::parse(): "%s" is not a namespaced key (no colon found).', $key )
				);
			}

			$provider_id = substr( $key, 0, $position );
			$native_id   = substr( $key, $position + 1 );

			if ( '' === trim( $provider_id ) || '' === trim( $native_id ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Locality_Key::parse(): "%s" has an empty or whitespace-only provider_id or native_id part.', $key )
				);
			}

			return [ $provider_id, $native_id ];
		}

		/**
		 * Escapes a RAW native id for storage in a composed key: a native id that itself
		 * begins with {@see self::DERIVED_MARKER} gets one more marker prepended
		 * (`derived:` becomes `derived:derived:`, `derived:derived:` becomes
		 * `derived:derived:derived:`, …), so it is stored one marker level "louder" than
		 * a genuinely {@see self::derive()}d key's single marker and
		 * {@see self::is_derived()} can never confuse the two. A native id that does not
		 * begin with the marker is stored verbatim — the entire cost of this scheme for
		 * every native id measured in production (#494): none of them begin with the
		 * marker, so none of them are touched.
		 *
		 * @since 2.0.2
		 *
		 * @param string $native_id RAW native id, as issued by the provider.
		 *
		 * @return string
		 */
		private static function escape_native_id( string $native_id ): string {
			if ( ! self::starts_with_marker( $native_id ) ) {
				return $native_id;
			}

			return self::DERIVED_MARKER . $native_id;
		}

		/**
		 * Reverses {@see self::escape_native_id()}: a stored native id beginning with the
		 * marker written TWICE in a row is an escaped provider id one marker level
		 * "louder" than it should read as, so exactly one marker is stripped to recover
		 * the original — correct at any depth, since escaping only ever adds ONE marker
		 * per {@see self::compose()} call. A segment beginning with the marker only ONCE
		 * is a genuine {@see self::derive()} marker, not an escaped value, and is
		 * returned unchanged.
		 *
		 * @since 2.0.2
		 *
		 * @param string $native_id STORED native id, as read from a composed key.
		 *
		 * @return string RAW native id, as originally issued by the provider.
		 */
		private static function unescape_native_id( string $native_id ): string {
			if ( ! self::starts_with_doubled_marker( $native_id ) ) {
				return $native_id;
			}

			return substr( $native_id, strlen( self::DERIVED_MARKER ) );
		}

		/**
		 * Whether `$native_id` begins with {@see self::DERIVED_MARKER} at least once.
		 *
		 * @since 2.0.2
		 *
		 * @param string $native_id A native-id segment (raw or stored).
		 *
		 * @return bool
		 */
		private static function starts_with_marker( string $native_id ): bool {
			return 0 === strpos( $native_id, self::DERIVED_MARKER );
		}

		/**
		 * Whether `$native_id` begins with {@see self::DERIVED_MARKER} written TWICE in a
		 * row — the signature of a stored native id {@see self::escape_native_id()}
		 * produced, as opposed to a genuinely {@see self::derive()}d key's single marker.
		 *
		 * @since 2.0.2
		 *
		 * @param string $native_id A STORED native-id segment.
		 *
		 * @return bool
		 */
		private static function starts_with_doubled_marker( string $native_id ): bool {
			return 0 === strpos( $native_id, self::DERIVED_MARKER . self::DERIVED_MARKER );
		}

		/**
		 * Splits a namespaced key into `[ provider_id, native_id ]`, on the FIRST colon
		 * only — a native id is free to contain colons of its own (e.g. a provider that
		 * derives composite ids), so only the first separator carries meaning — and
		 * reverses any {@see self::escape_native_id()} escaping {@see self::compose()}
		 * applied, so the returned native_id is always exactly what the provider issued
		 * (#494). A genuinely {@see self::derive()}d key's single-marker segment is
		 * returned unchanged: it was never escaped, and {@see self::is_derived()} still
		 * reads it as derived.
		 *
		 * See {@see self::split_key()} for the malformed-key refusal rules this delegates
		 * to.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key A key produced by {@see self::compose()} or {@see self::derive()}.
		 *
		 * @return array{0: string, 1: string} `[ provider_id, native_id ]`.
		 *
		 * @throws \InvalidArgumentException When the key has no colon, or either resulting
		 *                                   part is empty or whitespace-only.
		 */
		public static function parse( string $key ): array {
			[ $provider_id, $native_id ] = self::split_key( $key );

			return [ $provider_id, self::unescape_native_id( $native_id ) ];
		}

		/**
		 * Deterministically derives a key for a provider whose payload carries no native
		 * id of its own — the native-id segment is {@see self::DERIVED_MARKER} followed
		 * by a truncated SHA-1 of the locality's own components, so the same locality
		 * always derives the same key regardless of request order or provider
		 * implementation, and {@see self::is_derived()} can always tell this key apart
		 * from one a provider actually issued.
		 *
		 * Determinism rules (all load-bearing, all covered by
		 * {@see \Woodev\Tests\Unit\Shipping\Location\LocalityKeyTest}):
		 * - components are flattened first (see {@see self::flatten_components()}) so a
		 *   nested `{ region: { name, type } }` shape derives identically regardless of
		 *   the caller's array shape choice;
		 *   values are dropped entirely, so their absence never changes the key;
		 * - the flattened map is then key-sorted so argument ORDER never changes the key;
		 * - remaining values are trimmed and lower-cased with `mb_strtolower()` (Cyrillic
		 *   components are the common case here, not an edge case);
		 * - empty (post-trim) values are dropped entirely, so their absence never changes
		 *   the key;
		 * - pairs are joined as `key=value` and the pairs joined with `|`.
		 *
		 * Requires the PHP mbstring extension — see {@see self::multibyte_loaded()} — and
		 * deliberately offers NO ascii fallback when it is missing (P2 finding): mbstring
		 * is an OPTIONAL extension throughout this framework (it is declared nowhere as a
		 * requirement — not in `composer.json`, not in `Woodev_Plugin_Dependencies::$php_extensions`
		 * — and every OTHER guarded `mb_*()` call site, e.g. {@see \Woodev_Helper::str_starts_with()},
		 * degrades to an ASCII-only fallback rather than fatal). A fallback here would be
		 * actively harmful rather than merely degraded: the key this method returns is
		 * PERSISTED (the dual customer location store, the session resolution cache, the
		 * pickup `[location][type]` map), so the SAME locality would derive a DIFFERENT key
		 * depending on which extension set the current host happens to have — a server
		 * change or a host migration would then silently strand every stored record under
		 * its old, now-unreachable key. Spec D5 exists precisely so a stale/foreign key
		 * MISSES rather than being misread; a fallback here would instead produce a silent
		 * MIS-KEY, the outcome D5 exists to prevent. Refusing loudly is the only option that
		 * keeps derivation deterministic across hosts.
		 *
		 * Reachable whenever a provider's payload carries no native id of its own — the
		 * bundled DaData provider carries a real `fias_id` for most localities and calls
		 * {@see self::compose()} directly, but genuinely falls through to `derive()` for
		 * the GeoNames tier, where a suggestion's own `fias_id` is empty
		 * ({@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::record_from_dadata_fields()}'s
		 * own ternary; pinned by `DadataProviderTest::test_a_suggestion_missing_a_fias_id_derives_the_key_deterministically()`).
		 * A future native-id-less provider (e.g. one keyed purely by a region/settlement
		 * name pair) would reach it unconditionally.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $provider_id Unique id of the owning provider.
		 * @param array<string, mixed> $components  Locality components (e.g. region,
		 *                                           settlement, type); values may
		 *                                           themselves be associative arrays.
		 *
		 * @return string
		 *
		 * @throws \InvalidArgumentException When $provider_id is empty or whitespace-only.
		 * @throws \RuntimeException         When the PHP mbstring extension is not loaded.
		 */
		public static function derive( string $provider_id, array $components ): string {
			if ( ! static::multibyte_loaded() ) {
				throw new \RuntimeException(
					'Locality_Key::derive() requires the PHP mbstring extension to safely lower-case ' .
					'multibyte (e.g. Cyrillic) locality names for deterministic key derivation, and ' .
					'deliberately offers no ASCII fallback: this key is PERSISTED (the dual customer ' .
					'location store, the session resolution cache, the pickup [location][type] map), ' .
					'so a fallback would make the SAME locality derive a DIFFERENT key depending on the ' .
					'host\'s extension set — a server change or host migration would then silently ' .
					'strand every stored record under its old key (spec D5: a MISS is the safe outcome ' .
					'for a stale key, a silent mis-key is not).'
				);
			}

			$canonical = self::canonicalize( $components );

			// `join()`, not `compose()`: this is the ONE place allowed to mint a single,
			// un-escaped marker. `compose()` never produces one — it either leaves a
			// native id untouched (no leading marker) or escapes it to a DOUBLED one —
			// so a single leading marker is unambiguous evidence of derivation.
			return self::join( $provider_id, self::DERIVED_MARKER . substr( sha1( $canonical ), 0, self::DERIVED_ID_LENGTH ) );
		}

		/**
		 * Whether `$key` was DERIVED (see {@see self::derive()}) rather than issued by
		 * its owning provider — a FACT read off the key's own {@see self::DERIVED_MARKER}
		 * marker, never a guess about what a derived key's native-id segment happens to
		 * look like. See {@see self::DERIVED_MARKER}'s own docblock for why the earlier
		 * shape-guessing approach was wrong and this replaced it.
		 *
		 * Reads the RAW stored segment via {@see self::split_key()}, NOT
		 * {@see self::parse()}'s unescaped one: an escaped provider id (one that itself
		 * began with the marker) unescapes to a single leading marker too, one level
		 * shallower than its stored form — reading ownership off the unescaped value
		 * would misclassify that provider id as derived (#494, the bug this whole
		 * escaping scheme exists to close). Derived means "the marker appears exactly
		 * once", never "the marker appears at least once".
		 *
		 * A caller that needs to know whether a stored key can ever be looked up again
		 * by a provider's own by-id lookup (e.g.
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider::resolve_key()})
		 * asks this — a derived key was never issued by the provider in the first place,
		 * so no by-id lookup can ever match it.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key A key produced by {@see self::compose()} or {@see self::derive()}.
		 *
		 * @return bool
		 *
		 * @throws \InvalidArgumentException When `$key` is malformed — see {@see self::parse()}.
		 */
		public static function is_derived( string $key ): bool {
			[ , $native_id ] = self::split_key( $key );

			return self::starts_with_marker( $native_id ) && ! self::starts_with_doubled_marker( $native_id );
		}

		/**
		 * Whether the PHP mbstring extension is loaded — delegates to
		 * {@see \Woodev_Helper::multibyte_loaded()} for consistency with every other
		 * mbstring-guarded call site in the framework, rather than a local
		 * `extension_loaded()` check re-implementing the same predicate.
		 *
		 * `protected static` as a test seam: {@see self::derive()} calls this via
		 * `static::`, so a test double subclass can override this single line to
		 * simulate mbstring being unavailable — same shape and reasoning as
		 * {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::session()}
		 * (a probe overrides one seam line rather than needing to fake a legacy global
		 * static call across the whole PHPUnit process).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		protected static function multibyte_loaded(): bool {
			return \Woodev_Helper::multibyte_loaded();
		}

		/**
		 * Builds the canonical string {@see self::derive()} hashes.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $components Locality components, possibly nested.
		 *
		 * @return string
		 */
		private static function canonicalize( array $components ): string {
			$flat = self::flatten_components( $components );

			ksort( $flat );

			$pairs = [];

			foreach ( $flat as $path => $value ) {
				$value = mb_strtolower( trim( (string) $value ) );

				if ( '' === $value ) {
					continue;
				}

				$pairs[] = $path . '=' . $value;
			}

			return implode( '|', $pairs );
		}

		/**
		 * Flattens a (possibly nested) components array into a single-level map keyed by
		 * dot-joined path, e.g. `[ 'region' => [ 'name' => 'X' ] ]` becomes
		 * `[ 'region.name' => 'X' ]`.
		 *
		 * The dot-joined path is what keeps a nested shape distinguishable from an
		 * accidental flat collision on the same top-level key — a bare
		 * `[ 'region' => 'X' ]` flattens to `[ 'region' => 'X' ]`, a different path from
		 * `[ 'region' => [ 'name' => 'X' ] ]`'s `[ 'region.name' => 'X' ]`, so the two
		 * inputs deliberately derive different keys rather than colliding.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int|string, mixed> $components Possibly-nested components.
		 * @param string                   $prefix     Path prefix accumulated so far (internal recursion state).
		 *
		 * @return array<string, mixed> Flat map of dot-joined path => scalar value.
		 */
		private static function flatten_components( array $components, string $prefix = '' ): array {
			$flat = [];

			foreach ( $components as $key => $value ) {
				$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

				if ( is_array( $value ) ) {
					$flat += self::flatten_components( $value, $path );
				} else {
					$flat[ $path ] = $value;
				}
			}

			return $flat;
		}
	}

endif;
