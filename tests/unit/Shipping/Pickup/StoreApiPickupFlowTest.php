<?php
/**
 * SP-11 C-3 (#1090): the Store API pickup flows ACROSS requests.
 *
 * `StoreApiPickupTest` covers one request at a time. These tests replay the sequences the block
 * checkout really produces — read from WooCommerce 11.1's `Routes/V1/Checkout.php` and
 * `Utilities/CheckoutTrait.php`, not recalled — and assert what the session and the order hold
 * after every step:
 *
 *  - a cart read (`cart/update-customer`, `cart/select-shipping-rate`): `cart_data()` only — these
 *    routes fire none of the checkout hooks;
 *  - a cart mutation (`cart/extensions`): `update()`;
 *  - a draft PUT/PATCH: `…_update_draft` with no order (WooCommerce 10.8+), or
 *    `…_update_order_from_request` with the session's retry order;
 *  - a place-order POST: the order is synced from the cart, then `…_update_order_from_request` →
 *    `…_validate_order_before_payment` → status `pending` → `…_order_processed` (every plugin's
 *    `Checkout_Handler` at priority 10, then every `Pickup_Handler` at 20) → payment, which the
 *    test fails or lets pass.
 *
 * A «late» request is one that carries what the browser held BEFORE a newer request changed the
 * server: it is replayed here in the order the server would see it.
 *
 * No WordPress, no shared rig database.
 */
namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Pickup_Selection;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Pickup\Selection_Scope;
use Woodev\Framework\Shipping\Pickup\Store_Api_Pickup;
use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
use Woodev\Tests\Unit\Shipping\Order\Order_Persistence_Test_Map_Provider;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/StoreApiPickupTest.php';

/** One carrier's scope: its own session key and its own pickup method. */
final class C3_Scope implements Selection_Scope {
	public string $locality = 'msk';
	private string $key;
	private string $method;
	public function __construct( string $key, string $method ) { $this->key = $key; $this->method = $method; }
	public function session_key(): string { return $this->key; }
	public function current_locality(): string { return $this->locality; }
	public function locality_for_point( Pickup_Point $point ): string { return $point->to_array()['locality']; }
	public function type_for_method( string $method_id ): ?string { return $this->method === $method_id ? 'PVZ' : null; }
}

/** A handler on the fake session — with the REAL draft-order resolution (unlike `C2a_Handler`). */
final class C3_Handler extends Pickup_Handler {
	public int $weight = 2000;
	public ?Pickup_Selection $fake_selection = null;
	public bool $replace_address = false;
	protected function selection(): ?Pickup_Selection { return $this->fake_selection; }
	protected function replaces_address(): bool { return $this->replace_address; }
	protected function wc_cart() { return new \stdClass(); }
	public function current_cart_weight_grams(): int { return $this->weight; }
	protected function log_carrier_failure( \Throwable $exception, string $context ): void {}
}

/** The cart, as far as WooCommerce's «is this order still the cart's draft» check reads it. */
final class C3_Cart {
	public string $hash = 'cart-1';
	public function get_cart_hash(): string { return $this->hash; }
}

/** An order with an identity, a status and the cart hash it was created from. */
final class C3_Order extends \WC_Order {
	public int $id;
	public string $status = 'checkout-draft';
	public string $payment = 'bacs';
	public string $cart_hash = 'cart-1';
	/** @var C2a_Line[] */
	public array $lines = [];
	public array $address = [];
	public function __construct( int $id ) { $this->id = $id; }
	// Typed: the suite's `WC_Order` stand-ins differ, and some declare `get_id(): int`.
	public function get_id(): int { return $this->id; }
	public function get_items( $type = 'line_item' ) { return 'shipping' === $type ? $this->lines : []; }
	public function get_address( $type = 'billing' ) { return $this->address; }
	public function get_payment_method() { return $this->payment; }
	public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
	public function needs_payment() { return in_array( $this->status, [ 'pending', 'failed' ], true ); }
	public function has_cart_hash( $hash = '' ) { return $hash === $this->cart_hash; }
}

