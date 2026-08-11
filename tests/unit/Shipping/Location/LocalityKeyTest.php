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

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';

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

	public function test_derive_produces_a_twenty_character_hash_segment(): void {
		$key = Locality_Key::derive( 'noid', [ 'settlement' => 'Тюмень' ] );
		[ , $native_id ] = Locality_Key::parse( $key );

		$this->assertSame( 20, strlen( $native_id ) );
	}
}
