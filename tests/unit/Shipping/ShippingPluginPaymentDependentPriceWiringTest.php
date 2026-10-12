<?php
/**
 * Regression (#1244): a shipping plugin whose method class declares a price that always depends on the
 * payment method switches the payment plumbing on through `Shipping_Plugin::add_hooks()` alone.
 *
 * `FeePaymentsTest` calls `Fee_Payments::declare_payment_dependent_price()` directly, so deleting the
 * wiring line in `add_hooks()` leaves it green. This test goes through the real (private) `add_hooks()`
 * instead, with an EMPTY registry and no instance of any method: the declaration is the only thing that
 * can turn the classic payment-recalc script and the Checkout Blocks integration on.
 *
 * Runs in its own process: the Checkout Blocks integration implements WooCommerce Blocks'
 * `IntegrationInterface`, which the unit process must not declare (`PickupBlocksTest` asserts its absence).
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Checkout\Blocks\Locality_Blocks_Integration;
use Woodev\Framework\Shipping\Fee_Payments;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';

// the method base and the `WC_Shipping_Method` stub live there; whichever file loads first defines them
require_once __DIR__ . '/ShippingRateCacheTest.php';

/** A carrier whose price always depends on the payment method — the class-level declaration. */
class Wiring_Payment_Dependent_Method extends Woodev_Test_Shipping_Method_For_Rate_Cache {

	/** @return bool */
	public static function declares_payment_dependent_price(): bool {
		return true;
	}
}

/** Minimal Shipping_Plugin double: the constructor is bypassed, `add_hooks()` is reached by reflection. */
class Wiring_Test_Shipping_Plugin extends Shipping_Plugin {

	/** @var class-string[] */
	private array $method_classes;

	/** @param class-string[] $method_classes the shipping method classes the plugin declares. */
	public function __construct( array $method_classes ) {
		$this->method_classes = $method_classes;
	}

	/** @return class-string[] */
	protected function get_shipping_method_classes(): array {
		return $this->method_classes;
	}

	/** @return string */
	protected function get_file() {
		return __FILE__;
	}

	/** @return string */
	public function get_plugin_name() {
		return 'Payment Dependent Price Wiring Plugin';
	}

	/** @return int */
	public function get_download_id() {
		return 0;
	}

	/** @return string */
	public function get_id() {
		return 'payment-dependent-wiring';
	}

	/** @return string */
	public function get_id_underscored() {
		return 'payment_dependent_wiring';
	}

	/** @return null */
	public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
		return null;
	}
}

/** The registry `woocommerce_blocks_checkout_block_registration` hands over: it keeps what is registered. */
class Wiring_Test_Integration_Registry {

	/** @var object[] */
	public array $registered = [];

	/**
	 * @param object $integration the integration being registered.
	 * @return void
	 */
	public function register( object $integration ): void {
		$this->registered[] = $integration;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::add_hooks
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class ShippingPluginPaymentDependentPriceWiringTest extends TestCase {

	/** @var array<int, array{0: string, 1: mixed}> every `add_action()` call the plugin made: hook, callback. */
	private array $actions = [];

	/** @var array<int, array{0: string, 1: mixed}> every `add_filter()` call the plugin made: hook, callback. */
	private array $filters = [];

	/** @return void */
	protected function setUp(): void {
		parent::setUp();

		$this->actions = [];
		$this->filters = [];

		// the blocks integration implements an interface that exists only inside WooCommerce Blocks
		if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface', false ) ) {
			eval( 'namespace Automattic\WooCommerce\Blocks\Integrations; interface IntegrationInterface { public function get_name(); public function initialize(); public function get_script_handles(); public function get_editor_script_handles(); public function get_script_data(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/blocks/class-locality-blocks-integration.php';
		require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/blocks/class-fee-payments-blocks-integration.php';

		Fee_Payments::reset();

		// an EMPTY registry: no saved instance, no stored option of any kind
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $default );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_parse_args' )->returnArg( 1 );
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) {
				$this->actions[] = [ $hook, $callback ];

				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) {
				$this->filters[] = [ $hook, $callback ];

				return true;
			}
		);
	}

	/** @return void */
	protected function tearDown(): void {
		Fee_Payments::reset();

		parent::tearDown();
	}

