<?php
/**
 * Tests for the ADMIN pickup routes on Pickup_Controller (#959, spec
 * `2026-09-27-710-create-edit-order-design.md` D3): the list and point-detail routes the
 * admin order wizard calls with an EXPLICIT context — weight, payment method and
 * destination record — instead of the cart/session/visitor chain, which are null in an
 * admin REST request.
 *
 * The public routes' own behaviour stays covered by {@see PickupControllerTest}.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use WP_Error;
use WP_REST_Request;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Pickup\Location_Aware_Point_Source;
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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-location-aware-point-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-selection-result.php';

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/http/trait-rest-rate-limit.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-pickup-controller.php';

/**
 * A {@see Point_Source} double over injected closures.
 */
final class Pickup_Admin_Test_Source implements Point_Source {

	/** @var callable */
	private $points_provider;

	/** @var callable */
	private $details_provider;

	/** @var string */
	private string $strategy;

	public function __construct( callable $points_provider, callable $details_provider, string $strategy = Point_Source::STRATEGY_BULK ) {
		$this->points_provider  = $points_provider;
		$this->details_provider = $details_provider;
		$this->strategy         = $strategy;
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
 * A {@see Location_Aware_Point_Source} double whose points DEPEND on the destination: the
 * weight limit is `1000` g when the carrier identity is `strict`, unlimited otherwise.
 * The list and the detail build the point through the SAME rule, from the identity each
 * one is handed.
 */
final class Pickup_Admin_Test_Location_Source implements Location_Aware_Point_Source {

	/** @var array<int, string> what fetch_details_for() was called with, as `id|key|identity`. */
	public array $detail_calls = [];

	/** @var int how many times the plain fetch_details() ran. */
	public int $plain_detail_calls = 0;

	public function get_strategy(): string {
		return Point_Source::STRATEGY_BULK;
	}

	public function fetch_points( Point_Query $query ): array {
		return [ $this->point_for( $query->get_resolved_identity() ) ];
	}

	public function fetch_details( string $point_id ): ?Pickup_Point {
		++$this->plain_detail_calls;

		return $this->point_for( null );
	}

	public function fetch_details_for( string $point_id, Location_Record $record, $resolved_identity ): ?Pickup_Point {
		$this->detail_calls[] = $point_id . '|' . $record->key() . '|' . (string) $resolved_identity;

		return $this->point_for( $resolved_identity );
	}

	/**
	 * @param mixed $identity
	 */
	private function point_for( $identity ): Pickup_Point {
		return Pickup_Point::from_array(
			[
				'id'         => 'P1',
				'name'       => 'Точка',
				'lat'        => 55.75,
				'lng'        => 37.61,
				'address'    => 'Москва',
				'type'       => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
				'max_weight' => 'strict' === $identity ? 1000 : 0,
			]
		);
	}
}

/**
 * Silences the carrier-failure log and records it instead.
 */
final class Pickup_Admin_Test_Controller extends Pickup_Controller {

	/** @var array<int, string> */
	public array $logged_contexts = [];

	protected function log_carrier_failure( \Woodev_API_Exception $e, string $context ): void {
		$this->logged_contexts[] = $context;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller
 */
final class PickupControllerAdminRoutesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);
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
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'rest_ensure_response' )->returnArg();
	}

	/**
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

	/**
	 * Callables that fail the test if the admin path ever touches them — the whole point
	 * of the explicit context is that the cart/session readers are not consulted.
	 */
	private static function forbidden( string $what ): callable {
		return static function () use ( $what ) {
			throw new \LogicException( "The admin route must not read {$what} from the cart/session." );
		};
	}

	/**
	 * @param callable|null $location_resolver
	 */
	private function controller( Point_Source $source, ?callable $location_resolver = null ): Pickup_Admin_Test_Controller {
		return new Pickup_Admin_Test_Controller(
			'test-plugin',
			$source,
			self::forbidden( 'the cart weight' ),
			self::forbidden( 'the payment method' ),
			self::forbidden( 'the shipping method' ),
			self::forbidden( 'the visitor location' ),
			$location_resolver
		);
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function admin_routes( Pickup_Controller $controller ): array {
		$registered = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = [ 'namespace' => $namespace, 'endpoints' => $args ];
			}
		);

