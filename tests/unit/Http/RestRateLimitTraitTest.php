<?php
/**
 * Tests for Rest_Rate_Limit_Trait — the fixed-window rate limit shared by every
 * public, guest-reachable Woodev endpoint (REST controllers and admin-ajax handlers alike).
 *
 * Pins three separate bug fixes this trait now carries:
 *
 * 1. An earlier version tied window closure to the transient's own TTL, so every accepted
 *    request re-armed the full TTL and a caller making roughly one request per second
 *    (e.g. a customer panning a map) never let the window lapse. The window id is now part
 *    of the KEY (`floor( now / window )`), so a window closes on wall-clock schedule and
 *    the TTL is a pure garbage-collection hint.
 * 2. An unresolvable client address used to `return false` — i.e. DISABLE the limiter. A
 *    request with a junk `X-Forwarded-For` yielded no valid IP from WooCommerce, which cast
 *    to `''`, and the budget stopped applying at all. An unknown address is now bucketed,
 *    never waved through.
 * 3. The count was read, compared and written back as three separate steps, so concurrent
 *    requests all read the same count and all passed. The counter is now incremented FIRST
 *    and the returned value compared, over an atomic primitive.
 *
 * @package Woodev\Tests\Unit\Http
 */

namespace Woodev\Tests\Unit\Http;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/http/trait-rest-rate-limit.php';

// A `\WC_Geolocation` double, so the tests can drive the header-derived address the trait
// no longer trusts on its own. Global namespace (see the stub file's own docblock). Lives
// beside the Shipping REST controller tests that also need it — it is not specific to this
// trait's own new home, so it did not move with the trait.
if ( ! class_exists( '\\WC_Geolocation' ) ) {
	require_once dirname( __DIR__ ) . '/Shipping/Rest_Api/wc-geolocation-stub.php';
}

/**
 * Concrete class over the trait, exposing its protected members for testing.
 *
 * Keeps the REAL storage path — the tests stub WordPress's own storage functions rather
 * than the trait's own seam, so the trait's key derivation and its increment-then-compare
 * ordering are both genuinely exercised.
 */
final class Rest_Rate_Limit_Trait_Fixture {
	use \Woodev\Framework\Http\Rest_Rate_Limit_Trait;

	/** @var int|null frozen clock, or null to use the real one. */
	public ?int $now = null;

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

	/**
	 * @return string
	 */
	public function client_ip(): string {
		return $this->get_client_ip();
	}

	/**
	 * @return string
	 */
	public function edge_ip(): string {
		return $this->get_edge_ip();
	}

	/**
	 * @return int
	 */
	protected function rate_limit_now(): int {
		return null === $this->now ? time() : $this->now;
	}
}

/**
 * @covers \Woodev\Framework\Http\Rest_Rate_Limit_Trait
 */
final class RestRateLimitTraitTest extends TestCase {

	private const IP = '203.0.113.5';

	/** @var array<string, mixed> in-memory fake transient store */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();

		$this->store = [];

		\WC_Geolocation::$address = null;

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
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$_SERVER['REMOTE_ADDR'] = self::IP;
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		\WC_Geolocation::$address = null;
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------------
	// Budget enforcement
	// -----------------------------------------------------------------------------

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

