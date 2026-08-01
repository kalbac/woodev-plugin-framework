<?php
/**
 * Tests for Pickup_Controller — the woodev/v1 pickup-points REST routes (spec §7,
 * SP-5 pickup-points-and-map plan Task 7).
 *
 * Covers the WC-free core dispatch: the strategy/query guarantee this controller
 * exists to enforce, the escaped (never canonical) response payload, list
 * reindexing after a malformed/dropped entry, the selectable verdict travelling
 * with every point, and the carrier-failure-vs-empty-result distinction (a
 * `\Woodev_API_Exception` must become a distinguishable `WP_Error`, never a
 * silent empty list).
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Rest_Api\Pickup_Controller;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

/**
 * Minimal \WP_REST_Request stand-in: an array-accessible parameter bag.
 *
 * Declared namespace-scoped (Woodev\Tests\Unit\Shipping\Rest_Api\WP_REST_Request), so it
 * never collides with another suite's GLOBAL-namespace \WP_REST_Request stub sharing the
 * same process — guarding against `class_exists( 'WP_REST_Request', false )` (a global
 * lookup) would wrongly skip this declaration whenever another suite's global stub loaded
 * first, leaving THIS namespace without the class its own tests reference unqualified.
 * Pickup_Controller's callbacks leave `$request` untyped for exactly this reason (see
 * Pickup_Controller::handle_points_request()'s docblock), so this namespace-scoped
 * double — not the real global \WP_REST_Request — is what those tests pass in.
 */
