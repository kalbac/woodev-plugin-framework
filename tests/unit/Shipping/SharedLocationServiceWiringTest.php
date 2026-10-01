<?php
/**
 * Unit: the framework wires ONE Location_Service per plugin into the readers of a render (#1036).
 *
 * The lazily-memoized default locality (#1025) is a field of the Location_Service INSTANCE. A
 * classic-checkout render reads the customer record through the checkout handler, the pickup
 * handler (through `$plugin->get_location_service()`) and the provider-selection scope; when each
 * held its own instance, a failing provider cost one wait PER reader. `Shipping_Plugin::add_hooks()`
 * now hands the plugin's instance to the checkout handler and `Pickup_Handler::register()` hands it
 * to a `Provider_Selection_Scope` — unless an instance was injected explicitly.
 *
 * Reuses PickupHandlerTest.php's doubles (`Pickup_Handler_Test_Source`, `…_Map_Provider`,
 * `Pickup_Handler_Location_Fixture_Plugin`) rather than redeclaring them.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Location\Customer_Location_Store;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/pickup/class-provider-selection-scope.php';
require_once __DIR__ . '/Pickup/PickupHandlerTest.php';

/**
 * A session stand-in whose store always accepts writes.
 */
final class Shared_Service_Fake_Session {

	/** @var array<string, mixed> */
	private array $store = [];

	/**
	 * @param string $key     Session key.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return $this->store[ $key ] ?? $default;
	}

	/**
	 * @param string $key   Session key.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	public function set( $key, $value ): void {
		$this->store[ $key ] = $value;
	}
}

/**
 * Customer store reading and writing a {@see Shared_Service_Fake_Session}.
 */
final class Shared_Service_Customer_Store extends Customer_Location_Store {

	private Shared_Service_Fake_Session $fake_session;

	public function __construct() {
		$this->fake_session = new Shared_Service_Fake_Session();
	}

	protected function session() {
		return $this->fake_session;
	}
}

/**
 * A {@see Location_Service} whose default-locality lookup is a provider MISS (or hang) that counts
 * how often it was asked — the real memo, the real `get_customer_record()`, a counted `resolve_default()`.
 */
final class Shared_Service_Counting_Service extends Location_Service {

	public int $asked = 0;

	public function __construct() {
		parent::__construct( Location_Provider_Registry::instance(), new Shared_Service_Customer_Store() );
	}

	public function resolve_default(): ?Location_Record {
		++$this->asked;

		return null;
	}
}

/**
 * A provider-backed scope exposing its façade.
 */
final class Shared_Service_Scope extends Provider_Selection_Scope {

	public function service(): Location_Service {
		return $this->location_service();
	}

	public function session_key(): string {
		return 'shared_service_scope';
	}

	public function type_for_method( string $method_id ): ?string {
		return self::TYPE_ANY;
	}

	public function locality_for_point( \Woodev\Framework\Shipping\Pickup\Pickup_Point $point ): string {
		return '';
	}
}

/**
 * A checkout handler whose `register()` wires no WordPress hooks — only the service seam is under test.
 */
final class Shared_Service_Checkout_Handler extends Checkout_Handler {

	public function register(): void {
	}
}

/**
 * The carrier plugin: hands the framework its own checkout handler and a fixed location service.
 */
final class Shared_Service_Plugin extends \Woodev\Tests\Unit\Shipping\Pickup\Pickup_Handler_Location_Fixture_Plugin {

	public ?Checkout_Handler $fake_checkout = null;

	public function needs_location_provider(): bool {
		return false;
	}

	public function get_checkout_handler(): ?Checkout_Handler {
		return $this->fake_checkout;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::add_hooks
 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler::register
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::adopt_location_service
 * @covers \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope::adopt_location_service
 */
final class SharedLocationServiceWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_filter', 'add_action', 'wp_parse_args' ] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );
		Functions\when( 'wc_parse_relative_date_option' )->justReturn( [ 'number' => '', 'unit' => 'days' ] );

		Location_Provider_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		parent::tearDown();
	}

	private function plugin( Location_Service $service, ?Checkout_Handler $checkout ): Shared_Service_Plugin {
		$plugin = ( new \ReflectionClass( Shared_Service_Plugin::class ) )->newInstanceWithoutConstructor();

		$plugin->fake_location_service = $service;
		$plugin->fake_checkout         = $checkout;

		return $plugin;
	}

