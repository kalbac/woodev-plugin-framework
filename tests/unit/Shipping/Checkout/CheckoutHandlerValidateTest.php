<?php
/**
 * Tests for Checkout_Handler::validate() — conditional-required (A2) and
 * Checkout_Handler::save() / is_native_wc_field() — native-WC-field skip (Codex HIGH #3).
 *
 * Covers Task 7 of the checkout-field-layer plan (2026-07-06):
 *   - conditional required blocks checkout when condition is met
 *   - conditional required passes when condition is not met
 *   - plain-bool required still works (regression guard)
 *   - is_native_wc_field() identifies billing_* / shipping_* prefixes
 *   - save() skips persistence for native WC fields (via persist_field seam)
 *   - save() persists genuinely new fields (via persist_field seam)
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
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::validate
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::save
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::is_native_wc_field
 */
class CheckoutHandlerValidateTest extends TestCase {

	// -----------------------------------------------------------------------
	// Part A — conditional-required in validate()
	// -----------------------------------------------------------------------

	/**
	 * A condition-spec required field is required when the condition is met
	 * (chosen method matches): should add an error and return false.
	 */
	public function test_conditional_required_blocks_when_pickup_method_chosen(): void {
		Functions\expect( 'wc_add_notice' )->once();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )->to_array(),
		] );
		$ok = ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'pvz' => '' ], [ 'chosen_shipping_method' => 'carrier_pickup' ] );
		$this->assertFalse( $ok );
	}

	/**
	 * A condition-spec required field is NOT required when the condition is not
	 * met (different method): blank value should pass without an error.
	 */
	public function test_conditional_required_passes_when_other_method(): void {
		Functions\expect( 'wc_add_notice' )->never();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )->to_array(),
		] );
		$this->assertTrue( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'pvz' => '' ], [ 'chosen_shipping_method' => 'flat_rate' ] ) );
	}

	/**
	 * Plain-bool required = true still blocks an empty value (regression guard).
	 */
	public function test_plain_bool_required_blocks_blank(): void {
		Functions\expect( 'wc_add_notice' )->once();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'myfield' )->set_required( true )->to_array(),
		] );
		$ok = ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'myfield' => '' ], [] );
		$this->assertFalse( $ok );
	}

	/**
	 * Plain-bool required = false passes a blank value (regression guard).
	 */
	public function test_plain_bool_required_false_passes_blank(): void {
		Functions\expect( 'wc_add_notice' )->never();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'myfield' )->set_required( false )->to_array(),
		] );
		$this->assertTrue( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'myfield' => '' ], [] ) );
	}

	/**
	 * validate() default second-param = [] does not fatal on a plain-bool required field.
	 */
	public function test_validate_no_state_default(): void {
		Functions\expect( 'wc_add_notice' )->once();
		$fields = Checkout_Fields::from_array( [
			Field::create( 'f' )->set_required( true )->to_array(),
		] );
		// call with only one argument — second param defaults to []
		$ok = ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'f' => '' ] );
		$this->assertFalse( $ok );
	}

	// -----------------------------------------------------------------------
	// Part B — is_native_wc_field() unit tests
	// -----------------------------------------------------------------------

	/**
	 * billing_* IDs are recognised as native WC fields.
	 *
	 * Uses a transparent subclass to expose the protected method without reflection.
	 */
	public function test_is_native_wc_field_billing_prefix(): void {
		$handler = new NativeFieldProbe( Checkout_Fields::from_array( [] ), 'carrier' );

		$this->assertTrue( $handler->probe( 'billing_city' ) );
		$this->assertTrue( $handler->probe( 'billing_country' ) );
		$this->assertTrue( $handler->probe( 'billing_' ) );
	}

	/**
	 * shipping_* IDs are recognised as native WC fields.
	 */
	public function test_is_native_wc_field_shipping_prefix(): void {
		$handler = new NativeFieldProbe( Checkout_Fields::from_array( [] ), 'carrier' );

		$this->assertTrue( $handler->probe( 'shipping_address_1' ) );
		$this->assertTrue( $handler->probe( 'shipping_' ) );
	}

	/**
	 * Plugin-defined IDs are NOT native WC fields.
	 */
	public function test_is_native_wc_field_returns_false_for_custom_ids(): void {
		$handler = new NativeFieldProbe( Checkout_Fields::from_array( [] ), 'carrier' );

		$this->assertFalse( $handler->probe( 'carrier_pickup_point' ) );
		$this->assertFalse( $handler->probe( 'pvz' ) );
		$this->assertFalse( $handler->probe( '' ) );
	}

	// -----------------------------------------------------------------------
	// Part B — save() skip / persist via spy subclass
	// -----------------------------------------------------------------------

	/**
	 * save() must NOT call persist_field() for a billing_* field.
	 *
	 * Uses a spy subclass that overrides persist_field() to record calls so
	 * the test is independent of the Woodev_Order_Compatibility static method.
	 */
	public function test_save_skips_native_wc_fields(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->to_array(),
		] );

		$spy      = new SpyCheckoutHandler( $fields, 'carrier' );
		$spy->save( 123, [ 'billing_city' => 'Москва' ] );

		$this->assertSame( [], $spy->persisted, 'billing_city must NOT be persisted' );
	}

	/**
	 * save() MUST call persist_field() for a plugin-defined (non-native) field.
	 */
	public function test_save_persists_new_field(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )->to_array(),
		] );

		// do_action is called inside save() — stub it.
		Functions\when( 'do_action' )->justReturn();

		$spy = new SpyCheckoutHandler( $fields, 'carrier' );
		$spy->save( 123, [ 'carrier_pickup_point' => 'PVZ-001' ] );

		$this->assertSame(
			[ [ 'order' => 123, 'id' => 'carrier_pickup_point', 'value' => 'PVZ-001' ] ],
			$spy->persisted,
			'carrier_pickup_point must be persisted once'
		);
	}

	/**
	 * save() skips a native field AND persists a custom field in the same call.
	 */
	public function test_save_skips_native_but_persists_custom_in_same_call(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->to_array(),
			Field::create( 'carrier_pickup_point' )->to_array(),
		] );

		Functions\when( 'do_action' )->justReturn();

		$spy = new SpyCheckoutHandler( $fields, 'carrier' );
		$spy->save( 99, [ 'billing_city' => 'Москва', 'carrier_pickup_point' => 'PVZ-001' ] );

		$this->assertCount( 1, $spy->persisted );
		$this->assertSame( 'carrier_pickup_point', $spy->persisted[0]['id'] );
	}
}

/**
 * Spy subclass for Checkout_Handler that records persist_field() calls without
 * invoking the real Woodev_Order_Compatibility::update_order_meta().
 *
 * @internal For testing only.
 */
class SpyCheckoutHandler extends Checkout_Handler {

	/** @var array<int, array{order: mixed, id: string, value: mixed}> */
	public array $persisted = [];

	/**
	 * {@inheritdoc}
	 */
	protected function persist_field( $order, string $id, $value ): void {
		$this->persisted[] = [ 'order' => $order, 'id' => $id, 'value' => $value ];
	}
}

/**
 * Transparent probe subclass that exposes is_native_wc_field() publicly.
 *
 * Avoids ReflectionMethod::setAccessible() (deprecated in PHP 8.5) while still
 * testing the protected helper without changing its visibility in production code.
 *
 * @internal For testing only.
 */
class NativeFieldProbe extends Checkout_Handler {

	/**
	 * Delegates to the protected is_native_wc_field() for assertion in tests.
	 *
	 * @param string $id field id to test
	 *
	 * @return bool
	 */
	public function probe( string $id ): bool {
		return $this->is_native_wc_field( $id );
	}
}
