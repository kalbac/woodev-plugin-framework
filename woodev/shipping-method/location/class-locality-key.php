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
		 * Composes a namespaced key from a provider id and that provider's native id.
		 *
		 * Both parts are refused when empty or whitespace-only — an empty domain key is
		 * not a key (gotcha `an-empty-domain-key-is-not-a-key`), and the same discipline
		 * applies to a component of a key, not only to a whole key: a blank provider_id
		 * would produce a key with no real namespace, and a blank native_id would produce
		 * a namespace pointing at nothing.
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

			return $provider_id . ':' . $native_id;
		}

		/**
		 * Splits a namespaced key into `[ provider_id, native_id ]`, on the FIRST colon
		 * only — a native id is free to contain colons of its own (e.g. a provider that
		 * derives composite ids), so only the first separator carries meaning.
		 *
		 * A malformed key (no colon at all, or an empty provider/native part either side
		 * of the first colon) is refused rather than returning a best-effort guess: the
		 * same "an empty/absent namespace is not a namespace" discipline `compose()`
		 * enforces on the way in must hold on the way out, or a key that never should
		 * have existed could still be silently accepted by a caller trusting `parse()`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key A key produced by {@see self::compose()} or {@see self::derive()}.
		 *
		 * @return array{0: string, 1: string} `[ provider_id, native_id ]`.
		 *
		 * @throws \InvalidArgumentException When the key has no colon, or either resulting
		 *                                   part is empty.
		 */
		public static function parse( string $key ): array {
			$position = strpos( $key, ':' );

			if ( false === $position ) {
				throw new \InvalidArgumentException(
					sprintf( 'Locality_Key::parse(): "%s" is not a namespaced key (no colon found).', $key )
				);
			}

			$provider_id = substr( $key, 0, $position );
			$native_id   = substr( $key, $position + 1 );

			if ( '' === $provider_id || '' === $native_id ) {
				throw new \InvalidArgumentException(
					sprintf( 'Locality_Key::parse(): "%s" has an empty provider_id or native_id part.', $key )
				);
			}

			return [ $provider_id, $native_id ];
		}

		/**
		 * Deterministically derives a key for a provider whose payload carries no native
		 * id of its own — the native-id segment is a truncated SHA-1 of the locality's
		 * own components, so the same locality always derives the same key regardless of
		 * request order or provider implementation.
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
		 */
		public static function derive( string $provider_id, array $components ): string {
			$canonical = self::canonicalize( $components );

			return self::compose( $provider_id, substr( sha1( $canonical ), 0, self::DERIVED_ID_LENGTH ) );
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