	private function checkout_handler( ?Location_Service $explicit = null ): Shared_Service_Checkout_Handler {
		return new Shared_Service_Checkout_Handler( new Checkout_Fields(), 'carrier', $explicit );
	}

	private function pickup_handler( Shipping_Plugin $plugin, ?Provider_Selection_Scope $scope ): Pickup_Handler {
		return new Pickup_Handler(
			'carrier',
			'carrier_pickup_point',
			new \Woodev\Tests\Unit\Shipping\Pickup\Pickup_Handler_Test_Source( Point_Source::STRATEGY_BULK, static fn( string $id ) => null ),
			new \Woodev\Tests\Unit\Shipping\Pickup\Pickup_Handler_Test_Map_Provider( 'yandex', [] ),
			[ 'center' => [ 55.75, 37.61 ], 'zoom' => 10 ],
			null,
			null,
			[],
			'#06aedd',
			'',
			true,
			false,
			$scope,
			$plugin
		);
	}

	private function run_add_hooks( Shipping_Plugin $plugin ): void {
		$add_hooks = new \ReflectionMethod( Shipping_Plugin::class, 'add_hooks' );
		if ( PHP_VERSION_ID < 80100 ) {
			$add_hooks->setAccessible( true );
		}
		$add_hooks->invoke( $plugin );
	}

	/** The checkout handler's private façade accessor — the one its own reads go through. */
	private function checkout_service( Checkout_Handler $handler ): Location_Service {
		$accessor = new \ReflectionMethod( Checkout_Handler::class, 'location_service' );
		if ( PHP_VERSION_ID < 80100 ) {
			$accessor->setAccessible( true );
		}

		return $accessor->invoke( $handler );
	}

	public function test_one_render_s_readers_share_the_plugin_instance_and_a_failing_provider_is_asked_once(): void {
		$service  = new Shared_Service_Counting_Service();
		$checkout = $this->checkout_handler();
		$scope    = new Shared_Service_Scope();
		$plugin   = $this->plugin( $service, $checkout );
		$pickup   = $this->pickup_handler( $plugin, $scope );

		$this->run_add_hooks( $plugin );
		$pickup->register();

		$this->assertSame( $service, $this->checkout_service( $checkout ), 'the checkout handler reads the plugin instance' );
		$this->assertSame( $service, $scope->service(), 'the selection scope reads the plugin instance' );

		// One render: the checkout config, the pickup handler and the scope each read the record.
		$this->assertNull( $this->checkout_service( $checkout )->get_customer_record() );
		$this->assertNull( $plugin->get_location_service()->get_customer_record() );
		$this->assertNull( $scope->service()->get_customer_record() );

		$this->assertSame( 1, $service->asked, 'a failing provider is waited on once per render, not once per reader' );
	}

	public function test_an_explicitly_injected_instance_is_never_replaced(): void {
		$plugin_service   = new Shared_Service_Counting_Service();
		$checkout_service = new Shared_Service_Counting_Service();
		$scope_service    = new Shared_Service_Counting_Service();

		$checkout = $this->checkout_handler( $checkout_service );
		$scope    = new Shared_Service_Scope( $scope_service );
		$plugin   = $this->plugin( $plugin_service, $checkout );
		$pickup   = $this->pickup_handler( $plugin, $scope );

		$this->run_add_hooks( $plugin );
		$pickup->register();

		$this->assertSame( $checkout_service, $this->checkout_service( $checkout ) );
		$this->assertSame( $scope_service, $scope->service() );
	}

	public function test_a_scope_nobody_wired_builds_an_instance_of_its_own(): void {
		$scope = new Shared_Service_Scope();

		$this->assertInstanceOf( Location_Service::class, $scope->service() );
		$this->assertSame( $scope->service(), $scope->service(), 'built once, then kept' );
	}

	public function test_a_handler_that_already_built_its_own_default_keeps_it(): void {
		$service  = new Shared_Service_Counting_Service();
		$checkout = $this->checkout_handler();

		$own = $this->checkout_service( $checkout );
		$checkout->adopt_location_service( $service );

		$this->assertSame( $own, $this->checkout_service( $checkout ), 'adoption is for a handler that has not read yet' );
	}
}
