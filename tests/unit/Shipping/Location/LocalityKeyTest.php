<?php
/**
 * Unit tests for Locality_Key — namespaced key composition, first-colon-only parsing,
 * and deterministic derivation for providers with no native id.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Framework\Shipping\Location\Locality_Key;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';

/**
 * Test double simulating mbstring being unavailable — overrides the protected
 * static test seam {@see Locality_Key::multibyte_loaded()} rather than needing
 * to fake `extension_loaded()`/`\Woodev_Helper::multibyte_loaded()` itself (same
 * "protected method as test seam" shape as
 * {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::session()}).
 */
class Locality_Key_No_Mbstring extends Locality_Key {
	protected static function multibyte_loaded(): bool {
		return false;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Location\Locality_Key
 */
final class LocalityKeyTest extends TestCase {

	public function test_compose_prefixes_provider_id(): void {
		$this->assertSame( 'dadata:abc-123', Locality_Key::compose( 'dadata', 'abc-123' ) );
	}

	public function test_compose_refuses_an_empty_native_id(): void {
		// An empty domain key is not a key (gotcha an-empty-domain-key-is-not-a-key) —
		// the same discipline applies to a component of a key, not just a whole key.
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::compose( 'dadata', '' );
	}

	public function test_compose_refuses_a_whitespace_only_native_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::compose( 'dadata', '   ' );
	}

	public function test_compose_refuses_an_empty_provider_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::compose( '', 'abc-123' );
	}

