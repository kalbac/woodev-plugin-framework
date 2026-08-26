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
	/**
	 * `derive()` mints the marker through a private `join()` rather than through `compose()`.
	 * Regression cover for the reverted reservation guard: `compose()` deliberately does NOT
	 * refuse the marker (refusing it made a provider-issued id starting with the marker
	 * unrepresentable) — it ESCAPES it instead (#494), so this pins that derivation still
	 * works and reads back as derived.
	 */
	public function test_derive_mints_a_key_that_reads_back_as_derived(): void {
		$key = Locality_Key::derive( 'dadata', [ 'city' => 'Пенза', 'region' => 'Пензенская' ] );

		$this->assertTrue( Locality_Key::is_derived( $key ) );
		$this->assertSame( 'dadata', Locality_Key::parse( $key )[0] );
		$this->assertFalse( Locality_Key::is_derived( Locality_Key::compose( 'dadata', 'relation:59195' ) ) );
	}

	// ---- #494: a bare `derived:` prefix is a prediction about what a provider's own
	// native id looks like, and measured DaData native ids DO contain colons
	// (`relation:59195`, `way:1247091839`). A provider native id that itself begins with
	// the marker must still round-trip through compose()/parse() UNCHANGED, and must never
	// be misread as derived — see Locality_Key::DERIVED_MARKER's own docblock for the
	// sentinel-doubling scheme this pins. ----

	/**
	 * @dataProvider native_id_shape_provider
	 */
	public function test_parse_round_trips_every_native_id_shape( string $native_id ): void {
		$key = Locality_Key::compose( 'dadata', $native_id );

		$this->assertSame( [ 'dadata', $native_id ], Locality_Key::parse( $key ) );
	}

	/**
	 * @dataProvider native_id_shape_provider
	 */
	public function test_is_derived_is_false_for_every_composed_native_id_shape( string $native_id ): void {
		// A composed key is never derived, regardless of what its native id looks like —
		// including a native id that begins with the marker itself, which is exactly the
		// case a bare-prefix check would have gotten wrong.
		$this->assertFalse( Locality_Key::is_derived( Locality_Key::compose( 'dadata', $native_id ) ) );
	}

	public function native_id_shape_provider(): array {
		return [
			'plain'                                  => [ 'abc-123' ],
			'osm-relation'                            => [ 'relation:59195' ],
			'osm-way'                                  => [ 'way:1247091839' ],
			'fias-uuid'                                => [ '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' ],
			'cdek-numeric-code'                        => [ '616635' ],
			'coincidentally-shaped-like-a-derived-hash' => [ '0123456789abcdef0123' ],
			'starts-with-the-word-derived-not-the-marker' => [ 'derived-by-the-carrier-42' ],
			'single-marker'                            => [ 'derived:' ],
			'single-marker-with-suffix'                 => [ 'derived:abc-123' ],
			'doubled-marker'                            => [ 'derived:derived:' ],
			'doubled-marker-with-suffix'                 => [ 'derived:derived:abc-123' ],
			'marker-tripled'                            => [ 'derived:derived:derived:' ],
			'marker-tripled-with-suffix'                 => [ 'derived:derived:derived:abc-123' ],
		];
	}

	public function test_compose_escapes_a_native_id_that_begins_with_the_derived_marker(): void {
		// The escaping is observable: a native id beginning with the marker is stored
		// with the marker doubled, one level "louder" than a genuinely derived key's
		// single marker.
		$this->assertSame( 'dadata:derived:derived:abc-123', Locality_Key::compose( 'dadata', 'derived:abc-123' ) );
	}

	public function test_compose_does_not_touch_a_native_id_that_does_not_begin_with_the_marker(): void {
		// The entire cost of the escaping scheme for every native id measured in
		// production (#494): none of them begin with the marker, so composing them is
		// byte-for-byte identical to before this change.
		$this->assertSame( 'dadata:relation:59195', Locality_Key::compose( 'dadata', 'relation:59195' ) );
	}

	public function test_is_derived_is_true_only_for_a_key_whose_native_id_carries_exactly_one_marker(): void {
		$derived_key = Locality_Key::derive( 'dadata', [ 'settlement' => 'Тюмень' ] );
		$escaped_key = Locality_Key::compose( 'dadata', 'derived:abc-123' );

		$this->assertTrue( Locality_Key::is_derived( $derived_key ) );
		$this->assertFalse( Locality_Key::is_derived( $escaped_key ) );
	}

	// ---- #512 (the remainder of #494 a critic flagged during review): compose()
	// escapes a native id that begins with the marker, but parse() returns a
	// DERIVED key's native-id segment unescaped (derive() never escapes its own
	// marker) — so compose( ...parse( $key ) ) is NOT the identity for a derived
	// key. No in-repo caller round-trips that way today, but Locality_Key is
	// CONTRACT for third-party Location_Provider implementations, so this is
	// pinned deliberately rather than left as an undocumented trap. ----

	public function test_compose_of_parse_is_not_the_identity_for_a_derived_key(): void {
		// Deliberately PINS current behaviour — this is NOT a bug report. parse()
		// hands back a derived key's native-id segment with its single "derived:"
		// marker intact (derive() never escapes its own marker, so there is nothing
		// for parse() to reverse); compose() then ESCAPES that same marker by
		// doubling it, because compose() cannot tell "a marker derive() minted" apart
		// from "a marker a provider's own native id happens to start with". Feeding
		// parse()'s output straight back into compose() therefore silently produces a
		// key that is_derived() no longer recognises as derived. A future change that
		// makes this an identity must consciously break this test, not do so by
		// accident.
		$derived_key = Locality_Key::derive( 'dadata', [ 'settlement' => 'Тюмень' ] );

		$this->assertTrue( Locality_Key::is_derived( $derived_key ), 'sanity: derive() must mint a key that reads back as derived' );

		[ $provider_id, $native_id ] = Locality_Key::parse( $derived_key );
		$round_tripped = Locality_Key::compose( $provider_id, $native_id );

		$this->assertNotSame( $derived_key, $round_tripped, 'compose( ...parse( $key ) ) is expected to CHANGE a derived key' );
		$this->assertFalse( Locality_Key::is_derived( $round_tripped ), 'the round-tripped key is expected to silently stop reading as derived' );
	}

	// ---- #512: split_key()'s exceptions used to always name parse(), even when
	// reached through is_derived() — pointing a reader chasing a malformed-key
	// exception at the wrong call site. ----

	public function test_is_derived_names_itself_when_the_key_has_no_colon(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Locality_Key::is_derived(): "dadata-abc-123" is not a namespaced key (no colon found).' );
		Locality_Key::is_derived( 'dadata-abc-123' );
	}

	public function test_is_derived_names_itself_when_a_key_part_is_empty(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Locality_Key::is_derived(): "dadata:" has an empty or whitespace-only provider_id or native_id part.' );
		Locality_Key::is_derived( 'dadata:' );
	}

	public function test_parse_still_names_itself_when_the_key_has_no_colon(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Locality_Key::parse(): "dadata-abc-123" is not a namespaced key (no colon found).' );
		Locality_Key::parse( 'dadata-abc-123' );
	}

	// ---- #512: escaping widens a native id by up to strlen( "derived:" ) = 8
	// characters, and the one shipped caller that persists a composed key
	// (Popular_Settlement_Store) stores it in a `locality_key VARCHAR(191)`
	// column with no length guard anywhere. Measured rather than guessed: the
	// longest native-id shapes the one shipped provider can actually mint (a FIAS
	// UUID; derive()'s own hash segment), even escaped, leave well over 100
	// characters of headroom under the column width. ----

	public function test_a_composed_key_from_realistic_native_ids_never_approaches_the_locality_key_column_width(): void {
		$locality_key_column_width = 191; // Popular_Settlement_Store::get_schema(): `locality_key VARCHAR(191)`.
		$comfortable_margin        = 100;

		$fias_uuid_key = Locality_Key::compose( 'dadata', '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' );
		$derived_key   = Locality_Key::derive( 'dadata', [ 'settlement' => 'Тюмень' ] );
		// Worst case in both dimensions at once: a FIAS-UUID-length native id that
		// ALSO begins with the marker, forcing the 8-byte escape overhead.
		$escaped_uuid_length_key = Locality_Key::compose( 'dadata', 'derived:0c5b2444-70a0-4932-980c-b4dc0d3f02b5' );

		$this->assertLessThan( $locality_key_column_width - $comfortable_margin, strlen( $fias_uuid_key ) );
		$this->assertLessThan( $locality_key_column_width - $comfortable_margin, strlen( $derived_key ) );
		$this->assertLessThan( $locality_key_column_width - $comfortable_margin, strlen( $escaped_uuid_length_key ) );
	}
}