if ( ! class_exists( __NAMESPACE__ . '\\WP_REST_Request', false ) ) {
	class WP_REST_Request {

		/** @var array<string, mixed> */
		private array $params;

		/**
		 * @param array<string, mixed> $params request params.
		 */
		public function __construct( array $params = [] ) {
			$this->params = $params;
		}

		/**
		 * @param string $key param name.
		 *
		 * @return mixed|null
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/trait-rest-rate-limit.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-pickup-controller.php';

/**
 * A configurable {@see Point_Source} test double: fixed strategy, plus injectable
 * closures for the two fetch methods so tests can return static data, throw
 * `\Woodev_API_Exception`, or spy on whether the controller ever called them.
 */
final class Pickup_Controller_Test_Source implements Point_Source {

	/** @var string */
	private string $strategy;

	/** @var callable */
	private $points_provider;

	/** @var callable */
	private $details_provider;

	/**
	 * @param string   $strategy         One of {@see Point_Source}'s STRATEGY_* constants.
	 * @param callable $points_provider  `fn( Point_Query $query ): array`.
	 * @param callable $details_provider `fn( string $id ): ?Pickup_Point`.
	 */
	public function __construct( string $strategy, callable $points_provider, callable $details_provider ) {
		$this->strategy         = $strategy;
		$this->points_provider  = $points_provider;
		$this->details_provider = $details_provider;
	}

	public function get_strategy(): string {
		return $this->strategy;
	}

	public function fetch_points( Point_Query $query ): array {
		return ( $this->points_provider )( $query );
	}

	public function fetch_details( string $point_id ): ?Pickup_Point {
		return ( $this->details_provider )( $point_id );
	}
}

/**
 * Probe subclass bypassing the rate limiter and exposing the protected
 * normalization seam, mirroring Field_Source_Controller_Probe's pattern.
 */
class Pickup_Controller_Probe extends Pickup_Controller {

	/**
	 * Never rate-limits in unit tests.
	 *
	 * @param string $key_prefix transient key prefix (unused).
	 * @param int    $max        requests allowed per window (unused).
	 * @param int    $window     window length in seconds (unused).
	 *
	 * @return bool
	 */
	protected function is_rate_limited( string $key_prefix, int $max, int $window = 60 ): bool {
		return false;
	}

	/**
	 * Records a swallowed carrier failure instead of calling the real error_log() —
	 * a fake carrier message shaped like a credential-bearing URL must never actually
	 * reach the test suite's stderr and make a green run look broken.
	 *
	 * @var array<int, array{context: string, message: string}>
	 */
	public array $logged_failures = [];

	/**
	 * @param \Woodev_API_Exception $e       the caught carrier exception.
	 * @param string                $context short description of the failing call.
	 *
	 * @return void
	 */
	protected function log_carrier_failure( \Woodev_API_Exception $e, string $context ): void {
		$this->logged_failures[] = [
			'context' => $context,
			'message' => $e->getMessage(),
		];
	}

	/**
	 * Exposes normalize_points_params() for direct assertions.
	 *
	 * @param array<string, mixed> $raw raw request params.
	 *
	 * @return array<string, mixed>
	 */
	public function normalize_points_params_public( array $raw ): array {
		return $this->normalize_points_params( $raw );
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller
 */
final class PickupControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);

		// stubEscapeFunctions() returns the input verbatim; override esc_html/esc_url_raw so
		// the escaping contract (to_browser_array() vs to_array()) is actually exercised.
		Functions\when( 'esc_html' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, $decimals );
			}
		);
	}

	/**
	 * Builds a valid point, overridden by $extra.
	 *
	 * @param array<string, mixed> $extra
	 */
	private function point( array $extra = [] ): Pickup_Point {
		return Pickup_Point::from_array(
			array_merge(
				[
					'id'      => 'P1',
					'name'    => 'Точка',
					'lat'     => 55.75,
					'lng'     => 37.61,
					'address' => 'Москва',
					'type'    => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
				],
				$extra
			)
		);
	}

	/**
	 * A bulk-strategy source over static data (or an empty list by default) that
	 * never resolves point details, matching the spec's baseline test fixture.
	 *
	 * @param Pickup_Point[] $points points fetch_points() returns.
	 */
	private function source( array $points = [] ): Point_Source {
		return new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static fn( Point_Query $query ) => $points,
			static fn( string $id ) => null
		);
	}

	// ---- the three specified baseline cases ----

	public function test_points_carries_the_selectable_verdict(): void {
		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source( [ $this->point() ] ),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$data = $controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertCount( 1, $data['points'] );
		$this->assertSame( [ 'allowed' => true, 'reason' => null ], $data['points'][0]['selectable'] );
	}

	public function test_an_unusable_query_yields_an_empty_point_list(): void {
		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source( [ $this->point() ] ),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$this->assertSame( [ 'points' => [] ], $controller->get_points_data( [] ) );
	}

	public function test_details_returns_null_for_an_unknown_point(): void {
		$controller = new Pickup_Controller( 'test-plugin', $this->source(), static fn() => 0, static fn() => 'bacs' );

		$this->assertNull( $controller->get_point_data( 'unknown' ) );
	}

	// ---- the strategy/query guarantee, both directions ----

	public function test_a_bulk_source_given_only_a_bbox_yields_empty_without_calling_fetch(): void {
		$calls  = 0;
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) use ( &$calls ) {
				++$calls;
				return [];
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$data = $controller->get_points_data( [ 'bbox' => '0,0,1,1' ] );

		$this->assertSame( [ 'points' => [] ], $data );
		$this->assertSame( 0, $calls, 'a bulk source must never be handed a bbox-only query' );
	}

	public function test_a_viewport_source_given_only_a_locality_yields_empty_without_calling_fetch(): void {
		$calls  = 0;
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static function ( Point_Query $query ) use ( &$calls ) {
				++$calls;
				return [];
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$data = $controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertSame( [ 'points' => [] ], $data );
		$this->assertSame( 0, $calls, 'a viewport source must never be handed a locality-only query' );
	}

	public function test_a_bulk_source_given_a_locality_and_a_bbox_is_still_queried(): void {
		// The guarantee is "locality is non-null", not "bbox is null" — both may be present.
		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source( [ $this->point() ] ),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$data = $controller->get_points_data( [ 'locality' => 'Москва', 'bbox' => '0,0,1,1' ] );

		$this->assertCount( 1, $data['points'] );
	}

	// ---- carrier failure vs. empty result ----

	public function test_get_points_data_propagates_a_carrier_exception(): void {
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) {
				throw new \Woodev_API_Exception( 'carrier down' );
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$this->expectException( \Woodev_API_Exception::class );
		$controller->get_points_data( [ 'locality' => 'Москва' ] );
	}

	public function test_get_point_data_propagates_a_carrier_exception(): void {
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static fn( Point_Query $query ) => [],
			static function ( string $id ) {
				throw new \Woodev_API_Exception( 'carrier down' );
			}
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$this->expectException( \Woodev_API_Exception::class );
		$controller->get_point_data( 'P1' );
	}

	public function test_handle_points_request_turns_a_carrier_exception_into_a_502_wp_error(): void {
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) {
				throw new \Woodev_API_Exception( 'https://carrier.example/secret?token=abc123' );
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller_Probe( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$result = $controller->handle_points_request( new WP_REST_Request( [ 'locality' => 'Москва' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'carrier.example', $result->get_error_message() );
		$this->assertStringNotContainsString( 'token=abc123', $result->get_error_message() );
		$this->assertNotSame( '', $result->get_error_message() );

		// The real message must still go SOMEWHERE reachable — just not to the browser.
		$this->assertCount( 1, $controller->logged_failures );
		$this->assertStringContainsString( 'carrier.example', $controller->logged_failures[0]['message'] );
	}

	public function test_handle_point_request_turns_a_carrier_exception_into_a_502_wp_error(): void {
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static fn( Point_Query $query ) => [],
			static function ( string $id ) {
				throw new \Woodev_API_Exception( 'https://carrier.example/secret?token=abc123' );
			}
		);
		$controller = new Pickup_Controller_Probe( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$result = $controller->handle_point_request( new WP_REST_Request( [ 'id' => 'P1' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'carrier.example', $result->get_error_message() );
	}

	public function test_handle_point_request_returns_a_404_for_an_unknown_point(): void {
		$controller = new Pickup_Controller_Probe(
			'test-plugin',
			$this->source(),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$result = $controller->handle_point_request( new WP_REST_Request( [ 'id' => 'unknown' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	// ---- escaping: to_browser_array(), never to_array() ----

	public function test_response_points_carry_the_escaped_name_not_the_canonical_one(): void {
		$point = $this->point( [ 'name' => 'Пункт "А" & Ко <b>злой</b>' ] );
		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source( [ $point ] ),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$data = $controller->get_points_data( [ 'locality' => 'Москва' ] );
		$name = $data['points'][0]['name'];

		$this->assertStringContainsString( '&amp;', $name );
		$this->assertStringContainsString( '&quot;', $name );
		$this->assertStringNotContainsString( '<b>', $name );
	}

	public function test_point_detail_response_is_also_escaped(): void {
		$point = $this->point( [ 'name' => 'A & B' ] );
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static fn( Point_Query $query ) => [],
			static fn( string $id ) => $point
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$data = $controller->get_point_data( 'P1' );

		$this->assertStringContainsString( '&amp;', $data['name'] );
	}

	// ---- the list stays a true list ----

	/**
	 * Documentation-only, NOT a mutation-resistant assertion: get_points_data() rebuilds
	 * the list with `$points[] = …` inside its foreach loop, which always produces a
	 * contiguous 0-indexed array regardless of the source's original keys — so this
	 * holds identically whether array_values() is present or absent, and whether the
	 * source returns sparse keys or not. It documents the intended behaviour (a sparse
	 * source can never leak into the response), it just cannot FAIL on it; the mutant
	 * that actually pins array_values()/list-ness lives in
	 * test_the_point_list_is_reindexed_after_a_malformed_entry_is_dropped below, via the
	 * `instanceof Pickup_Point` filter rather than array_values() itself.
	 */
	public function test_sparse_source_keys_are_documented_as_harmless_by_construction(): void {
		$first  = $this->point();
		$second = $this->point( [ 'id' => 'P2' ] );
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static fn( Point_Query $query ) => [ 5 => $first, 10 => $second ],
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$data = $controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertSame( [ 0, 1 ], array_keys( $data['points'] ) );
	}

	public function test_the_point_list_is_reindexed_after_a_malformed_entry_is_dropped(): void {
		$good   = $this->point();
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static fn( Point_Query $query ) => [ 'not-a-point', $good ],
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$data = $controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertSame( [ 0 ], array_keys( $data['points'] ) );
		$this->assertCount( 1, $data['points'] );
	}

	// ---- the selectable verdict travels intact, and is recomputed at details ----

	public function test_a_blocked_selectable_verdict_reaches_the_payload_intact(): void {
		$point      = $this->point( [ 'accepts_cod' => false ] );
		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source( [ $point ] ),
			static fn() => 0,
			static fn() => 'cod'
		);

		$data = $controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertFalse( $data['points'][0]['selectable']['allowed'] );
		$this->assertNotNull( $data['points'][0]['selectable']['reason'] );
	}

	public function test_get_point_data_recomputes_the_verdict_from_current_cart_weight(): void {
		$point  = $this->point( [ 'max_weight' => 1000 ] );
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static fn( Point_Query $query ) => [],
			static fn( string $id ) => $point
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 2000, static fn() => 'bacs' );

		$data = $controller->get_point_data( 'P1' );

		$this->assertFalse( $data['selectable']['allowed'] );
	}

	// ---- a zero/unloaded cart weight stays permissive (weight gate never a hard failure) ----

	public function test_a_zero_cart_weight_never_blocks_on_weight(): void {
		$point      = $this->point( [ 'max_weight' => 1 ] );
		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source( [ $point ] ),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$data = $controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertTrue( $data['points'][0]['selectable']['allowed'] );
	}

	// ---- parameter hygiene ----

	public function test_locality_and_q_are_capped_to_the_max_param_length(): void {
		$probe = new Pickup_Controller_Probe(
			'test-plugin',
			$this->source(),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$long   = str_repeat( 'a', 200 );
		$params = $probe->normalize_points_params_public( [ 'locality' => $long, 'q' => $long, 'bbox' => $long ] );

		$this->assertSame( 128, strlen( $params['locality'] ) );
		$this->assertSame( 128, strlen( $params['q'] ) );
		$this->assertSame( 128, strlen( $params['bbox'] ) );
	}

	// ---- the strategy default branch (an undeclared/typo'd strategy) ----

	public function test_an_unrecognized_strategy_yields_empty_without_calling_fetch(): void {
		// The `default` branch of query_matches_strategy() — a source declaring neither
		// STRATEGY_BULK nor STRATEGY_VIEWPORT (a typo, or a future strategy this
		// framework version does not know about) must fail CLOSED, not open. Both a
		// locality and a bbox are supplied so neither of the two declared branches could
		// accidentally be the one producing the empty result.
		$calls  = 0;
		$source = new Pickup_Controller_Test_Source(
			'bogus-strategy',
			static function ( Point_Query $query ) use ( &$calls ) {
				++$calls;
				return [];
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$data = $controller->get_points_data( [ 'locality' => 'Москва', 'bbox' => '0,0,1,1' ] );

		$this->assertSame( [ 'points' => [] ], $data );
		$this->assertSame( 0, $calls, 'an unrecognized strategy must never reach fetch_points()' );
	}

	// ---- `q` is actually plumbed into the query handed to the source ----

	public function test_the_search_term_reaches_the_query_handed_to_fetch_points(): void {
		$captured = null;
		$source   = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$controller->get_points_data( [ 'locality' => 'Москва', 'q' => 'твер' ] );

		$this->assertInstanceOf( Point_Query::class, $captured );
		$this->assertSame(
			'твер',
			$captured->get_search(),
			'the q param must reach the query passed to fetch_points()'
		);
	}

	// ---- `types` (D-10) is plumbed through to the query handed to fetch_points() ----

	public function test_the_types_param_reaches_the_query_handed_to_fetch_points(): void {
		$captured = null;
		$source   = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static function ( Point_Query $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$controller->get_points_data( [ 'bbox' => '0,0,1,1', 'types' => 'pvz,postamat' ] );

		$this->assertInstanceOf( Point_Query::class, $captured );
		$this->assertSame(
			[ 'pvz', 'postamat' ],
			$captured->get_types(),
			'the types param must reach the query passed to fetch_points()'
		);
	}

	public function test_handle_points_request_reads_the_types_param_off_the_request(): void {
		// register_rest_route() is not called here, so rest_ensure_response() never runs
		// either — Brain Monkey has no built-in WP stub for it and the other
		// handle_points_request() tests in this file only ever exercise the exception
		// paths, which return before reaching it. Functions\when() stubs the symbol just
		// for this test rather than adding an unrelated global fixture.
		Functions\when( 'rest_ensure_response' )->returnArg();

		$captured = null;
		$source   = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static function ( Point_Query $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller_Probe( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$controller->handle_points_request( new WP_REST_Request( [ 'bbox' => '0,0,1,1', 'types' => 'pvz' ] ) );

		$this->assertInstanceOf( Point_Query::class, $captured );
		$this->assertSame( [ 'pvz' ], $captured->get_types() );
	}

	public function test_types_is_capped_to_the_max_param_length(): void {
		$probe = new Pickup_Controller_Probe(
			'test-plugin',
			$this->source(),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$long   = str_repeat( 'a,', 100 );
		$params = $probe->normalize_points_params_public( [ 'types' => $long ] );

		$this->assertSame( 128, strlen( $params['types'] ) );
	}

	public function test_register_routes_declares_types_as_a_sanitized_string_arg(): void {
		$registered = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args;
			}
		);

		$controller = new Pickup_Controller( 'test-plugin', $this->source(), static fn() => 0, static fn() => 'bacs' );
		$controller->register_routes();

		$points_route = null;

		foreach ( $registered as $route => $endpoints ) {
			if ( false !== strpos( $route, '/points' ) && false === strpos( $route, '(?P<id>' ) ) {
				$points_route = $endpoints[0];
				break;
			}
		}

		$this->assertNotNull( $points_route, 'the points collection route must be registered' );
		$this->assertArrayHasKey( 'types', $points_route['args'] );
		$this->assertSame( 'string', $points_route['args']['types']['type'] );
		$this->assertSame( 'sanitize_text_field', $points_route['args']['types']['sanitize_callback'] );
	}

	// ---- the detail route caps `id` before it reaches the source ----

	public function test_the_detail_route_id_is_capped_before_reaching_the_source(): void {
		$captured = null;
		$source   = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static fn( Point_Query $query ) => [],
			static function ( string $id ) use ( &$captured ) {
				$captured = $id;
				return null;
			}
		);
		$controller = new Pickup_Controller_Probe( 'test-plugin', $source, static fn() => 0, static fn() => 'bacs' );

		$long = str_repeat( 'a', 200 );
		$controller->handle_point_request( new WP_REST_Request( [ 'id' => $long ] ) );

		$this->assertNotNull( $captured );
		$this->assertSame( 128, strlen( $captured ), 'a long id must be capped before reaching fetch_details()' );
	}

	// ---- Point_Query's own non-string guard is reachable, not defeated by a cast ----

	public function test_an_array_valued_locality_yields_empty_points_not_a_warning(): void {
		// Exercises the FULL production path: normalize_points_params() (where the bug
		// lived — an intervening (string) cast used to turn this into the literal
		// string "Array") feeding straight into get_points_data(). Simulates a repeated
		// query key (`locality[]=a&locality[]=b`) arriving as an array —
		// Point_Query::from_request()'s own is_string() guard must be what rejects it.
		$probe = new Pickup_Controller_Probe(
			'test-plugin',
			$this->source( [ $this->point() ] ),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$params = $probe->normalize_points_params_public( [ 'locality' => [ 'a', 'b' ] ] );

		$this->assertIsArray( $params['locality'], 'a non-string value must be passed through, not cast' );
		$this->assertSame( [ 'points' => [] ], $probe->get_points_data( $params ) );
	}

	public function test_an_array_valued_q_yields_empty_points_not_a_warning(): void {
		// `q` alone (no locality/bbox) is already an unusable query, but this pins that
		// an array-valued `q` specifically fails via Point_Query's own guard rather than
		// crashing or being stringified — paired with a valid locality so a query WOULD
		// otherwise be usable if q were simply ignored.
		$probe = new Pickup_Controller_Probe(
			'test-plugin',
			$this->source( [ $this->point() ] ),
			static fn() => 0,
			static fn() => 'bacs'
		);

		$params = $probe->normalize_points_params_public( [ 'locality' => 'Москва', 'q' => [ 'a', 'b' ] ] );

		$this->assertIsArray( $params['q'], 'a non-string value must be passed through, not cast' );
		$this->assertSame( [ 'points' => [] ], $probe->get_points_data( $params ) );
	}

	// ---- register_routes() wires WP's own arg validation ----

	public function test_register_routes_declares_a_validate_callback_for_every_arg(): void {
		$registered = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args;
			}
		);

		$controller = new Pickup_Controller( 'test-plugin', $this->source(), static fn() => 0, static fn() => 'bacs' );
		$controller->register_routes();

		$this->assertCount( 2, $registered );

		foreach ( $registered as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				if ( ! isset( $endpoint['args'] ) ) {
					continue;
				}

				foreach ( $endpoint['args'] as $arg_name => $arg_schema ) {
					$this->assertArrayHasKey(
						'validate_callback',
						$arg_schema,
						"route \"$route\" arg \"$arg_name\" must declare a validate_callback"
					);
					$this->assertSame( 'rest_validate_request_arg', $arg_schema['validate_callback'] );
				}
			}
		}
	}
}
