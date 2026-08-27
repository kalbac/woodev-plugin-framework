<?php
/**
 * Integration: the shared REST rate limiter against real WordPress storage.
 *
 * The unit suite drives `Rest_Rate_Limit_Trait` over stubbed storage, which proves the
 * window/bucket POLICY but says nothing about the two things that only exist on a real
 * install: that the create-or-increment statement the atomic counter rests on is accepted
 * by the actual database against the actual options-table schema, and that the rows it
 * writes by hand are the ones WordPress's own transient API recognises (so
 * `delete_expired_transients()` still collects them instead of leaving one row per window
 * behind forever).
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Http\Rest_Rate_Limit_Trait;
use Woodev\Tests\Integration\TestCase;

/**
 * Concrete class over the trait, exposing its protected members.
 */
class Rest_Rate_Limit_Integration_Fixture {

	use Rest_Rate_Limit_Trait;

	/**
	 * @param string $key_prefix storage key prefix.
	 * @param int    $max        requests allowed per window.
	 * @param int    $window     window length, in seconds.
	 *
	 * @return bool
	 */
	public function check( string $key_prefix, int $max, int $window = 60 ): bool {
		return $this->is_rate_limited( $key_prefix, $max, $window );
	}

	/**
	 * @param string $key storage key.
	 * @param int    $ttl lifetime, in seconds.
	 *
	 * @return int
	 */
	public function increment( string $key, int $ttl ): int {
		return $this->increment_rate_limit_counter( $key, $ttl );
	}

	/**
	 * @return string
	 */
	public function edge(): string {
		return $this->get_edge_ip();
	}
}

class RestRateLimitTest extends TestCase {

	/** @var string unique per test run, so one test's bucket is never another's. */
	private string $prefix;

	protected function setUp(): void {
		parent::setUp();

		$this->prefix           = 'woodev_rl_it_' . wp_generate_password( 8, false, false ) . '_';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
	}

	/**
	 * The budget must actually hold when the counter lives in the real database.
	 *
	 * @return void
	 */
	public function test_the_budget_is_enforced_over_real_storage(): void {
		$fixture = new Rest_Rate_Limit_Integration_Fixture();
		$passed  = 0;

		for ( $i = 0; $i < 12; $i++ ) {
			if ( ! $fixture->check( $this->prefix, 5 ) ) {
				++$passed;
			}
		}

		$this->assertSame( 5, $passed, 'exactly $max requests may pass, over real storage' );
	}

	/**
	 * The counter returns 1, 2, 3 … from the database, not a repeated 1 — i.e. the
	 * `ON DUPLICATE KEY UPDATE option_value = option_value + 1` statement is accepted and
	 * does what it says against the real options-table schema.
	 *
	 * @return void
	 */
	public function test_the_counter_increments_in_the_database(): void {
		$fixture = new Rest_Rate_Limit_Integration_Fixture();
		$key     = $this->prefix . 'counter';

		$this->assertSame( 1, $fixture->increment( $key, 120 ) );
		$this->assertSame( 2, $fixture->increment( $key, 120 ) );
		$this->assertSame( 3, $fixture->increment( $key, 120 ) );
	}

	/**
	 * The hand-written rows must be a REAL transient, or every window would leave a row in
	 * `wp_options` that nothing ever collects.
	 *
	 * @return void
	 */
	public function test_the_counter_is_a_real_transient_wordpress_can_read_and_expire(): void {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This install stores transients in the object cache, not the options table.' );
		}

		$fixture = new Rest_Rate_Limit_Integration_Fixture();
		$key     = $this->prefix . 'transient';

		$fixture->increment( $key, 120 );
		$fixture->increment( $key, 120 );

		$this->assertSame( '2', (string) get_transient( $key ), 'get_transient() must read the counter back' );
		$this->assertNotFalse(
			get_option( '_transient_timeout_' . $key ),
			'the expiry row must exist, or delete_expired_transients() can never collect the counter'
		);

		// Backdate the expiry: WordPress's own `get_transient()` must then recognise the pair
		// as an expired transient and delete BOTH rows itself, which is the property that
		// keeps a per-window counter from accumulating in `wp_options` forever.
		update_option( '_transient_timeout_' . $key, time() - 1 );

		$this->assertFalse( get_transient( $key ), 'an expired counter must read as absent' );
		$this->assertFalse( get_option( '_transient_' . $key ), 'the counter row must be collected' );
		$this->assertFalse( get_option( '_transient_timeout_' . $key ), 'the expiry row must be collected' );
	}

	/**
	 * A request the server cannot attribute to any address must still be limited — the
	 * regression this rework exists for, pinned here end to end rather than over stubs.
	 *
	 * @return void
	 */
	public function test_an_unattributable_request_is_still_limited(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		$fixture = new Rest_Rate_Limit_Integration_Fixture();

		$this->assertSame( 'unknown', $fixture->edge() );
		$this->assertFalse( $fixture->check( $this->prefix, 1 ) );
		$this->assertTrue( $fixture->check( $this->prefix, 1 ) );
	}
}