	/**
	 * Runs the plugin's real private `add_hooks()`, as the constructor does.
	 *
	 * @param Shipping_Plugin $plugin the plugin to boot.
	 * @return void
	 */
	private function boot( Shipping_Plugin $plugin ): void {
		$add_hooks = new \ReflectionMethod( Shipping_Plugin::class, 'add_hooks' );
		if ( PHP_VERSION_ID < 80100 ) {
			$add_hooks->setAccessible( true );
		}
		$add_hooks->invoke( $plugin );
	}

	/**
	 * Whether `add_hooks()` bound the given class method to the hook.
	 *
	 * @param string $hook   the action name.
	 * @param string $class  the class the callback names.
	 * @param string $method the method the callback names.
	 * @return bool
	 */
	private function action_bound( string $hook, string $class, string $method ): bool {
		foreach ( $this->actions as [ $name, $callback ] ) {
			if ( $hook === $name && [ $class, $method ] === $callback ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fires the callback `add_hooks()` bound to the hook, with the arguments WordPress would pass.
	 *
	 * @param string $hook   the action name.
	 * @param string $class  the class the callback names.
	 * @param string $method the method the callback names.
	 * @param array  $args   the arguments.
	 * @return void
	 */
	private function fire( string $hook, string $class, string $method, array $args = [] ): void {
		$this->assertTrue( $this->action_bound( $hook, $class, $method ), "{$method} is bound to {$hook}" );

		call_user_func_array( [ $class, $method ], $args );
	}

	/**
	 * The integration the blocks registry received from the bound registration callback.
	 *
	 * @return object
	 */
	private function blocks_integration(): object {
		$registry = new Wiring_Test_Integration_Registry();

		$this->fire( 'woocommerce_blocks_checkout_block_registration', Fee_Payments::class, 'register_blocks_integration', [ $registry ] );

		$this->assertCount( 1, $registry->registered, 'the blocks integration is registered' );

		return $registry->registered[0];
	}

	/** @return void */
	public function test_a_declaring_method_switches_all_three_on_through_add_hooks_with_an_empty_registry(): void {
		$this->boot( new Wiring_Test_Shipping_Plugin( [ Wiring_Payment_Dependent_Method::class ] ) );

		// (a) the shared plumbing is on, though nothing is saved
		$this->assertTrue( Fee_Payments::is_used() );

		// (b) the classic payment-recalc script is enqueued on checkout
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/fee-payments-classic.js' );
		Functions\expect( 'wp_enqueue_script' )->once()->with(
			'woodev-fee-payments-classic',
			'https://example.test/fee-payments-classic.js',
			[ 'jquery', 'wc-checkout' ],
			Mockery::type( 'string' ),
			true
		);

		$this->fire( 'wp_enqueue_scripts', Fee_Payments::class, 'enqueue_classic_script' );

		// (c) the blocks integration publishes its enabled data and its script handle
		Functions\when( 'wp_script_is' )->justReturn( true );
		$integration = $this->blocks_integration();

		$this->assertSame(
			[ 'enabled' => true, 'namespace' => Fee_Payments::EXTENSION_NAMESPACE ],
			$integration->get_script_data()
		);
		$this->assertSame( [ Locality_Blocks_Integration::SCRIPT_HANDLE ], $integration->get_script_handles() );
	}

	/** @return void */
	public function test_a_method_that_does_not_declare_leaves_all_three_off_through_add_hooks(): void {
		$this->boot( new Wiring_Test_Shipping_Plugin( [ Woodev_Test_Shipping_Method_For_Rate_Cache::class ] ) );

		// (a) nothing turns the plumbing on
		$this->assertFalse( Fee_Payments::is_used() );

		// (b) no classic script on checkout
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\expect( 'wp_enqueue_script' )->never();

		$this->fire( 'wp_enqueue_scripts', Fee_Payments::class, 'enqueue_classic_script' );

		// (c) the blocks integration publishes nothing to load
		$integration = $this->blocks_integration();

		$this->assertSame( [ 'enabled' => false ], $integration->get_script_data() );
		$this->assertSame( [], $integration->get_script_handles() );
	}
}
