<?php
/**
 * Tests for Locality_Blocks — the registration and feature-detection half of the Checkout Blocks
 * locality chooser (SP-11 C-1, #1087).
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout\Blocks
 */

namespace Woodev\Tests\Unit\Shipping\Checkout\Blocks;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Checkout\Blocks\Locality_Blocks;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';
require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/blocks/class-locality-blocks.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Blocks\Locality_Blocks
 */
class LocalityBlocksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Locality_Blocks::reset();
	}

	protected function tearDown(): void {
		Locality_Blocks::reset();
		parent::tearDown();
	}

	private function handler(): Checkout_Handler {
		return new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier' );
	}

	public function test_register_wires_the_block_type_and_the_checkout_integration(): void {
		Functions\expect( 'add_action' )->once()->with( 'init', [ Locality_Blocks::class, 'register_block_types' ] );
		Functions\expect( 'add_action' )->once()->with( 'woocommerce_blocks_checkout_block_registration', [ Locality_Blocks::class, 'register_integration' ] );

		Locality_Blocks::register( $this->handler() );
	}

	public function test_register_is_idempotent_so_a_second_shipping_plugin_adds_nothing(): void {
		Functions\expect( 'add_action' )->twice();

		$first = $this->handler();

		Locality_Blocks::register( $first );
		Locality_Blocks::register( $this->handler() );

		// The first handler keeps answering for the fleet.
		$this->assertSame( $first, Locality_Blocks::handler() );
	}

	public function test_the_integration_is_not_registered_when_woocommerce_blocks_is_absent(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Locality_Blocks::register( $this->handler() );

		// No `IntegrationInterface` in the unit-test process: a store without Blocks gets no chooser.
		$this->assertFalse( interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface', false ) );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'register' )->never();

		Locality_Blocks::register_integration( $registry );
	}

	public function test_the_integration_is_not_registered_before_any_handler_registered(): void {
		$registry = Mockery::mock();
		$registry->shouldReceive( 'register' )->never();

		Locality_Blocks::register_integration( $registry );
	}

	public function test_register_block_types_registers_the_block_from_its_block_json(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );
		Functions\expect( 'register_block_type' )
			->twice();

		Locality_Blocks::register_block_types();
	}

	/**
	 * The JS registers `block.json` as its metadata and WooCommerce reads the SERVER-registered
	 * `parent`; both read this one file, so its contract is pinned here.
	 */
	public function test_block_json_declares_a_forced_inner_block_of_the_shipping_address_block(): void {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/blocks/shipping-locality/block.json' ), true );

		$this->assertSame( Locality_Blocks::BLOCK_SHIPPING, $json['name'] );
		$this->assertSame( [ 'woocommerce/checkout-shipping-address-block' ], $json['parent'] );
		// `force` defaults to `lock.default.remove` when the client does not say so.
		$this->assertTrue( $json['attributes']['lock']['default']['remove'] );
		$this->assertFalse( $json['supports']['inserter'] );
	}

	public function test_billing_block_json_targets_only_the_billing_address_parent(): void {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/blocks/shipping-locality-billing/block.json' ), true );

		$this->assertSame( Locality_Blocks::BLOCK_BILLING, $json['name'] );
		$this->assertSame( [ 'woocommerce/checkout-billing-address-block' ], $json['parent'] );
	}

	public function test_i18n_strings_carry_every_key_the_bundle_reads_and_are_english_msgids(): void {
		$strings = Locality_Blocks::i18n_strings();

		foreach ( [ 'label', 'hint', 'searching', 'listLabel', 'clear', 'regionNotSet', 'syncFailed', 'retry' ] as $key ) {
			$this->assertArrayHasKey( $key, $strings );
			$this->assertSame( 1, preg_match( '/^[\x20-\x7E…—]+$/u', $strings[ $key ] ), "msgid for \"$key\" must be English" );
		}
	}
}