	public function test_exactly_max_requests_pass_before_the_budget_closes(): void {
		$fixture = new Rest_Rate_Limit_Trait_Fixture();
		$passed  = 0;

		for ( $i = 0; $i < 40; $i++ ) {
			if ( ! $fixture->check( 'x_', 15 ) ) {
				++$passed;
			}
		}

		$this->assertSame( 15, $passed, 'the budget must admit exactly $max requests, no more' );
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

	// -----------------------------------------------------------------------------
	// Window closure — keyed by window id, never by TTL
	// -----------------------------------------------------------------------------

	public function test_a_lapsed_window_starts_a_fresh_count(): void {
		$fixture      = new Rest_Rate_Limit_Trait_Fixture();
		$fixture->now = 1_000_000;

		$this->assertFalse( $fixture->check( 'e_', 1, 60 ) );
		$this->assertTrue( $fixture->check( 'e_', 1, 60 ), 'the second request in the window is over budget' );

		$fixture->now = 1_000_000 + 60;

		$this->assertFalse(
			$fixture->check( 'e_', 1, 60 ),
			'a lapsed window must start a fresh count, not stay exhausted'
		);
	}

	public function test_an_accepted_request_does_not_move_the_window_boundary(): void {
		// The original bug: an accepted request must not re-arm the window. The window id
		// now lives in the KEY, so an accepted request cannot move it by construction —
		// pinned here by the key staying byte-identical across two accepted requests.
		$fixture      = new Rest_Rate_Limit_Trait_Fixture();
		$fixture->now = 1_000_030;

		$fixture->check( 'w_', 5, 100 );
		$first_keys = array_keys( $this->store );

		$fixture->check( 'w_', 5, 100 );
		$second_keys = array_keys( $this->store );

		$this->assertSame( $first_keys, $second_keys, 'an accepted request must not open a new window' );
		$this->assertCount( 1, $second_keys );
	}

	public function test_the_count_increments_on_each_accepted_request(): void {
		$fixture      = new Rest_Rate_Limit_Trait_Fixture();
		$fixture->now = 1_000_000;

		$fixture->check( 'c_', 5, 100 );
		$fixture->check( 'c_', 5, 100 );

		$this->assertSame( [ 2 ], array_values( $this->store ) );
	}

	// -----------------------------------------------------------------------------
	// Finding 1 — an unresolvable address must not DISABLE the limiter
	// -----------------------------------------------------------------------------

	public function test_an_unknown_address_is_still_rate_limited(): void {
		// REGRESSION (Codex finding 1): this used to `return false` unconditionally, so a
		// request the server could not attribute to any address bypassed the budget
		// entirely — the limiter was OFF before the carrier call it exists to protect.
		unset( $_SERVER['REMOTE_ADDR'] );
		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertTrue( $fixture->check( 'p_', 0 ) );
		$this->assertSame( 'unknown', $fixture->edge_ip() );
	}

	public function test_every_unattributable_request_shares_one_bucket(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertFalse( $fixture->check( 'u_', 1 ) );
		$this->assertTrue( $fixture->check( 'u_', 1 ), 'unknown addresses must not each get their own budget' );
	}

	public function test_a_junk_forwarded_address_falls_back_to_the_edge_address(): void {
		// WooCommerce yields '' for an X-Forwarded-For that is not an IP address. That empty
		// string is what used to switch the limiter off; it now simply does not qualify as a
		// client identity and the connection's own address is used instead.
		\WC_Geolocation::$address = 'not-an-ip-address';
		$fixture                  = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertSame( self::IP, $fixture->client_ip() );
	}

	public function test_a_junk_forwarded_address_cannot_rotate_the_bucket(): void {
		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		\WC_Geolocation::$address = 'junk-1';
		$this->assertFalse( $fixture->check( 'j_', 1 ) );

		\WC_Geolocation::$address = 'junk-2';
		$this->assertTrue( $fixture->check( 'j_', 1 ), 'a junk header must not mint a second budget' );
	}

	// -----------------------------------------------------------------------------
	// Finding 1 — a forgeable forwarded address is bounded by the edge bucket
	// -----------------------------------------------------------------------------

	public function test_a_forwarded_address_gets_its_own_bucket(): void {
		// The fairness half: behind a reverse proxy every customer shares one REMOTE_ADDR,
		// so bucketing on that alone would throttle a whole store at one customer's budget.
		\WC_Geolocation::$address = '198.51.100.7';
		$fixture                  = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertFalse( $fixture->check( 'f_', 1 ) );

		\WC_Geolocation::$address = '198.51.100.8';

		$this->assertFalse( $fixture->check( 'f_', 1 ), 'a second forwarded client has its own budget' );
	}

	public function test_rotating_the_forwarded_address_is_bounded_by_the_edge_budget(): void {
		// The security half: the forwarded address is client-controlled on any install that
		// has not declared a trusted-proxy boundary, so an attacker can mint fresh fine
		// buckets at will. The coarse bucket — keyed on the address the web server itself
		// observed, which is never forgeable — is what bounds that to a multiple of the
		// budget instead of leaving it unbounded.
		$fixture = new Rest_Rate_Limit_Trait_Fixture();
		$passed  = 0;

		for ( $i = 0; $i < 200; $i++ ) {
			\WC_Geolocation::$address = '198.51.100.' . ( $i % 250 );

			if ( ! $fixture->check( 'r_', 2 ) ) {
				++$passed;
			}
		}

		$this->assertSame(
			2 * 10,
			$passed,
			'rotating the forwarded address must stay bounded by $max * RATE_LIMIT_EDGE_MULTIPLIER'
		);
	}

	public function test_no_edge_bucket_is_spent_when_the_client_is_the_edge(): void {
		// No forwarded header at all: the client identity IS the connection's address, so
		// there is nothing to bound and the request must cost exactly one bucket write.
		$fixture      = new Rest_Rate_Limit_Trait_Fixture();
		$fixture->now = 1_000_000;

		$fixture->check( 'n_', 5, 60 );

		$this->assertCount( 1, $this->store );
	}

	public function test_the_client_ip_filter_overrides_the_bucket_identity(): void {
		// The trusted-proxy boundary hook: an install that KNOWS which header its own edge
		// rewrites says so here, and the fine bucket becomes trustworthy.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'woodev_rest_rate_limit_client_ip' === $hook ? '192.0.2.44' : $value;
			}
		);

		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertSame( '192.0.2.44', $fixture->client_ip() );
	}

	public function test_the_client_ip_filter_cannot_inject_a_non_address(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'woodev_rest_rate_limit_client_ip' === $hook ? 'nonsense' : $value;
			}
		);

		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertSame( self::IP, $fixture->client_ip() );
	}

	// -----------------------------------------------------------------------------
	// Finding 2 — the counter primitive is atomic
	// -----------------------------------------------------------------------------

	public function test_the_object_cache_path_uses_an_atomic_increment(): void {
		$added = [];
		$incrs = [];

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_add' )->alias(
			static function ( $key, $value, $group, $ttl ) use ( &$added ) {
				$added[] = [ $key, $value, $group, $ttl ];
				return true;
			}
		);
		Functions\when( 'wp_cache_incr' )->alias(
			static function ( $key, $offset, $group ) use ( &$incrs ) {
				$incrs[ $key ] = ( $incrs[ $key ] ?? 0 ) + $offset;
				return $incrs[ $key ];
			}
		);

		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertFalse( $fixture->check( 'oc_', 1 ) );
		$this->assertTrue( $fixture->check( 'oc_', 1 ) );

		$this->assertCount( 2, $added, 'wp_cache_add() seeds the bucket without clobbering a live count' );
		$this->assertSame( [ 2 ], array_values( $incrs ) );
		$this->assertSame( [], $this->store, 'an object-cached install must not also write options rows' );
	}

	public function test_the_database_path_increments_in_a_single_statement(): void {
		// WHAT THIS PROVES, and what it does not. It cannot prove atomicity — PHPUnit runs
		// one process and cannot interleave two requests inside MySQL. What it pins is the
		// property atomicity actually rests on: the count is never read into PHP, compared,
		// and written back. It is created-or-incremented by ONE statement the database
		// executes indivisibly, and only then read for comparison. A read-back that races
		// can only over-count (someone else's later increment), which fails CLOSED.
		$wpdb = new Rest_Rate_Limit_Fake_Wpdb();

		$GLOBALS['wpdb'] = $wpdb;

		$fixture = new Rest_Rate_Limit_Trait_Fixture();

		$this->assertFalse( $fixture->check( 'db_', 5 ) );

		$upsert = array_values(
			array_filter(
				$wpdb->queries,
				static function ( string $sql ): bool {
					return false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' );
				}
			)
		);

		$this->assertCount( 1, $upsert, 'exactly one create-or-increment statement per bucket' );
		$this->assertStringContainsString( 'option_value = option_value + 1', $upsert[0] );

		$increment_at = null;
		$select_at    = null;

		foreach ( $wpdb->queries as $index => $sql ) {
			if ( null === $increment_at && false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' ) ) {
				$increment_at = $index;
			}

			if ( null === $select_at && 0 === strpos( $sql, 'SELECT' ) ) {
				$select_at = $index;
			}
		}

		$this->assertNotNull( $increment_at );
		$this->assertNotNull( $select_at );
		$this->assertLessThan(
			$select_at,
			$increment_at,
			'the count must be incremented before it is read, never read-compare-write'
		);
	}

	public function test_the_database_path_enforces_the_budget_over_a_real_counter(): void {
		$GLOBALS['wpdb'] = new Rest_Rate_Limit_Fake_Wpdb();

		$fixture = new Rest_Rate_Limit_Trait_Fixture();
		$passed  = 0;

		for ( $i = 0; $i < 10; $i++ ) {
			if ( ! $fixture->check( 'dbb_', 4 ) ) {
				++$passed;
			}
		}

		$this->assertSame( 4, $passed );
	}
}

