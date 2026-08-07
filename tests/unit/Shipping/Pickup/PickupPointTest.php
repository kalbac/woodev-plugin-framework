<?php
/**
 * Unit tests for Pickup_Point — payload validation, rejection rules, and the
 * canonical (to_array) vs. browser-safe (to_browser_array) serialization split.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Point
 */
final class PickupPointTest extends TestCase {

	private function valid(): array {
		return [
			'id'      => 'PVZ-1',
			'name'    => 'ПВЗ на Тверской',
			'lat'     => 55.7558,
			'lng'     => 37.6173,
			'address' => 'Москва, ул. Тверская, 1',
			'type'    => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи' ],
		];
	}

	/**
	 * Builds a point from the valid() payload with the given overrides merged in.
	 *
	 * @param array<string, mixed> $overrides Payload keys to merge over the valid baseline.
	 */
	private function make_point( array $overrides ): Pickup_Point {
		$point = Pickup_Point::from_array( array_merge( $this->valid(), $overrides ) );
		$this->assertNotNull( $point, 'test payload must build a valid point' );

		return $point;
	}

	public function test_builds_from_a_complete_payload(): void {
		$point = Pickup_Point::from_array( $this->valid() );
		$this->assertNotNull( $point, 'a complete, valid payload must build a point' );
		$this->assertSame( 'PVZ-1', $point->get_id() );
		$this->assertSame( 55.7558, $point->get_lat() );
	}

	public function test_returns_null_when_a_required_field_is_missing(): void {
		foreach ( [ 'id', 'name', 'lat', 'lng', 'address', 'type' ] as $key ) {
			$payload = $this->valid();
			unset( $payload[ $key ] );
			$this->assertNull( Pickup_Point::from_array( $payload ), "missing {$key} must reject" );
		}
	}

	public function test_returns_null_when_a_required_field_is_an_empty_string(): void {
		foreach ( [ 'id', 'name', 'address' ] as $key ) {
			$payload         = $this->valid();
			$payload[ $key ] = '';
			$this->assertNull( Pickup_Point::from_array( $payload ), "empty {$key} must reject" );
		}
	}

	public function test_returns_null_when_type_is_not_an_array(): void {
		$payload         = $this->valid();
		$payload['type'] = 'PVZ';
		$this->assertNull( Pickup_Point::from_array( $payload ) );
	}

	public function test_returns_null_when_type_is_missing_code(): void {
		$payload         = $this->valid();
		$payload['type'] = [ 'label' => 'Пункт выдачи' ];
		$this->assertNull( Pickup_Point::from_array( $payload ) );
	}

	public function test_returns_null_when_type_is_missing_label(): void {
		$payload         = $this->valid();
		$payload['type'] = [ 'code' => 'PVZ' ];
		$this->assertNull( Pickup_Point::from_array( $payload ) );
	}

	public function test_returns_null_for_out_of_range_coordinates(): void {
		$payload        = $this->valid();
		$payload['lat'] = 91.0;
		$this->assertNull( Pickup_Point::from_array( $payload ), 'lat above 90 must reject' );

		$payload        = $this->valid();
		$payload['lat'] = -91.0;
		$this->assertNull( Pickup_Point::from_array( $payload ), 'lat below -90 must reject' );

		$payload        = $this->valid();
		$payload['lng'] = 181.0;
		$this->assertNull( Pickup_Point::from_array( $payload ), 'lng above 180 must reject' );

		$payload        = $this->valid();
		$payload['lng'] = -181.0;
		$this->assertNull( Pickup_Point::from_array( $payload ), 'lng below -180 must reject' );
	}

	public function test_returns_null_for_non_scalar_required_fields(): void {
		$payload       = $this->valid();
		$payload['id'] = [ 'x' ];
		$this->assertNull( Pickup_Point::from_array( $payload ), 'a non-scalar id must reject' );
	}

	public function test_returns_null_for_non_numeric_coordinates(): void {
		$payload        = $this->valid();
		$payload['lat'] = 'abc';
		$this->assertNull( Pickup_Point::from_array( $payload ), 'a non-numeric lat must reject, not coerce to 0.0' );

		$payload        = $this->valid();
		$payload['lat'] = true;
		$this->assertNull( Pickup_Point::from_array( $payload ), 'a boolean lat must reject' );

		$payload        = $this->valid();
		$payload['lng'] = [];
		$this->assertNull( Pickup_Point::from_array( $payload ), 'an array lng must reject' );
	}

