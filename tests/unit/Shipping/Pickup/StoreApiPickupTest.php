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
	public array $data = [ 'chosen_shipping_methods' => [ 'carrier_pickup:7' ], 'store_api_draft_order' => 123 ];
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
	public static int $context_reads = 0;
	protected static function draft_order_id(): int { return (int) self::$session->get( 'store_api_draft_order', 0 ); }
	protected static function selection_rate_limited(): bool { return self::$throttled; }
	public static array $packages = [];
	public static array $gateways = [];
	/** @var string[] The framework pickup method ids this rig reports (#1100). */
	public static array $pickup_methods = [];
	public static C2a_Session $session;
	protected static function pickup_method_ids(): array { return self::$pickup_methods; }
	/** @var array<int, array<string, string>> Every destination write, in order (#1089). */
	public static array $writes = [];
	/** @var string|null A postcode the carrier does not serve: the destination there has no rates. */
	public static ?string $unserved_postcode = null;
	protected static function context( bool $refresh = false ): array {
		++self::$context_reads;
		$packages = self::$packages;
		if ( null !== self::$unserved_postcode && self::$unserved_postcode === ( $packages[0]['destination']['postcode'] ?? '' ) ) {
			$packages[0]['rates'] = [];
		}
		return [ 'rate_id' => self::$session->data['chosen_shipping_methods'][0] ?? '',
			'address_key' => self::address_key( self::$packages[0]['destination'] ?? [] ),
			'chosen' => self::$session->data['chosen_shipping_methods'], 'packages' => $packages ];
	}
	protected static function write_destination( array $fields, ?array $chosen = null ): array {
		self::$writes[] = $fields;
		$previous = [];
		foreach ( array_keys( $fields ) as $key ) { $previous[ $key ] = (string) ( self::$packages[0]['destination'][ $key ] ?? '' ); }
		self::$packages[0]['destination'] = array_merge( self::$packages[0]['destination'], $fields );
		if ( null !== $chosen ) { self::$session->data['chosen_shipping_methods'] = $chosen; }
		return $previous;
	}
	protected static function payment_available( string $payment ): bool { return isset( self::$gateways[ $payment ] ); }
	protected static function chosen_payment_method(): string { return ''; }
}
final class C2a_Handler extends Pickup_Handler {
	public int $weight = 2000;
	public array $logs = [];
	public Pickup_Selection $fake_selection;
	public ?C2a_Order $draft = null;
	public bool $replace_address = false;
	/** @var bool Whether the store's checkout page runs the Checkout block (#1100). */
	public bool $block_checkout = true;
	protected function checkout_uses_blocks(): bool { return $this->block_checkout; }
	protected function selection(): ?Pickup_Selection { return $this->fake_selection; }
	protected function replaces_address(): bool { return $this->replace_address; }
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
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) { unset( $this->meta[ $key ] ); return true; } );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d ) => number_format( $n, $d ) );
		Functions\when( 'wc_add_notice' )->justReturn( null );
		C2a_Adapter::$throttled = false;
		C2a_Adapter::$context_reads = 0;
		C2a_Adapter::$writes = [];
		C2a_Adapter::$unserved_postcode = null;
		C2a_Adapter::$pickup_methods = [];
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

	/**
	 * SP-11 C-2b (#1089): the block checkout shows its pickup button from the server's answer alone.
	 */
	public function test_cart_data_names_the_field_that_owns_the_chosen_rate(): void {
		$this->assertSame(
			[ 'plugin_id' => 'carrier', 'field_id' => 'carrier_point', 'rate_id' => 'carrier_pickup:7', 'locality' => 'msk' ],
			C2a_Adapter::cart_data()['owner']
		);

		// The owner is about the RATE, not about a point: it is named before anything is confirmed
		// and follows the customer's settlement as the chain changes.
		$this->scope->locality = '';
		$this->assertSame( '', C2a_Adapter::cart_data()['owner']['locality'] );
	}

	public function test_cart_data_names_no_owner_for_a_foreign_or_missing_rate(): void {
		$this->session->data['chosen_shipping_methods'] = [ 'flat_rate:9' ];
		$this->assertNull( C2a_Adapter::cart_data()['owner'] );

		$this->session->data['chosen_shipping_methods'] = [];
		$this->assertNull( C2a_Adapter::cart_data()['owner'] );
	}

	public function test_owner_is_cart_output_only_and_never_part_of_the_checkout_echo_schema(): void {
		$this->assertTrue( Store_Api_Pickup::schema()['owner']['readonly'] );
		$this->assertContains( 'null', Store_Api_Pickup::schema()['owner']['type'] );
		$this->assertSame( [ 'pickup' ], array_keys( Store_Api_Pickup::checkout_schema() ) );
		$this->assertFalse( Store_Api_Pickup::checkout_schema()['pickup']['readonly'] );
	}

	/**
	 * SP-11 C-2b (#1089): `pickup_replace_address` on the block checkout. The destination moves to
	 * the point's address in the SAME request that confirms the point, so the confirmation is
	 * bound to the new destination instead of being dropped by it.
	 */
	public function test_address_replacement_moves_the_destination_and_keeps_the_confirmation(): void {
		$this->handler->replace_address = true;
		$this->point_data['address'] = 'Tverskaya 1';
		$this->point_data['postal_code'] = '101000';

		C2a_Adapter::update( $this->command() );

		$moved = [ 'address_1' => 'Tverskaya 1', 'postcode' => '101000' ];
		$this->assertSame( [ $moved ], C2a_Adapter::$writes );
		$this->assertSame( array_merge( $this->address, $moved ), $this->packages[0]['destination'] );
		// The city is the customer's own confirmed locality and is never replaced.
		$this->assertSame( 'Moscow', $this->packages[0]['destination']['city'] );

		$snapshot = C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'];
		$this->assertSame( 'P1', $snapshot['point_id'] );
		$this->assertSame( $moved, $snapshot['destination'] );

		// The checkout's own reconciliation and the pre-payment gate both accept it at the new address…
		C2a_Adapter::update_draft( $this->request( [], 'PATCH' ) );
		$this->assertSame( 'P1', $this->handler->get_selected_point_for_method( 'carrier_pickup' )['point_id'] );
		$placed = new C2a_Order( $this->packages[0]['destination'] );
		$this->assertFalse( $this->validate( $placed, [ 'pickup' => [ 'carrier' => [ 'carrier_point' => $snapshot ] ] ] )->has_errors() );

		// …and it is still bound to it: the residential address it replaced no longer carries the point.
		$this->packages[0]['destination'] = $this->address;
		$this->assertNull( C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'] );
	}

	public function test_address_replacement_is_off_unless_the_store_asks_for_it(): void {
		$this->point_data['address'] = 'Tverskaya 1';
		$this->point_data['postal_code'] = '101000';

		C2a_Adapter::update( $this->command() );

		$this->assertSame( [], C2a_Adapter::$writes );
		$this->assertSame( $this->address, $this->packages[0]['destination'] );
		$snapshot = C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'];
		$this->assertSame( 'P1', $snapshot['point_id'] );
		$this->assertSame( [], $snapshot['destination'] );
	}

	public function test_address_replacement_never_blanks_a_field_the_point_has_no_value_for(): void {
		$this->handler->replace_address = true;
		$this->point_data['address'] = 'Tverskaya 1';

		C2a_Adapter::update( $this->command() );

		$this->assertSame( [ [ 'address_1' => 'Tverskaya 1' ] ], C2a_Adapter::$writes );
		$this->assertSame( '123', $this->packages[0]['destination']['postcode'] );
		$this->assertSame( [ 'address_1' => 'Tverskaya 1' ], C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point']['destination'] );
	}

	public function test_address_replacement_is_undone_when_the_chosen_rate_does_not_survive_it(): void {
		$this->handler->replace_address = true;
		$this->point_data['address'] = 'Tverskaya 1';
		$this->point_data['postal_code'] = '101000';
		C2a_Adapter::$unserved_postcode = '101000';

		C2a_Adapter::update( $this->command() );

		// Written, found to lose the rate, and put back together with the chosen rate.
		$this->assertCount( 2, C2a_Adapter::$writes );
		$this->assertSame( [ 'address_1' => 'Street', 'postcode' => '123' ], C2a_Adapter::$writes[1] );
		$this->assertSame( $this->address, $this->packages[0]['destination'] );
		$this->assertSame( [ 'carrier_pickup:7' ], $this->session->data['chosen_shipping_methods'] );
		// The point stays confirmed — for the customer's own address.
		$snapshot = C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'];
		$this->assertSame( 'P1', $snapshot['point_id'] );
		$this->assertSame( [], $snapshot['destination'] );
	}

	public function test_a_refused_point_never_moves_the_destination(): void {
		$this->handler->replace_address = true;
		$this->point_data['address'] = 'Tverskaya 1';
		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->andReturn( [ 'allowed' => false, 'reason' => 'Domain refused' ] );
		$this->expectExceptionMessage( 'Domain refused' );
		try { C2a_Adapter::update( $this->command() ); }
		finally {
			$this->assertSame( [], C2a_Adapter::$writes );
			$this->assertSame( $this->address, $this->packages[0]['destination'] );
		}
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

	public function test_cart_address_change_hides_confirmation_without_writing_session_or_order(): void {
		C2a_Adapter::update( $this->command() );
		$this->handler->draft = new C2a_Order( $this->address );
		$this->meta['carrier_point'] = 'P1';
		$before = $this->session->data;
		$this->packages[0]['destination']['postcode'] = '999';
		$this->assertNull( C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'] );
		$this->assertSame( $before, $this->session->data );
		$this->assertSame( 'P1', $this->meta['carrier_point'] );
		C2a_Adapter::update_draft( $this->request() );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta );
		$this->assertSame( '', $this->handler->get_selected_point_for_method( 'carrier_pickup' )['point_id'] );
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

	/**
	 * #1110: a hand-typed city the chooser never resolved leaves the scope with no locality, which
	 * refuses every point. The customer is told what to do, not handed the generic «choose a point».
	 */
	public function test_a_cart_with_no_resolved_locality_is_told_to_choose_the_city(): void {
		$this->scope->locality = '';
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		$this->expectExceptionMessage( 'Choose your locality from the suggestions to see pickup points.' );
		try { C2a_Adapter::update( $this->command() ); }
		finally {
			// Refused before any carrier round trip: there is no locality to confirm a point against.
			$this->assertSame( 0, $this->fetches );
			$this->assertNull( C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point'] );
		}
	}

	public function test_a_cart_with_a_resolved_locality_still_confirms_a_point(): void {
		C2a_Adapter::update( $this->command() );
		$this->assertSame( 'msk', C2a_Adapter::cart_data()['owner']['locality'] );
		$this->assertSame( 'P1', C2a_Adapter::cart_data()['pickup']['carrier']['carrier_point']['point_id'] );
	}

	public function test_placing_an_order_with_no_resolved_locality_names_the_city_as_the_way_out(): void {
		$this->scope->locality = '';
		$this->assertSame(
			[ 'Choose your locality from the suggestions to see pickup points.' ],
			$this->validate()->get_error_messages()
		);
	}

	public function test_placing_an_order_with_a_locality_and_no_point_keeps_the_generic_message(): void {
		$this->assertSame(
			[ 'Please choose a pickup point on the checkout page before paying.' ],
			$this->validate()->get_error_messages()
		);
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
		$order = new C2a_Order( $this->address );
		$order->status = 'pending';
		$this->meta['carrier_point'] = 'P1';
		$this->session->data['store_api_draft_order'] = 999;
		$this->session->data['chosen_shipping_methods'] = [];
		$this->packages = [];
		C2a_Adapter::update_order( $order, $this->request( $this->command( [ 'clear' => true ] ) ) );
		$errors = new \WP_Error();
		C2a_Adapter::validate_order( $order, $errors );
		$this->assertFalse( $errors->has_errors() );
		$this->assertSame( 0, C2a_Adapter::$context_reads );
		$this->assertSame( 0, $this->fetches );
		$this->assertSame( 'P1', $this->meta['carrier_point'] );
	}

	/**
	 * An earlier request placed the session's order (id 123) with P1, and its processing emptied
	 * the memory: what a retry stands on is the confirmation kept for that order (#1090).
	 */
	private function placed_earlier( array $over = [] ): void {
		$this->meta['carrier_point'] = 'P1';
		$this->handler->fake_selection->remember_placed( 123, $over + [
			'plugin_id' => 'carrier', 'field_id' => 'carrier_point', 'point_id' => 'P1', 'locality' => 'msk',
			'rate_id' => 'carrier_pickup:7', 'address_key' => Store_Api_Pickup::address_key( $this->address ),
		] );
	}

	public function test_outage_policy_is_shared_with_classic(): void {
		$this->placed_earlier();
		$this->failure = new \RuntimeException( 'secret carrier text' );
		$this->assertFalse( $this->validate()->has_errors() );
		$this->assertSame( [ 'checkout re-check' ], $this->handler->logs );
	}

	public function test_outage_filter_can_refuse_without_exposing_carrier_text(): void {
		$this->placed_earlier();
		$this->failure = new \RuntimeException( 'secret carrier text' );
		Filters\expectApplied( 'woodev_shipping_pickup_recheck_outage_allows_checkout' )->andReturn( false );
		$this->assertStringContainsString( 'Could not verify', $this->validate()->get_error_message() );
	}

	public function test_unknown_persisted_retry_point_is_refused(): void {
		$this->placed_earlier();
		$this->missing = true;
		$this->assertStringContainsString( 'no longer available', $this->validate()->get_error_message() );
	}

	/**
	 * #1090, critic round 1: the id on a retry order is not a confirmation. Without the one the
	 * order was placed with — or with one made for another rate instance, destination, locality,
	 * point or order — the retry is refused before the carrier is asked, and the id is dropped.
	 *
	 * @dataProvider unbacked_retry_points
	 */
	public function test_a_retry_point_without_its_own_confirmation_is_refused_and_dropped( ?array $placed ): void {
		$this->meta['carrier_point'] = 'P1';
		if ( null !== $placed ) {
			$this->placed_earlier( $placed );
		}
		$errors = $this->validate();
		$this->assertTrue( $errors->has_errors() );
		$this->assertStringContainsString( 'checkout page', $errors->get_error_message() );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta );
		$this->assertSame( 0, $this->fetches );
	}

	/** @return array<string, array{0: array<string, mixed>|null}> */
	public function unbacked_retry_points(): array {
		return [
			'no confirmation kept' => [ null ],
			'another instance of the method' => [ [ 'rate_id' => 'carrier_pickup:8' ] ],
			'another destination' => [ [ 'address_key' => 'the-fingerprint-of-another-address' ] ],
			'another locality' => [ [ 'locality' => 'spb' ] ],
			'another point' => [ [ 'point_id' => 'P2' ] ],
		];
	}

	public function test_a_confirmation_kept_for_another_order_backs_no_retry(): void {
		$this->placed_earlier();
		$this->handler->fake_selection->remember_placed( 456, [ 'point_id' => 'P1' ] );
		$this->assertTrue( $this->validate()->has_errors() );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta );
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
		// WC is not loaded in this unit suite: assert both endpoint registrations and
		// the writable open pickup object WC's recursive sanitizer must retain instead.
		$registrations = [];
		Functions\expect( 'woocommerce_store_api_register_endpoint_data' )->twice()->andReturnUsing( static function ( $args ) use ( &$registrations ) {
			$registrations[ $args['endpoint'] ] = $args;
			return true;
		} );
		\Brain\Monkey\Actions\expectAdded( 'woocommerce_store_api_checkout_update_draft' )->once()->with( [ Store_Api_Pickup::class, 'update_draft' ] );
		Store_Api_Pickup::register();
		Store_Api_Pickup::add_handler( $this->handler, 'second_carrier', 'second_field' );
		Store_Api_Pickup::register();
		$this->assertArrayHasKey( 'second_carrier', C2a_Adapter::cart_data()['pickup'] );
		$this->assertSame( [ 'cart', 'checkout' ], array_keys( $registrations ) );
		foreach ( $registrations as $endpoint => $args ) {
			$this->assertSame( 'woodev-shipping', $args['namespace'] );
			$schema = call_user_func( $args['schema_callback'] );
			$this->assertSame( 'object', $schema['pickup']['type'] );
			$this->assertTrue( $schema['pickup']['additionalProperties'] );
			$this->assertSame( 'cart' === $endpoint, $schema['pickup']['readonly'] );
		}
		$this->assertTrue( is_callable( $registrations['cart']['data_callback'] ) );
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
		Functions\expect( 'woocommerce_store_api_register_endpoint_data' )->twice()->andReturn( true );
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

	public function test_pay_for_order_route_is_skipped_even_when_it_targets_the_session_draft(): void {
		$request = new \WP_REST_Request( [ 'id' => 123 ] );
		$request->set_url_params( [ 'id' => 123 ] );
		$order = new C2a_Order( $this->address );
		C2a_Adapter::update_order( $order, $request );
		$errors = new \WP_Error();
		C2a_Adapter::validate_order( $order, $errors );
		$this->assertFalse( $errors->has_errors() );
		$this->assertSame( 0, C2a_Adapter::$context_reads );
	}

	public function test_an_id_posted_in_the_checkout_body_does_not_skip_the_gate(): void {
		// get_param() prefers the body; only the /checkout/{id} route param marks pay-for-order.
		$request = new \WP_REST_Request( [ 'id' => 123, 'extensions' => [ 'woodev-shipping' => [] ] ] );
		$order   = new C2a_Order( $this->address );
		C2a_Adapter::update_order( $order, $request );
		$errors = new \WP_Error();
		C2a_Adapter::validate_order( $order, $errors );
		$this->assertTrue( $errors->has_errors() );
	}

	public function test_clear_after_switching_to_courier_is_a_noop_success(): void {
		C2a_Adapter::update( $this->command() );
		$this->session->data['chosen_shipping_methods'] = [ 'flat_rate:9' ];
		$before = $this->session->data;
		C2a_Adapter::update( $this->command( [ 'clear' => true ] ) );
		$this->assertSame( $before, $this->session->data );
		$this->assertSame( 1, $this->fetches );
	}

	public function test_invalid_later_command_does_not_apply_an_earlier_clear(): void {
		C2a_Adapter::update( $this->command() );
		Store_Api_Pickup::add_handler( $this->handler, 'second', 'second_point' );
		$before = $this->session->data;
		$data = $this->command( [ 'clear' => true ] );
		$data['pickup']['second']['second_point'] = [ 'point_id' => '' ];
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		try { C2a_Adapter::update( $data ); }
		finally { $this->assertSame( $before, $this->session->data ); }
	}

	public function test_later_carrier_denial_does_not_remember_an_earlier_selection(): void {
		Store_Api_Pickup::add_handler( $this->handler, 'second', 'second_point' );
		$count = 0;
		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->twice()->andReturnUsing( static function ( $verdict ) use ( &$count ) {
			return ++$count === 1 ? $verdict : [ 'allowed' => false, 'reason' => 'Domain refused' ];
		} );
		$data = $this->command();
		$data['pickup']['second']['second_point'] = [ 'point_id' => 'P2' ];
		$this->expectExceptionMessage( 'Domain refused' );
		try { C2a_Adapter::update( $data ); }
		finally { $this->assertArrayNotHasKey( 'installed_carrier_selection', $this->session->data ); }
	}

	public function test_cart_response_reads_existing_rates_without_calculating_shipping(): void {
		$cart = \Mockery::mock();
		$cart->shouldNotReceive( 'calculate_shipping' );
		$shipping = \Mockery::mock();
		$shipping->shouldReceive( 'get_packages' )->once()->andReturn( $this->packages );
		$wc = \Mockery::mock();
		$wc->cart = $cart;
		$wc->session = $this->session;
		$wc->shouldReceive( 'shipping' )->once()->andReturn( $shipping );
		Functions\when( 'WC' )->justReturn( $wc );
		$this->assertNull( Store_Api_Pickup::cart_data()['pickup']['carrier']['carrier_point'] );
	}

	public function test_first_attempt_shared_payment_hooks_return_one_refusal_for_the_carrier(): void {
		$wc = new \stdClass();
		$wc->session = $this->session;
		Functions\when( 'WC' )->justReturn( $wc );
		$checkout = new \Woodev\Framework\Shipping\Checkout\Checkout_Handler(
			\Woodev\Framework\Shipping\Checkout\Checkout_Fields::from_array( [
				\Woodev\Framework\Shipping\Checkout\Field::create( 'carrier_point' )->mark_pickup_slot()->to_array(),
			] ), 'carrier'
		);
		$checkout->set_requires_pickup_methods( [ 'carrier_pickup' ] );
		$order = new C2a_Order( $this->address );
		C2a_Adapter::update_order( $order, $this->request() );
		$errors = new \WP_Error();
		$checkout->handle_store_api_validate_order( $order, $errors );
		C2a_Adapter::validate_order( $order, $errors );
		$this->assertCount( 1, $errors->get_error_messages() );
		$this->assertStringContainsString( 'checkout page', $errors->get_error_message() );
	}

	public function test_bundled_copies_can_include_both_pickup_classes_again(): void {
		require __DIR__ . '/../../../../woodev/shipping-method/pickup/class-store-api-pickup.php';
		require __DIR__ . '/../../../../woodev/shipping-method/pickup/class-pickup-selection-service.php';
		$this->assertTrue( class_exists( Store_Api_Pickup::class, false ) );
		$this->assertTrue( class_exists( \Woodev\Framework\Shipping\Pickup\Pickup_Selection_Service::class, false ) );
	}

	public function test_store_api_confirmation_cannot_bypass_classic_selection_quota(): void {
		C2a_Adapter::$throttled = true;
		$this->expectExceptionCode( 429 );
		$this->expectExceptionMessage( 'Too many requests' );
		try { C2a_Adapter::update( [] ); }
		finally { $this->assertSame( 0, $this->fetches ); }
	}

	private function unscoped_handler(): C2a_Handler {
		$source = \Mockery::mock( Point_Source::class );
		$handler = new C2a_Handler( 'noscope', 'noscope_point', $source, new Order_Persistence_Test_Map_Provider(), [ 'center' => [ 55, 37 ], 'zoom' => 9 ] );
		$handler->fake_selection = $this->handler->fake_selection;
		return $handler;
	}

	private function noscope_order(): C2a_Order {
		$order = new C2a_Order( $this->address );
		$order->lines = [ new C2a_Line( 'noscope_pickup', 3 ) ];
		return $order;
	}

	public function test_an_unscoped_handler_never_owns_a_rate_which_is_the_dead_end(): void {
		$handler = $this->unscoped_handler();
		$this->assertFalse( $handler->has_selection_scope() );
		$this->assertFalse( $handler->owns_store_api_rate( 'noscope_pickup:3' ) );
		$this->assertTrue( $this->handler->has_selection_scope() );
	}

	public function test_a_pickup_rate_nobody_owns_is_refused_when_a_handler_has_no_scope(): void {
		Store_Api_Pickup::add_handler( $this->unscoped_handler(), 'noscope', 'noscope_point' );
		C2a_Adapter::$pickup_methods = [ 'carrier_pickup', 'noscope_pickup' ];
		$errors = $this->validate( $this->noscope_order() );
		$this->assertSame( 'woodev_pickup_unavailable', $errors->get_error_code() );
		$this->assertCount( 1, $errors->get_error_messages() );
		$this->assertStringContainsString( 'not available at checkout', $errors->get_error_message() );
	}

	public function test_an_unowned_pickup_rate_is_left_alone_when_every_handler_has_a_scope(): void {
		C2a_Adapter::$pickup_methods = [ 'carrier_pickup', 'noscope_pickup' ];
		$this->assertNotSame( 'woodev_pickup_unavailable', $this->validate( $this->noscope_order() )->get_error_code() );
	}

	public function test_a_foreign_rate_is_left_alone_even_when_a_handler_has_no_scope(): void {
		Store_Api_Pickup::add_handler( $this->unscoped_handler(), 'noscope', 'noscope_point' );
		C2a_Adapter::$pickup_methods = [ 'carrier_pickup' ];
		$this->assertNotSame( 'woodev_pickup_unavailable', $this->validate( $this->noscope_order() )->get_error_code() );
	}

	public function test_a_scoped_handlers_own_rate_is_never_refused_as_unserved(): void {
		Store_Api_Pickup::add_handler( $this->unscoped_handler(), 'noscope', 'noscope_point' );
		C2a_Adapter::$pickup_methods = [ 'carrier_pickup', 'noscope_pickup' ];
		$this->assertNotSame( 'woodev_pickup_unavailable', $this->validate()->get_error_code() );
	}

	public function test_registering_an_unscoped_handler_reports_it_once_to_developer_and_merchant(): void {
		Functions\expect( '_doing_it_wrong' )->once()->with( \Mockery::any(), \Mockery::pattern( '/"noscope".*Selection_Scope/' ), '2.0.2' );
		\Brain\Monkey\Actions\expectAdded( 'admin_notices' )->once();
		$handler = $this->unscoped_handler();
		$handler->register();
		$handler->register();
	}

	public function test_registering_a_scoped_handler_reports_nothing(): void {
		Functions\expect( '_doing_it_wrong' )->never();
		\Brain\Monkey\Actions\expectAdded( 'admin_notices' )->never();
		$this->handler->register();
	}

	public function test_the_merchant_notice_names_the_plugin_and_needs_the_capability(): void {
		$handler = $this->unscoped_handler();
		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		$handler->render_missing_selection_scope_notice();
		$this->assertSame( '', ob_get_clean() );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		ob_start();
		$handler->render_missing_selection_scope_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'noscope', $html );
	}

	public function test_the_merchant_notice_is_silent_on_a_classic_only_store_but_the_developer_signal_stays(): void {
		Functions\expect( '_doing_it_wrong' )->once();
		$handler = $this->unscoped_handler();
		$handler->block_checkout = false;
		\Brain\Monkey\Actions\expectAdded( 'admin_notices' )->once();
		$handler->register();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html' )->returnArg();
		ob_start();
		$handler->render_missing_selection_scope_notice();
		$this->assertSame( '', ob_get_clean() );
	}

}