	public function test_compose_refuses_a_whitespace_only_provider_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::compose( '   ', 'abc-123' );
	}

	public function test_parse_splits_on_first_colon_only(): void {
		$this->assertSame( [ 'dadata', 'a:b' ], Locality_Key::parse( 'dadata:a:b' ) );
	}

	public function test_parse_round_trips_a_composed_key(): void {
		$key = Locality_Key::compose( 'cdek', 'fias-42' );
		$this->assertSame( [ 'cdek', 'fias-42' ], Locality_Key::parse( $key ) );
	}

	public function test_parse_refuses_a_key_with_no_colon(): void {
		// No colon means there is no namespace to trust — refuse rather than guess.
		// Same discipline as compose()'s empty-part refusal: a malformed key is not a
		// key with a blank namespace, it is not a key at all.
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::parse( 'dadata-abc-123' );
	}

	public function test_parse_refuses_an_empty_key(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::parse( '' );
	}

	public function test_parse_refuses_a_key_with_an_empty_provider_part(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::parse( ':abc-123' );
	}

	public function test_parse_refuses_a_key_with_an_empty_native_id_part(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::parse( 'dadata:' );
	}

	// ---- P2 finding: parse() must be symmetric with compose(), which already
	// refuses a whitespace-only part rather than only a fully-empty one — a
	// blank-but-not-empty native/provider id is exactly the "empty domain key"
	// discipline (gotcha an-empty-domain-key-is-not-a-key) broken through the
	// back door. ----

	public function test_parse_refuses_a_key_with_a_whitespace_only_native_id_part(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::parse( 'dadata:   ' );
	}

	public function test_parse_refuses_a_key_with_a_whitespace_only_provider_part(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::parse( '   :abc-123' );
	}

	public function test_derive_is_deterministic_and_prefixed(): void {
		$components = [ 'country' => 'RU', 'region' => 'Тюменская', 'settlement' => 'Октябрьский', 'type' => 'пгт' ];
		$a          = Locality_Key::derive( 'noid', $components );
		$b          = Locality_Key::derive(
			'noid',
			[ 'type' => 'пгт', 'settlement' => 'Октябрьский', 'region' => 'Тюменская', 'country' => 'RU' ]
		);

		$this->assertSame( $a, $b ); // key order must not matter
		$this->assertStringStartsWith( 'noid:', $a );
	}

	public function test_derive_is_insensitive_to_surrounding_whitespace_and_case(): void {
		$a = Locality_Key::derive( 'noid', [ 'settlement' => 'Октябрьский' ] );
		$b = Locality_Key::derive( 'noid', [ 'settlement' => '  ОКТЯБРЬСКИЙ  ' ] );

		$this->assertSame( $a, $b );
	}

	public function test_derive_produces_different_keys_for_different_components(): void {
		$a = Locality_Key::derive( 'noid', [ 'settlement' => 'Октябрьский' ] );
		$b = Locality_Key::derive( 'noid', [ 'settlement' => 'Тюмень' ] );

		$this->assertNotSame( $a, $b );
	}

	public function test_derive_drops_empty_components_so_absence_does_not_change_the_key(): void {
		$a = Locality_Key::derive( 'noid', [ 'settlement' => 'Тюмень', 'district' => '' ] );
		$b = Locality_Key::derive( 'noid', [ 'settlement' => 'Тюмень' ] );

		$this->assertSame( $a, $b );
	}

	public function test_derive_flattens_nested_component_arrays_deterministically(): void {
		$a = Locality_Key::derive(
			'noid',
			[ 'region' => [ 'name' => 'Тюменская', 'type' => 'обл' ] ]
		);
		$b = Locality_Key::derive(
			'noid',
			[ 'region' => [ 'type' => 'обл', 'name' => 'Тюменская' ] ]
		);

		$this->assertSame( $a, $b, 'key order inside a nested component must not matter either' );
	}

	public function test_derive_distinguishes_a_nested_component_from_a_flat_collision(): void {
		// [ 'region' => 'Тюмень' ] and [ 'region' => [ 'name' => 'Тюмень' ] ] must not
		// canonicalize to the same string — pins the flattening rule (dot-joined path
		// prefixes), not just "flattening happens".
		$nested = Locality_Key::derive( 'noid', [ 'region' => [ 'name' => 'Тюмень' ] ] );
		$flat   = Locality_Key::derive( 'noid', [ 'region' => 'Тюмень' ] );

		$this->assertNotSame( $nested, $flat );
	}

	public function test_derive_produces_a_twenty_character_hash_segment_after_the_derived_marker(): void {
		$key = Locality_Key::derive( 'noid', [ 'settlement' => 'Тюмень' ] );
		[ , $native_id ] = Locality_Key::parse( $key );

		$this->assertStringStartsWith( 'derived:', $native_id );
		$this->assertSame( 20, strlen( $native_id ) - strlen( 'derived:' ) );
	}

	// ---- Round 3 (#488): "was this key derived, or issued by the provider" must
	// be a FACT readable off the key itself, never a guess about what a derived
	// key's native-id segment happens to look like — see Locality_Key::DERIVED_MARKER's
	// own docblock and docs-internal/gotchas/the-classic-adapter-reverts-a-select-the-location-cascade-owns.md
	// for why a shape heuristic (round 2's approach) is exactly the mistake this
	// replaces. ----

	public function test_is_derived_is_true_for_a_derived_key(): void {
		$key = Locality_Key::derive( 'dadata', [ 'settlement' => 'Тюмень' ] );

		$this->assertTrue( Locality_Key::is_derived( $key ) );
	}

	public function test_is_derived_is_false_for_a_composed_key(): void {
		$this->assertFalse( Locality_Key::is_derived( Locality_Key::compose( 'dadata', '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' ) ) );
	}

	public function test_is_derived_is_false_for_a_composed_key_that_coincidentally_has_a_twenty_hex_character_native_id(): void {
		// Pins the exact failure mode the round-2 shape regex had: a REAL native id
		// happening to be shaped like a derived hash must still resolve, never be
		// mistaken for derived, because derivation is now a marker, not a shape.
		$this->assertFalse( Locality_Key::is_derived( Locality_Key::compose( 'dadata', '0123456789abcdef0123' ) ) );
	}

	public function test_is_derived_is_false_for_a_composed_key_whose_native_id_starts_with_the_word_derived(): void {
		// A native id a provider genuinely issued could coincidentally start with
		// the literal word "derived" — that must never be misread either, because
		// is_derived() checks for the marker's OWN exact prefix ("derived:"), not a
		// looser substring/word match.
		$this->assertFalse( Locality_Key::is_derived( Locality_Key::compose( 'dadata', 'derived-by-the-carrier-42' ) ) );
	}

	// ---- P2 finding: derive() must refuse to run without mbstring rather than
	// silently falling back to strtolower() — this key is PERSISTED (dual
	// customer store, session resolution cache, pickup [location][type] map), so
	// a fallback would make the SAME locality derive a DIFFERENT key depending on
	// the host's extension set, silently stranding stored records after a server
	// change or host migration (spec D5: a MISS is the safe outcome for a stale
	// key, a silent mis-key is not). ----

	public function test_derive_throws_when_mbstring_is_unavailable(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/mbstring/i' );

		Locality_Key_No_Mbstring::derive( 'noid', [ 'settlement' => 'Тюмень' ] );
	}

	public function test_derive_still_works_when_mbstring_is_available(): void {
		// Sanity: the guard does not break the happy path — real mbstring is
		// available in the test process (every other derive() test above relies
		// on it too), this just pins that the new guard does not itself regress it.
		$key = Locality_Key::derive( 'noid', [ 'settlement' => 'Тюмень' ] );

		$this->assertStringStartsWith( 'noid:', $key );
	}
}