/**
 * The flows stub `WC()` and `wc_get_order()`; a function Brain Monkey once defined stays defined
 * for the process, and code elsewhere feature-detects both. Isolate them from the other suites.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class StoreApiPickupFlowTest extends TestCase {
	private const RATE = 'carrier_pickup:7';

	private C2a_Session $session;
	private C3_Cart $cart;
	private array $address = [ 'country' => 'RU', 'state' => 'MOW', 'city' => 'Moscow', 'postcode' => '123', 'address_1' => 'Street' ];
	private array $packages;
	private array $gateways = [ 'bacs' => true, 'cod' => true ];
	/** @var array<int, array<string, mixed>> Order meta, by order id. */
	private array $meta = [];
	/** @var array<int, array{0: string, 1: int, 2: string, 3?: mixed}> Every meta write and delete, in order. */
	private array $writes = [];
	/** @var array<int, array{0: string, 1: array}> Every framework hook fired, in order. */
	private array $hooks = [];
	/** @var array<int, C3_Order> */
	private array $orders = [];
	/** @var array<string, array{handler: C3_Handler, checkout: Checkout_Handler, scope: C3_Scope, field: string}> */
	private array $carriers = [];
	/** @var array<string, array<string, mixed>> Point data the carriers answer with, by point id. */
	private array $points = [];
	/** @var array<string, int> Carrier detail requests, by plugin id. */
	private array $fetches = [];

	protected function setUp(): void {
		parent::setUp();
		$this->reset_adapter();
		$this->session = new C2a_Session();
		$this->session->data = [ 'chosen_shipping_methods' => [ self::RATE ] ];
		$this->cart = new C3_Cart();
		$this->packages = [ [ 'destination' => $this->address, 'rates' => [
			self::RATE => true, 'carrier_pickup:8' => true, 'second_pickup:3' => true, 'flat_rate:9' => true,
		] ] ];
		$this->points = [ 'P1' => $this->point( 'P1' ), 'P2' => $this->point( 'P2' ) ];

		Functions\when( 'wc_clean' )->alias( static fn( $value ) => is_array( $value ) ? $value : trim( (string) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wc_parse_relative_date_option' )->justReturn( [ 'number' => '', 'unit' => 'days' ] );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d ) => number_format( $n, $d ) );
		Functions\when( 'wc_add_notice' )->justReturn( null );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key, $single = true ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
			$this->meta[ $id ][ $key ] = $value;
			$this->writes[] = [ 'set', $id, $key, $value ];
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) {
			unset( $this->meta[ $id ][ $key ] );
			$this->writes[] = [ 'delete', $id, $key ];
			return true;
		} );
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->orders[ $id ] ?? false );
		$wc = new \stdClass();
		$wc->session = $this->session;
		$wc->cart = $this->cart;
		Functions\when( 'WC' )->justReturn( $wc );
		// The selected action reaches every handler, as the one global hook does.
		Functions\when( 'do_action' )->alias( function ( $hook, ...$args ) {
			$this->hooks[] = [ $hook, $args ];
			if ( 'woodev_shipping_pickup_point_selected' === $hook ) {
				foreach ( $this->carriers as $carrier ) { $carrier['handler']->remember_selection( ...$args ); }
			}
		} );
		// Every pickup handler sits on the one posted-data filter.
		Filters\expectApplied( 'woodev_shipping_store_api_posted_data' )->zeroOrMoreTimes()->andReturnUsing( function ( $posted, $order ) {
			foreach ( $this->carriers as $carrier ) { $posted = $carrier['handler']->contribute_store_api_posted_data( $posted, $order ); }
			return $posted;
		} );

		C2a_Adapter::$throttled = false;
		C2a_Adapter::$context_reads = 0;
		C2a_Adapter::$writes = [];
		C2a_Adapter::$unserved_postcode = null;
		C2a_Adapter::$session = $this->session;
		C2a_Adapter::$packages =& $this->packages;
		C2a_Adapter::$gateways =& $this->gateways;
		Location_Provider_Registry::instance()->reset_for_tests();
		Shipping_Settings_Tab::reset_for_tests();

		$this->carrier( 'carrier', 'carrier_point', 'carrier_pickup' );
	}

	protected function tearDown(): void {
		$this->reset_adapter();
		Checkout_Handler::reset_native_field_registry();
		Shipping_Settings_Tab::reset_for_tests();
		parent::tearDown();
	}

	private function reset_adapter(): void {
		foreach ( [ 'handlers' => [], 'booted' => false, 'registered' => false, 'echoes' => [] ] as $name => $value ) {
			$property = new \ReflectionProperty( Store_Api_Pickup::class, $name );
			if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
			$property->setValue( null, $value );
		}
	}

	private function point( string $id, array $over = [] ): array {
		return $over + [ 'id' => $id, 'name' => 'Point ' . $id, 'lat' => 55.7, 'lng' => 37.6, 'address' => 'Point street ' . $id,
			'locality' => 'msk', 'type' => [ 'code' => 'PVZ', 'label' => 'Pickup' ] ];
	}

	/** Wires one carrier plugin the way its `register()` does: a pickup handler and a checkout handler. */
	private function carrier( string $plugin, string $field, string $method ): void {
		$this->fetches[ $plugin ] = 0;
		$source = \Mockery::mock( Point_Source::class );
		$source->shouldReceive( 'fetch_details' )->andReturnUsing( function ( $id ) use ( $plugin ) {
			++$this->fetches[ $plugin ];
			return isset( $this->points[ $id ] ) ? Pickup_Point::from_array( $this->points[ $id ] ) : null;
		} );
		$scope = new C3_Scope( $plugin . '_selection', $method );
		$handler = new C3_Handler( $plugin, $field, $source, new Order_Persistence_Test_Map_Provider(), [ 'center' => [ 55, 37 ], 'zoom' => 9 ],
			new Shipping_Order_Handler( [ 'pickup_full' => $plugin . '_full' ] ), 'pickup_full', [], '#123456', '', true, false, $scope );
		$selection = new C2a_Selection( $scope );
		$selection->fake_session = $this->session;
		$handler->fake_selection = $selection;
		$checkout = new Checkout_Handler( Checkout_Fields::from_array( [ Field::create( $field )->mark_pickup_slot()->to_array() ] ), $plugin );
		$checkout->set_requires_pickup_methods( [ $method ] );
		$this->carriers[ $plugin ] = [ 'handler' => $handler, 'checkout' => $checkout, 'scope' => $scope, 'field' => $field ];
		Store_Api_Pickup::add_handler( $handler, $plugin, $field );
	}

	/** A new PHP request: the adapter's echoes and each handler's memoized carrier lookups are per request. */
	private function begin_request(): void {
		$property = new \ReflectionProperty( Store_Api_Pickup::class, 'echoes' );
		if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
		$property->setValue( null, [] );
		foreach ( $this->carriers as $carrier ) {
			foreach ( [ 'fetched_points', 'fetch_failures' ] as $name ) {
				$memo = new \ReflectionProperty( Pickup_Handler::class, $name );
				if ( PHP_VERSION_ID < 80100 ) { $memo->setAccessible( true ); }
				$memo->setValue( $carrier['handler'], [] );
			}
		}
	}

	/** `POST cart/extensions`: confirm a point (or send any other command) for one carrier. */
	private function confirm( string $point_id = 'P1', string $plugin = 'carrier', array $command = [] ): void {
		$this->begin_request();
		C2a_Adapter::update( [ 'pickup' => [ $plugin => [ $this->carriers[ $plugin ]['field'] => $command + [ 'point_id' => $point_id, 'payment_method' => 'bacs' ] ] ] ] );
	}

	/** What a cart response carries for one carrier's field. */
	private function snapshot( string $plugin = 'carrier' ): ?array {
		return C2a_Adapter::cart_data()['pickup'][ $plugin ][ $this->carriers[ $plugin ]['field'] ];
	}

	/** The echo the block sends with a checkout request, built from the cart it holds (`buildEcho()`). */
	private function echo_of_cart(): array {
		$data = C2a_Adapter::cart_data();
		$echo = [];
		foreach ( $data['pickup'] as $plugin => $fields ) {
			foreach ( $fields as $field => $snapshot ) {
				$owned = null !== $data['owner'] && $plugin === $data['owner']['plugin_id'] && $field === $data['owner']['field_id'];
				$echo[ $plugin ][ $field ] = $owned && null !== $snapshot && $snapshot['rate_id'] === $data['owner']['rate_id']
					? array_intersect_key( $snapshot, array_flip( [ 'plugin_id', 'field_id', 'point_id', 'locality', 'rate_id' ] ) )
					: null;
			}
		}
		return [ 'pickup' => $echo ];
	}

	private function request( array $echo ): \WP_REST_Request {
		return new \WP_REST_Request( [ 'extensions' => [ 'woodev-shipping' => $echo ] ] );
	}

	/** `OrderController::update_order_from_cart()`: the order follows the cart's rate and destination. */
	private function sync_order_from_cart( C3_Order $order ): void {
		$rate = (string) ( $this->session->data['chosen_shipping_methods'][0] ?? '' );
		$order->lines = '' === $rate ? [] : [ new C2a_Line( explode( ':', $rate )[0], (int) ( explode( ':', $rate )[1] ?? 0 ) ) ];
		$order->address = $this->packages[0]['destination'];
		$order->cart_hash = $this->cart->hash;
		$this->orders[ $order->id ] = $order;
		$this->session->data['store_api_draft_order'] = $order->id;
	}

	/** `PUT/PATCH /checkout`: with the session's retry order, or with no order (deferred draft). */
	private function patch( ?C3_Order $order = null, array $echo = [] ): void {
		$this->begin_request();
		if ( null === $order ) {
			C2a_Adapter::update_draft( $this->request( $echo ) );
			return;
		}
		$this->sync_order_from_cart( $order );
		C2a_Adapter::update_order( $order, $this->request( $echo ) );
	}

	/** `POST /checkout` up to the payment: the errors that refused it, or none once it was processed. */
	private function post( C3_Order $order, array $echo ): \WP_Error {
		$this->begin_request();
		$this->sync_order_from_cart( $order );
		C2a_Adapter::update_order( $order, $this->request( $echo ) );
		$errors = new \WP_Error();
		foreach ( $this->carriers as $carrier ) { $carrier['checkout']->handle_store_api_validate_order( $order, $errors ); }
		C2a_Adapter::validate_order( $order, $errors );
		if ( $errors->has_errors() ) {
			return $errors;
		}
		$order->status = 'pending';
		foreach ( $this->carriers as $carrier ) { $carrier['checkout']->handle_store_api_order_processed( $order ); }
		foreach ( $this->carriers as $carrier ) { $carrier['handler']->handle_store_api_order_processed( $order ); }
		return $errors;
	}

	private function choose_rate( string $rate ): void { $this->session->data['chosen_shipping_methods'] = [ $rate ]; }
	private function move_to( array $fields ): void { $this->packages[0]['destination'] = array_merge( $this->packages[0]['destination'], $fields ); }
	private function remembered( string $plugin = 'carrier', string $method = 'carrier_pickup' ): string {
		return (string) ( $this->carriers[ $plugin ]['handler']->get_selected_point_for_method( $method )['point_id'] ?? '' );
	}
	/** @return array<int, array> The writes of one meta key on one order. */
	private function writes_of( int $order_id, string $key ): array {
		return array_values( array_filter( $this->writes, static fn( array $write ) => $write[1] === $order_id && $write[2] === $key ) );
	}
	/** @return array<int, array> The payloads one framework hook was fired with. */
	private function fired( string $hook ): array {
		return array_values( array_map( static fn( array $event ) => $event[1], array_filter( $this->hooks, static fn( array $event ) => $event[0] === $hook ) ) );
	}

	// ----- Rapid address updates ------------------------------------------------------------------

	public function test_rapid_address_updates_show_the_point_only_for_the_destination_it_was_confirmed_for(): void {
		$this->confirm();
		$session = $this->session->data;

		// Three address pushes in a row (`cart/update-customer` fires no checkout hook): nothing is
		// written, and only the destination the point was confirmed for shows it.
		foreach ( [ [ 'postcode' => '999' ], [ 'city' => 'Tula' ], [ 'address_1' => 'Other street' ] ] as $edit ) {
			$this->move_to( $edit );
			$this->assertNull( $this->snapshot() );
		}
		$this->assertSame( $session, $this->session->data );
		$this->packages[0]['destination'] = $this->address;
		$this->assertSame( 'P1', $this->snapshot()['point_id'] );
		$this->assertSame( 1, $this->fetches['carrier'] );
	}

	public function test_a_late_order_placed_with_the_previous_address_echo_is_refused_and_repairs_nothing(): void {
		$this->confirm();
		$echo = $this->echo_of_cart();

		// The shopper edits the address and presses Place Order inside the address push's debounce:
		// the request carries the NEW address and the echo of the point confirmed for the OLD one.
		$this->move_to( [ 'address_1' => 'Other street' ] );
		$order = new C3_Order( 501 );
		$errors = $this->post( $order, $echo );

		$this->assertSame( [ 'Please choose a pickup point on the checkout page before paying.' ], $errors->get_error_messages() );
		$this->assertSame( '', $this->remembered() );
		$this->assertSame( [], $this->writes_of( 501, 'carrier_point' ), 'nothing was persisted for a refused order' );
		$this->assertSame( [], $this->fired( 'woodev_shipping_carrier_checkout_processed' ) );

		// Going back to the old address does not resurrect what the refusal cleared…
		$this->packages[0]['destination'] = $this->address;
		$this->assertNull( $this->snapshot() );
		// …and the same late request, sent again, is refused again without another carrier request.
		$this->assertTrue( $this->post( $order, $echo )->has_errors() );
		$this->assertSame( 1, $this->fetches['carrier'] );
	}

	public function test_repeated_draft_updates_clear_a_moved_confirmation_once(): void {
		$this->confirm();
		$this->move_to( [ 'city' => 'Tula' ] );

		$this->patch();
		$after_first = $this->session->data;
		$this->patch();
		$this->patch();

		$this->assertSame( '', $this->remembered() );
		$this->assertSame( $after_first, $this->session->data, 'a second and third draft update change nothing' );
		$this->assertSame( [], $this->writes, 'the deferred draft has no order to write to' );
	}

	// ----- Corrected point and address replacement ------------------------------------------------

	public function test_a_corrected_point_is_the_one_echoed_validated_and_persisted(): void {
		$this->points['P1-fixed'] = $this->point( 'P1-fixed' );
		Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )->once()->andReturnUsing(
			fn( array $verdict ) => [ 'allowed' => true, 'reason' => null, 'point' => $this->points['P1-fixed'] ]
		);
		$this->confirm( 'P1' );
		$this->assertSame( 'P1-fixed', $this->snapshot()['point_id'] );

		// The browser asked for P1; an echo that still names it is not the confirmation the server holds.
		$stale = $this->echo_of_cart();
		$stale['pickup']['carrier']['carrier_point']['point_id'] = 'P1';
		$order = new C3_Order( 502 );
		$this->assertTrue( $this->post( $order, $stale )->has_errors() );
		$this->assertSame( 'P1-fixed', $this->remembered(), 'a refused echo neither clears nor repairs the confirmation' );

		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );
		$this->assertSame( 'P1-fixed', $this->meta[502]['carrier_point'] );
		$this->assertSame( 'P1-fixed', $this->meta[502]['carrier_full']['id'] );
	}

	public function test_address_replacement_survives_draft_updates_and_ends_with_the_next_manual_edit(): void {
		$this->carriers['carrier']['handler']->replace_address = true;
		$this->points['P1']['postal_code'] = '101000';
		$this->confirm();
		$moved = [ 'address_1' => 'Point street P1', 'postcode' => '101000' ];
		$this->assertSame( $moved, $this->snapshot()['destination'] );

		// The block's own draft updates (payment method, order notes) leave the moved confirmation alone.
		$this->patch();
		$this->patch();
		$this->assertSame( 'P1', $this->snapshot()['point_id'] );
		$this->assertSame( [ $moved ], C2a_Adapter::$writes, 'the destination moved once, with the confirmation' );

		// The shopper types their own street back: the confirmation was for the point's address.
		$this->move_to( [ 'address_1' => 'Street' ] );
		$this->assertNull( $this->snapshot() );
		$this->assertTrue( $this->post( new C3_Order( 503 ), [] )->has_errors() );
	}

	// ----- Method-instance and carrier switches ---------------------------------------------------

	public function test_a_method_instance_switch_drops_the_point_and_switching_back_does_not_resurrect_it(): void {
		$this->confirm();
		$echo = $this->echo_of_cart();

		$this->choose_rate( 'carrier_pickup:8' );
		$this->assertNull( $this->snapshot() );
		$this->assertSame( 'carrier_pickup:8', C2a_Adapter::cart_data()['owner']['rate_id'] );

		// A late order: the echo of instance 7's confirmation, placed on instance 8.
		$order = new C3_Order( 504 );
		$this->assertTrue( $this->post( $order, $echo )->has_errors() );
		$this->assertSame( '', $this->remembered() );

		$this->choose_rate( self::RATE );
		$this->assertNull( $this->snapshot() );
		$this->assertTrue( $this->post( $order, $echo )->has_errors() );
		$this->assertSame( [], $this->writes_of( 504, 'carrier_point' ) );
	}

	public function test_a_carrier_switch_keeps_each_carriers_point_apart_and_reports_one_error(): void {
		$this->carrier( 'second', 'second_point', 'second_pickup' );
		$this->confirm( 'P1' );
		$first_echo = $this->echo_of_cart();

		$this->choose_rate( 'second_pickup:3' );
		$cart = C2a_Adapter::cart_data();
		$this->assertSame( 'second', $cart['owner']['plugin_id'] );
		$this->assertNull( $cart['pickup']['carrier']['carrier_point'], 'the first carrier shows no point on the second carrier’s rate' );
		$this->assertNull( $cart['pickup']['second']['second_point'] );

		// No point for the second carrier yet: ONE refusal, and it is the owner's.
		$order = new C3_Order( 505 );
		$errors = $this->post( $order, $this->echo_of_cart() );
		$this->assertSame( [ 'Please choose a pickup point on the checkout page before paying.' ], $errors->get_error_messages() );

		// A late echo of the FIRST carrier's confirmation cannot stand in for the second carrier's point.
		$this->assertTrue( $this->post( $order, $first_echo )->has_errors() );

		// A clear sent for the second carrier is scoped to it.
		$this->confirm( 'P2', 'second' );
		$this->confirm( '', 'second', [ 'clear' => true ] );
		$this->assertSame( '', $this->remembered( 'second', 'second_pickup' ) );
		$this->assertSame( 'P1', $this->remembered(), 'the first carrier’s memory is its own' );

		$this->confirm( 'P2', 'second' );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );
		$this->assertSame( 'P2', $this->meta[505]['second_point'] );
		$this->assertSame( 'P2', $this->meta[505]['second_full']['id'] );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta[505], 'the order carries the second carrier’s point only' );
		$this->assertArrayNotHasKey( 'carrier_full', $this->meta[505] );
		$this->assertSame( 1, $this->fetches['carrier'], 'the first carrier was asked once, for its own confirmation' );
	}

	// ----- Payment switches -----------------------------------------------------------------------

	public function test_a_payment_switch_is_rechecked_at_payment_and_keeps_the_confirmation(): void {
		$this->points['P1']['accepts_cod'] = false;
		$this->confirm();
		$echo = $this->echo_of_cart();

		// The payment method changes after the point was confirmed; the block sends a draft update.
		$this->patch();
		$order = new C3_Order( 506 );
		$order->payment = 'cod';
		$errors = $this->post( $order, $echo );
		$this->assertStringContainsString( 'cash on delivery', $errors->get_error_message() );
		$this->assertSame( 'P1', $this->remembered(), 'the point is not dropped: the shopper may switch the payment back' );
		$this->assertSame( [], $this->writes );

		$order->payment = 'bacs';
		$this->assertFalse( $this->post( $order, $echo )->has_errors() );
		$this->assertSame( 'P1', $this->meta[506]['carrier_point'] );
	}

	// ----- Payment retries ------------------------------------------------------------------------

	public function test_the_first_attempt_persists_the_point_and_clears_the_memory_once(): void {
		$this->confirm();
		$order = new C3_Order( 507 );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );

		$this->assertSame( [ [ 'set', 507, 'carrier_point', 'P1' ] ], $this->writes_of( 507, 'carrier_point' ) );
		$this->assertCount( 1, $this->writes_of( 507, 'carrier_full' ) );
		$this->assertSame( '', $this->remembered() );
		$this->assertCount( 1, $this->fired( 'woodev_shipping_carrier_checkout_processed' ) );
		// One carrier request to confirm, one for the order: the full point reuses the pre-payment re-check's.
		$this->assertSame( 2, $this->fetches['carrier'] );
	}

	public function test_a_payment_retry_keeps_the_point_the_order_was_placed_with(): void {
		$this->confirm();
		$echo = $this->echo_of_cart();
		$order = new C3_Order( 508 );
		$this->assertFalse( $this->post( $order, $echo )->has_errors() );
		$order->status = 'failed';
		$fetches = $this->fetches['carrier'];

		// The shopper retries with what the page still shows: the cart was not refreshed, so the
		// echo is the original confirmation, and the session's memory was cleared by the first attempt.
		$this->assertFalse( $this->post( $order, $echo )->has_errors() );

		$this->assertSame( 'P1', $this->meta[508]['carrier_point'], 'the retry must not write a blank over the stored point id' );
		$this->assertSame( 'P1', $this->meta[508]['carrier_full']['id'] );
		$this->assertCount( 1, $this->writes_of( 508, 'carrier_full' ), 'the full point is persisted once, by the first attempt' );
		$this->assertSame( 1, $this->fetches['carrier'] - $fetches, 'the retry asks the carrier once: the pre-payment re-check' );
		$processed = $this->fired( 'woodev_shipping_carrier_checkout_processed' );
		$this->assertCount( 2, $processed );
		$this->assertSame( 'P1', $processed[1][1]['carrier_point'], 'the retry announces the point, not a blank' );

		// A third attempt is the second one over again.
		$order->status = 'failed';
		$this->assertFalse( $this->post( $order, $echo )->has_errors() );
		$this->assertSame( 'P1', $this->meta[508]['carrier_point'] );
	}

	public function test_a_payment_retry_without_a_point_is_refused(): void {
		$this->confirm();
		$order = new C3_Order( 509 );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );
		$order->status = 'failed';

		// The retry order loses its point (an explicit clear from the cart): nothing may resurrect it.
		$this->confirm( '', 'carrier', [ 'clear' => true ] );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta[509] );
		$this->assertSame( '', $this->meta[509]['carrier_full'] );

		foreach ( [ 'failed', 'pending' ] as $status ) {
			$order->status = $status;
			$errors = $this->post( $order, [] );
			$this->assertSame( [ 'Please choose a pickup point on the checkout page before paying.' ], $errors->get_error_messages() );
		}
		$this->assertCount( 1, $this->fired( 'woodev_shipping_carrier_checkout_processed' ), 'a refused retry is not processed' );
	}

	public function test_a_retry_with_another_point_replaces_the_stored_one_once(): void {
		$this->confirm( 'P1' );
		$order = new C3_Order( 510 );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );
		$order->status = 'failed';

		$this->confirm( 'P2' );
		$this->assertSame( 'P2', $this->snapshot()['point_id'] );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );

		$this->assertSame( 'P2', $this->meta[510]['carrier_point'] );
		$this->assertSame( 'P2', $this->meta[510]['carrier_full']['id'] );
		$this->assertSame( [ 'P1', 'P2' ], array_column( $this->writes_of( 510, 'carrier_point' ), 3 ) );
		$this->assertSame( '', $this->remembered() );
	}

	public function test_a_draft_update_of_the_retry_order_leaves_its_point_alone(): void {
		$this->confirm();
		$echo = $this->echo_of_cart();
		$order = new C3_Order( 511 );
		$this->assertFalse( $this->post( $order, $echo )->has_errors() );
		$order->status = 'failed';
		$writes = $this->writes;

		// The shopper picks another payment method for the retry: an order-backed PUT, no echo.
		$this->patch( $order );
		$this->patch( $order );

		$this->assertSame( $writes, $this->writes, 'no clear and no second persistence' );
		$this->assertSame( 'P1', $this->meta[511]['carrier_point'] );
		$this->assertFalse( $this->post( $order, $echo )->has_errors() );
	}

	public function test_a_retry_order_switched_to_another_carrier_drops_the_previous_carriers_point(): void {
		$this->carrier( 'second', 'second_point', 'second_pickup' );
		$this->confirm( 'P1' );
		$order = new C3_Order( 512 );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );
		$order->status = 'failed';

		// Same total, so WooCommerce reuses the order — now on the second carrier's rate.
		$this->choose_rate( 'second_pickup:3' );
		$this->confirm( 'P2', 'second' );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );

		$this->assertSame( 'P2', $this->meta[512]['second_point'] );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta[512], 'the reused order no longer names the first carrier’s point' );
		$this->assertSame( '', $this->meta[512]['carrier_full'] );
	}

	public function test_a_retry_order_switched_to_a_courier_rate_drops_its_point_for_good(): void {
		$this->confirm();
		$echo = $this->echo_of_cart();
		$order = new C3_Order( 513 );
		$this->assertFalse( $this->post( $order, $echo )->has_errors() );
		$order->status = 'failed';

		$this->choose_rate( 'flat_rate:9' );
		$this->patch( $order );
		$this->assertArrayNotHasKey( 'carrier_point', $this->meta[513] );
		$this->patch( $order );
		$this->assertCount( 1, array_filter( $this->writes_of( 513, 'carrier_point' ), static fn( array $write ) => 'delete' === $write[0] ), 'dropped once' );

		// Back on the pickup rate, the first attempt's point is gone: a late echo of it is refused.
		$this->choose_rate( self::RATE );
		$this->assertTrue( $this->post( $order, $echo )->has_errors() );
	}

	public function test_a_handler_without_a_selection_scope_keeps_what_its_order_carries(): void {
		// No scope: the handler owns no rate on the Store API at all (#1100) — that is not «the
		// carrier no longer owns the order's rate», and its order data is not dropped on a retry.
		$source = \Mockery::mock( Point_Source::class );
		$legacy = new C3_Handler( 'legacy', 'legacy_point', $source, new Order_Persistence_Test_Map_Provider(), [ 'center' => [ 55, 37 ], 'zoom' => 9 ],
			new Shipping_Order_Handler( [ 'pickup_full' => 'legacy_full' ] ), 'pickup_full', [], '#123456', '', true, false, null );
		Store_Api_Pickup::add_handler( $legacy, 'legacy', 'legacy_point' );

		$this->confirm();
		$order = new C3_Order( 516 );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );
		$order->status = 'failed';
		$this->meta[516]['legacy_point'] = 'L1';
		$this->meta[516]['legacy_full'] = [ 'id' => 'L1' ];
		$writes = $this->writes;

		$this->patch( $order );
		$this->choose_rate( 'flat_rate:9' );
		$this->patch( $order );

		$this->assertSame( 'L1', $this->meta[516]['legacy_point'] );
		$this->assertSame( [ 'id' => 'L1' ], $this->meta[516]['legacy_full'] );
		$this->assertSame( [], array_filter( array_slice( $this->writes, count( $writes ) ), static fn( array $write ) => 0 === strpos( $write[2], 'legacy_' ) ) );
	}

	public function test_an_order_the_cart_has_moved_on_from_is_never_cleared_through_the_cart(): void {
		$this->confirm();
		$order = new C3_Order( 514 );
		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );
		$order->status = 'failed';
		$writes = $this->writes;

		// The shopper changes the cart: WooCommerce no longer reuses the failed order (it stays
		// payable from My Account), but the session still names it.
		$this->cart->hash = 'cart-2';
		$this->confirm( 'P2' );
		$this->move_to( [ 'city' => 'Tula' ] );
		$this->patch();
		$this->confirm( '', 'carrier', [ 'clear' => true ] );

		$this->assertSame( $writes, $this->writes, 'the existing order is not this cart’s draft' );
		$this->assertSame( 'P1', $this->meta[514]['carrier_point'] );
		$this->assertSame( 'P1', $this->meta[514]['carrier_full']['id'] );
	}

	// ----- Late echoes ----------------------------------------------------------------------------

	public function test_a_late_echo_of_a_replaced_confirmation_is_refused_and_the_newer_point_stays(): void {
		$this->confirm( 'P1' );
		$late = $this->echo_of_cart();
		$this->confirm( 'P2' );

		$order = new C3_Order( 515 );
		$this->assertTrue( $this->post( $order, $late )->has_errors() );
		$this->assertSame( 'P2', $this->remembered() );
		$this->assertSame( [], $this->writes );

		$this->assertFalse( $this->post( $order, $this->echo_of_cart() )->has_errors() );
		$this->assertSame( 'P2', $this->meta[515]['carrier_point'] );
	}
}
