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

	/**
	 * Issue #199: `point_short_name` is the domain's optional override for the card's tab
	 * label — absent must mean "fall back to `type.label`", same `isset() ? … : ''` cascade
	 * as `short_address`/`locality`, never an error.
	 */
	public function test_point_short_name_defaults_to_empty_when_absent(): void {
		$point = $this->make_point( [] );

		$this->assertSame( '', $point->to_array()['point_short_name'] );
	}

	public function test_point_short_name_is_kept_when_present(): void {
		$point = $this->make_point( [ 'point_short_name' => 'Терминал у метро' ] );

		$this->assertSame( 'Терминал у метро', $point->to_array()['point_short_name'] );
	}

	public function test_point_short_name_is_escaped_for_the_browser(): void {
		$point = $this->make_point( [ 'point_short_name' => '<b>ПВЗ</b>' ] );

		$this->assertStringNotContainsString( '<b>', $point->to_browser_array()['point_short_name'] );
	}

	/**
	 * `from_array()` is load-bearing on the confirmation path: `Selection_Result::sanitize_point()`
	 * rebuilds a domain filter's corrected point through this exact method, so a field it does
	 * not know about is silently dropped the moment the customer confirms their selection — see
	 * {@see \Woodev\Tests\Unit\Shipping\Pickup\SelectionResultTest} for that round trip.
	 */
	public function test_point_short_name_is_not_a_required_field(): void {
		$point = Pickup_Point::from_array( $this->valid() );

		$this->assertNotNull( $point, 'point_short_name must stay optional — omitting it must not reject the point' );
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

	// -----------------------------------------------------------------------------
	// icons (issue #193) — cascade tier 1: the point's OWN icon, ahead of the domain's
	// type-keyed icons and the framework's default pin.
	// -----------------------------------------------------------------------------

	public function test_icons_default_to_null_when_the_key_is_absent(): void {
		$point = $this->make_point( [] );

		$this->assertNull( $point->to_array()['icons'] );
	}

	/**
	 * An explicit `null` and an absent key must resolve identically — both mean "this
	 * point carries no icon of its own", not two different states worth telling apart.
	 */
	public function test_icons_default_to_null_when_explicitly_null(): void {
		$point = $this->make_point( [ 'icons' => null ] );

		$this->assertNull( $point->to_array()['icons'] );
	}

	public function test_icons_are_kept_when_both_states_are_supplied(): void {
		$point = $this->make_point(
			[
				'icons' => [
					'default' => 'https://example.test/5post.svg',
					'active'  => 'https://example.test/5post-active.svg',
				],
			]
		);

		$this->assertSame(
			[
				'default' => 'https://example.test/5post.svg',
				'active'  => 'https://example.test/5post-active.svg',
			],
			$point->to_array()['icons']
		);
	}

	/**
	 * D-5's rule, applied to the per-point tier too (mirrors
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::normalized_point_icons()}):
	 * a domain that supplies only one image gets it mirrored into `active`, never a blank.
	 */
	public function test_icons_active_falls_back_to_default_when_only_default_is_supplied(): void {
		$point = $this->make_point( [ 'icons' => [ 'default' => 'https://example.test/5post.svg' ] ] );

		$this->assertSame(
			[ 'default' => 'https://example.test/5post.svg', 'active' => 'https://example.test/5post.svg' ],
			$point->to_array()['icons']
		);
	}

	/**
	 * An empty `default` carries no more information than an absent one — a blank string
	 * can never be rendered as an image, so it is treated the SAME as "no icon" rather than
	 * a distinct third state. This is deliberately not the `close`-flag trap (PR #192): that
	 * flag is a boolean, where an explicit `false` is itself a meaningful decision distinct
	 * from "unspoken". A URL has no equivalent meaningful-but-empty state.
	 */
	public function test_icons_with_an_empty_default_are_dropped_to_null(): void {
		$point = $this->make_point( [ 'icons' => [ 'default' => '', 'active' => 'https://example.test/a.svg' ] ] );

		$this->assertNull( $point->to_array()['icons'] );
	}

	public function test_icons_with_a_non_array_value_are_dropped_to_null(): void {
		$point = $this->make_point( [ 'icons' => 'https://example.test/5post.svg' ] );

		$this->assertNull( $point->to_array()['icons'] );
	}

	public function test_icons_with_a_non_string_default_are_dropped_to_null(): void {
		$point = $this->make_point( [ 'icons' => [ 'default' => [ 'x' ] ] ] );

		$this->assertNull( $point->to_array()['icons'] );
	}

	public function test_icons_with_a_non_string_active_fall_back_to_default(): void {
		$point = $this->make_point(
			[ 'icons' => [ 'default' => 'https://example.test/5post.svg', 'active' => [ 'x' ] ] ]
		);

		$this->assertSame(
			[ 'default' => 'https://example.test/5post.svg', 'active' => 'https://example.test/5post.svg' ],
			$point->to_array()['icons']
		);
	}

	public function test_to_array_does_not_escape_icons(): void {
		$point = $this->make_point( [ 'icons' => [ 'default' => 'https://example.test/a.svg?x=1&y=2' ] ] );

		$this->assertStringNotContainsString( '&amp;', $point->to_array()['icons']['default'] );
	}

	public function test_to_browser_array_escapes_icons_via_esc_url_raw_not_esc_url(): void {
		// The stubbed esc_url_raw() from stubEscapeFunctions() is a pass-through, which is
		// enough to prove the VALUE is untouched (no HTML-entity-encoding of the querystring
		// '&') — the same JSON-payload rule `photos` already documents.
		$point = $this->make_point( [ 'icons' => [ 'default' => 'https://example.test/a.svg?x=1&y=2' ] ] );

		$this->assertSame(
			'https://example.test/a.svg?x=1&y=2',
			$point->to_browser_array()['icons']['default']
		);
	}

	public function test_to_browser_array_escapes_the_active_icon_url_too(): void {
		$point = $this->make_point(
			[
				'icons' => [
					'default' => 'https://example.test/a.svg',
					'active'  => 'https://example.test/a-active.svg?x=1&y=2',
				],
			]
		);

		$this->assertSame(
			'https://example.test/a-active.svg?x=1&y=2',
			$point->to_browser_array()['icons']['active']
		);
	}

	public function test_to_browser_array_icons_are_null_when_absent(): void {
		$point = $this->make_point( [] );

		$this->assertNull( $point->to_browser_array()['icons'] );
	}

	/**
	 * A `default` that survives `esc_url_raw()` as an empty string (a `javascript:` URL,
	 * which WordPress's own bad-protocol stripping collapses to `''`) drops the whole icon
	 * override at the browser boundary — mirrors
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::normalized_point_icons()}'s
	 * own rule for the type-level tier.
	 */
	public function test_to_browser_array_drops_icons_whose_default_becomes_empty_after_escaping(): void {
		\Brain\Monkey\Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				return 0 === stripos( ltrim( (string) $url ), 'javascript:' ) ? '' : $url;
			}
		);

		$point = $this->make_point( [ 'icons' => [ 'default' => 'javascript:alert(1)' ] ] );

		$this->assertNull( $point->to_browser_array()['icons'] );
	}
}
