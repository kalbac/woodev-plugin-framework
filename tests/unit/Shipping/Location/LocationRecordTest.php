<?php
/**
 * Unit tests for Location_Record — contract-shaped validation, the required
 * key/provider_id/level/country quartet, opaque `raw` passthrough, and the
 * from_array()/to_array() round trip.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';

/**
 * @covers \Woodev\Framework\Shipping\Location\Location_Record
 */
final class LocationRecordTest extends TestCase {

	public function test_from_array_requires_key_provider_level_country(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array( [ 'level' => 'settlement', 'country' => 'RU' ] ); // no key/provider
	}

	public function test_from_array_refuses_a_missing_provider_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array( [ 'key' => 'dadata:1', 'level' => 'settlement', 'country' => 'RU' ] );
	}

	public function test_from_array_refuses_a_missing_level(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array( [ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'country' => 'RU' ] );
	}

	public function test_from_array_refuses_a_missing_country(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array( [ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'settlement' ] );
	}

	public function test_from_array_refuses_a_whitespace_only_key(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => '   ', 'provider_id' => 'dadata', 'level' => 'settlement', 'country' => 'RU' ]
		);
	}

	public function test_from_array_refuses_a_whitespace_only_provider_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => '   ', 'level' => 'settlement', 'country' => 'RU' ]
		);
	}

	public function test_from_array_refuses_a_whitespace_only_level(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => '   ', 'country' => 'RU' ]
		);
	}

	public function test_invalid_level_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array( [ 'key' => 'x:1', 'provider_id' => 'x', 'level' => 'galaxy', 'country' => 'RU' ] );
	}

	// ---- key / provider_id consistency (design decision: the key's own namespace
	// prefix must agree with the declared provider_id — a mismatch is exactly the
	// "stale foreign-namespace key" the whole prefixed-key discipline exists to catch,
	// so it is refused at construction rather than let through to travel silently). ----

	public function test_from_array_refuses_a_key_whose_provider_prefix_does_not_match_provider_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => 'dadata:fias-1', 'provider_id' => 'cdek', 'level' => 'settlement', 'country' => 'RU' ]
		);
	}

	public function test_from_array_refuses_a_malformed_key_with_no_namespace(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => 'fias-1', 'provider_id' => 'dadata', 'level' => 'settlement', 'country' => 'RU' ]
		);
	}

	// ---- country ----

	public function test_country_is_uppercased(): void {
		$record = Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'ru' ]
		);
		$this->assertSame( 'RU', $record->country() );
	}

	public function test_three_letter_country_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'RUS' ]
		);
	}

	public function test_one_letter_country_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'R' ]
		);
	}

	public function test_non_alphabetic_country_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'R1' ]
		);
	}

	// ---- round trip (D12: contract-shaped record + opaque raw) ----

	public function test_from_array_round_trips_and_keeps_raw_opaque(): void {
		$data = [
			'key'         => 'dadata:fias-1',
			'provider_id' => 'dadata',
			'level'       => 'settlement',
			'country'     => 'RU',
			'region'      => [ 'name' => 'Москва', 'type' => 'г' ],
			'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
			'postcode'    => '101000',
			'lat'         => 55.75,
			'lon'         => 37.61,
			'label'       => 'г Москва',
			'raw'         => [ 'city_kladr_id' => '7700000000000' ],
		];
		$record = Location_Record::from_array( $data );

		$this->assertSame( 'dadata:fias-1', $record->key() );
		$this->assertSame( 'settlement', $record->level() );
		$this->assertSame( [ 'city_kladr_id' => '7700000000000' ], $record->raw() );
		$this->assertSame( $data, array_intersect_key( $record->to_array(), $data ) );
	}

	public function test_full_record_round_trips_through_from_array_and_to_array(): void {
		// Every field the spec §4.2 lists, including the address-level components
		// (district, street, house, block, flat) not exercised above.
		$data = [
			'key'         => 'dadata:addr-1',
			'provider_id' => 'dadata',
			'level'       => 'address',
			'country'     => 'RU',
			'region'      => [ 'name' => 'Москва', 'type' => 'г' ],
			'district'    => [ 'name' => 'Центральный', 'type' => 'р-н' ],
			'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
			'street'      => [ 'name' => 'Тверская', 'type' => 'ул' ],
			'house'       => '1',
			'block'       => 'к2',
			'flat'        => '10',
			'postcode'    => '125009',
			'lat'         => 55.757,
			'lon'         => 37.615,
			'label'       => 'г Москва, ул Тверская, д 1',
			'raw'         => [ 'fias_id' => 'abc', 'nested' => [ 'a' => 1 ] ],
		];

		$record   = Location_Record::from_array( $data );
		$round_2  = Location_Record::from_array( $record->to_array() );

		$this->assertSame( $data, array_intersect_key( $record->to_array(), $data ) );
		$this->assertSame( $record->to_array(), $round_2->to_array(), 'a second round trip must be idempotent' );

		$this->assertSame( [ 'name' => 'Центральный', 'type' => 'р-н' ], $record->district() );
		$this->assertSame( [ 'name' => 'Тверская', 'type' => 'ул' ], $record->street() );
		$this->assertSame( '1', $record->house() );
		$this->assertSame( 'к2', $record->block() );
		$this->assertSame( '10', $record->flat() );
	}

	public function test_minimal_record_omits_optional_groups(): void {
		$record = Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'RU' ]
		);

		$this->assertNull( $record->region() );
		$this->assertNull( $record->district() );
		$this->assertNull( $record->settlement() );
		$this->assertNull( $record->street() );
		$this->assertNull( $record->lat() );
		$this->assertNull( $record->lon() );
		$this->assertSame( '', $record->house() );
		$this->assertSame( '', $record->postcode() );
		$this->assertSame( '', $record->label() );
		$this->assertNull( $record->raw() );
	}

	// ---- raw is opaque: scalars, nested arrays, and null all survive untouched ----

	public function test_raw_scalar_survives_untouched(): void {
		$record = Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'RU', 'raw' => 'plain-string' ]
		);
		$this->assertSame( 'plain-string', $record->raw() );
	}

	public function test_raw_nested_array_survives_untouched(): void {
		$raw    = [ 'a' => [ 'b' => [ 'c' => 1 ] ], 'list' => [ 1, 2, 3 ] ];
		$record = Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'RU', 'raw' => $raw ]
		);
		$this->assertSame( $raw, $record->raw() );
	}

	public function test_raw_explicit_null_is_indistinguishable_from_absent(): void {
		// raw is opaque; the framework never inspects it, so an explicit null and an
		// absent key both mean "nothing carried" and are not required to be told apart.
		$with_null = Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'RU', 'raw' => null ]
		);
		$absent    = Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'RU' ]
		);
		$this->assertNull( $with_null->raw() );
		$this->assertNull( $absent->raw() );
	}

	// ---- level enum surface ----

	public function test_levels_constant_exposes_all_three_levels(): void {
		$this->assertSame(
			[ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT, Location_Record::LEVEL_ADDRESS ],
			Location_Record::LEVELS
		);
	}

	public function test_level_constants_match_the_contract_strings(): void {
		$this->assertSame( 'region', Location_Record::LEVEL_REGION );
		$this->assertSame( 'settlement', Location_Record::LEVEL_SETTLEMENT );
		$this->assertSame( 'address', Location_Record::LEVEL_ADDRESS );
	}

	// ---- malformed optional shapes ----

	public function test_a_non_array_region_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'region', 'country' => 'RU', 'region' => 'Москва' ]
		);
	}

	public function test_a_numeric_postcode_is_coerced_to_a_string(): void {
		// Optional display-ish string fields follow the Pickup_Point precedent
		// (is_scalar + cast) rather than a strict is_string check — a provider payload
		// legitimately carries a numeric postcode/house number as an int or a string
		// depending on the source, and both must produce the same record.
		$record = Location_Record::from_array(
			[
				'key'         => 'dadata:1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
				'postcode'    => 101000,
			]
		);
		$this->assertSame( '101000', $record->postcode() );
	}

	public function test_a_non_scalar_postcode_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[
				'key'         => 'dadata:1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
				'postcode'    => [ '101000' ],
			]
		);
	}

	public function test_a_non_numeric_lat_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array(
			[
				'key'         => 'dadata:1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
				'lat'         => 'not-a-number',
			]
		);
	}
}
