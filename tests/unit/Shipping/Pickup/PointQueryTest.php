<?php
/**
 * Unit tests for Point_Query — locality/bbox addressing modes, the per-side span cap
 * that stops a client asking for the whole planet, and rejection of malformed or
 * unusable requests.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Point_Query
 */
final class PointQueryTest extends TestCase {

	public function test_parses_a_bbox(): void {
		$query = Point_Query::from_request( [ 'bbox' => '55.70,37.50,55.80,37.70' ] );
		$this->assertNotNull( $query );
		$this->assertSame( [ 55.70, 37.50, 55.80, 37.70 ], $query->get_bounds() );
	}

	public function test_rejects_a_bbox_with_the_wrong_arity(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '55.70,37.50,55.80' ] ) );
	}

	public function test_rejects_a_bbox_larger_than_the_span_cap(): void {
		// Whole planet — must be refused, not silently served.
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '-90,-180,90,180' ] ) );
	}

	public function test_rejects_a_full_circumference_strip(): void {
		// A narrow band of latitude spanning the whole 360° of longitude has a small
		// area (well under the old 100 sq-deg area cap) but is ~40,000 km wide — exactly
		// the whole-planet request the cap exists to refuse. An area-only cap would let
		// this through; the per-side span cap must not.
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '0,-180,0.27,180' ] ) );
	}

	public function test_rejects_a_long_thin_longitude_strip(): void {
		// 1 x 100 degrees: small area, but 100 degrees of longitude is ten times the
		// per-side span cap.
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '50,0,51,100' ] ) );
	}

	public function test_rejects_an_inverted_bbox(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '55.80,37.70,55.70,37.50' ] ) );
	}

	public function test_carries_locality_and_search_term(): void {
		$query = Point_Query::from_request(
			[
				'locality' => 'Москва',
				'q'        => 'твер',
			]
		);
		$this->assertNotNull( $query );
		$this->assertSame( 'Москва', $query->get_locality() );
		$this->assertSame( 'твер', $query->get_search() );
		$this->assertNull( $query->get_bounds() );
	}

	public function test_rejects_an_empty_request(): void {
		$this->assertNull( Point_Query::from_request( [] ) );
	}

	public function test_rejects_a_bbox_with_a_non_numeric_component(): void {
		// Isolated so removing the is_numeric() guard alone is what must be caught: if
		// 'abc' silently coerced to 0.0 via floatval(), this box (0,0)-(1,1) would still
		// be a perfectly valid, non-inverted, in-range, in-span box — only the explicit
		// numeric check can catch it.
		$this->assertNull( Point_Query::from_request( [ 'bbox' => 'abc,0,1,1' ] ) );
	}

	public function test_rejects_a_bbox_with_min_lat_below_range(): void {
		// Isolated: a tiny box just below -90 so the span cap and inversion check cannot
		// accidentally catch this instead of the explicit range check.
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '-91,0,-90.5,1' ] ) );
	}

	public function test_rejects_a_bbox_with_max_lat_above_range(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '90.5,0,91,1' ] ) );
	}

	public function test_rejects_a_bbox_with_min_lng_below_range(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '0,-181,1,-180.5' ] ) );
	}

	public function test_rejects_a_bbox_with_max_lng_above_range(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '0,180.5,1,181' ] ) );
	}

	public function test_rejects_a_request_carrying_only_a_search_term(): void {
		$this->assertNull( Point_Query::from_request( [ 'q' => 'твер' ] ) );
	}

	public function test_rejects_an_empty_locality(): void {
		$this->assertNull( Point_Query::from_request( [ 'locality' => '' ] ) );
	}

	public function test_an_empty_locality_paired_with_a_valid_bbox_is_a_usable_viewport_query(): void {
		// An empty locality is not itself invalid — from_request() only refuses it when it
		// leaves nothing to address by. Pinning the docblock claim: from_request() does
		// NOT reject merely because locality is an empty string.
		$query = Point_Query::from_request(
			[
				'locality' => '',
				'bbox'     => '55.70,37.50,55.80,37.70',
			]
		);
		$this->assertNotNull( $query, 'an empty locality alongside a valid bbox must still produce a query' );
		$this->assertNull( $query->get_locality(), 'an empty locality normalizes to null, not to the empty string' );
		$this->assertSame( [ 55.70, 37.50, 55.80, 37.70 ], $query->get_bounds() );
	}

	public function test_an_empty_bbox_is_treated_as_absent_not_malformed(): void {
		// Deliberate asymmetry, pinned side by side: an EMPTY bbox alongside a locality
		// silently downgrades to a locality-only (bulk) query — this exists because a
		// later REST route may declare `bbox` with a default of '', and every bulk
		// request must not be rejected outright as a result. A MALFORMED bbox (wrong
		// arity, non-numeric, out of range, inverted, oversized) is a different case:
		// see test_rejects_the_whole_request_when_bbox_is_invalid_even_with_a_valid_locality
		// below — it still rejects the whole request rather than falling back.
		$query = Point_Query::from_request(
			[
				'locality' => 'Москва',
				'bbox'     => '',
			]
		);
		$this->assertNotNull( $query, 'an empty bbox must not itself invalidate the request' );
		$this->assertSame( 'Москва', $query->get_locality() );
		$this->assertNull( $query->get_bounds(), 'an empty bbox is treated as not supplied' );
	}

	public function test_accepts_a_bbox_exactly_at_the_span_cap(): void {
		// 10 x 10 degrees, exactly the per-side cap on both axes.
		$query = Point_Query::from_request( [ 'bbox' => '0,0,10,10' ] );
		$this->assertNotNull( $query, 'a bbox exactly at the cap must be accepted' );
		$this->assertSame( [ 0.0, 0.0, 10.0, 10.0 ], $query->get_bounds() );
	}

	public function test_rejects_a_bbox_just_over_the_span_cap_on_the_lat_axis(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '0,0,10.01,10' ] ) );
	}

	public function test_rejects_a_bbox_just_over_the_span_cap_on_the_lng_axis(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '0,0,10,10.01' ] ) );
	}

	public function test_retains_both_locality_and_bbox_when_both_are_supplied(): void {
		$query = Point_Query::from_request(
			[
				'locality' => 'Москва',
				'bbox'     => '55.70,37.50,55.80,37.70',
			]
		);
		$this->assertNotNull( $query );
		$this->assertSame( 'Москва', $query->get_locality() );
		$this->assertSame( [ 55.70, 37.50, 55.80, 37.70 ], $query->get_bounds() );
	}

	public function test_rejects_a_non_string_bbox(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => [ '55.70', '37.50', '55.80', '37.70' ] ] ) );
	}

	public function test_rejects_a_non_string_locality(): void {
		$this->assertNull( Point_Query::from_request( [ 'locality' => [ 'Москва' ] ] ) );
	}

	public function test_defaults_search_to_empty_string_when_absent(): void {
		$query = Point_Query::from_request( [ 'locality' => 'Москва' ] );
		$this->assertNotNull( $query );
		$this->assertSame( '', $query->get_search() );
	}

	public function test_rejects_a_non_scalar_search_term(): void {
		$this->assertNull( Point_Query::from_request( [ 'locality' => 'Москва', 'q' => [ 'твер' ] ] ) );
	}

	public function test_rejects_a_bbox_with_zero_height(): void {
		// min_lat === max_lat: the box collapses to a horizontal line, zero height.
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '55.70,37.50,55.70,37.70' ] ) );
	}

	public function test_rejects_a_bbox_with_zero_width(): void {
		// min_lng === max_lng: the box collapses to a vertical line, zero width.
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '55.70,37.50,55.80,37.50' ] ) );
	}

	public function test_accepts_a_bbox_touching_the_south_pole_boundary(): void {
		$query = Point_Query::from_request( [ 'bbox' => '-90,0,-85,5' ] );
		$this->assertNotNull( $query, 'min_lat exactly -90 is a valid latitude and must be accepted' );
		$this->assertSame( [ -90.0, 0.0, -85.0, 5.0 ], $query->get_bounds() );
	}

	public function test_accepts_a_bbox_touching_the_north_pole_boundary(): void {
		$query = Point_Query::from_request( [ 'bbox' => '85,0,90,5' ] );
		$this->assertNotNull( $query, 'max_lat exactly 90 is a valid latitude and must be accepted' );
		$this->assertSame( [ 85.0, 0.0, 90.0, 5.0 ], $query->get_bounds() );
	}

	public function test_accepts_a_bbox_touching_the_antimeridian_min_side(): void {
		$query = Point_Query::from_request( [ 'bbox' => '0,-180,5,-175' ] );
		$this->assertNotNull( $query, 'min_lng exactly -180 is a valid longitude and must be accepted' );
		$this->assertSame( [ 0.0, -180.0, 5.0, -175.0 ], $query->get_bounds() );
	}

	public function test_accepts_a_bbox_touching_the_antimeridian_max_side(): void {
		$query = Point_Query::from_request( [ 'bbox' => '0,175,5,180' ] );
		$this->assertNotNull( $query, 'max_lng exactly 180 is a valid longitude and must be accepted' );
		$this->assertSame( [ 0.0, 175.0, 5.0, 180.0 ], $query->get_bounds() );
	}

	public function test_rejects_the_whole_request_when_bbox_is_invalid_even_with_a_valid_locality(): void {
		// An invalid bbox must not be silently dropped in favour of the locality — the
		// caller asked for a viewport query and got something malformed; falling back to
		// bulk mode would mask the error instead of surfacing it.
		$this->assertNull(
			Point_Query::from_request(
				[
					'locality' => 'Москва',
					'bbox'     => '-90,-180,90,180',
				]
			)
		);
	}

	// ---- `types` (D-10): server-side type filter for the viewport strategy ----
	//
	// NOTE on a deliberate deviation from the plan's literal snippet: the plan's three
	// given test bodies call from_request() with ONLY a `types` param (or no params at
	// all) and expect a non-null query back. That contradicts a PRE-EXISTING, out-of-scope
	// invariant this suite already pins (test_rejects_an_empty_request,
	// test_rejects_a_request_carrying_only_a_search_term): from_request() refuses any
	// request naming neither `locality` nor `bbox` — there is nothing to address by.
	// `types` narrows a query exactly like `q` already does; it must not become a third
	// way to satisfy that rule (see
	// test_a_request_naming_only_types_and_no_addressing_mode_is_still_refused below,
	// which pins the invariant this reasoning preserves). Each test below therefore pairs
	// its `types` param with a valid `locality`, keeping the plan's exact intent and
	// assertions while producing a query that is actually reachable in production.

	public function test_types_default_to_an_empty_array_meaning_all_types(): void {
		$query = Point_Query::from_request( [ 'locality' => 'Москва' ] );

		$this->assertNotNull( $query );
		$this->assertSame( [], $query->get_types() );
	}

	public function test_types_are_parsed_from_a_comma_separated_list(): void {
		$query = Point_Query::from_request( [ 'locality' => 'Москва', 'types' => 'pvz,postamat' ] );

		$this->assertNotNull( $query );
		$this->assertSame( [ 'pvz', 'postamat' ], $query->get_types() );
	}

	public function test_blank_and_duplicate_type_codes_are_dropped(): void {
		$query = Point_Query::from_request( [ 'locality' => 'Москва', 'types' => 'pvz,,pvz, postamat ' ] );

		$this->assertNotNull( $query );
		$this->assertSame( [ 'pvz', 'postamat' ], $query->get_types() );
	}

	public function test_type_codes_are_compared_case_sensitively(): void {
		// Type codes are opaque strings owned by the plugin/carrier, not framework
		// vocabulary — folding case would presume an ASCII convention the framework has
		// no business assuming, and could wrongly merge two carrier-distinct codes.
		// 'PVZ' and 'pvz' must therefore survive as two distinct entries, not one.
		$query = Point_Query::from_request( [ 'locality' => 'Москва', 'types' => 'PVZ,pvz' ] );

		$this->assertNotNull( $query );
		$this->assertSame( [ 'PVZ', 'pvz' ], $query->get_types() );
	}

	public function test_a_request_naming_only_types_and_no_addressing_mode_is_still_refused(): void {
		// `types` narrows a query, it does not address one — it must not become a third
		// way to satisfy from_request()'s "name at least one addressing mode" rule.
		$this->assertNull( Point_Query::from_request( [ 'types' => 'pvz' ] ) );
	}

	public function test_rejects_a_non_string_types(): void {
		// Mirrors the is_string() guard already applied to locality/bbox/q — a non-scalar
		// `types` (e.g. a repeated `types[]=a&types[]=b` query key) must be rejected, not
		// coerced into the literal string "Array".
		$this->assertNull(
			Point_Query::from_request(
				[
					'locality' => 'Москва',
					'types'    => [ 'pvz' ],
				]
			)
		);
	}

	public function test_types_do_not_disturb_an_otherwise_valid_bbox_query(): void {
		$query = Point_Query::from_request(
			[
				'bbox'  => '55.70,37.50,55.80,37.70',
				'types' => 'pvz,postamat',
			]
		);

		$this->assertNotNull( $query );
		$this->assertSame( [ 55.70, 37.50, 55.80, 37.70 ], $query->get_bounds() );
		$this->assertSame( [ 'pvz', 'postamat' ], $query->get_types() );
	}

	// -------------------------------------------------------------------------
	// Task 15 (issue #159): locality addressing by record/key.
	// -------------------------------------------------------------------------

	/**
	 * Builds a well-formed Location_Record for the tests below — the exact shape does
	 * not matter, only that it round-trips through {@see Point_Query::with_location()}
	 * unchanged.
	 */
	private function make_record( string $key = 'dadata:fias-1' ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $key,
				'provider_id' => explode( ':', $key )[0],
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				'label'       => 'Москва',
			]
		);
	}

	public function test_a_freshly_built_query_carries_no_location_context(): void {
		$query = Point_Query::from_request( [ 'locality' => 'dadata:fias-1' ] );

		$this->assertNotNull( $query );
		$this->assertNull( $query->get_record() );
		$this->assertNull( $query->get_resolved_identity() );
	}

	public function test_with_location_attaches_record_and_resolved_identity(): void {
		$original = Point_Query::from_request( [ 'locality' => 'dadata:fias-1' ] );
		$this->assertNotNull( $original );

		$record   = $this->make_record();
		$enriched = $original->with_location( $record, 'carrier-city-77' );

		$this->assertSame( $record, $enriched->get_record() );
		$this->assertSame( 'carrier-city-77', $enriched->get_resolved_identity() );

		// Immutable: the ORIGINAL instance is untouched by with_location().
		$this->assertNull( $original->get_record() );
		$this->assertNull( $original->get_resolved_identity() );
	}

	public function test_with_location_accepts_a_null_resolved_identity(): void {
		// A legitimate, first-class answer (Location_Adapter::resolve()'s own docblock):
		// "this carrier does not serve this locality" — must not be confused with "no
		// record was ever attached at all", which is exactly why get_record() is what a
		// Point_Source checks FIRST (interface-point-source.php's own docblock).
		$query = Point_Query::from_request( [ 'locality' => 'dadata:fias-1' ] );
		$this->assertNotNull( $query );

		$record   = $this->make_record();
		$enriched = $query->with_location( $record, null );

		$this->assertSame( $record, $enriched->get_record() );
		$this->assertNull( $enriched->get_resolved_identity() );
	}

	public function test_a_bbox_only_query_still_works_untouched(): void {
		// The viewport path must not regress: a bbox-only query still builds, still
		// carries no locality, and still accepts with_location() the same as any other
		// query (a viewport source MAY read the record too, per the interface docblock).
		$query = Point_Query::from_request( [ 'bbox' => '55.70,37.50,55.80,37.70' ] );

		$this->assertNotNull( $query );
		$this->assertNull( $query->get_locality() );
		$this->assertSame( [ 55.70, 37.50, 55.80, 37.70 ], $query->get_bounds() );
		$this->assertNull( $query->get_record() );

		$enriched = $query->with_location( $this->make_record(), 'x' );
		$this->assertSame( [ 55.70, 37.50, 55.80, 37.70 ], $enriched->get_bounds() );
		$this->assertNotNull( $enriched->get_record() );
	}
}