/**
 * A `$wpdb` stand-in that behaves like the options table for the two statements the trait
 * issues against it: `INSERT IGNORE` (create only if absent) and `INSERT … ON DUPLICATE KEY
 * UPDATE option_value = option_value + 1` (create at 1, else increment).
 */
final class Rest_Rate_Limit_Fake_Wpdb {

	/** @var string */
	public string $options = 'wp_options';

	/** @var array<int, string> every SQL statement issued, in order. */
	public array $queries = [];

	/** @var array<string, string> the fake table, keyed by option_name. */
	public array $rows = [];

	/**
	 * @param string $query  SQL with %s placeholders.
	 * @param mixed  ...$args placeholder values.
	 *
	 * @return string
	 */
	public function prepare( $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . (string) $arg . "'", (string) $query, 1 );
		}

		return (string) $query;
	}

	/**
	 * @param string $query SQL statement.
	 *
	 * @return int
	 */
	public function query( $query ): int {
		$this->queries[] = (string) $query;

		if ( ! preg_match( "/VALUES \('([^']+)', '([^']+)'/", (string) $query, $matches ) ) {
			return 0;
		}

		$name = $matches[1];

		if ( false !== strpos( (string) $query, 'ON DUPLICATE KEY UPDATE' ) ) {
			$this->rows[ $name ] = isset( $this->rows[ $name ] )
				? (string) ( (int) $this->rows[ $name ] + 1 )
				: '1';

			return 1;
		}

		// INSERT IGNORE.
		if ( ! isset( $this->rows[ $name ] ) ) {
			$this->rows[ $name ] = $matches[2];

			return 1;
		}

		return 0;
	}

	/**
	 * @param string $query SQL statement.
	 *
	 * @return string|null
	 */
	public function get_var( $query ) {
		$this->queries[] = (string) $query;

		return preg_match( "/option_name = '([^']+)'/", (string) $query, $matches )
			? ( $this->rows[ $matches[1] ] ?? null )
			: null;
	}
}
