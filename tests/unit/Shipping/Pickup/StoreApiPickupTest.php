<?php
/**
 * Server Store API pickup acceptance without WordPress or the shared rig database.
 */
namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Pickup_Selection;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Pickup\Selection_Scope;
use Woodev\Framework\Shipping\Pickup\Store_Api_Pickup;
use Woodev\Tests\Unit\Shipping\Order\Order_Persistence_Test_Map_Provider;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/../Order/order-persistence-fixtures.php';
require_once __DIR__ . '/../Order/StoreApiOrderPersistenceTest.php';

final class C2a_Session {
	public array $data = [ 'chosen_shipping_methods' => [ 'carrier_pickup:7' ] ];
	public function get( $key, $default = null ) { return $this->data[ $key ] ?? $default; }
	public function set( $key, $value ): void { $this->data[ $key ] = $value; }
}
final class C2a_Scope implements Selection_Scope {
	public string $locality = 'msk';
	public function session_key(): string { return 'installed_carrier_selection'; }
	public function current_locality(): string { return $this->locality; }
	public function locality_for_point( Pickup_Point $point ): string { return $point->to_array()['locality']; }
	public function type_for_method( string $method_id ): ?string { return 'carrier_pickup' === $method_id ? 'PVZ' : null; }
}

final class C2a_Selection extends Pickup_Selection {
	public C2a_Session $fake_session;
	protected function session() { return $this->fake_session; }
}
class C2a_Adapter extends Store_Api_Pickup {
	public static bool $throttled = false;
	protected static function selection_rate_limited(): bool { return self::$throttled; }
	public static array $packages = [];
	public static array $gateways = [];
	public static C2a_Session $session;
	protected static function context(): array {
		return [ 'rate_id' => self::$session->data['chosen_shipping_methods'][0],
			'address_key' => self::address_key( self::$packages[0]['destination'] ),
			'chosen' => self::$session->data['chosen_shipping_methods'], 'packages' => self::$packages ];
	}
	protected static function payment_available( string $payment ): bool { return isset( self::$gateways[ $payment ] ); }
	protected static function chosen_payment_method(): string { return ''; }
}
final class C2a_Handler extends Pickup_Handler {
	public int $weight = 2000;
	public array $logs = [];
	public Pickup_Selection $fake_selection;
	public ?C2a_Order $draft = null;
	protected function selection(): ?Pickup_Selection { return $this->fake_selection; }
	protected function wc_cart() { return new \stdClass(); }
	protected function store_api_draft_order(): ?\WC_Order { return $this->draft; }
	public function current_cart_weight_grams(): int { return $this->weight; }
	protected function log_carrier_failure( \Throwable $exception, string $context ): void { $this->logs[] = $context; }
}
final class C2a_Line {
	private string $method;
	private int $instance;
	public function __construct( string $method = 'carrier_pickup', int $instance = 7 ) { $this->method = $method; $this->instance = $instance; }
	public function get_method_id(): string { return $this->method; }
	public function get_instance_id(): int { return $this->instance; }
}
final class C2a_Order extends \WC_Order {
	public string $status = 'checkout-draft';
	public string $payment = 'bacs';
	public array $lines;
	public array $address;
	public function __construct( array $address ) { $this->address = $address; $this->lines = [ new C2a_Line() ]; }
	public function get_items( $type = 'line_item' ) { return $this->lines; }
	public function get_address( $type = 'billing' ) { return $this->address; }
	public function get_payment_method() { return $this->payment; }
	public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
}

