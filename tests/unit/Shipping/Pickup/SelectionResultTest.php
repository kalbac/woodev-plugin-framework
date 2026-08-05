<?php
/**
 * Unit tests for Selection_Result — the verdict/advice shape a pickup-point selection
 * round-trip is built from, and its two-tier fail-closed sanitisation.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Selection_Result;
use Woodev\Tests\Unit\TestCase;

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
			'point'            => [ 'id' => 'X-1' ],
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
}
