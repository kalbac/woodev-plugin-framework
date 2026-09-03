<?php
/**
 * Tests for Checkout_Handler::register() — hook wiring, plugin_id(), config_object_suffix(),
 * and the multi-plugin native-id conflict guard.
 *
 * Covers Task 9 of the checkout-field-layer plan (2026-07-06):
 *   - register() adds all 5 expected hooks (3 checkout + wp_enqueue_scripts + rest_api_init)
 *   - plugin_id() returns the constructor's hook_prefix (or 'shipping' for empty prefix)
 *   - config_object_suffix() sanitizes the plugin id to a valid JS identifier
 *   - guard fires _doing_it_wrong when two handlers claim the same native billing_* field
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::register
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::plugin_id
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::config_object_suffix
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::guard_native_field_conflicts
 */
class CheckoutHandlerRegisterTest extends TestCase {

	protected function tearDown(): void {
		Checkout_Handler::reset_native_field_registry();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// register() — hook wiring
	// -------------------------------------------------------------------------

	/**
	 * register() must wire all hooks: 2 filters (woocommerce_checkout_fields +
	 * woocommerce_states) + 6 actions (checkout_process, order_processed,
	 * wp_enqueue_scripts, rest_api_init, the Task 12 `init` suppression check,
	 * and issue #518's `woodev_shipping_pickup_point_selected` listener).
	 */
	public function test_register_wires_all_seven_hooks(): void {

		Functions\expect( 'add_filter' )
			->once()
			->with( 'woocommerce_checkout_fields', \Mockery::type( 'array' ) );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'woocommerce_states', \Mockery::type( 'array' ) );

		// Six since issue #518 added `woodev_shipping_pickup_point_selected`.
		Functions\expect( 'add_action' )
			->times( 6 )
			->withAnyArgs();

		$fields  = Checkout_Fields::from_array( [] );
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->register();
	}

