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
	 * register() must wire all 5 hooks:
	 * 3 classic checkout hooks + wp_enqueue_scripts + rest_api_init.
	 */
	public function test_register_wires_all_five_hooks(): void {

		Functions\expect( 'add_filter' )
			->once()
			->with( 'woocommerce_checkout_fields', \Mockery::type( 'array' ) );

		Functions\expect( 'add_action' )
			->times( 4 )
			->withAnyArgs();

		$fields  = Checkout_Fields::from_array( [] );
		$handler = new Checkout_Handler( $fields, 'carrier' );
		$handler->register();
	}

	/**
	 * register() wires woocommerce_checkout_process action.
	 */
	public function test_register_hooks_checkout_process(): void {

		Functions\expect( 'add_filter' )->once()->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'woocommerce_checkout_process', \Mockery::type( 'array' ) );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	/**
	 * register() wires woocommerce_checkout_order_processed action.
	 */
	public function test_register_hooks_checkout_order_processed(): void {

		Functions\expect( 'add_filter' )->once()->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'woocommerce_checkout_order_processed', \Mockery::type( 'array' ), 10, 3 );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	/**
	 * register() wires wp_enqueue_scripts action.
	 */
	public function test_register_hooks_wp_enqueue_scripts(): void {

		Functions\expect( 'add_filter' )->once()->withAnyArgs();
		Functions\expect( 'add_action' )
			->atLeast()->once()
			->with( 'wp_enqueue_scripts', \Mockery::type( 'array' ) );

		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' ) )->register();
	}

	/**
	 * register() wires rest_api_init action.
	 */
	public function test_register_hooks_rest_api_init(): void {

		Functions\expect( 'add_filter' )->once()->withAnyArgs();
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
		// hyphens and dots replaced with underscores
		$this->assertSame( 'my_carrier_plugin', $handler->config_object_suffix() );
	}

	public function test_config_object_suffix_keeps_valid_identifier_unchanged(): void {
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'wc_carrier_2' );
		$this->assertSame( 'wc_carrier_2', $handler->config_object_suffix() );
	}

	public function test_config_object_suffix_for_empty_prefix_is_shipping(): void {
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ) );
		$this->assertSame( 'shipping', $handler->config_object_suffix() );
	}

	// -------------------------------------------------------------------------
	// Native-id conflict guard
	// -------------------------------------------------------------------------

	/**
	 * When two handlers register the same billing_* field, the second call
	 * to register() must fire _doing_it_wrong.
	 */
	public function test_guard_fires_doing_it_wrong_on_native_field_conflict(): void {

		Functions\expect( 'add_filter' )->twice()->withAnyArgs();
		Functions\expect( 'add_action' )->times( 8 )->withAnyArgs();
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

		Functions\expect( 'add_filter' )->twice()->withAnyArgs();
		Functions\expect( 'add_action' )->times( 8 )->withAnyArgs();
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

		Functions\expect( 'add_filter' )->twice()->withAnyArgs();
		Functions\expect( 'add_action' )->times( 8 )->withAnyArgs();
		Functions\expect( '_doing_it_wrong' )->never();

		$field = Field::create( 'carrier_pvz' )->set_type( 'hidden' )->set_section( 'order' )->to_array();

		( new Checkout_Handler( Checkout_Fields::from_array( [ $field ] ), 'plugin_a' ) )->register();
		( new Checkout_Handler( Checkout_Fields::from_array( [ $field ] ), 'plugin_b' ) )->register();
	}
}
