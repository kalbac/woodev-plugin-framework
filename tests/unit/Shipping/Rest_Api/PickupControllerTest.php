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

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Rest_Api\Pickup_Controller;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-selection-result.php';

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

		/** @var array<string, string> */
		private array $headers;

		/**
		 * @param array<string, mixed>  $params  request params.
		 * @param array<string, string> $headers request headers, keyed exactly as
		 *                                       {@see self::get_header()} is asked for them —
		 *                                       the real \WP_REST_Request normalises the key,
		 *                                       this double deliberately does not, so a
		 *                                       production caller asking for a differently-cased
		 *                                       header than the browser sends is a MISS here and
		 *                                       the test fails rather than passing by luck.
		 */
		public function __construct( array $params = [], array $headers = [] ) {
			$this->params  = $params;
			$this->headers = $headers;
		}

		/**
		 * @param string $key param name.
		 *
		 * @return mixed|null
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param string $key header name.
		 *
		 * @return string|null
		 */
		public function get_header( $key ) {
			return $this->headers[ $key ] ?? null;
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
 * Bypasses only the rate limiter — unlike {@see Pickup_Controller_Probe}, this probe
 * leaves `log_carrier_failure()` UNOVERRIDDEN, so tests built on it exercise the real
 * method (and, through it, {@see \Woodev_API_Base::redact_secret_log_text()} — #585)
 * instead of the probe's own capture-only stand-in.
 */
final class Pickup_Controller_Log_Redaction_Probe extends Pickup_Controller {

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
}

/**
 * Probe whose rate limiter is always TRIPPED, and which records the prefix and budget it
 * was asked for. The inverse of {@see Pickup_Controller_Probe}, which never limits — a
 * route's guard cannot be proven to exist by a probe that disables it, so the only way to
 * assert the guard actually fires (and fires with its OWN bucket, not a sibling's) is a
 * second probe that forces the other branch.
 */
final class Pickup_Controller_Limited_Probe extends Pickup_Controller {

	/**
	 * Every ( prefix, max ) pair the controller asked the limiter about.
	 *
	 * @var array<int, array{prefix: string, max: int}>
	 */
	public array $limit_checks = [];

	/**
	 * @param string $key_prefix transient key prefix.
	 * @param int    $max        requests allowed per window.
	 * @param int    $window     window length in seconds (unused).
	 *
	 * @return bool
	 */
	protected function is_rate_limited( string $key_prefix, int $max, int $window = 60 ): bool {
		$this->limit_checks[] = [
			'prefix' => $key_prefix,
			'max'    => $max,
		];

		return true;
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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$this->assertSame( [ 'points' => [] ], $controller->get_points_data( [] ) );
	}

	public function test_details_returns_null_for_an_unknown_point(): void {
		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source(),
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller_Probe(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller_Probe(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
			static fn() => 'cod',
			static fn() => 'carrier_pickup'
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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 2000,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
		$controller = new Pickup_Controller_Probe(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$controller->handle_points_request( new WP_REST_Request( [ 'bbox' => '0,0,1,1', 'types' => 'pvz' ] ) );

		$this->assertInstanceOf( Point_Query::class, $captured );
		$this->assertSame( [ 'pvz' ], $captured->get_types() );
	}

	public function test_types_is_capped_to_the_max_param_length(): void {
		$probe = new Pickup_Controller_Probe(
			'test-plugin',
			$this->source(),
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		// Distinct leading and trailing content, so the assertion below can tell a cap that
		// truncates from the correct end apart from one that keeps the tail — a length-only
		// assertion passes for both, and for a cap that rewrote the content entirely.
		$long   = str_repeat( 'a,', 80 ) . str_repeat( 'z,', 80 );
		$params = $probe->normalize_points_params_public( [ 'types' => $long ] );

		$this->assertSame( substr( $long, 0, 128 ), $params['types'] );
		$this->assertSame( 128, strlen( $params['types'] ) );
		$this->assertStringNotContainsString( 'z', $params['types'] );
	}

	public function test_register_routes_declares_types_as_a_sanitized_string_arg(): void {
		$registered = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args;
			}
		);

		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source(),
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);
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
		$controller = new Pickup_Controller_Probe(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
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
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
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

		$controller = new Pickup_Controller(
			'test-plugin',
			$this->source(),
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);
		$controller->register_routes();

		$this->assertCount( 3, $registered );

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

	// ---- POST .../select — the selection round-trip and its domain seam ----

	/**
	 * A source that resolves exactly one point by id and lists nothing — the shape every
	 * select-route test needs, since the select route never lists.
	 *
	 * @param Pickup_Point|null $point what fetch_details() returns for any id.
	 */
	private function details_source( ?Pickup_Point $point ): Point_Source {
		return new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static fn( Point_Query $query ) => [],
			static fn( string $id ) => $point
		);
	}

	/**
	 * Builds a controller over {@see self::details_source()} with each injected callable
	 * pinned to a distinguishable value, so a test asserting on the filter context can tell
	 * which callable produced which key.
	 *
	 * @param Pickup_Point|null $point          what the source resolves.
	 * @param int               $cart_weight    what the cart-weight callable returns (GRAMS).
	 * @param string            $payment_method what the payment-method callable returns.
	 * @param string            $method_id      what the shipping-method callable returns.
	 */
	private function select_controller(
		?Pickup_Point $point,
		int $cart_weight = 0,
		string $payment_method = 'bacs',
		string $method_id = 'carrier_pickup'
	): Pickup_Controller_Probe {
		return new Pickup_Controller_Probe(
			'test-plugin',
			$this->details_source( $point ),
			static fn() => $cart_weight,
			static fn() => $payment_method,
			static fn() => $method_id
		);
	}

	/**
	 * Captures the routes register_routes() declares, keyed by route path.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function registered_routes( Pickup_Controller $controller ): array {
		$registered = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args;
			}
		);

		$controller->register_routes();

		return $registered;
	}

	public function test_the_select_route_is_a_post_behind_a_real_permission_callback(): void {
		$controller = $this->select_controller( $this->point() );
		$registered = $this->registered_routes( $controller );

		$this->assertArrayHasKey( '/shipping/pickup/test-plugin/select', $registered );

		$endpoint = $registered['/shipping/pickup/test-plugin/select'][0];

		$this->assertSame( 'POST', $endpoint['methods'] );
		$this->assertSame( [ $controller, 'handle_select_request' ], $endpoint['callback'] );

		// The two GET reads are declared `__return_true`; this one must NOT be — an
		// unguarded POST is a way to burn the merchant's carrier quota through a
		// visitor's browser (see Pickup_Controller::check_select_permission()).
		$this->assertNotSame( '__return_true', $endpoint['permission_callback'] );
		$this->assertSame( [ $controller, 'check_select_permission' ], $endpoint['permission_callback'] );

		$this->assertTrue( $endpoint['args']['field_id']['required'] );
		$this->assertTrue( $endpoint['args']['point_id']['required'] );

		// The browser must not be able to ASSERT which shipping method it is checking out
		// with — that comes from the injected callable, never from a request param.
		$this->assertArrayNotHasKey( 'method_id', $endpoint['args'] );
	}

	public function test_the_select_route_declares_both_ids_as_non_empty(): void {
		// REGRESSION (Codex finding 5): `required => true, type => string` accepts `''`,
		// which sailed through to fetch_details( '' ) — a carrier call for nothing.
		$endpoint = $this->registered_routes(
			$this->select_controller( $this->point() )
		)['/shipping/pickup/test-plugin/select'][0];

		$this->assertSame( 1, $endpoint['args']['point_id']['minLength'] );
		$this->assertSame( 1, $endpoint['args']['field_id']['minLength'] );
	}

	/**
	 * @dataProvider provide_empty_select_ids
	 *
	 * @param array<string, mixed> $params the select route's two params.
	 */
	public function test_an_empty_id_is_refused_before_the_carrier_is_called( array $params ): void {
		// The schema's `minLength` is WordPress's own gate and only applies to a REST-dispatched
		// request; this is the controller's own, which also catches a value that survives the
		// schema but cleans down to nothing (a whitespace-only or control-character id).
		$called = 0;

		$controller = new Pickup_Controller_Probe(
			'test-plugin',
			new Pickup_Controller_Test_Source(
				Point_Source::STRATEGY_BULK,
				static fn( Point_Query $query ) => [],
				function ( string $id ) use ( &$called ) {
					++$called;
					return $this->point();
				}
			),
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$result = $controller->handle_select_request( new WP_REST_Request( $params ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_pickup_invalid_selection', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 0, $called, 'the carrier must not be called for an empty id' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function provide_empty_select_ids(): array {
		return [
			'empty point id'          => [ [ 'field_id' => 'pvz', 'point_id' => '' ] ],
			'whitespace-only point id' => [ [ 'field_id' => 'pvz', 'point_id' => '   ' ] ],
			'missing point id'        => [ [ 'field_id' => 'pvz' ] ],
			'empty field id'          => [ [ 'field_id' => '', 'point_id' => 'P1' ] ],
			'missing field id'        => [ [ 'point_id' => 'P1' ] ],
			'both missing'            => [ [] ],
		];
	}

	public function test_the_empty_id_guard_runs_after_the_rate_limit_check(): void {
		// Ordering matters: a throttled caller must read 429, not 400, or the budget it just
		// exhausted becomes invisible to it.
		$probe  = new Pickup_Controller_Limited_Probe(
			'test-plugin',
			$this->details_source( $this->point() ),
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);
		$result = $probe->handle_select_request( new WP_REST_Request( [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_pickup_rate_limited', $result->get_error_code() );
	}

	public function test_a_missing_nonce_header_is_refused(): void {
		// wp_verify_nonce() is deliberately NOT stubbed: reaching it with no header at all
		// would be a call to an undefined function, so this also pins the short-circuit.
		$result = $this->select_controller( $this->point() )->check_select_permission( new WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_pickup_invalid_nonce', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_a_nonce_for_the_wrong_action_is_refused(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$request = new WP_REST_Request( [], [ 'X-WP-Nonce' => 'nonce-for-another-action' ] );
		$result  = $this->select_controller( $this->point() )->check_select_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_pickup_invalid_nonce', $result->get_error_code() );
	}

	public function test_a_valid_nonce_passes_the_permission_gate(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$request = new WP_REST_Request( [], [ 'X-WP-Nonce' => 'good' ] );

		$this->assertTrue( $this->select_controller( $this->point() )->check_select_permission( $request ) );
	}

	public function test_with_no_filter_attached_the_response_is_the_framework_verdict_alone(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$result = $this->select_controller( $this->point() )->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertSame(
			[
				'allowed'          => true,
				'reason'           => null,
				'close'            => null,
				'refresh_checkout' => null,
				'point'            => null,
			],
			$result,
			'an unspoken flag must be null — "the domain did not speak", not a decision'
		);
	}

	public function test_the_verdict_is_recomputed_against_the_current_cart_not_what_the_browser_saw(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// The cart grew past the point's limit between drawing the map and confirming.
		$controller = $this->select_controller( $this->point( [ 'max_weight' => 1000 ] ), 2000 );

		$result = $controller->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertFalse( $result['allowed'] );
		$this->assertNotNull( $result['reason'] );
	}

	/**
	 * The load-bearing case. `close`/`refresh_checkout` are THREE-STATE, and an explicit
	 * `false` is a decision the browser must honour — collapsing it into `null` would hand
	 * control back to the plugin's configured default the domain just overrode. Asserted
	 * with assertSame(), never assertFalse()/assertEmpty(), so a mutant that returns `null`
	 * here fails instead of passing.
	 */
	public function test_a_filter_can_refuse_and_an_explicit_false_survives_as_false(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();

		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->once()->andReturn(
			[
				'allowed'          => false,
				'reason'           => 'Этот пункт не принимает негабаритные отправления.',
				'close'            => false,
				'refresh_checkout' => false,
			]
		);

		$result = $this->select_controller( $this->point() )->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'Этот пункт не принимает негабаритные отправления.', $result['reason'] );
		$this->assertSame( false, $result['close'], 'an explicit false must not collapse into null' );
		$this->assertSame( false, $result['refresh_checkout'], 'an explicit false must not collapse into null' );
	}

	public function test_an_unknown_point_id_yields_a_404(): void {
		$result = $this->select_controller( null )->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'gone' ] )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_pickup_point_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_a_carrier_failure_becomes_a_502_not_a_fatal(): void {
		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static fn( Point_Query $query ) => [],
			static function ( string $id ) {
				throw new \Woodev_API_Exception( 'https://carrier.example/secret?token=abc123' );
			}
		);
		$controller = new Pickup_Controller_Probe(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$result = $controller->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'carrier.example', $result->get_error_message() );
		$this->assertCount( 1, $controller->logged_failures );
	}

	/**
	 * The guard must fire BEFORE the point lookup — a 429 that has already spent a carrier
	 * call has protected nothing. Asserted by the source's own call counter, not just by
	 * the returned error, so a guard moved below the lookup fails here.
	 */
	public function test_a_throttled_selection_never_reaches_the_carrier(): void {
		$fetches = 0;
		$source  = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static fn( Point_Query $query ) => [],
			function ( string $id ) use ( &$fetches ) {
				++$fetches;

				return $this->point();
			}
		);
		$controller = new Pickup_Controller_Limited_Probe(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$result = $controller->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_pickup_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame( 0, $fetches, 'a throttled selection must not reach fetch_details()' );
	}

	/**
	 * The selection route must draw on its OWN bucket and its OWN budget — sharing a
	 * sibling's prefix would let map panning exhaust the confirmation allowance (or the
	 * reverse), which is the failure the trait's per-workload prefixes exist to prevent.
	 */
	public function test_the_selection_route_uses_its_own_rate_limit_bucket(): void {
		$controller = new Pickup_Controller_Limited_Probe(
			'test-plugin',
			$this->details_source( $this->point() ),
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$controller->handle_select_request( new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] ) );

		$this->assertCount( 1, $controller->limit_checks );
		$this->assertSame( 'woodev_pickup_sel_rl_', $controller->limit_checks[0]['prefix'] );

		// Tighter than the detail read's 60 — see SELECT_RATE_LIMIT_MAX's own docblock.
		$this->assertSame( 15, $controller->limit_checks[0]['max'] );
		$this->assertLessThan( 60, $controller->limit_checks[0]['max'] );
	}

	public function test_the_filter_context_carries_the_injected_method_id_never_a_request_param(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();

		$captured = null;

		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->once()->andReturnUsing(
			static function ( $computed, $point, $context ) use ( &$captured ) {
				$captured = $context;

				return $computed;
			}
		);

		$controller = $this->select_controller( $this->point(), 750, 'cod', 'carrier_pickup' );

		// `method_id` IS posted — and must be ignored. A browser that could assert which
		// method it is checking out with could talk the domain filter into approving a
		// point for a method the customer never chose.
		$controller->handle_select_request(
			new WP_REST_Request(
				[ 'field_id' => 'pvz', 'point_id' => 'P1', 'method_id' => 'attacker_method' ]
			)
		);

		$this->assertIsArray( $captured );
		$this->assertSame( 'carrier_pickup', $captured['method_id'] );
		$this->assertSame( 'pvz', $captured['field_id'] );
		$this->assertSame( 'cod', $captured['payment_method'] );
		$this->assertSame( 750, $captured['cart_weight'] );
	}

	// -------------------------------------------------------------------------
	// woodev_shipping_pickup_point_selected — the pickup-selection-persistence
	// write seam (issue #176). Fired AFTER the domain filter and AFTER
	// Selection_Result::sanitize() has resolved the FINAL verdict, and ONLY when
	// that verdict is allowed === true.
	// -------------------------------------------------------------------------

	/**
	 * The baseline: an allowed selection with no domain filter attached fires the
	 * action exactly once, with the resolved point and the same context shape the
	 * `woodev_shipping_pickup_point_selection` filter itself receives.
	 */
	public function test_an_allowed_selection_fires_the_selected_action(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$captured_point   = null;
		$captured_context = null;

		Actions\expectDone( 'woodev_shipping_pickup_point_selected' )->once()->whenHappen(
			static function ( $point, $context ) use ( &$captured_point, &$captured_context ) {
				$captured_point   = $point;
				$captured_context = $context;
			}
		);

		$this->select_controller( $this->point(), 750, 'cod', 'carrier_pickup' )->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertInstanceOf( Pickup_Point::class, $captured_point );
		$this->assertSame( 'P1', $captured_point->get_id() );
		$this->assertIsArray( $captured_context );
		$this->assertSame( 'pvz', $captured_context['field_id'] );
		$this->assertSame( 'carrier_pickup', $captured_context['method_id'] );
		$this->assertSame( 'cod', $captured_context['payment_method'] );
		$this->assertSame( 750, $captured_context['cart_weight'] );
	}

	/**
	 * THE load-bearing case (coordinator guidance, issue #176): a point the
	 * FRAMEWORK's own {@see \Woodev\Framework\Shipping\Pickup\Constraint_Checker}
	 * verdict already allows, but that a domain filter on
	 * `woodev_shipping_pickup_point_selection` FLIPS to refused, must never fire
	 * the selected action — a persistence write gated on `$computed` (the
	 * pre-filter verdict) instead of the sanitized, post-filter result would
	 * remember a point the domain just refused.
	 */
	public function test_a_domain_filter_refusing_the_point_prevents_the_selected_action(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();

		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->once()->andReturn(
			[
				'allowed'          => false,
				'reason'           => 'Этот пункт не принимает негабаритные отправления.',
				'close'            => null,
				'refresh_checkout' => null,
			]
		);

		Actions\expectDone( 'woodev_shipping_pickup_point_selected' )->never();

		$result = $this->select_controller( $this->point() )->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		// Sanity: the refusal itself really did happen — a mutant that always allows
		// would make the `never()` assertion above pass for the wrong reason.
		$this->assertFalse( $result['allowed'] );
	}

	/**
	 * The mirror case: the FRAMEWORK's own verdict refuses (cart too heavy for the
	 * point), and a domain filter FLIPS it to allowed. The action must still fire —
	 * gating must read the FINAL sanitized verdict, never `$computed`.
	 */
	public function test_a_domain_filter_overriding_a_refusal_to_allowed_fires_the_selected_action(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();

		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->once()->andReturn(
			[
				'allowed'          => true,
				'reason'           => null,
				'close'            => null,
				'refresh_checkout' => null,
			]
		);

		Actions\expectDone( 'woodev_shipping_pickup_point_selected' )->once();

		// The framework's own verdict refuses: the point caps weight at 1000 g, the
		// cart weighs 2000 g.
		$result = $this->select_controller( $this->point( [ 'max_weight' => 1000 ] ), 2000 )->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertTrue( $result['allowed'] );
	}

	/**
	 * A domain filter is contractually allowed to return a CORRECTED point, and the
	 * browser then REPLACES the point it holds with that one — so the address
	 * replacement, and with it whatever the checkout later reports as the current
	 * locality, follow the CORRECTED point. The action must therefore carry the
	 * corrected point too: a listener filing the selection under the pre-filter
	 * point's locality would key it under a locality the checkout no longer reports,
	 * and the restore would miss with nothing logged and nothing thrown.
	 *
	 * Note the deliberate divergence in BOTH `id` and `locality` — asserting only the
	 * locality would leave a mutant that forwards `$point` but re-reads its locality
	 * from somewhere else alive.
	 */
	public function test_the_selected_action_carries_a_domain_corrected_point(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();

		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->once()->andReturn(
			[
				'allowed'          => true,
				'reason'           => null,
				'close'            => null,
				'refresh_checkout' => null,
				'point'            => [
					'id'       => 'P1-CORRECTED',
					'name'     => 'Точка',
					'lat'      => 55.75,
					'lng'      => 37.61,
					'address'  => 'Химки, Ленинский проспект, 1',
					'locality' => 'Химки',
					'type'     => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
				],
			]
		);

		$captured_point = null;

		Actions\expectDone( 'woodev_shipping_pickup_point_selected' )->once()->whenHappen(
			static function ( $point ) use ( &$captured_point ) {
				$captured_point = $point;
			}
		);

		// The pre-filter point is 'P1' in Москва — see self::point().
		$this->select_controller( $this->point( [ 'locality' => 'Москва' ] ) )->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertInstanceOf( Pickup_Point::class, $captured_point );
		$this->assertSame( 'P1-CORRECTED', $captured_point->get_id() );
		$this->assertSame( 'Химки', $captured_point->to_array()['locality'] );
	}

	/**
	 * The other half of the same rule: a corrected point that does NOT validate is
	 * dropped by {@see \Woodev\Framework\Shipping\Pickup\Selection_Result::sanitize_point()}
	 * ("nothing to update, keep the point you already have"), so the browser keeps the
	 * point it holds — and the action must keep the pre-filter point in step with it,
	 * not fall through to a half-adopted correction.
	 */
	public function test_the_selected_action_ignores_an_invalid_domain_correction(): void {
		Functions\when( 'rest_ensure_response' )->returnArg();

		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->once()->andReturn(
			[
				'allowed'          => true,
				'reason'           => null,
				'close'            => null,
				'refresh_checkout' => null,
				// No `address`, no `type` — Pickup_Point::from_array() refuses it.
				'point'            => [ 'id' => 'P1-BROKEN', 'name' => 'Точка' ],
			]
		);

		$captured_point = null;

		Actions\expectDone( 'woodev_shipping_pickup_point_selected' )->once()->whenHappen(
			static function ( $point ) use ( &$captured_point ) {
				$captured_point = $point;
			}
		);

		$this->select_controller( $this->point( [ 'locality' => 'Москва' ] ) )->handle_select_request(
			new WP_REST_Request( [ 'field_id' => 'pvz', 'point_id' => 'P1' ] )
		);

		$this->assertInstanceOf( Pickup_Point::class, $captured_point );
		$this->assertSame( 'P1', $captured_point->get_id() );
		$this->assertSame( 'Москва', $captured_point->to_array()['locality'] );
	}

	// -------------------------------------------------------------------
	// Task 15 (issue #159): location context enrichment — the seam between the
	// framework's own Location Provider layer and a plugin's Point_Source. This is the
	// SERVER-SIDE half of the server<->client join: the client sends the locality KEY as
	// `locality` (opaque to Point_Query, unchanged shape), and the controller separately
	// attaches the SAME customer's full record + this plugin's resolved identity, exactly
	// as a real Pickup_Handler::location_context() callable would (see
	// PickupHandlerTest::test_get_js_config_emits_the_current_locality_key_when_a_plugin_is_wired()
	// for the CLIENT-facing half of the same join — the shape `location.current.key` on the
	// JS config and the shape this controller reads off `$location_context()` are the SAME
	// {@see Location_Record}, proven by sharing the fixture builder between both test files'
	// equivalent).
	// -------------------------------------------------------------------

	private function location_record( string $key = 'dadata:fias-1' ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $key,
				'provider_id' => explode( ':', $key )[0],
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
			]
		);
	}

	public function test_a_bulk_source_receives_the_record_and_resolved_identity_through_the_query(): void {
		$record  = $this->location_record();
		$queries = [];

		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) use ( &$queries ) {
				$queries[] = $query;
				return [];
			},
			static fn( string $id ) => null
		);

		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup',
			static fn() => [ 'record' => $record, 'resolved_identity' => 'carrier-city-77' ]
		);

		$controller->get_points_data( [ 'locality' => 'dadata:fias-1' ] );

		$this->assertCount( 1, $queries );
		$this->assertSame( $record, $queries[0]->get_record() );
		$this->assertSame( 'carrier-city-77', $queries[0]->get_resolved_identity() );
	}

	public function test_a_viewport_source_also_receives_the_location_context(): void {
		// Task 15's own scope: the record/resolved-identity enrichment is attached for
		// EITHER strategy, not only bulk — the viewport (bbox) addressing path itself is
		// what must stay untouched, not the enrichment.
		$record  = $this->location_record();
		$queries = [];

		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_VIEWPORT,
			static function ( Point_Query $query ) use ( &$queries ) {
				$queries[] = $query;
				return [];
			},
			static fn( string $id ) => null
		);

		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup',
			static fn() => [ 'record' => $record, 'resolved_identity' => null ]
		);

		$controller->get_points_data( [ 'bbox' => '55.70,37.50,55.80,37.70' ] );

		$this->assertCount( 1, $queries );
		$this->assertSame( [ 55.70, 37.50, 55.80, 37.70 ], $queries[0]->get_bounds() );
		$this->assertSame( $record, $queries[0]->get_record() );
		$this->assertNull( $queries[0]->get_resolved_identity() );
	}

	public function test_no_location_context_callable_leaves_the_query_bare_exactly_as_before(): void {
		// Backward compatibility: a plugin that has NOT wired the Location Provider layer
		// (the 5-argument constructor form, unchanged) must see the query completely
		// untouched — this is what every EXISTING test in this file above already proves
		// implicitly, made explicit here as the one dedicated regression guard.
		$captured = null;

		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			},
			static fn( string $id ) => null
		);

		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertNotNull( $captured );
		$this->assertNull( $captured->get_record() );
		$this->assertNull( $captured->get_resolved_identity() );
	}

	public function test_a_location_context_answering_null_leaves_the_query_bare(): void {
		// A legitimate "no current record yet" answer (Pickup_Handler::location_context()'s
		// own docblock) — must degrade the same as no callable at all, never fatal or
		// attach a garbage record.
		$captured = null;

		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			},
			static fn( string $id ) => null
		);

		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup',
			static fn() => null
		);

		$controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertNotNull( $captured );
		$this->assertNull( $captured->get_record() );
	}

	// -------------------------------------------------------------------
	// Review finding F7 (issue #159 PR #312): attach_location_context()'s own defensive
	// branch — `! is_array( $context ) || ! ( $context['record'] ?? null ) instanceof
	// Location_Record` — had no test. This is a PUBLIC, guest-facing route; a plugin's own
	// `$location_context` callable misbehaving (a bug in the plugin's own code, not this
	// framework's) must degrade the query to bare, never fatal on a bad shape.
	// -------------------------------------------------------------------

	public function test_a_non_array_location_context_leaves_the_query_bare(): void {
		$captured = null;

		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			},
			static fn( string $id ) => null
		);

		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup',
			// A misbehaving plugin callable answering a scalar, not the documented
			// array{record, resolved_identity} shape.
			static fn() => 'not-an-array'
		);

		$controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertNotNull( $captured );
		$this->assertNull( $captured->get_record() );
		$this->assertNull( $captured->get_resolved_identity() );
	}

	public function test_a_location_context_with_a_non_record_leaves_the_query_bare(): void {
		$captured = null;

		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			},
			static fn( string $id ) => null
		);

		$controller = new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup',
			// The right shape ('record' key present) but the wrong type — never a
			// Location_Record. A mutant that drops the `instanceof` half of the guard
			// would let this through to Point_Query::with_location(), which types its
			// own parameter as Location_Record and would fatal.
			static fn() => [ 'record' => 'dadata:fias-1', 'resolved_identity' => null ]
		);

		$controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertNotNull( $captured );
		$this->assertNull( $captured->get_record() );
		$this->assertNull( $captured->get_resolved_identity() );
	}

	// -------------------------------------------------------------------------
	// log_carrier_failure() — a foreign exception message is redacted before it
	// reaches error_log(), through Woodev_API_Base::redact_secret_log_text() (#585).
	// Uses Pickup_Controller_Log_Redaction_Probe (real log_carrier_failure(), only
	// the rate limiter bypassed) — NOT Pickup_Controller_Probe, whose overridden
	// log_carrier_failure() would bypass the code under test.
	// -------------------------------------------------------------------------

	public function test_log_carrier_failure_redacts_a_secret_in_a_foreign_exception_message(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$captured = null;
		Functions\expect( 'error_log' )
			->once()
			->with(
				\Mockery::on(
					static function ( $message ) use ( &$captured ) {
						$captured = $message;
						return true;
					}
				)
			);

		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) {
				throw new \Woodev_API_Exception( 'carrier rejected api_key=LIVESECRET' );
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller_Log_Redaction_Probe(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$controller->handle_points_request( new WP_REST_Request( [ 'locality' => 'Москва' ] ) );

		$this->assertSame(
			'[woodev] pickup points fetch failed for plugin "test-plugin": carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
			$captured
		);
	}

	/**
	 * Control: an exception message carrying NO secret must reach the
	 * rendered error_log() line byte-for-byte — asserted on the COMPLETE
	 * rendered line, not merely a substring, so a redactor that mangled
	 * anything else in the line could not pass silently.
	 */
	public function test_log_carrier_failure_leaves_a_message_without_a_secret_untouched(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$captured = null;
		Functions\expect( 'error_log' )
			->once()
			->with(
				\Mockery::on(
					static function ( $message ) use ( &$captured ) {
						$captured = $message;
						return true;
					}
				)
			);

		$source = new Pickup_Controller_Test_Source(
			Point_Source::STRATEGY_BULK,
			static function ( Point_Query $query ) {
				throw new \Woodev_API_Exception( 'carrier unreachable' );
			},
			static fn( string $id ) => null
		);
		$controller = new Pickup_Controller_Log_Redaction_Probe(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);

		$controller->handle_points_request( new WP_REST_Request( [ 'locality' => 'Москва' ] ) );

		$this->assertSame(
			'[woodev] pickup points fetch failed for plugin "test-plugin": carrier unreachable',
			$captured
		);
	}
}