/**
 * Store API registration mocks define global functions; isolate capability tests from other suites.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class StoreApiPickupTest extends TestCase {
	private C2a_Session $session;
	private C2a_Scope $scope;
	private C2a_Handler $handler;
	private array $address = [ 'country' => 'RU', 'state' => 'MOW', 'city' => 'Moscow', 'postcode' => '123', 'address_1' => 'Street' ];
	private array $packages;
	private array $meta = [];
	private array $point_data;
	private int $fetches = 0;
	private ?\Throwable $failure = null;
	private bool $missing = false;
	private ?C2a_Order $draft_order = null;
	private array $gateways = [ 'bacs' => true, 'cod' => true ];

	protected function setUp(): void {
		parent::setUp();
		foreach ( [ 'handlers' => [], 'booted' => false, 'registered' => false, 'echoes' => [] ] as $name => $value ) {
			$property = new \ReflectionProperty( Store_Api_Pickup::class, $name );
			if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
			$property->setValue( null, $value );
		}
		$this->session = new C2a_Session();
		$this->scope = new C2a_Scope();
		$this->packages = [ [ 'destination' => $this->address, 'rates' => [ 'carrier_pickup:7' => new C2a_Line() ] ] ];
		$this->point_data = [ 'id' => 'P1', 'name' => 'Point', 'lat' => 55.7, 'lng' => 37.6, 'address' => 'Street', 'locality' => 'msk', 'type' => [ 'code' => 'PVZ', 'label' => 'Pickup' ] ];
		Functions\when( 'wc_clean' )->alias( static fn( $value ) => is_array( $value ) ? $value : trim( (string) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key, $single = true ) => $this->meta[ $key ] ?? '' );
				Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) { unset( $this->meta[ $key ] ); return true; } );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d ) => number_format( $n, $d ) );
		Functions\when( 'wc_add_notice' )->justReturn( null );
		C2a_Adapter::$throttled = false;
		C2a_Adapter::$session = $this->session;
		C2a_Adapter::$packages =& $this->packages;
		C2a_Adapter::$gateways =& $this->gateways;
		$source = \Mockery::mock( Point_Source::class );
		$source->shouldReceive( 'fetch_details' )->andReturnUsing( function () {
			++$this->fetches;
			if ( null !== $this->failure ) { throw $this->failure; }
			return $this->missing ? null : Pickup_Point::from_array( $this->point_data );
		} );
		$this->handler = new C2a_Handler( 'carrier', 'carrier_point', $source, new Order_Persistence_Test_Map_Provider(), [ 'center' => [ 55, 37 ], 'zoom' => 9 ], null, null, [], '#123456', '', true, false, $this->scope );
		$selection = new C2a_Selection( $this->scope );
		$selection->fake_session = $this->session;
		$this->handler->fake_selection = $selection;
		Store_Api_Pickup::add_handler( $this->handler, 'carrier', 'carrier_point' );
		// The real global action has this remember listener on every registered handler.
		Functions\when( 'do_action' )->alias( function ( $hook, ...$args ) {
			if ( 'woodev_shipping_pickup_point_selected' === $hook ) { $this->handler->remember_selection( ...$args ); }
		} );
	}

	protected function tearDown(): void {
		foreach ( [ 'handlers' => [], 'booted' => false, 'registered' => false, 'echoes' => [] ] as $name => $value ) {
			$property = new \ReflectionProperty( Store_Api_Pickup::class, $name );
			if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
			$property->setValue( null, $value );
		}
		parent::tearDown();
	}

	private function command( array $value = [] ): array {
		return [ 'pickup' => [ 'carrier' => [ 'carrier_point' => $value + [ 'point_id' => 'P1', 'payment_method' => 'bacs' ] ] ] ];
	}
	private function request( array $echo = [], string $method = 'POST' ): \WP_REST_Request {
		$request = new \WP_REST_Request( [ 'extensions' => [ 'woodev-shipping' => $echo ] ] );
		return $request;
	}
	private function validate( ?C2a_Order $order = null, array $echo = [] ): \WP_Error {
		$order = $order ?? new C2a_Order( $this->address );
		C2a_Adapter::update_order( $order, $this->request( $echo ) );
		$errors = new \WP_Error();
		C2a_Adapter::validate_order( $order, $errors );
		return $errors;
	}

	public function test_confirmation_uses_shared_verdict_action_memory_and_snapshot(): void {
		C2a_Adapter::update( $this->command() );
		$snapshot = C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'];
		$this->assertSame( 'P1', $snapshot['point_id'] );
		$this->assertSame( 'carrier_pickup:7', $snapshot['rate_id'] );
		$this->assertSame( 'msk', $snapshot['locality'] );
		$this->assertSame( 'Street', $snapshot['summary'] );
		$this->assertTrue( $snapshot['selection']['allowed'] );
		$this->assertArrayNotHasKey( 'address_key', $snapshot );
		$this->assertArrayHasKey( 'installed_carrier_selection', $this->session->data );
		$this->assertFalse( $this->validate( null, [ 'pickup' => [ 'carrier' => [ 'carrier_point' => $snapshot ] ] ] )->has_errors() );
		$this->assertSame( 1, $this->fetches );
	}

	public function test_domain_denial_is_not_remembered(): void {
		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->andReturn( [ 'allowed' => false, 'reason' => 'Domain refused' ] );
		$this->expectExceptionMessage( 'Domain refused' );
		try { C2a_Adapter::update( $this->command() ); }
		finally { $this->assertNull( C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'] ); }
	}

	public function test_explicit_clear_is_scoped_and_refuses_express_payment(): void {
		C2a_Adapter::update( $this->command() );
		$this->session->data['installed_carrier_selection']['other'] = [ 'PVZ' => [ 'id' => 'P2', 'seq' => 10 ] ];
		C2a_Adapter::update( $this->command( [ 'clear' => true ] ) );
		$this->assertArrayHasKey( 'other', $this->session->data['installed_carrier_selection'] );
		$this->assertStringContainsString( 'checkout page', $this->validate()->get_error_message() );
	}

	public function test_deferred_draft_reconciles_address_and_never_requires_a_point(): void {
		C2a_Adapter::update( $this->command() );
		$this->packages[0]['destination']['city'] = 'Different city';
		C2a_Adapter::update_draft( $this->request( [], 'PATCH' ) );
		$this->assertNull( C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'] );
		$this->assertSame( '', $this->handler->get_selected_point_for_method( 'carrier_pickup' )['point_id'] );
	}

	public function test_cart_address_change_clears_confirmation_without_checkout_rendering(): void {
		C2a_Adapter::update( $this->command() );
		$this->packages[0]['destination']['postcode'] = '999';
		$this->assertNull( C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'] );
	}

	public function test_rate_instance_change_invalidates_the_snapshot(): void {
		C2a_Adapter::update( $this->command() );
		$this->session->data['chosen_shipping_methods'][0] = 'carrier_pickup:8';
		$this->assertNull( C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'] );
	}

	public function test_stale_nonempty_echo_is_refused_and_cannot_repair_session(): void {
		C2a_Adapter::update( $this->command() );
		$snapshot = C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'];
		$snapshot['point_id'] = 'FORGED';
		$this->assertTrue( $this->validate( null, [ 'pickup' => [ 'carrier' => [ 'carrier_point' => $snapshot ] ] ] )->has_errors() );
		$this->assertSame( 'P1', $this->handler->get_selected_point_for_method( 'carrier_pickup' )['point_id'] );
	}

	public function test_unknown_plugin_field_is_rejected_before_carrier_lookup(): void {
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		try { C2a_Adapter::update( [ 'pickup' => [ 'foreign' => [ 'carrier_point' => [ 'point_id' => 'P1' ] ] ] ] ); }
		finally { $this->assertSame( 0, $this->fetches ); }
	}

	public function test_no_longer_available_rate_is_rejected_before_carrier_lookup(): void {
		$this->packages[0]['rates'] = [];
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		try { C2a_Adapter::update( $this->command() ); }
		finally { $this->assertSame( 0, $this->fetches ); }
	}

	public function test_unavailable_gateway_cannot_bypass_cod_check(): void {
		$this->gateways = [ 'cod' => true ];
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		try { C2a_Adapter::update( $this->command( [ 'payment_method' => 'invented' ] ) ); }
		finally { $this->assertSame( 0, $this->fetches ); }
	}

	public function test_secondary_framework_package_is_refused_before_lookup(): void {
		$this->session->data['chosen_shipping_methods'][1] = 'carrier_pickup:8';
		$this->expectExceptionMessage( 'multiple shipping packages' );
		try { C2a_Adapter::update( $this->command() ); }
		finally { $this->assertSame( 0, $this->fetches ); }
	}

	public function test_secondary_order_shipping_line_is_refused(): void {
		$order = new C2a_Order( $this->address );
		$order->lines[] = new C2a_Line( 'carrier_pickup', 8 );
		$this->assertStringContainsString( 'multiple shipping packages', $this->validate( $order )->get_error_message() );
	}

	public function test_cod_is_checked_against_the_final_order_gateway(): void {
		$this->point_data['accepts_cod'] = false;
		C2a_Adapter::update( $this->command() );
		$order = new C2a_Order( $this->address );
		$order->payment = 'cod';
		$this->assertStringContainsString( 'cash on delivery', $this->validate( $order )->get_error_message() );
	}

	public function test_weight_is_checked_against_the_final_server_cart(): void {
		$this->point_data['max_weight'] = 2500;
		C2a_Adapter::update( $this->command() );
		$this->handler->weight = 3000;
		$this->assertStringContainsString( 'weight', $this->validate()->get_error_message() );
	}

	public function test_point_from_another_locality_is_refused(): void {
		$this->point_data['locality'] = 'spb';
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		C2a_Adapter::update( $this->command() );
	}

	public function test_different_order_address_cannot_use_cart_confirmation(): void {
		C2a_Adapter::update( $this->command() );
		$order = new C2a_Order( $this->address );
		$order->address['city'] = 'Forged city';
		$this->assertTrue( $this->validate( $order )->has_errors() );
	}

	public function test_payment_failure_retry_with_no_point_is_refused(): void {
		C2a_Adapter::update( $this->command() );
		$order = new C2a_Order( $this->address );
		$this->assertFalse( $this->validate( $order )->has_errors() );
		$this->handler->handle_store_api_order_processed( $order );
		$order->status = 'failed';
		$this->assertStringContainsString( 'checkout page', $this->validate( $order )->get_error_message() );
		$order->status = 'pending';
		$this->assertTrue( $this->validate( $order )->has_errors() );
	}

	public function test_retry_with_persisted_point_passes_after_existing_session_clear(): void {
		C2a_Adapter::update( $this->command() );
		$order = new C2a_Order( $this->address );
		$this->meta['carrier_point'] = 'P1';
		$this->handler->handle_store_api_order_processed( $order );
		$order->status = 'failed';
		$this->assertFalse( $this->validate( $order )->has_errors() );
	}

	public function test_existing_pay_for_order_is_outside_the_checkout_adapter(): void {
		$errors = new \WP_Error();
		C2a_Adapter::validate_order( new C2a_Order( $this->address ), $errors );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_outage_policy_is_shared_with_classic(): void {
		$this->meta['carrier_point'] = 'P1';
		$this->failure = new \RuntimeException( 'secret carrier text' );
		$this->assertFalse( $this->validate()->has_errors() );
		$this->assertSame( [ 'checkout re-check' ], $this->handler->logs );
	}

	public function test_outage_filter_can_refuse_without_exposing_carrier_text(): void {
		$this->meta['carrier_point'] = 'P1';
		$this->failure = new \RuntimeException( 'secret carrier text' );
		Filters\expectApplied( 'woodev_shipping_pickup_recheck_outage_allows_checkout' )->andReturn( false );
		$this->assertStringContainsString( 'Could not verify', $this->validate()->get_error_message() );
	}

	public function test_unknown_persisted_retry_point_is_refused(): void {
		$this->meta['carrier_point'] = 'P1';
		$this->missing = true;
		$this->assertStringContainsString( 'no longer available', $this->validate()->get_error_message() );
	}
	public function test_valid_payment_retry_can_echo_the_original_confirmation(): void {
		C2a_Adapter::update( $this->command() );
		$snapshot = C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'];
		$order = new C2a_Order( $this->address );
		$this->meta['carrier_point'] = 'P1';
		$this->handler->handle_store_api_order_processed( $order );
		$order->status = 'failed';
		$this->assertFalse( $this->validate( $order, [ 'pickup' => [ 'carrier' => [ 'carrier_point' => $snapshot ] ] ] )->has_errors() );
	}

	public function test_adapter_is_dormant_without_the_wc_99_payment_gate(): void {
		Functions\expect( 'woocommerce_store_api_register_update_callback' )->never();
		Store_Api_Pickup::register();
		$this->assertSame( 'woodev-shipping', Store_Api_Pickup::EXTENSION_NAMESPACE );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_registration_is_single_multi_carrier_and_feature_detects_deferred_draft(): void {
		require __DIR__ . '/store-api-capabilities.php';
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
		Functions\expect( 'woocommerce_store_api_register_update_callback' )->once()->with( [
			'namespace' => 'woodev-shipping', 'callback' => [ Store_Api_Pickup::class, 'update' ],
		] )->andReturn( true );
		Functions\expect( 'woocommerce_store_api_register_endpoint_data' )->once()->with( \Mockery::on( static function ( $args ) {
			return 'cart' === $args['endpoint'] && 'woodev-shipping' === $args['namespace'] && is_callable( $args['data_callback'] );
		} ) )->andReturn( true );
		\Brain\Monkey\Actions\expectAdded( 'woocommerce_store_api_checkout_update_draft' )->once()->with( [ Store_Api_Pickup::class, 'update_draft' ] );
		Store_Api_Pickup::register();
		Store_Api_Pickup::add_handler( $this->handler, 'second_carrier', 'second_field' );
		Store_Api_Pickup::register();
		$this->assertArrayHasKey( 'second_carrier', C2a_Adapter::cart_data()['pickup'] );
	}

	public function test_corrected_point_outside_scope_is_not_remembered(): void {
		$corrected = $this->point_data;
		$corrected['locality'] = 'spb';
		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->andReturn( [ 'allowed' => true, 'reason' => null, 'point' => $corrected ] );
		$this->expectExceptionMessage( 'no longer available' );
		try { C2a_Adapter::update( $this->command() ); }
		finally { $this->assertArrayNotHasKey( 'installed_carrier_selection', $this->session->data ); }
	}

	public function test_foreign_courier_second_package_remains_supported(): void {
		$this->session->data['chosen_shipping_methods'][1] = 'flat_rate:9';
		C2a_Adapter::update( $this->command() );
		$this->assertFalse( $this->validate()->has_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_99_registers_without_the_newer_deferred_draft_hook(): void {
		require __DIR__ . '/store-api-99-capability.php';
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
		Functions\expect( 'woocommerce_store_api_register_update_callback' )->once()->andReturn( true );
		Functions\expect( 'woocommerce_store_api_register_endpoint_data' )->once()->andReturn( true );
		\Brain\Monkey\Actions\expectAdded( 'woocommerce_store_api_checkout_update_draft' )->never();
		Store_Api_Pickup::register();
		$this->assertSame( 'object', Store_Api_Pickup::schema()['pickup']['type'] );
	}

	public function test_order_backed_clear_removes_retry_meta_and_cannot_be_resurrected(): void {
		$this->meta['carrier_point'] = 'P1';
		$order = new C2a_Order( $this->address );
		C2a_Adapter::update_order( $order, $this->request( $this->command( [ 'clear' => true ] ), 'PATCH' ) );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta );
		$this->assertTrue( $this->validate( $order )->has_errors() );
	}

	public function test_cart_clear_removes_current_pending_draft_meta(): void {
		$this->meta['carrier_point'] = 'P1';
		$this->draft_order = new C2a_Order( $this->address );
		$this->handler->draft = $this->draft_order;
		C2a_Adapter::update( $this->command( [ 'clear' => true ] ) );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta );
		$this->assertTrue( $this->validate( $this->draft_order )->has_errors() );
	}

	public function test_nonempty_malformed_echo_is_refused(): void {
		C2a_Adapter::update( $this->command() );
		$this->assertTrue( $this->validate( null, [ 'point_id' => 'P1' ] )->has_errors() );
		$this->assertTrue( $this->validate( null, [ 'pickup' => [ 'carrier' => [ 'carrier_point' => 'P1' ] ] ] )->has_errors() );
	}

	public function test_foreign_owner_in_checkout_echo_is_refused(): void {
		C2a_Adapter::update( $this->command() );
		$this->assertTrue( $this->validate( null, [ 'pickup' => [ 'foreign' => [ 'carrier_point' => [ 'point_id' => 'P1' ] ] ] ] )->has_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_floor_stays_99_even_if_newer_blocks_capabilities_are_present(): void {
		require __DIR__ . '/store-api-capabilities.php';
		define( 'WC_VERSION', '9.8.9' );
		Functions\expect( 'woocommerce_store_api_register_update_callback' )->never();
		Store_Api_Pickup::register();
		$this->assertSame( 'woodev-shipping', Store_Api_Pickup::EXTENSION_NAMESPACE );
	}

	public function test_corrected_snapshot_uses_canonical_point_before_browser_escaping(): void {
		$this->scope->locality = 'm&sk';
		$this->point_data['locality'] = 'm&sk';
		$corrected = $this->point_data;
		$corrected['address'] = 'A & B';
		$corrected['short_address'] = 'A & B';
		Functions\when( 'esc_html' )->alias( static fn( $value ) => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false ) );
		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->andReturn( [ 'allowed' => true, 'reason' => null, 'point' => $corrected ] );
		C2a_Adapter::update( $this->command() );
		$snapshot = C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'];
		$this->assertSame( 'm&sk', $snapshot['locality'] );
		$this->assertSame( 'A & B', $snapshot['summary'] );
		$this->assertSame( 'A &amp; B', $snapshot['selection']['point']['short_address'] );
	}

	public function test_store_api_confirmation_cannot_bypass_classic_selection_quota(): void {
		C2a_Adapter::$throttled = true;
		$this->expectExceptionCode( 429 );
		$this->expectExceptionMessage( 'Too many requests' );
		try { C2a_Adapter::update( [] ); }
		finally { $this->assertSame( 0, $this->fetches ); }
	}

}