	/**
	 * register() wires the Task 12 WC-address-provider suppression check onto
	 * `init` at priority 21 — strictly after the Location Provider Registry's
	 * own `init:20` collection ({@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::collect()}),
	 * so the provider list/countries are already populated when it runs.
	 */
	public function test_register_hooks_init_for_wc_address_provider_suppression(): void {

		Functions\expect( 'add_filter' )->times( 4 )->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'init', \Mockery::type( 'array' ), 21 );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	/**
	 * register() wires woocommerce_checkout_process action.
	 */
	public function test_register_hooks_checkout_process(): void {

		Functions\expect( 'add_filter' )->times( 4 )->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'woocommerce_checkout_process', \Mockery::type( 'array' ) );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	/**
	 * Issue #518: a confirmed pickup point promotes an implicit locality, so the
	 * checkout's address lock stops holding a field the customer's point address
	 * is about to be written into.
	 *
	 * The listener lives here rather than in the pickup controller so the location
	 * layer stays the only writer of its own record.
	 */
	public function test_register_hooks_the_pickup_point_selected_listener(): void {

		Functions\expect( 'add_filter' )->times( 4 )->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'woodev_shipping_pickup_point_selected', \Mockery::type( 'array' ) );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	/**
	 * The #518 critic pass, MINOR: the wiring test above accepts ANY array
	 * callback, so wiring the right hook to a wrong or empty method would pass
	 * it. That is the "a test which prepares its own precondition does not test
	 * the producer" trap — pin the CALLBACK and what it does, not just the hook
	 * name.
	 *
	 * Asserts the registered callback is this exact method, and that calling it
	 * promotes the customer's record.
	 */
	public function test_the_registered_callback_is_the_one_that_promotes_the_record(): void {

		Functions\when( 'add_filter' )->justReturn( true );

		$registered = [];

		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback = null ) use ( &$registered ) {
				$registered[ $hook ] = $callback;

				return true;
			}
		);

		$service = \Mockery::mock( \Woodev\Framework\Shipping\Location\Location_Service::class );
		$service->shouldReceive( 'promote_customer_record_to_explicit' )
			->once()
			->andReturn( true );

		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$handler->register();

		$this->assertArrayHasKey( 'woodev_shipping_pickup_point_selected', $registered );
		$this->assertSame(
			[ $handler, 'handle_pickup_point_selected' ],
			$registered['woodev_shipping_pickup_point_selected'],
			'the hook must carry THIS method — a right hook on a wrong method is the defect this catches'
		);

		// Fire it exactly as the pickup controller's do_action() would. The
		// Mockery `once()` expectation above is the assertion.
		call_user_func( $registered['woodev_shipping_pickup_point_selected'] );
	}

	/**
	 * register() wires woocommerce_checkout_order_processed action.
	 */
	public function test_register_hooks_checkout_order_processed(): void {

		Functions\expect( 'add_filter' )->times( 4 )->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'woocommerce_checkout_order_processed', \Mockery::type( 'array' ), 10, 3 );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	/**
	 * register() wires wp_enqueue_scripts action.
	 */
	public function test_register_hooks_wp_enqueue_scripts(): void {

		Functions\expect( 'add_filter' )->times( 4 )->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'wp_enqueue_scripts', \Mockery::type( 'array' ) );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	/**
	 * register() wires rest_api_init action.
	 */
	public function test_register_hooks_rest_api_init(): void {

		Functions\expect( 'add_filter' )->times( 4 )->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'rest_api_init', \Mockery::type( 'array' ) );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	// -------------------------------------------------------------------------
	// plugin_id()
	// -------------------------------------------------------------------------

	public function test_plugin_id_returns_hook_prefix(): void {
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'my_carrier' );
		$this->assertSame( 'my_carrier', $handler->plugin_id() );
	}

	public function test_plugin_id_falls_back_to_shipping_when_prefix_empty(): void {
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ) );
		$this->assertSame( 'shipping', $handler->plugin_id() );
	}

	// -------------------------------------------------------------------------
	// config_object_suffix()
	// -------------------------------------------------------------------------

	public function test_config_object_suffix_is_alphanumeric_with_underscores(): void {
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'my-carrier.plugin' );
		// Hyphens and dots replaced with underscores, plus the disambiguator every REWRITTEN
		// id now carries — see test_two_plugin_ids_that_differ_only_in_punctuation_do_not_collide().
		$this->assertStringStartsWith( 'my_carrier_plugin_', $handler->config_object_suffix() );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_]+$/', $handler->config_object_suffix() );
	}

	public function test_config_object_suffix_keeps_valid_identifier_unchanged(): void {
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'wc_carrier_2' );
		$this->assertSame( 'wc_carrier_2', $handler->config_object_suffix() );
	}

	public function test_config_object_suffix_for_empty_prefix_is_shipping(): void {
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ) );
		$this->assertSame( 'shipping', $handler->config_object_suffix() );
	}

	/**
	 * @dataProvider provide_colliding_plugin_ids
	 *
	 * @param string $first  one plugin id.
	 * @param string $second another plugin id that used to sanitise to the same suffix.
	 */
	public function test_two_plugin_ids_that_differ_only_in_punctuation_do_not_collide(
		string $first,
		string $second
	): void {
		// REGRESSION (issue #142): `preg_replace( '/[^a-z0-9_]/i', '_' )` mapped `carrier-a`,
		// `carrier_a` and `carrier.a` onto one JS config global name, so two shipping plugins
		// with near-identical ids on one checkout page silently overwrote each other's field
		// descriptors and REST endpoint. The same defect, same line, in Pickup_Handler.
		$this->assertNotSame(
			( new Checkout_Handler( Checkout_Fields::from_array( [] ), $first ) )->config_object_suffix(),
			( new Checkout_Handler( Checkout_Fields::from_array( [] ), $second ) )->config_object_suffix()
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_colliding_plugin_ids(): array {
		return [
			'hyphen vs underscore' => [ 'carrier-a', 'carrier_a' ],
			'dot vs underscore'    => [ 'carrier.a', 'carrier_a' ],
			'dot vs hyphen'        => [ 'carrier.a', 'carrier-a' ],
		];
	}

	public function test_a_rewritten_config_object_suffix_is_still_a_valid_js_identifier(): void {
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'my carrier plugin!' );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_]+$/', $handler->config_object_suffix() );
	}

	// -------------------------------------------------------------------------
	// Native-id conflict guard
	// -------------------------------------------------------------------------

	/**
	 * When two handlers register the same billing_* field, the second call
	 * to register() must fire _doing_it_wrong.
	 */
	public function test_guard_fires_doing_it_wrong_on_native_field_conflict(): void {

		Functions\expect( 'add_filter' )->times( 8 )->withAnyArgs();
		Functions\expect( 'add_action' )->times( 12 )->withAnyArgs();
		Functions\expect( '_doing_it_wrong' )
			->once()
			->with(
				\Mockery::type( 'string' ),
				\Mockery::pattern( "/billing_city.*more than one shipping plugin/" ),
				'2.0.2'
			);

		$field = Field::create( 'billing_city' )->set_type( 'text' )->set_section( 'billing' )->to_array();

		( new Checkout_Handler( Checkout_Fields::from_array( [ $field ] ), 'plugin_a' ) )->register();
		( new Checkout_Handler( Checkout_Fields::from_array( [ $field ] ), 'plugin_b' ) )->register();
	}

	/**
	 * The same handler registering the same native field twice (e.g. if register()
	 * is accidentally called twice) must NOT fire _doing_it_wrong — it is the same
	 * plugin_id, so there is no conflict.
	 */
	public function test_guard_does_not_fire_for_same_plugin_id(): void {

		Functions\expect( 'add_filter' )->times( 8 )->withAnyArgs();
		Functions\expect( 'add_action' )->times( 12 )->withAnyArgs();
		Functions\expect( '_doing_it_wrong' )->never();

		$field   = Field::create( 'billing_city' )->set_type( 'text' )->set_section( 'billing' )->to_array();
		$fields  = Checkout_Fields::from_array( [ $field ] );
		$handler = new Checkout_Handler( $fields, 'plugin_a' );

		$handler->register();
		$handler->register();
	}

	/**
	 * Non-native field ids (e.g. carrier_pvz) must NOT trigger the conflict guard
	 * even when two plugins both register it.
	 */
	public function test_guard_ignores_non_native_fields(): void {

		Functions\expect( 'add_filter' )->times( 8 )->withAnyArgs();
		Functions\expect( 'add_action' )->times( 12 )->withAnyArgs();
		Functions\expect( '_doing_it_wrong' )->never();

		$field = Field::create( 'carrier_pvz' )->set_type( 'hidden' )->set_section( 'order' )->to_array();

		( new Checkout_Handler( Checkout_Fields::from_array( [ $field ] ), 'plugin_a' ) )->register();
		( new Checkout_Handler( Checkout_Fields::from_array( [ $field ] ), 'plugin_b' ) )->register();
	}
}