	public function test_unknown_constraints_default_to_permissive(): void {
		$point = Pickup_Point::from_array( $this->valid() );
		$this->assertNotNull( $point );
		$this->assertNull( $point->get_accepts_cod() );
		$this->assertNull( $point->get_max_weight() );
	}

	public function test_known_constraints_are_honored_when_present(): void {
		$payload                  = $this->valid();
		$payload['accepts_cod']   = true;
		$payload['max_weight']    = 15000;
		$point                    = Pickup_Point::from_array( $payload );
		$this->assertNotNull( $point );
		$this->assertTrue( $point->get_accepts_cod() );
		$this->assertSame( 15000, $point->get_max_weight() );
	}

	public function test_optional_string_fields_default_to_empty(): void {
		$point = Pickup_Point::from_array( $this->valid() );
		$this->assertNotNull( $point );
		$this->assertSame( '', $point->get_locality() );
		$this->assertSame( '', $point->get_postal_code() );
	}

	public function test_optional_string_fields_are_kept_when_present(): void {
		$payload               = $this->valid();
		$payload['locality']   = 'Москва';
		$payload['postal_code'] = '125009';
		$point                 = Pickup_Point::from_array( $payload );
		$this->assertNotNull( $point );
		$this->assertSame( 'Москва', $point->get_locality() );
		$this->assertSame( '125009', $point->get_postal_code() );
	}

	public function test_to_array_does_not_escape(): void {
		$payload         = $this->valid();
		$payload['name'] = '<script>alert(1)</script>';
		$array           = Pickup_Point::from_array( $payload )->to_array();
		$this->assertStringContainsString( '<script>', $array['name'], 'to_array() is the canonical, unescaped form' );
	}

	public function test_to_browser_array_escapes_display_strings(): void {
		$payload         = $this->valid();
		$payload['name'] = '<script>alert(1)</script>';
		$array           = Pickup_Point::from_array( $payload )->to_browser_array();
		$this->assertStringNotContainsString( '<script>', $array['name'] );
	}

	public function test_to_browser_array_escapes_nested_type_and_payment_methods(): void {
		$payload                     = $this->valid();
		$payload['type']['label']    = '<b>Пункт</b>';
		$payload['payment_methods']  = [ '<b>cash</b>' ];
		$array                       = Pickup_Point::from_array( $payload )->to_browser_array();
		$this->assertStringNotContainsString( '<b>', $array['type']['label'] );
		$this->assertStringNotContainsString( '<b>', $array['payment_methods'][0] );
	}

	public function test_to_browser_array_does_not_escape_id(): void {
		$payload       = $this->valid();
		$payload['id'] = 'PVZ-1&2';
		$array         = Pickup_Point::from_array( $payload )->to_browser_array();
		$this->assertSame( 'PVZ-1&2', $array['id'], 'id is an identity token, not display text' );
	}

	public function test_services_default_to_an_empty_array(): void {
		$point = $this->make_point( [] );

		$this->assertSame( [], $point->to_array()['services'] );
	}

	public function test_services_are_escaped_for_the_browser(): void {
		$point = $this->make_point( [ 'services' => [ 'Примерка', 'A & B' ] ] );

		$this->assertSame( [ 'Примерка', 'A &amp; B' ], $point->to_browser_array()['services'] );
	}

	public function test_non_string_services_are_dropped(): void {
		$point = $this->make_point( [ 'services' => [ 'Примерка', [ 'x' ], null, 5 ] ] );

		$this->assertSame( [ 'Примерка' ], $point->to_array()['services'] );
	}

	public function test_surviving_services_are_reindexed_from_zero(): void {
		// array_filter() PRESERVES keys, so without array_values() a dropped entry leaves a
		// sparse array and wp_json_encode() emits a JSON OBJECT instead of an array — the JS
		// then iterates nothing. Every other fixture here happens to keep key 0, so removing
		// array_values() would survive them all. This is the one that kills that mutant.
		$point = $this->make_point( [ 'services' => [ [ 'x' ], 'Примерка', null, 'Выкуп' ] ] );

		$this->assertSame( [ 0, 1 ], array_keys( $point->to_array()['services'] ) );
		$this->assertSame( [ 'Примерка', 'Выкуп' ], $point->to_array()['services'] );
	}

	public function test_whitespace_only_services_are_dropped(): void {
		$point = $this->make_point( [ 'services' => [ 'Примерка', '   ', '' ] ] );

		$this->assertSame( [ 'Примерка' ], $point->to_array()['services'] );
	}

