<?php
/**
 * Unit tests for Selection_Result — the verdict/advice shape a pickup-point selection
 * round-trip is built from, and its two-tier fail-closed sanitisation.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Selection_Result;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-selection-result.php';

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Selection_Result
 */
final class SelectionResultTest extends TestCase {

	public function test_from_verdict_seeds_allowed_and_reason_and_leaves_flags_unspoken(): void {
		$result = Selection_Result::from_verdict( [ 'allowed' => false, 'reason' => 'Тяжело' ] );

		$this->assertSame(
			[
				'allowed'          => false,
				'reason'           => 'Тяжело',
				'close'            => null,
				'refresh_checkout' => null,
				'point'            => null,
			],
			$result
		);
	}

	public function test_sanitize_keeps_a_well_formed_domain_answer(): void {
		$computed = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered = [
			'allowed'          => false,
			'reason'           => 'Пункт временно не принимает заказы',
			'close'            => false,
			'refresh_checkout' => true,
			'point'            => null,
		];

		$this->assertSame( $filtered, Selection_Result::sanitize( $filtered, $computed ) );
	}

	public function test_sanitize_preserves_an_explicit_false_rather_than_treating_it_as_absent(): void {
		$computed           = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered           = $computed;
		$filtered['close']  = false;

		$this->assertFalse( Selection_Result::sanitize( $filtered, $computed )['close'] );
	}

	/**
	 * @dataProvider provide_junk_returns
	 *
	 * @param mixed $junk
	 */
	public function test_sanitize_falls_back_to_the_computed_result_for_junk( $junk ): void {
		$computed = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );

		$this->assertSame( $computed, Selection_Result::sanitize( $junk, $computed ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_junk_returns(): array {
		return [
			'not an array'      => [ 'yes' ],
			'null'               => [ null ],
			'missing allowed'   => [ [ 'reason' => null ] ],
			'allowed not bool'  => [ [ 'allowed' => 1, 'reason' => null ] ],
			'reason not string' => [ [ 'allowed' => true, 'reason' => [ 'x' ] ] ],
		];
	}

	public function test_sanitize_normalises_a_non_bool_flag_to_absent_without_discarding_the_rest(): void {
		$computed            = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered            = $computed;
		$filtered['close']   = 'yes';
		$filtered['reason']  = 'Годится';

		$sanitized = Selection_Result::sanitize( $filtered, $computed );

		$this->assertNull( $sanitized['close'], 'a non-bool flag is "the domain said nothing"' );
		$this->assertSame( 'Годится', $sanitized['reason'], 'the rest of a usable answer survives' );
	}

	public function test_sanitize_drops_a_non_array_point(): void {
		$computed           = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered           = $computed;
		$filtered['point']  = 'X-1';

		$this->assertNull( Selection_Result::sanitize( $filtered, $computed )['point'] );
	}

	// -----------------------------------------------------------------------------
	// The corrected point — validated against the framework's own point shape
	// -----------------------------------------------------------------------------

	/**
	 * Replaces the base TestCase's pass-through `esc_html` with WordPress's ACTUAL
	 * semantics, which are what the escaping assertions below depend on: `esc_html()` is
	 * `_wp_specialchars( $text, ENT_QUOTES )` and that helper's `$double_encode` defaults
	 * to FALSE, so an already-escaped string passes through unchanged. `htmlspecialchars()`
	 * on its own double-encodes and would make an idempotency assertion pass or fail for
	 * the wrong reason.
	 *
	 * @return void
	 */
	private function stub_real_esc_html(): void {
		\Brain\Monkey\Functions\when( 'esc_html' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8', false );
			}
		);
		\Brain\Monkey\Functions\when( 'esc_url_raw' )->returnArg();
	}

	/**
	 * A complete, well-formed corrected point in the shape the filter's own contract
	 * documents — `Pickup_Point::to_browser_array()`'s keys.
	 *
	 * @param array<string, mixed> $extra overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function corrected_point( array $extra = [] ): array {
		return array_merge(
			[
				'id'      => 'X-1',
				'name'    => 'ПВЗ на Ленина',
				'address' => 'г. Москва, ул. Ленина, д. 1',
				'lat'     => 55.75,
				'lng'     => 37.61,
				'type'    => [
					'code'  => 'PVZ',
					'label' => 'Пункт выдачи',
				],
			],
			$extra
		);
	}

	public function test_a_corrected_point_is_escaped_before_it_reaches_the_browser(): void {
		// REGRESSION (Codex finding 6): the corrected point used to be forwarded verbatim
		// whenever it was an array. The handler's own contract tells the browser NOT to
		// re-escape these strings, so a domain filter passing carrier data straight through
		// could put live markup into the checkout page.
		$this->stub_real_esc_html();

		$computed          = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered          = $computed;
		$filtered['point'] = $this->corrected_point(
			[ 'name' => '<img src=x onerror=alert(1)>' ]
		);

		$point = Selection_Result::sanitize( $filtered, $computed )['point'];

		$this->assertIsArray( $point );
		$this->assertStringNotContainsString( '<img', $point['name'] );
		$this->assertSame( '&lt;img src=x onerror=alert(1)&gt;', $point['name'] );
	}

	public function test_a_point_already_built_by_the_framework_serializer_survives_unchanged(): void {
		// The documented recipe is "mutate the resolved point and call to_browser_array()".
		// Re-running that output through the same serializer must be a no-op, or every
		// well-behaved plugin would see its addresses double-escaped.
		$this->stub_real_esc_html();

		$built = Pickup_Point::from_array(
			$this->corrected_point( [ 'address' => 'ул. «Мира» & Ко, д. 5' ] )
		);

		$this->assertNotNull( $built );

		$browser_shape = $built->to_browser_array();

		$computed          = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered          = $computed;
		$filtered['point'] = $browser_shape;

		$point = Selection_Result::sanitize( $filtered, $computed )['point'];

		unset( $point['selectable'] );

		$this->assertSame( $browser_shape, $point );
	}

	public function test_a_malformed_corrected_point_is_dropped_rather_than_half_adopted(): void {
		$computed = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => 'Тяжело' ] );

		foreach (
			[
				'missing everything but an id' => [ 'id' => 'X-1' ],
				'no address'                   => $this->corrected_point( [ 'address' => '' ] ),
				'non-numeric coordinates'      => $this->corrected_point( [ 'lat' => 'сюда' ] ),
				'coordinates off the globe'    => $this->corrected_point( [ 'lng' => 999 ] ),
				'type is not a code/label pair' => $this->corrected_point( [ 'type' => 'PVZ' ] ),
			] as $label => $junk
		) {
			$filtered          = $computed;
			$filtered['point'] = $junk;

			$sanitized = Selection_Result::sanitize( $filtered, $computed );

			$this->assertNull( $sanitized['point'], $label );
			$this->assertSame( 'Тяжело', $sanitized['reason'], $label . ': the verdict still survives' );
		}
	}

	/**
	 * Issue #199: `point_short_name` is a KNOWN field of `Pickup_Point::from_array()`'s shape
	 * (unlike `carrier_raw` in the sibling test below), so it must survive the confirmation
	 * round trip — this is what makes `from_array()` load-bearing on this path: a field it
	 * does not know about would be dropped here exactly when the customer clicks select.
	 */
	public function test_a_point_short_name_survives_the_confirmation_round_trip(): void {
		$this->stub_real_esc_html();

		$computed          = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered          = $computed;
		$filtered['point'] = $this->corrected_point( [ 'point_short_name' => 'ПВЗ у метро' ] );

		$point = Selection_Result::sanitize( $filtered, $computed )['point'];

		$this->assertIsArray( $point );
		$this->assertSame( 'ПВЗ у метро', $point['point_short_name'] );
	}

	public function test_an_unknown_key_on_a_corrected_point_does_not_reach_the_browser(): void {
		$computed          = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered          = $computed;
		$filtered['point'] = $this->corrected_point( [ 'carrier_raw' => '<script>x</script>' ] );

		$point = Selection_Result::sanitize( $filtered, $computed )['point'];

		$this->assertIsArray( $point );
		$this->assertArrayNotHasKey( 'carrier_raw', $point );
	}

	/**
	 * Issue #193's own regression target: `Pickup_Point::from_array()` is load-bearing on
	 * THIS path — a domain filter's corrected point is rebuilt through it before it ever
	 * reaches the browser (see {@see \Woodev\Framework\Shipping\Pickup\Selection_Result::sanitize_point()}'s
	 * own docblock). A field `from_array()` does not know is silently dropped, which for
	 * `icons` would mean a point's own icon override vanishing at EXACTLY the moment the
	 * customer confirms a selection — the one place a domain most plausibly wants to say
	 * "actually, THIS point gets its own pin" (e.g. after resolving which operator a
	 * co-located group's representative actually belongs to).
	 */
	public function test_a_corrected_points_own_icon_survives_the_confirmation_path(): void {
		$this->stub_real_esc_html();

		$computed          = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered          = $computed;
		$filtered['point'] = $this->corrected_point(
			[
				'icons' => [
					'default' => 'https://example.test/5post.svg',
					'active'  => 'https://example.test/5post-active.svg?x=1&y=2',
				],
			]
		);

		$point = Selection_Result::sanitize( $filtered, $computed )['point'];

		$this->assertSame(
			[
				'default' => 'https://example.test/5post.svg',
				'active'  => 'https://example.test/5post-active.svg?x=1&y=2',
			],
			$point['icons']
		);
	}

	public function test_the_corrected_points_verdict_mirrors_the_results_own(): void {
		// A point whose `selectable` disagreed with the result carrying it would let a
		// domain hand the browser a refusal and a point that says it is fine, so the entry
		// is derived from the (already validated) verdict rather than trusted from input.
		$computed          = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered          = $computed;
		$filtered['allowed'] = false;
		$filtered['reason']  = 'Перегруз';
		$filtered['point']   = $this->corrected_point(
			[
				'selectable' => [
					'allowed' => true,
					'reason'  => null,
				],
			]
		);

		$point = Selection_Result::sanitize( $filtered, $computed )['point'];

		$this->assertSame(
			[
				'allowed' => false,
				'reason'  => 'Перегруз',
			],
			$point['selectable']
		);
	}
}
