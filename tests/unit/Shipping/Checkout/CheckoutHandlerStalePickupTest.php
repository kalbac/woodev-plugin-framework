<?php
/**
 * Tests for the server-side half of issue #745 — a pickup-slot field whose own
 * method is NOT the chosen shipping method must never reach order meta, and must
 * never reach the `checkout_field_saved` / `checkout_data_saved` / `checkout_processed`
 * hook payloads either.
 *
 * Measured on the rig in s113: the classic checkout only HIDES an out-of-scope pickup
 * slot's hidden input ({@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler}'s own
 * `save()` docblock), never `disabled`, so `form.checkout.serialize()` still submits it and
 * {@see Checkout_Handler::save()} used to write EVERY posted value through
 * `persist_field()` unconditionally. With two carriers side by side, both carriers'
 * pickup codes could land on one order.
 *
 * The fix reuses {@see Checkout_Handler::validate()}'s own backstop predicate — the same
 * `requires_pickup_methods` list and the same `chosen_method_matches()` matcher — rather
 * than inventing a second one; see `Checkout_Handler::stale_pickup_field_ids()`.
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
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::save
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::process
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::validate
 */
class CheckoutHandlerStalePickupTest extends TestCase {

	/**
	 * Every test declares its own `requires_pickup_methods` list via
	 * `set_requires_pickup_methods()` (mirroring how both rig fixtures are actually
	 * configured — see the class docblock's "KNOWN LIMIT" note), so
	 * `Checkout_Config::pickup_method_ids()` is never reached and needs no fixture here.
	 *
	 * @return Checkout_Handler_Persist_Save_Spy
	 */
	private function handler_with_pickup_and_ordinary_fields(): Checkout_Handler_Persist_Save_Spy {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )->mark_pickup_slot()->to_array(),
			Field::create( 'carrier_city' )->to_array(),
		] );

		$handler = new Checkout_Handler_Persist_Save_Spy( $fields, 'carrier' );
		$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );

		return $handler;
	}

	// -------------------------------------------------------------------------
	// (1) Non-pickup method chosen — pickup value dropped from persistence AND
	//     from every save hook payload. A sibling non-pickup field is untouched (3).
	// -------------------------------------------------------------------------

	public function test_save_drops_stale_pickup_value_from_persistence_and_hook_payloads(): void {
		$handler = $this->handler_with_pickup_and_ordinary_fields();

		// Captures every do_action() call as [hook => [ [args...], ... ]], without
		// invoking WordPress — same technique the dedup tests in
		// CheckoutHandlerValidateTest already use for wc_add_notice().
		$captured = [];
		Functions\when( 'do_action' )->alias(
			static function ( string $hook, ...$args ) use ( &$captured ) {
				$captured[ $hook ][] = $args;
			}
		);

		$handler->save(
			123,
			[ 'carrier_pickup_point' => 'STALE-POINT-123', 'carrier_city' => 'Москва' ],
			'free_shipping'
		);

		$this->assertSame(
			[ [ 'order' => 123, 'id' => 'carrier_city', 'value' => 'Москва' ] ],
			$handler->persisted,
			'only the non-pickup field may be persisted (3)'
		);

		$this->assertSame(
			[ [ 123, 'carrier_city', 'Москва' ] ],
			$captured['woodev_shipping_carrier_checkout_field_saved'] ?? [],
			'checkout_field_saved must fire only for the surviving field'
		);

		$this->assertSame(
			[ [ 123, [ 'carrier_city' => 'Москва' ] ] ],
			$captured['woodev_shipping_carrier_checkout_data_saved'] ?? [],
			'checkout_data_saved payload must not carry the stale pickup value'
		);
	}

	public function test_process_drops_stale_pickup_value_from_persistence_and_checkout_processed_payload(): void {
		$handler = $this->handler_with_pickup_and_ordinary_fields();

		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$captured = [];
		Functions\when( 'do_action' )->alias(
			static function ( string $hook, ...$args ) use ( &$captured ) {
				$captured[ $hook ][] = $args;
			}
		);

		$ok = $handler->process(
			[
				'carrier_pickup_point' => 'STALE-POINT-123',
				'carrier_city'         => 'Москва',
				'shipping_method'      => [ 'free_shipping:1' ],
				'billing_country'      => 'RU',
			],
			123
		);

		$this->assertTrue( $ok );
		$this->assertSame(
			[ [ 'order' => 123, 'id' => 'carrier_city', 'value' => 'Москва' ] ],
			$handler->persisted
		);
		$this->assertSame(
			[ [ 123, [ 'carrier_city' => 'Москва' ] ] ],
			$captured['woodev_shipping_carrier_checkout_processed'] ?? [],
			'checkout_processed payload must not carry the stale pickup value'
		);
	}

	// -------------------------------------------------------------------------
	// (2) The field's own pickup method chosen — value persisted exactly as before.
	// -------------------------------------------------------------------------

	public function test_save_persists_pickup_value_exactly_as_before_when_its_own_method_chosen(): void {
		$handler = $this->handler_with_pickup_and_ordinary_fields();

		Functions\when( 'do_action' )->justReturn();

		// Instance-suffixed id ('carrier_pickup:2') — chosen_method_matches() matches by
		// prefix, exactly like the backstop in validate() already does.
		$handler->save(
			123,
			[ 'carrier_pickup_point' => 'PVZ-001', 'carrier_city' => 'Москва' ],
			'carrier_pickup:2'
		);

		$this->assertSame(
			[
				[ 'order' => 123, 'id' => 'carrier_pickup_point', 'value' => 'PVZ-001' ],
				[ 'order' => 123, 'id' => 'carrier_city', 'value' => 'Москва' ],
			],
			$handler->persisted
		);
	}

	public function test_process_persists_pickup_value_exactly_as_before_when_its_own_method_chosen(): void {
		$handler = $this->handler_with_pickup_and_ordinary_fields();

		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$captured = [];
		Functions\when( 'do_action' )->alias(
			static function ( string $hook, ...$args ) use ( &$captured ) {
				$captured[ $hook ][] = $args;
			}
		);

		$ok = $handler->process(
			[
				'carrier_pickup_point' => 'PVZ-001',
				'carrier_city'         => 'Москва',
				'shipping_method'      => [ 'carrier_pickup:2' ],
				'billing_country'      => 'RU',
			],
			123
		);

		$this->assertTrue( $ok );
		$this->assertSame(
			[
				[ 'order' => 123, 'id' => 'carrier_pickup_point', 'value' => 'PVZ-001' ],
				[ 'order' => 123, 'id' => 'carrier_city', 'value' => 'Москва' ],
			],
			$handler->persisted
		);
		$this->assertSame(
			[
				[
					123,
					[ 'carrier_pickup_point' => 'PVZ-001', 'carrier_city' => 'Москва' ],
				],
			],
			$captured['woodev_shipping_carrier_checkout_processed'] ?? []
		);
	}

	// -------------------------------------------------------------------------
	// (4) validate()'s verdict must be unaffected by the drop — a dropped value must
	//     never turn into a NEW validation error. The drop runs strictly AFTER
	//     process() calls validate(), so a filled pickup field must still satisfy a
	//     plain-bool `required` regardless of which method will later cause the same
	//     value to be stripped before persistence.
	// -------------------------------------------------------------------------

	public function test_validate_verdict_is_unaffected_by_the_persistence_drop(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )->mark_pickup_slot()->set_required( true )->to_array(),
		] );

		$handler = new Checkout_Handler_Persist_Save_Spy( $fields, 'carrier' );
		$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );

		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'wc_add_notice' )->never();

		$ok = $handler->process(
			[
				'carrier_pickup_point' => 'STALE-POINT-123',
				'shipping_method'      => [ 'free_shipping' ],
				'billing_country'      => 'RU',
			],
			123
		);

		$this->assertTrue(
			$ok,
			'the field is filled, so plain-bool required must pass regardless of which method the drop will later strip its value for'
		);
		$this->assertSame(
			[],
			$handler->persisted,
			'yet the value must still never reach persistence once free_shipping is the chosen method'
		);
	}
}

/**
 * Spy subclass recording persist_field() calls without touching
 * Woodev_Order_Compatibility — same pattern as SpyCheckoutHandler in
 * CheckoutHandlerValidateTest, kept as a distinct class to avoid a same-namespace
 * class-name collision between the two test files.
 *
 * @internal For testing only.
 */
class Checkout_Handler_Persist_Save_Spy extends Checkout_Handler {

	/** @var array<int, array{order: mixed, id: string, value: mixed}> */
	public array $persisted = [];

	/**
	 * {@inheritdoc}
	 */
	protected function persist_field( $order, string $id, $value ): void {
		$this->persisted[] = [ 'order' => $order, 'id' => $id, 'value' => $value ];
	}
}
