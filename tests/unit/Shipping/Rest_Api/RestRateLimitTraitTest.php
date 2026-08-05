<?php
/**
 * Tests for Rest_Rate_Limit_Trait — the fixed-window per-IP rate limit shared by
 * Field_Source_Controller and Pickup_Controller.
 *
 * Pins the bug fix this trait exists to carry: an earlier version tied window closure
 * to the transient's own TTL, so every accepted request re-armed the full TTL and a
 * caller making roughly one request per second (e.g. a customer panning a map) never
 * let the window lapse. The fix stores `{ count, reset }` explicitly and gates on
 * `time() >= reset`, set once at window start — these tests pin that the `reset`
 * timestamp does not move on an accepted request within the window, distinct budgets
 * per key prefix, and the basic allow/deny boundary.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/trait-rest-rate-limit.php';

/**
 * Minimal concrete class over the trait, exposing its protected members for testing.
 */
final class Rest_Rate_Limit_Trait_Fixture {
	use \Woodev\Framework\Shipping\Rest_Api\Rest_Rate_Limit_Trait;

	/**
	 * @param string $key_prefix transient key prefix.
	 * @param int    $max        requests allowed per window.
	 * @param int    $window     window length, in seconds.
	 *
	 * @return bool
	 */
	public function check( string $key_prefix, int $max, int $window = 60 ): bool {
		return $this->is_rate_limited( $key_prefix, $max, $window );
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Rest_Rate_Limit_Trait
 */
final class RestRateLimitTraitTest extends TestCase {

	private const IP = '203.0.113.5';

	/** @var array<string, mixed> in-memory fake transient store */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();

		$this->store = [];

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->store[ $key ] = $value;
				return true;
			}
		);

		$_SERVER['REMOTE_ADDR'] = self::IP;
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_requests_within_budget_are_not_limited(): void {
		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertFalse( $fixture->check( 'p_', 3 ) );
		$this->assertFalse( $fixture->check( 'p_', 3 ) );
		$this->assertFalse( $fixture->check( 'p_', 3 ) );
	}

	public function test_the_request_exceeding_the_budget_is_limited(): void {
		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$fixture->check( 'p_', 2 );
		$fixture->check( 'p_', 2 );

		$this->assertTrue( $fixture->check( 'p_', 2 ) );
	}

	public function test_a_budget_of_zero_limits_the_very_first_request(): void {
		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertTrue( $fixture->check( 'p_', 0 ) );
	}

	public function test_two_key_prefixes_have_independent_budgets(): void {
		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$fixture->check( 'a_', 1 );

		$this->assertFalse( $fixture->check( 'b_', 1 ), 'a different prefix must not share a budget' );
	}

	public function test_an_unknown_ip_is_never_rate_limited(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertFalse( $fixture->check( 'p_', 0 ) );
	}

	public function test_an_accepted_request_does_not_move_the_window_boundary(): void {
		// The bug this fixes: an accepted request must not re-arm the window — only
		// wall-clock time crossing `reset` may open a new one. Pin the state shape the
		// fix relies on: `reset` is stored once at window start and is not recomputed
		// on every accepted request within that window.
		$fixture = new Rest_Rate_Limit_Trait_Fixture();
		$key     = 'w_' . md5( self::IP );

		$fixture->check( 'w_', 5, 100 );
		$first_reset = $this->store[ $key ]['reset'];

		$fixture->check( 'w_', 5, 100 );
		$second_reset = $this->store[ $key ]['reset'];

		$this->assertSame(
			$first_reset,
			$second_reset,
			'reset must not move on an accepted request within the same window'
		);
	}

	public function test_the_count_increments_on_each_accepted_request(): void {
		$fixture = new Rest_Rate_Limit_Trait_Fixture();
		$key     = 'c_' . md5( self::IP );

		$fixture->check( 'c_', 5, 100 );
		$fixture->check( 'c_', 5, 100 );

		$this->assertSame( 2, $this->store[ $key ]['count'] );
	}

	public function test_a_window_that_has_lapsed_resets_the_count(): void {
		$fixture = new Rest_Rate_Limit_Trait_Fixture();
		$key     = 'e_' . md5( self::IP );

		// Simulate a lapsed window by seeding a `reset` timestamp already in the past.
		$this->store[ $key ] = [
			'count' => 5,
			'reset' => time() - 1,
		];

		$this->assertFalse(
			$fixture->check( 'e_', 5 ),
			'a lapsed window must start a fresh count, not stay exhausted'
		);
	}
}