		$controller->register_admin_routes();

		return $registered;
	}

	// ---- route declaration ----

	public function test_register_admin_routes_declares_the_list_and_detail_routes_under_the_orders_path(): void {
		$routes = $this->admin_routes( $this->controller( new Pickup_Admin_Test_Source( static fn() => [], static fn() => null ) ) );

		$this->assertSame(
			[
				'/shipping/orders/pickup/test-plugin/points',
				'/shipping/orders/pickup/test-plugin/points/(?P<id>[^/]+)',
			],
			array_keys( $routes )
		);

		foreach ( $routes as $route ) {
			$this->assertSame( 'woodev/v1', $route['namespace'] );
			$this->assertCount( 1, $route['endpoints'] );
			$this->assertSame( 'GET', $route['endpoints'][0]['methods'] );
		}
	}

	public function test_the_admin_routes_are_behind_the_capability_check_never_public(): void {
		$routes = $this->admin_routes( $this->controller( new Pickup_Admin_Test_Source( static fn() => [], static fn() => null ) ) );

		foreach ( $routes as $route => $declared ) {
			$permission = $declared['endpoints'][0]['permission_callback'];

			$this->assertNotSame( '__return_true', $permission, "$route must not be public" );
			$this->assertIsCallable( $permission );
		}
	}

	public function test_the_permission_callback_requires_edit_shop_orders(): void {
		$controller = $this->controller( new Pickup_Admin_Test_Source( static fn() => [], static fn() => null ) );

		Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( true );
		$this->assertTrue( $controller->check_admin_permission() );

		Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( false );
		$this->assertFalse( $controller->check_admin_permission() );
	}

	public function test_every_admin_route_arg_declares_a_validate_callback_and_the_context_args_exist(): void {
		$routes = $this->admin_routes( $this->controller( new Pickup_Admin_Test_Source( static fn() => [], static fn() => null ) ) );

		foreach ( $routes as $route => $declared ) {
			$args = $declared['endpoints'][0]['args'];

			foreach ( [ 'weight', 'payment_method' ] as $context_arg ) {
				$this->assertArrayHasKey( $context_arg, $args, "$route must take $context_arg explicitly" );
			}

			foreach ( $args as $name => $schema ) {
				$this->assertSame( 'rest_validate_request_arg', $schema['validate_callback'], "$route arg $name" );
			}
		}

		$list_args = $routes['/shipping/orders/pickup/test-plugin/points']['endpoints'][0]['args'];
		$this->assertArrayHasKey( 'location', $list_args );
		$this->assertSame( 'object', $list_args['location']['type'] );

		$detail_args = $routes['/shipping/orders/pickup/test-plugin/points/(?P<id>[^/]+)']['endpoints'][0]['args'];
		$this->assertArrayHasKey( 'location', $detail_args, 'spec D3: the detail takes the explicit record too' );
		$this->assertSame( $list_args['location'], $detail_args['location'], 'one schema for both routes' );
	}

	// ---- the explicit context reaches the verdict; the public callables are never read ----

	public function test_the_list_takes_weight_and_payment_method_from_the_request(): void {
		$heavy = $this->point( [ 'id' => 'P1', 'max_weight' => 1000 ] );
		$cod   = $this->point( [ 'id' => 'P2', 'accepts_cod' => false ] );

		$controller = $this->controller( new Pickup_Admin_Test_Source( static fn() => [ $heavy, $cod ], static fn() => null ) );

		$response = $controller->handle_admin_points_request(
			new WP_REST_Request( [ 'locality' => 'dadata:fias-1', 'weight' => 2000, 'payment_method' => 'cod' ] )
		);

		$this->assertFalse( $response['points'][0]['selectable']['allowed'], 'over the 1 kg limit at 2 kg' );
		$this->assertFalse( $response['points'][1]['selectable']['allowed'], 'refuses cash on delivery' );
	}

	public function test_an_unknown_payment_method_and_weight_filter_nothing(): void {
		$heavy = $this->point( [ 'id' => 'P1', 'max_weight' => 1000 ] );
		$cod   = $this->point( [ 'id' => 'P2', 'accepts_cod' => false ] );

		$controller = $this->controller( new Pickup_Admin_Test_Source( static fn() => [ $heavy, $cod ], static fn() => null ) );

		$response = $controller->handle_admin_points_request( new WP_REST_Request( [ 'locality' => 'dadata:fias-1' ] ) );

		$this->assertTrue( $response['points'][0]['selectable']['allowed'] );
		$this->assertTrue( $response['points'][1]['selectable']['allowed'] );
	}

	/**
	 * @dataProvider provide_unusable_weights
	 *
	 * @param mixed $weight raw request value.
	 */
	public function test_a_negative_or_non_numeric_weight_falls_back_to_zero( $weight ): void {
		$heavy      = $this->point( [ 'max_weight' => 1 ] );
		$controller = $this->controller( new Pickup_Admin_Test_Source( static fn() => [ $heavy ], static fn() => null ) );

		$response = $controller->handle_admin_points_request(
			new WP_REST_Request( [ 'locality' => 'dadata:fias-1', 'weight' => $weight ] )
		);

		$this->assertTrue( $response['points'][0]['selectable']['allowed'] );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function provide_unusable_weights(): array {
		return [
			'negative'    => [ -500 ],
			'non-numeric' => [ 'heavy' ],
			'array'       => [ [ 5000 ] ],
		];
	}

	public function test_the_detail_verdict_agrees_with_the_list_for_the_same_context(): void {
		$point = $this->point( [ 'id' => 'P1', 'max_weight' => 1000, 'accepts_cod' => false ] );

		$controller = $this->controller(
			new Pickup_Admin_Test_Source( static fn() => [ $point ], static fn() => $point )
		);

		foreach (
			[
				[ 500, 'bacs' ],
				[ 2000, 'bacs' ],
				[ 500, 'cod' ],
				[ 0, '' ],
			] as [ $weight, $method ]
		) {
			$context = [ 'weight' => $weight, 'payment_method' => $method ];

			$list   = $controller->handle_admin_points_request( new WP_REST_Request( [ 'locality' => 'dadata:fias-1' ] + $context ) );
			$detail = $controller->handle_admin_point_request( new WP_REST_Request( [ 'id' => 'P1' ] + $context ) );

			$this->assertSame(
				$list['points'][0]['selectable'],
				$detail['selectable'],
				"list and detail must agree for weight $weight / method \"$method\""
			);
		}
	}

	// ---- the explicit location record ----

	public function test_the_explicit_record_and_its_resolved_identity_reach_the_source(): void {
		$record  = $this->location_record();
		$queries = [];

		$source = new Pickup_Admin_Test_Source(
			static function ( Point_Query $query ) use ( &$queries ) {
				$queries[] = $query;
				return [];
			},
			static fn() => null
		);

		$asked = [];

		$controller = $this->controller(
			$source,
			static function ( Location_Record $given ) use ( &$asked ) {
				$asked[] = $given;
				return [ 'record' => $given, 'resolved_identity' => 'carrier-city-77' ];
			}
		);

		$controller->handle_admin_points_request(
			new WP_REST_Request( [ 'locality' => 'dadata:fias-1', 'location' => $record->to_array() ] )
		);

		$this->assertCount( 1, $asked );
		$this->assertSame( $record->key(), $asked[0]->key() );
		$this->assertCount( 1, $queries );
		$this->assertSame( $record->key(), $queries[0]->get_record()->key() );
		$this->assertSame( 'carrier-city-77', $queries[0]->get_resolved_identity() );
	}

	public function test_a_viewport_source_gets_the_explicit_record_too(): void {
		$queries = [];

		$source = new Pickup_Admin_Test_Source(
			static function ( Point_Query $query ) use ( &$queries ) {
				$queries[] = $query;
				return [];
			},
			static fn() => null,
			Point_Source::STRATEGY_VIEWPORT
		);

		$controller = $this->controller(
			$source,
			static fn( Location_Record $given ) => [ 'record' => $given, 'resolved_identity' => 'x' ]
		);

		$controller->handle_admin_points_request(
			new WP_REST_Request( [ 'bbox' => '37.0,55.0,38.0,56.0', 'location' => $this->location_record()->to_array() ] )
		);

		$this->assertCount( 1, $queries );
		$this->assertNotNull( $queries[0]->get_record() );
	}

	public function test_no_record_means_a_bare_query_and_the_resolver_is_not_asked(): void {
		$queries = [];

		$source = new Pickup_Admin_Test_Source(
			static function ( Point_Query $query ) use ( &$queries ) {
				$queries[] = $query;
				return [];
			},
			static fn() => null
		);

		$controller = $this->controller(
			$source,
			static function () {
				throw new \LogicException( 'No record was sent, so nothing to resolve.' );
			}
		);

		$controller->handle_admin_points_request( new WP_REST_Request( [ 'locality' => 'dadata:fias-1' ] ) );

		$this->assertNull( $queries[0]->get_record() );
	}

	public function test_a_plugin_without_a_resolver_gets_a_bare_query_even_with_a_record(): void {
		$queries = [];

		$source = new Pickup_Admin_Test_Source(
			static function ( Point_Query $query ) use ( &$queries ) {
				$queries[] = $query;
				return [];
			},
			static fn() => null
		);

		$this->controller( $source )->handle_admin_points_request(
			new WP_REST_Request( [ 'locality' => 'dadata:fias-1', 'location' => $this->location_record()->to_array() ] )
		);

		$this->assertNull( $queries[0]->get_record() );
	}

	public function test_a_resolver_answering_null_leaves_the_query_bare(): void {
		$queries = [];

		$source = new Pickup_Admin_Test_Source(
			static function ( Point_Query $query ) use ( &$queries ) {
				$queries[] = $query;
				return [];
			},
			static fn() => null
		);

		$this->controller( $source, static fn() => null )->handle_admin_points_request(
			new WP_REST_Request( [ 'locality' => 'dadata:fias-1', 'location' => $this->location_record()->to_array() ] )
		);

		$this->assertNull( $queries[0]->get_record() );
	}

	/**
	 * @dataProvider provide_invalid_locations
	 *
	 * @param mixed $location raw request value.
	 */
	public function test_an_invalid_location_is_a_400_and_never_reaches_the_carrier( $location ): void {
		$called = false;

		$source = new Pickup_Admin_Test_Source(
			static function () use ( &$called ) {
				$called = true;
				return [];
			},
			static fn() => null
		);

		$response = $this->controller( $source )->handle_admin_points_request(
			new WP_REST_Request( [ 'locality' => 'dadata:fias-1', 'location' => $location ] )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'woodev_location_invalid_record', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertFalse( $called );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function provide_invalid_locations(): array {
		return [
			'a string'                 => [ 'dadata:fias-1' ],
			'an object missing fields' => [ [ 'key' => 'dadata:fias-1' ] ],
			'a bad country'            => [
				[
					'key'         => 'dadata:fias-1',
					'provider_id' => 'dadata',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'Russia',
					'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				],
			],
		];
	}

	// ---- the explicit location record on the DETAIL route (spec D3) ----

	private function strict_resolver(): callable {
		return static fn( Location_Record $given ) => [ 'record' => $given, 'resolved_identity' => 'strict' ];
	}

	public function test_the_detail_hands_a_location_aware_source_the_record_and_its_identity(): void {
		$record = $this->location_record();
		$source = new Pickup_Admin_Test_Location_Source();

		$response = $this->controller( $source, $this->strict_resolver() )->handle_admin_point_request(
			new WP_REST_Request( [ 'id' => 'P1', 'location' => $record->to_array() ] )
		);

		$this->assertSame( [ 'P1|dadata:fias-1|strict' ], $source->detail_calls );
		$this->assertSame( 0, $source->plain_detail_calls );
		$this->assertIsArray( $response );
	}

	public function test_the_detail_and_list_agree_for_a_location_dependent_source(): void {
		$location = $this->location_record()->to_array();
		$source   = new Pickup_Admin_Test_Location_Source();

		$with_resolver = $this->controller( $source, $this->strict_resolver() );
		$without       = $this->controller( $source );

		foreach ( [ [ 2000, false ], [ 500, true ] ] as [ $weight, $expected ] ) {
			$args = [ 'weight' => $weight, 'payment_method' => 'bacs' ];

			$list   = $with_resolver->handle_admin_points_request( new WP_REST_Request( [ 'locality' => 'dadata:fias-1', 'location' => $location ] + $args ) );
			$detail = $with_resolver->handle_admin_point_request( new WP_REST_Request( [ 'id' => 'P1', 'location' => $location ] + $args ) );

			$this->assertSame( $expected, $list['points'][0]['selectable']['allowed'], "list, weight $weight" );
			$this->assertSame( $list['points'][0]['selectable'], $detail['selectable'], "list and detail must agree with a location, weight $weight" );
		}

		// Without a record both fall back to the same location-free verdict — 2000 g passes.
		$args   = [ 'weight' => 2000, 'payment_method' => 'bacs' ];
		$list   = $without->handle_admin_points_request( new WP_REST_Request( [ 'locality' => 'dadata:fias-1' ] + $args ) );
		$detail = $without->handle_admin_point_request( new WP_REST_Request( [ 'id' => 'P1' ] + $args ) );

		$this->assertSame( $list['points'][0]['selectable'], $detail['selectable'], 'list and detail must agree without a location' );
	}

	public function test_the_detail_without_a_record_uses_the_plain_lookup_and_never_asks_the_resolver(): void {
		$source = new Pickup_Admin_Test_Location_Source();

		$this->controller(
			$source,
			static function () {
				throw new \LogicException( 'No record was sent, so nothing to resolve.' );
			}
		)->handle_admin_point_request( new WP_REST_Request( [ 'id' => 'P1' ] ) );

		$this->assertSame( 1, $source->plain_detail_calls );
		$this->assertSame( [], $source->detail_calls );
	}

	public function test_the_detail_falls_back_to_the_plain_lookup_when_the_resolver_is_missing_or_unusable(): void {
		$location = $this->location_record()->to_array();

		foreach ( [ 'no resolver' => null, 'answers null' => static fn() => null, 'answers junk' => static fn() => 'x' ] as $label => $resolver ) {
			$source = new Pickup_Admin_Test_Location_Source();

			$this->controller( $source, $resolver )->handle_admin_point_request(
				new WP_REST_Request( [ 'id' => 'P1', 'location' => $location ] )
			);

			$this->assertSame( 1, $source->plain_detail_calls, $label );
			$this->assertSame( [], $source->detail_calls, $label );
		}
	}

	public function test_a_plain_source_still_answers_the_detail_when_a_record_is_sent(): void {
		$asked = [];

		$source = new Pickup_Admin_Test_Source(
			static fn() => [],
			static function ( string $id ) use ( &$asked ) {
				$asked[] = $id;
				return Pickup_Point::from_array(
					[
						'id'      => $id,
						'name'    => 'Точка',
						'lat'     => 55.75,
						'lng'     => 37.61,
						'address' => 'Москва',
						'type'    => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
					]
				);
			}
		);

		$response = $this->controller( $source, $this->strict_resolver() )->handle_admin_point_request(
			new WP_REST_Request( [ 'id' => 'P1', 'location' => $this->location_record()->to_array() ] )
		);

		$this->assertSame( [ 'P1' ], $asked );
		$this->assertIsArray( $response );
	}

	/**
	 * @dataProvider provide_invalid_locations
	 *
	 * @param mixed $location raw request value.
	 */
	public function test_an_invalid_location_on_the_detail_is_a_400_and_never_reaches_the_carrier( $location ): void {
		$source = new Pickup_Admin_Test_Location_Source();

		$response = $this->controller( $source, $this->strict_resolver() )->handle_admin_point_request(
			new WP_REST_Request( [ 'id' => 'P1', 'location' => $location ] )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'woodev_location_invalid_record', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertSame( [], $source->detail_calls );
		$this->assertSame( 0, $source->plain_detail_calls );
	}

	// ---- shape, strategy guarantee, errors ----

	public function test_the_admin_list_has_the_public_response_shape(): void {
		$point = $this->point();

		$source = new Pickup_Admin_Test_Source( static fn() => [ 'junk', $point ], static fn() => null );

		$controller = $this->controller( $source );

		$admin = $controller->get_points_data_for( [ 'locality' => 'dadata:fias-1' ], 0, '', null );

		// Same core as the public route: junk skipped, a true list, `selectable` on every point.
		$public = ( new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => '',
			static fn() => ''
		) )->get_points_data( [ 'locality' => 'dadata:fias-1' ] );

		$this->assertSame( $public, $admin );
		$this->assertSame( [ 0 ], array_keys( $admin['points'] ) );
		$this->assertArrayHasKey( 'selectable', $admin['points'][0] );
	}

	public function test_a_query_not_matching_the_strategy_yields_an_empty_list_without_calling_the_carrier(): void {
		$called = false;

		$source = new Pickup_Admin_Test_Source(
			static function () use ( &$called ) {
				$called = true;
				return [];
			},
			static fn() => null
		);

		$response = $this->controller( $source )->handle_admin_points_request(
			new WP_REST_Request( [ 'bbox' => '37.0,55.0,38.0,56.0' ] )
		);

		$this->assertSame( [ 'points' => [] ], $response );
		$this->assertFalse( $called );
	}

	public function test_a_carrier_failure_on_the_list_is_a_502_and_is_logged(): void {
		$source = new Pickup_Admin_Test_Source(
			static function () {
				throw new \Woodev_API_Exception( 'carrier down' );
			},
			static fn() => null
		);

		$controller = $this->controller( $source );
		$response   = $controller->handle_admin_points_request( new WP_REST_Request( [ 'locality' => 'dadata:fias-1' ] ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'woodev_pickup_upstream_error', $response->get_error_code() );
		$this->assertSame( 502, $response->get_error_data()['status'] );
		$this->assertSame( [ 'admin points fetch' ], $controller->logged_contexts );
	}

	public function test_a_carrier_failure_on_the_detail_is_a_502_and_is_logged(): void {
		$source = new Pickup_Admin_Test_Source(
			static fn() => [],
			static function () {
				throw new \Woodev_API_Exception( 'carrier down' );
			}
		);

		$controller = $this->controller( $source );
		$response   = $controller->handle_admin_point_request( new WP_REST_Request( [ 'id' => 'P1' ] ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 502, $response->get_error_data()['status'] );
		$this->assertSame( [ 'admin point details fetch' ], $controller->logged_contexts );
	}

	public function test_an_unknown_point_is_a_404(): void {
		$controller = $this->controller( new Pickup_Admin_Test_Source( static fn() => [], static fn() => null ) );

		$response = $controller->handle_admin_point_request( new WP_REST_Request( [ 'id' => 'NOPE' ] ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'woodev_pickup_point_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_the_detail_id_is_capped_before_reaching_the_source(): void {
		$received = null;

		$source = new Pickup_Admin_Test_Source(
			static fn() => [],
			static function ( string $id ) use ( &$received ) {
				$received = $id;
				return null;
			}
		);

		$this->controller( $source )->handle_admin_point_request( new WP_REST_Request( [ 'id' => str_repeat( 'a', 500 ) ] ) );

		$this->assertSame( 128, strlen( (string) $received ) );
	}

	public function test_the_detail_payload_is_the_escaped_one(): void {
		$point = $this->point( [ 'name' => '<b>Точка</b>' ] );

		$controller = $this->controller( new Pickup_Admin_Test_Source( static fn() => [], static fn() => $point ) );

		$response = $controller->handle_admin_point_request( new WP_REST_Request( [ 'id' => 'P1' ] ) );

		$this->assertSame( '&lt;b&gt;Точка&lt;/b&gt;', $response['name'] );
	}
}
