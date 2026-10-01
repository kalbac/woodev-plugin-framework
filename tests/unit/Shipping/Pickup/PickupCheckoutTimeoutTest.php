<?php
/**
 * Tests for the timeout of the pickup-point reference calls by REQUEST CONTEXT (card #1017).
 *
 * A pickup-point lookup is a `reference` call (20 s, #954) — but inside a checkout request the
 * customer waits for it exactly as for a rate, so it gets the 8 s checkout budget. The same
 * lookup in the admin (the order editor's persistence, the admin pickup routes) keeps 20 s.
 *
 * Every test drives the REAL {@see Pickup_Handler} / {@see Pickup_Controller} against a source
 * that records the timeout the call it receives would get.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Pickup_Selection;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Pickup\Selection_Scope;
use Woodev\Framework\Shipping\Rest_Api\Pickup_Controller;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-request-purpose.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-selection-result.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-selection-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-selection.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/interface-map-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipping-order-handler.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once dirname( __DIR__ ) . '/Rest_Api/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/http/trait-rest-rate-limit.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-pickup-controller.php';

/**
 * A {@see Point_Source} that records the timeout every call it receives would get.
 */
final class Pickup_Timeout_Probe_Source implements Point_Source {

	/** @var int[] the timeout, in seconds, of each call — in call order */
	public array $timeouts = [];

	public function get_strategy(): string {
		return Point_Source::STRATEGY_BULK;
	}

	public function fetch_points( Point_Query $query ): array {
		$this->timeouts[] = $this->timeout_now();

		return [ $this->point() ];
	}

	public function fetch_details( string $point_id ): ?Pickup_Point {
		$this->timeouts[] = $this->timeout_now();

		return $this->point();
	}

	private function timeout_now(): int {
		return \Woodev_API_Request_Purpose::default_timeout( \Woodev_API_Request_Purpose::current() );
	}

	private function point(): Pickup_Point {
		return Pickup_Point::from_array(
			[
				'id'      => 'P1',
				'name'    => 'Точка',
				'lat'     => 55.75,
				'lng'     => 37.61,
				'address' => 'Москва',
				'type'    => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
			]
		);
	}
}

/**
 * Array-backed `\WC_Session` stand-in — get()/set() only.
 */
final class Pickup_Timeout_Fake_Session {

	/** @var array<string, mixed> */
	public array $store = [];

	public function get( $key, $default = null ) {
		return $this->store[ $key ] ?? $default;
	}

	public function set( $key, $value ): void {
		$this->store[ $key ] = $value;
	}
}

/**
 * One plugin's selection scope: `carrier_pickup` is its pickup method (type `PVZ`).
 */
final class Pickup_Timeout_Scope implements Selection_Scope {

	public function session_key(): string {
		return 'carrier_selection';
	}

	public function locality_for_point( Pickup_Point $point ): string {
		return 'msk';
	}

	public function current_locality(): string {
		return 'msk';
	}

	public function type_for_method( string $method_id ): ?string {
		return 'carrier_pickup' === $method_id ? 'PVZ' : null;
	}
}

/**
 * {@see Pickup_Selection} reading the fake session instead of `WC()->session`.
 */
final class Pickup_Timeout_Selection extends Pickup_Selection {

	private Pickup_Timeout_Fake_Session $fake_session;

	public function __construct( Selection_Scope $scope, Pickup_Timeout_Fake_Session $fake_session ) {
		parent::__construct( $scope );
		$this->fake_session = $fake_session;
	}

	protected function session() {
		return $this->fake_session;
	}
}

/**
 * A {@see Pickup_Handler} whose selection map is backed by the fake session — the Store API path
 * recalls the point the customer confirmed from it.
 */
final class Pickup_Timeout_Store_Api_Handler extends Pickup_Handler {

	private Pickup_Selection $forced_selection;

