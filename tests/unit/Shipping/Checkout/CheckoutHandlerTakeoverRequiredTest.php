<?php
/**
 * Tests for Checkout_Handler::validate() — a takeover field's `required` is enforced
 * only when the field is both OWNED (its `takeover_condition` is true for this submit's
 * country) and RENDERED (WooCommerce actually kept it on the form for its section).
 *
 * Issue #708: `inject()` deliberately skips takeover fields when injecting into
 * WooCommerce's checkout array — "takeover fields are owned entirely by the CLIENT" — but
 * `validate()` used to enforce `required` on them unconditionally via `effective_fields()`.
 * A field whose ownership condition is false for the current country, or whose section
 * WooCommerce has hidden via its own `woocommerce_checkout_*_field` setting, was then
 * required without ever being rendered anywhere — checkout could never be completed.
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
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::field_rendered_by_woocommerce
 */
class CheckoutHandlerTakeoverRequiredTest extends TestCase {

	/**
	 * OWNED + RENDERED — the only combination that must still block checkout.
	 */
	public function test_owned_and_rendered_blocks_blank(): void {
		Functions\expect( 'wc_add_notice' )->once();

		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_company' )
				->set_required( true )
				->set_section( 'billing' )
				->set_takeover_condition( static fn( array $c ): bool => 'BY' === ( $c['country'] ?? '' ) )
				->to_array(),
		] );

		$handler           = new Takeover_Render_Probe( $fields, 'carrier' );
		$handler->rendered = [ 'billing_company' => true ];

		$ok = $handler->validate( [ 'billing_company' => '' ], [ 'country' => 'BY' ] );

		$this->assertFalse( $ok );
	}

	/**
	 * OWNED but NOT RENDERED — WooCommerce hid the field via its own per-field visibility
	 * setting (e.g. `woocommerce_checkout_address_2_field = hidden`); the client-side
	 * takeover has nothing to attach to either. Requiring it would block checkout forever.
	 */
	public function test_owned_but_not_rendered_does_not_block(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_address_2' )
				->set_required( true )
				->set_section( 'billing' )
				->set_takeover_condition(
					static fn( array $c ): bool => in_array( (string) ( $c['country'] ?? '' ), [ 'RU', 'BY', 'KZ', 'UZ' ], true )
				)
				->to_array(),
		] );

		$handler           = new Takeover_Render_Probe( $fields, 'carrier' );
		$handler->rendered = [ 'billing_address_2' => false ];

		$ok = $handler->validate( [ 'billing_address_2' => '' ], [ 'country' => 'RU' ] );

		$this->assertTrue( $ok );
	}

	/**
	 * NOT OWNED but (irrelevantly) RENDERED — the takeover condition is false for this
	 * country (e.g. RU is deliberately excluded, #294), so ownership fails regardless of
	 * whatever WooCommerce happens to render for the field's own native id.
	 */
	public function test_not_owned_does_not_block_even_when_rendered(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_company' )
				->set_required( true )
				->set_section( 'billing' )
				->set_takeover_condition(
					static fn( array $c ): bool => in_array( (string) ( $c['country'] ?? '' ), [ 'BY', 'KZ', 'UZ' ], true )
				)
				->to_array(),
		] );

		$handler           = new Takeover_Render_Probe( $fields, 'carrier' );
		$handler->rendered = [ 'billing_company' => true ];

		$ok = $handler->validate( [ 'billing_company' => '' ], [ 'country' => 'RU' ] );

		$this->assertTrue( $ok );
	}

	/**
	 * NOT OWNED and NOT RENDERED — the trivial fourth combination.
	 */
	public function test_not_owned_and_not_rendered_does_not_block(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_company' )
				->set_required( true )
				->set_section( 'billing' )
				->set_takeover_condition(
					static fn( array $c ): bool => in_array( (string) ( $c['country'] ?? '' ), [ 'BY', 'KZ', 'UZ' ], true )
				)
				->to_array(),
		] );

		$handler = new Takeover_Render_Probe( $fields, 'carrier' );
		// $handler->rendered stays empty — field_rendered_by_woocommerce() defaults to false.

		$ok = $handler->validate( [ 'billing_company' => '' ], [ 'country' => 'RU' ] );

		$this->assertTrue( $ok );
	}

	/**
	 * Regression guard: a field WITHOUT a `takeover_condition` is injected by `inject()`
	 * and present by construction, so it must never go through the presence guard — it
	 * still blocks checkout when blank exactly as before this fix.
	 */
	public function test_non_takeover_required_field_still_blocks_regardless_of_render_probe(): void {
		Functions\expect( 'wc_add_notice' )->once();

		$fields = Checkout_Fields::from_array( [
			Field::create( 'carrier_pickup_point' )->set_required( true )->to_array(),
		] );

		$handler           = new Takeover_Render_Probe( $fields, 'carrier' );
		$handler->rendered = [ 'carrier_pickup_point' => false ];

		$ok = $handler->validate( [ 'carrier_pickup_point' => '' ], [] );

		$this->assertFalse( $ok );
	}

	/**
	 * The measured reproduction (card #708): country RU, submitting BOTH fixture-shaped
	 * takeover descriptors blank.
	 *
	 *  - `billing_company`   — takeover condition true for BY/KZ/UZ only (RU excluded, #294)
	 *                          → ownership fails for RU regardless of rendering.
	 *  - `billing_address_2` — takeover condition true for RU/BY/KZ/UZ, so ownership holds,
	 *                          but WooCommerce's own `woocommerce_checkout_address_2_field`
	 *                          is `hidden` on the rig, so it is never rendered either.
	 *
	 * Neither field may block checkout for RU.
	 */
	public function test_measured_reproduction_ru_with_both_fixture_fields_is_not_blocked(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_company' )
				->set_type( 'select' )
				->set_section( 'billing' )
				->set_required( true )
				->set_takeover_condition(
					static function ( array $context ): bool {
						return in_array( $context['country'] ?? '', [ 'BY', 'KZ', 'UZ' ], true );
					}
				)
				->to_array(),
			Field::create( 'billing_address_2' )
				->set_section( 'billing' )
				->set_required( true )
				->set_takeover_condition(
					static fn( array $c ): bool => in_array( (string) ( $c['country'] ?? '' ), [ 'RU', 'BY', 'KZ', 'UZ' ], true )
				)
				->to_array(),
		] );

		$handler           = new Takeover_Render_Probe( $fields, 'carrier' );
		// Rig measurement: both `woocommerce_checkout_company_field` and
		// `..._address_2_field` are `hidden`, so WooCommerce renders neither.
		$handler->rendered = [ 'billing_company' => false, 'billing_address_2' => false ];

		$ok = $handler->validate(
			[ 'billing_company' => '', 'billing_address_2' => '' ],
			[ 'country' => 'RU' ]
		);

		$this->assertTrue(
			$ok,
			'billing_company: ownership fails for RU; billing_address_2: owned but not rendered — neither may block checkout'
		);
	}
}

/**
 * Probe subclass overriding the `field_rendered_by_woocommerce()` seam directly, so these
 * tests never touch the global `WC()` function (gotcha `brain-monkey-function-pollution`:
 * mocking `WC()` with Brain Monkey defines it process-wide and PHP cannot un-define it).
 *
 * @internal For testing only.
 */
class Takeover_Render_Probe extends Checkout_Handler {

	/** @var array<string, bool> field id => whether WooCommerce rendered it */
	public array $rendered = [];

	/**
	 * {@inheritdoc}
	 */
	protected function field_rendered_by_woocommerce( string $id, string $section ): bool {
		return $this->rendered[ $id ] ?? false;
	}
}
