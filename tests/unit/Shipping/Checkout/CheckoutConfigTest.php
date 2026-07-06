<?php
/**
 * Tests for Checkout_Config — JS-safe config emitter.
 *
 * Verifies that build() strips all PHP callables/secrets from the emitted
 * array, evaluates takeover_condition predicates per-country, and produces
 * the expected endpoint/nonce/fields shape.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Checkout_Config;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Config
 */
class CheckoutConfigTest extends TestCase {

	public function test_emit_excludes_callables_and_includes_field_shape(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->set_type( 'select' )->set_source( static fn() => [], 'options' )
				->set_takeover_condition( static fn( $c ) => in_array( $c['country'] ?? '', [ 'RU', 'BY' ], true ) )->to_array(),
		] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'NONCE', [ 'RU', 'BY', 'FR' ] ) )->build( $fields );

		$field = $config['fields']['billing_state'];
		$this->assertArrayNotHasKey( 'source', $field );
		$this->assertArrayNotHasKey( 'takeover_condition', $field );
		$this->assertArrayNotHasKey( 'sanitize_callback', $field );
		$this->assertSame( 'options', $field['source_kind'] );
		$this->assertSame( 'select', $field['type'] );
		$this->assertSame( [ 'RU' => true, 'BY' => true, 'FR' => false ], $config['takeover']['billing_state'] );
		$this->assertSame( 'NONCE', $config['nonce'] );
		$this->assertSame( 'https://x/wp-json/woodev/v1/shipping/checkout/carrier/field-source', $config['endpoint'] );
	}

	public function test_field_without_takeover_has_no_takeover_map_entry(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' )->to_array() ] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );
		$this->assertArrayNotHasKey( 'billing_city', $config['takeover'] );
	}
}