	public function test_the_string_zero_is_a_legitimate_service(): void {
		$point = $this->make_point( [ 'services' => [ '0' ] ] );

		$this->assertSame( [ '0' ], $point->to_array()['services'] );
	}

	public function test_non_string_payment_methods_are_dropped(): void {
		$point = $this->make_point( [ 'payment_methods' => [ 'Наличные', [ 'x' ], null, 5 ] ] );

		$this->assertSame( [ 'Наличные' ], $point->to_array()['payment_methods'] );
	}

	public function test_surviving_payment_methods_are_reindexed_from_zero(): void {
		// Same array_filter()-preserves-keys trap as services (see the note on that field's
		// fixture): every other case here happens to keep key 0, so this is the one that
		// kills the mutant that would drop array_values() and leave a sparse array.
		$point = $this->make_point( [ 'payment_methods' => [ [ 'x' ], 'Наличные', null, 'Картой' ] ] );

		$this->assertSame( [ 0, 1 ], array_keys( $point->to_array()['payment_methods'] ) );
		$this->assertSame( [ 'Наличные', 'Картой' ], $point->to_array()['payment_methods'] );
	}

	public function test_whitespace_only_payment_methods_are_dropped(): void {
		$point = $this->make_point( [ 'payment_methods' => [ 'Наличные', '   ', '' ] ] );

		$this->assertSame( [ 'Наличные' ], $point->to_array()['payment_methods'] );
	}

	/**
	 * Pins a deliberate behaviour change (issue #154 follow-up, 2026-08-07): the pre-fix
	 * `array_map( 'strval', ... )` coerced scalars, so an integer element used to SURVIVE as
	 * its stringified form ('5'). `sanitize_string_list()` requires `is_string()` and drops
	 * it instead. See that method's docblock for why: neither reference carrier
	 * (`plugins-reference/woocommerce-edostavka`, `plugins-reference/woocommerce-yandex-delivery`)
	 * nor any in-repo fixture ever produces a numeric `payment_methods` element, so this is not
	 * a real shape to accommodate — it is far more likely a broken adapter leaking an internal
	 * id, and displaying that id as a payment method would be exactly the "Array" bug's failure
	 * mode wearing a different number.
	 */
	public function test_integer_payment_methods_are_dropped_not_stringified(): void {
		$point = $this->make_point( [ 'payment_methods' => [ 'Наличные', 5 ] ] );

		$this->assertSame( [ 'Наличные' ], $point->to_array()['payment_methods'] );
	}

	public function test_non_string_photos_are_dropped(): void {
		$point = $this->make_point( [ 'photos' => [ 'https://example.test/a.jpg', [ 'x' ], null, 5 ] ] );

		$this->assertSame( [ 'https://example.test/a.jpg' ], $point->to_array()['photos'] );
	}

	public function test_surviving_photos_are_reindexed_from_zero(): void {
		$point = $this->make_point(
			[ 'photos' => [ [ 'x' ], 'https://example.test/a.jpg', null, 'https://example.test/b.jpg' ] ]
		);

		$this->assertSame( [ 0, 1 ], array_keys( $point->to_array()['photos'] ) );
		$this->assertSame(
			[ 'https://example.test/a.jpg', 'https://example.test/b.jpg' ],
			$point->to_array()['photos']
		);
	}

	public function test_whitespace_only_photos_are_dropped(): void {
		$point = $this->make_point( [ 'photos' => [ 'https://example.test/a.jpg', '   ', '' ] ] );

		$this->assertSame( [ 'https://example.test/a.jpg' ], $point->to_array()['photos'] );
	}

	/**
	 * Pins the same deliberate change as
	 * {@see self::test_integer_payment_methods_are_dropped_not_stringified()} for `photos`: an
	 * integer element is dropped, not `strval()`-coerced into a fabricated "URL". No reference
	 * carrier populates `photos` at all yet (always `[]` in every in-repo fixture), so there is
	 * no real shape being narrowed here — only a hypothetical one that would be equally wrong to
	 * accommodate silently.
	 */
	public function test_integer_photos_are_dropped_not_stringified(): void {
		$point = $this->make_point( [ 'photos' => [ 'https://example.test/a.jpg', 5 ] ] );

		$this->assertSame( [ 'https://example.test/a.jpg' ], $point->to_array()['photos'] );
	}
}
