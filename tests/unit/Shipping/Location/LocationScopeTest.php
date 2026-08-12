<?php
/**
 * Unit tests for Location_Scope — level/country validation, the parent constraint
 * accepted either as a Location_Record or as raw components, the parent-level
 * ordering refusal (spec §4.1: a parent must sit above the level being searched),
 * and the shape-agnostic parent_components() accessor.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';

/**
 * @covers \Woodev\Framework\Shipping\Location\Location_Scope
 */
final class LocationScopeTest extends TestCase {

	private function region_record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'dadata:region-1',
				'provider_id' => 'dadata',
				'level'       => 'region',
				'country'     => 'RU',
				'region'      => [ 'name' => 'Тюменская', 'type' => 'обл' ],
			]
		);
	}

	private function settlement_record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'dadata:settlement-1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
			]
		);
	}

	private function address_record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'dadata:address-1',
				'provider_id' => 'dadata',
				'level'       => 'address',
				'country'     => 'RU',
				'street'      => [ 'name' => 'Тверская', 'type' => 'ул' ],
				'house'       => '1',
			]
		);
	}

	// ---- country/level validation ----

	public function test_for_country_refuses_an_unknown_level(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::for_country( 'RU', 'city' );
	}

	public function test_for_country_normalizes_a_lower_case_country(): void {
		$scope = Location_Scope::for_country( 'ru', 'region' );
		$this->assertSame( 'RU', $scope->country() );
	}

	public function test_for_country_refuses_a_three_letter_country(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::for_country( 'RUS', 'region' );
	}

	public function test_for_country_refuses_a_non_alphabetic_country(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::for_country( 'R1', 'region' );
	}

	public function test_for_country_has_no_parent(): void {
		$scope = Location_Scope::for_country( 'RU', 'region' );
		$this->assertFalse( $scope->has_parent() );
		$this->assertNull( $scope->parent_record() );
		$this->assertNull( $scope->parent_components() );
	}

	public function test_for_country_carries_the_requested_level(): void {
		$scope = Location_Scope::for_country( 'RU', 'settlement' );
		$this->assertSame( 'settlement', $scope->level() );
	}

	// ---- within() — Location_Record parent ----

	public function test_within_accepts_a_region_parent_for_a_settlement_search(): void {
		$scope = Location_Scope::within( $this->region_record(), 'settlement' );

		$this->assertTrue( $scope->has_parent() );
		$this->assertSame( 'settlement', $scope->level() );
		$this->assertSame( 'RU', $scope->country(), 'country is derived from the parent record' );
		$this->assertSame( $this->region_record()->key(), $scope->parent_record()->key() );
	}

	public function test_within_accepts_a_settlement_parent_for_an_address_search(): void {
		$scope = Location_Scope::within( $this->settlement_record(), 'address' );

		$this->assertTrue( $scope->has_parent() );
		$this->assertSame( 'address', $scope->level() );
	}

	public function test_within_accepts_a_region_parent_for_an_address_search(): void {
		// region is two levels shallower than address — still a valid ordering, not
		// just the immediately-adjacent level.
		$scope = Location_Scope::within( $this->region_record(), 'address' );
		$this->assertSame( 'address', $scope->level() );
	}

	// ---- parent-level ordering refusal (design decision under test) ----

	public function test_within_refuses_an_address_parent_for_a_settlement_search(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::within( $this->address_record(), 'settlement' );
	}

	public function test_within_refuses_a_parent_at_the_same_level_as_the_search(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::within( $this->settlement_record(), 'settlement' );
	}

	public function test_within_refuses_a_settlement_parent_for_a_region_search(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::within( $this->settlement_record(), 'region' );
	}

	public function test_within_refuses_any_parent_at_all_for_a_region_search(): void {
		// No level in Location_Record::LEVELS is shallower than region — a region
		// search can only ever be scoped by country, never by a parent record.
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::within( $this->region_record(), 'region' );
	}

	public function test_within_refuses_an_unknown_level(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::within( $this->region_record(), 'city' );
	}

	// ---- within_components() — raw components parent ----

	public function test_within_components_accepts_a_raw_components_parent(): void {
		$scope = Location_Scope::within_components( 'RU', 'settlement', [ 'region' => [ 'name' => 'Тюменская', 'type' => 'обл' ] ] );

		$this->assertTrue( $scope->has_parent() );
		$this->assertNull( $scope->parent_record(), 'no Location_Record was given' );
		$this->assertSame( [ 'region' => [ 'name' => 'Тюменская', 'type' => 'обл' ] ], $scope->parent_components() );
	}

	public function test_within_components_refuses_an_unknown_level(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::within_components( 'RU', 'city', [] );
	}

	public function test_within_components_refuses_an_invalid_country(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Scope::within_components( 'RUS', 'settlement', [] );
	}

	public function test_within_components_with_an_empty_array_is_still_a_declared_parent(): void {
		// An explicitly-supplied empty components array is a real (if trivial) parent
		// constraint, distinguishable from "no parent" — has_parent() must say so.
		$scope = Location_Scope::within_components( 'RU', 'settlement', [] );

		$this->assertTrue( $scope->has_parent() );
		$this->assertSame( [], $scope->parent_components() );
	}

	// ---- parent_components() derives from a record parent (shape-agnostic accessor) ----

	public function test_parent_components_derives_from_a_record_parent(): void {
		$scope      = Location_Scope::within( $this->region_record(), 'settlement' );
		$components = $scope->parent_components();

		$this->assertNotNull( $components, 'a provider that only wants components must get them even from a record-built scope' );
		$this->assertSame( [ 'name' => 'Тюменская', 'type' => 'обл' ], $components['region'] );
	}

	public function test_parent_components_from_a_record_omits_empty_groups_and_scalars(): void {
		$scope      = Location_Scope::within( $this->settlement_record(), 'address' );
		$components = $scope->parent_components();

		$this->assertArrayHasKey( 'settlement', $components );
		$this->assertArrayNotHasKey( 'region', $components );
		$this->assertArrayNotHasKey( 'house', $components );
	}

	public function test_parent_components_from_a_record_includes_a_populated_postcode(): void {
		$settlement_with_postcode = Location_Record::from_array(
			[
				'key'         => 'dadata:settlement-2',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				'postcode'    => '101000',
			]
		);

		$scope = Location_Scope::within( $settlement_with_postcode, 'address' );

		$this->assertSame( '101000', $scope->parent_components()['postcode'] );
	}

	// ---- to_array() ----

	public function test_to_array_with_no_parent(): void {
		$scope = Location_Scope::for_country( 'RU', 'region' );
		$this->assertSame( [ 'country' => 'RU', 'level' => 'region', 'parent' => null ], $scope->to_array() );
	}

	public function test_to_array_with_a_record_parent_embeds_the_records_own_array(): void {
		$scope = Location_Scope::within( $this->region_record(), 'settlement' );
		$array = $scope->to_array();

		$this->assertSame( 'settlement', $array['level'] );
		$this->assertSame( $this->region_record()->to_array(), $array['parent'] );
	}

	public function test_to_array_with_a_components_parent_embeds_the_raw_components(): void {
		$scope = Location_Scope::within_components( 'RU', 'settlement', [ 'region' => [ 'name' => 'X', 'type' => 'обл' ] ] );
		$array = $scope->to_array();

		$this->assertSame( [ 'region' => [ 'name' => 'X', 'type' => 'обл' ] ], $array['parent'] );
	}
}