	public function __construct( Point_Source $source, Selection_Scope $scope, Pickup_Selection $selection ) {
		parent::__construct(
			'p',
			'pickup_point',
			$source,
			new Pickup_Timeout_Probe_Map_Provider(),
			[ 'center' => [ 55.75, 37.61 ], 'zoom' => 10 ],
			new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] ),
			'pickup_full',
			[],
			'#000000',
			'',
			true,
			false,
			$scope
		);

		$this->forced_selection = $selection;
	}

	protected function selection(): ?Pickup_Selection {
		return $this->forced_selection;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler
 * @covers \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller
 * @covers \Woodev_API_Request_Purpose::run_reference
 */
final class PickupCheckoutTimeoutTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$_POST = [];

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias( static fn( $value ) => is_string( $value ) ? trim( $value ) : $value );
		Functions\when( 'esc_html' )->alias( static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $number, $decimals = 0 ) => number_format( (float) $number, $decimals ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'update_post_meta' )->justReturn( true );
	}

	protected function tearDown(): void {
		$_POST = [];

		parent::tearDown();
	}

	private function controller( Pickup_Timeout_Probe_Source $source ): Pickup_Controller {
		return new Pickup_Controller(
			'test-plugin',
			$source,
			static fn() => 0,
			static fn() => 'bacs',
			static fn() => 'carrier_pickup'
		);
	}

	private function handler( Pickup_Timeout_Probe_Source $source ): Pickup_Handler {
		return new Pickup_Handler(
			'p',
			'pickup_point',
			$source,
			new Pickup_Timeout_Probe_Map_Provider(),
			[ 'center' => [ 55.75, 37.61 ], 'zoom' => 10 ],
			new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] ),
			'pickup_full'
		);
	}

	/** An order double — the persistence path reads its id to write the meta. */
	private function order(): \WC_Order {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );

		return $order;
	}

	private function location_record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
			]
		);
	}

	// ---- the REST controller: public routes serve the checkout picker, admin routes the order wizard ----

	public function test_a_public_point_details_call_gets_the_checkout_budget(): void {
		$source = new Pickup_Timeout_Probe_Source();

		$this->controller( $source )->get_point_data( 'P1' );

		$this->assertSame( [ 8 ], $source->timeouts );
	}

	public function test_a_public_points_list_call_gets_the_checkout_budget(): void {
		$source = new Pickup_Timeout_Probe_Source();

		$this->controller( $source )->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertSame( [ 8 ], $source->timeouts );
	}

	public function test_an_admin_point_details_call_keeps_the_reference_timeout(): void {
		$source = new Pickup_Timeout_Probe_Source();

		$this->controller( $source )->get_point_data_for( 'P1', 0, 'bacs', null );

		$this->assertSame( [ 20 ], $source->timeouts );
	}

	public function test_an_admin_points_list_call_keeps_the_reference_timeout(): void {
		$source = new Pickup_Timeout_Probe_Source();

		$this->controller( $source )->get_points_data_for( [ 'locality' => 'Москва' ], 0, 'bacs', $this->location_record() );

		$this->assertSame( [ 20 ], $source->timeouts );
	}

	// ---- the handler: the checkout re-check and persistence vs the admin order editor ----

	public function test_the_checkout_recheck_of_the_posted_point_gets_the_checkout_budget(): void {
		$source = new Pickup_Timeout_Probe_Source();

		$this->handler( $source )->validate_posted_point( 'P1', 'bacs', 0 );

		$this->assertSame( [ 8 ], $source->timeouts );
	}

	public function test_persisting_the_point_from_the_classic_checkout_hook_gets_the_checkout_budget(): void {
		$source = new Pickup_Timeout_Probe_Source();
		$_POST  = [ 'pickup_point' => 'P1' ];

		$this->handler( $source )->handle_checkout_order_processed( 1, [], $this->order() );

		$this->assertSame( [ 8 ], $source->timeouts );
	}

	public function test_persisting_the_point_from_the_store_api_hook_gets_the_checkout_budget(): void {
		$source  = new Pickup_Timeout_Probe_Source();
		$session = new Pickup_Timeout_Fake_Session();
		$scope   = new Pickup_Timeout_Scope();

		// The REST `select` route remembers the confirmed point in the session; the block checkout
		// posts no field, so the order-processed hook recalls it from there.
		( new Pickup_Timeout_Selection( $scope, $session ) )->remember( 'msk', 'PVZ', 'P1', 'Москва' );

		$line = new class() {
			public function get_method_id(): string {
				return 'carrier_pickup';
			}
		};

		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_items' )->with( 'shipping' )->andReturn( [ $line ] );

		$handler = new Pickup_Timeout_Store_Api_Handler( $source, $scope, new Pickup_Timeout_Selection( $scope, $session ) );

		$handler->handle_store_api_order_processed( $order );

		$this->assertSame( [ 8 ], $source->timeouts, 'the re-fetch of the confirmed point runs in the Store API request' );
	}

	public function test_persisting_the_point_from_the_admin_order_editor_keeps_the_reference_timeout(): void {
		$source = new Pickup_Timeout_Probe_Source();

		// The admin order editor calls persist_full_point() with no checkout flag.
		$this->handler( $source )->persist_full_point( $this->order(), 'P1' );

		$this->assertSame( [ 20 ], $source->timeouts );
	}

	public function test_the_timeout_scope_does_not_leak_past_the_call(): void {
		$source = new Pickup_Timeout_Probe_Source();

		$this->handler( $source )->validate_posted_point( 'P1', 'bacs', 0 );

		$this->assertSame( \Woodev_API_Request_Purpose::DEFAULT_PURPOSE, \Woodev_API_Request_Purpose::current() );
	}
}

/**
 * The smallest {@see \Woodev\Framework\Shipping\Map\Map_Provider} the handler's constructor accepts.
 */
final class Pickup_Timeout_Probe_Map_Provider implements \Woodev\Framework\Shipping\Map\Map_Provider {

	public function get_id(): string {
		return 'yandex';
	}

	public function get_label(): string {
		return 'Yandex';
	}

	public function get_script_handle(): string {
		return 'yandex-map';
	}

	public function get_settings_fields(): array {
		return [];
	}

	public function owns_chrome(): bool {
		return false;
	}

	public function get_js_config( array $context ): array {
		return [];
	}
}
