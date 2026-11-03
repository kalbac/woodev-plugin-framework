<?php
/**
 * Unit: the analytics route (#711, spec §4) — the HTTP half.
 *
 * Covers the route table and its gate (the page capability, a plain boolean for the REST layer to turn into 403), the argument
 * schema, the date validator, and the 400s: reversed range, range too wide, a half compare pair, an unknown carrier. The SQL and
 * the arithmetic have their own tests; here the cache is a stand-in, so a hit never reaches the database.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\{Orders_Provider, Orders_Registry};
use Woodev\Framework\Shipping\Rest_Api\Analytics_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Analytics_Controller
 */
final class AnalyticsControllerTest extends TestCase {

	/** @var array<string,mixed> */
	private $transients = [];

	protected function setUp(): void {
		parent::setUp();

		$this->transients = [];

		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'start_of_week' === $key ? 1 : $default );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/Moscow' ) );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
	}

	private function controller( bool $can = true ): Analytics_Controller {
		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldReceive( 'get_page_capability' )->andReturn( 'manage_woocommerce' );
		$registry->shouldReceive( 'get_providers' )->andReturn( [ Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] ) ] );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => $can && 'manage_woocommerce' === $cap );

		return new Analytics_Controller( $registry );
	}

	private function request( array $params ): \WP_REST_Request {
		return new \WP_REST_Request( $params );
	}

	public function test_the_route_is_additive_under_the_shipping_orders_namespace(): void {
		$route = null;
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $path, $args ) use ( &$route ) {
				$route = [ $namespace, $path, $args ];
			}
		);

		$this->controller()->register_routes();

		$this->assertSame( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE, $route[0] );
		$this->assertSame( '/shipping/orders/analytics', $route[1] );
		$this->assertSame( 'GET', $route[2]['methods'] );
		$this->assertSame( [ 'after', 'before', 'compare_after', 'compare_before', 'interval', 'carrier', 'refresh' ], array_keys( $route[2]['args'] ) );
		$this->assertTrue( $route[2]['args']['after']['required'] );
		$this->assertTrue( $route[2]['args']['before']['required'] );
		$this->assertSame( [ 'day', 'week', 'month', 'auto' ], $route[2]['args']['interval']['enum'] );
	}

	public function test_the_gate_is_the_page_capability(): void {
		$this->assertTrue( $this->controller( true )->get_permissions_check() );
		$this->assertFalse( $this->controller( false )->get_permissions_check() );
	}

	/**
	 * @dataProvider date_cases
	 */
	public function test_validate_date( $value, bool $valid ): void {
		$this->assertSame( $valid, Analytics_Controller::validate_date( $value ) );
	}

	public static function date_cases(): array {
		return [
			'a real day'        => [ '2026-10-31', true ],
			'a leap day'        => [ '2028-02-29', true ],
			'not a leap year'   => [ '2026-02-29', false ],
			'a made-up month'   => [ '2026-13-01', false ],
			'a timestamp'       => [ '2026-10-31T10:00:00', false ],
			'empty is optional' => [ '', true ],
			'null is optional'  => [ null, true ],
			'an array'          => [ [ '2026-10-31' ], false ],
		];
	}

	/**
	 * @dataProvider bad_requests
	 */
	public function test_a_bad_request_is_a_400_and_reaches_no_cache_or_query( array $params, string $code ): void {
		$result = $this->controller()->get_report( $this->request( $params ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->code );
		$this->assertSame( 400, $result->data['status'] );
		$this->assertSame( [], $this->transients );
	}

	public static function bad_requests(): array {
		return [
			'reversed range'      => [ [ 'after' => '2026-10-31', 'before' => '2026-10-01' ], 'woodev_shipping_analytics_bad_range' ],
			'missing after'       => [ [ 'before' => '2026-10-01' ], 'woodev_shipping_analytics_bad_range' ],
			'range too wide'      => [ [ 'after' => '2024-01-01', 'before' => '2026-10-01' ], 'woodev_shipping_analytics_range_too_wide' ],
			'half a compare pair' => [ [ 'after' => '2026-10-01', 'before' => '2026-10-31', 'compare_after' => '2026-09-01' ], 'woodev_shipping_analytics_bad_range' ],
			'reversed compare'    => [ [ 'after' => '2026-10-01', 'before' => '2026-10-31', 'compare_after' => '2026-09-30', 'compare_before' => '2026-09-01' ], 'woodev_shipping_analytics_bad_range' ],
			'unknown carrier'     => [ [ 'after' => '2026-10-01', 'before' => '2026-10-31', 'carrier' => 'nobody' ], 'woodev_shipping_analytics_unknown_carrier' ],
		];
	}

	public function test_a_stored_answer_is_served_without_building(): void {
		Functions\when( 'get_transient' )->justReturn( [ 'totals' => 'stored' ] );

		$result = $this->controller()->get_report( $this->request( [ 'after' => '2026-01-01', 'before' => '2026-01-31', 'carrier' => 'all' ] ) );

		$this->assertSame( [ 'totals' => 'stored', 'cached' => true ], $result );
	}

	public function test_the_method_label_falls_back_to_the_id_without_woocommerce(): void {
		$this->assertSame( 'cdek_door', $this->controller()->method_label( 'cdek_door' ) );
	}

	/**
	 * Rig smoke (#711): a failing statement used to come back as «no orders», get cached and be drawn as zeros — with the database's HTML
	 * in the body. It is now a 500 with a generic sentence, nothing is stored, and the database's text goes to the log only.
	 */
	public function test_a_failing_query_is_a_500_that_is_never_cached_and_leaks_no_database_text(): void {
		$logged = [];
		Functions\when( 'error_log' )->alias(
			static function ( $line ) use ( &$logged ) {
				$logged[] = $line;
			}
		);
		$GLOBALS['wpdb'] = new class() {
			public $prefix     = 'wp_';
			public $posts      = 'wp_posts';
			public $postmeta   = 'wp_postmeta';
			public $last_error = '';

			public function suppress_errors( $suppress = true ) {
				return false;
			}

			public function prepare( $sql, $args ) {
				return 'PREPARED';
			}

			public function get_results( $query, $output ) {
				$this->last_error = "You have an error in your SQL syntax near '2026-10-31'";

				return null;
			}
		};

		$result = $this->controller()->get_report( $this->request( [ 'after' => '2026-10-01', 'before' => '2026-10-31' ] ) );

		unset( $GLOBALS['wpdb'] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_shipping_analytics_failed', $result->code );
		$this->assertSame( 500, $result->data['status'] );
		$this->assertStringNotContainsString( 'SQL', $result->message );
		$this->assertSame( [], $this->transients, 'a failed read is not stored as an answer' );
		$this->assertStringContainsString( 'SQL syntax', $logged[0], 'the database text reaches the server log' );
	}
}
