<?php
/**
 * Tests for Pickup_Blocks — the registration and feature-detection half of the Checkout Blocks
 * pickup-point button (SP-11 C-2b, #1089).
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout\Blocks
 */

namespace Woodev\Tests\Unit\Shipping\Checkout\Blocks;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Checkout\Blocks\Pickup_Blocks;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Blocks\Pickup_Blocks
 */
class PickupBlocksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Pickup_Blocks::reset();
	}

	protected function tearDown(): void {
		Pickup_Blocks::reset();
		parent::tearDown();
	}

	/**
	 * @param string $plugin_id Transport key.
	 * @param string $field_id  Transport key.
	 */
	private function handler( string $plugin_id, string $field_id ): Pickup_Handler {
		$handler = Mockery::mock( Pickup_Handler::class );
		$handler->shouldReceive( 'blocks_descriptor' )->andReturn(
			[
				'pluginId'  => $plugin_id,
				'fieldId'   => $field_id,
				'configKey' => 'woodev_pickup_config_' . $plugin_id,
			]
		);

		return $handler;
	}

	public function test_the_first_handler_wires_the_block_type_and_the_checkout_integration(): void {
		Functions\expect( 'add_action' )->once()->with( 'init', [ Pickup_Blocks::class, 'register_block_types' ] );
		Functions\expect( 'add_action' )->once()->with( 'woocommerce_blocks_checkout_block_registration', [ Pickup_Blocks::class, 'register_integration' ] );

		Pickup_Blocks::add_handler( $this->handler( 'carrier', 'carrier_point' ), 'carrier', 'carrier_point' );
	}

	public function test_every_carrier_is_published_but_the_hooks_are_wired_once(): void {
		Functions\expect( 'add_action' )->twice();

		Pickup_Blocks::add_handler( $this->handler( 'carrier', 'carrier_point' ), 'carrier', 'carrier_point' );
		Pickup_Blocks::add_handler( $this->handler( 'second', 'second_point' ), 'second', 'second_point' );
		// The same field registering again replaces itself — never a second entry.
		Pickup_Blocks::add_handler( $this->handler( 'carrier', 'carrier_point' ), 'carrier', 'carrier_point' );

		$this->assertSame(
			[
				[ 'pluginId' => 'carrier', 'fieldId' => 'carrier_point', 'configKey' => 'woodev_pickup_config_carrier' ],
				[ 'pluginId' => 'second', 'fieldId' => 'second_point', 'configKey' => 'woodev_pickup_config_second' ],
			],
			Pickup_Blocks::field_descriptors()
		);
	}

	public function test_the_integration_is_not_registered_when_woocommerce_blocks_is_absent(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Pickup_Blocks::add_handler( $this->handler( 'carrier', 'carrier_point' ), 'carrier', 'carrier_point' );

		// No `IntegrationInterface` in the unit-test process: a store without Blocks gets no button.
		$this->assertFalse( interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface', false ) );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'register' )->never();

		Pickup_Blocks::register_integration( $registry );
	}

	public function test_the_integration_is_not_registered_before_any_handler_was_added(): void {
		$registry = Mockery::mock();
		$registry->shouldReceive( 'register' )->never();

		Pickup_Blocks::register_integration( $registry );
		$this->assertSame( [], Pickup_Blocks::field_descriptors() );
	}

	public function test_register_block_types_registers_the_block_from_its_block_json(): void {
		Functions\expect( 'register_block_type' )
			->once()
			->with( Mockery::on( static fn( $path ): bool => is_string( $path ) && 1 === preg_match( '#/blocks/shipping-pickup$#', $path ) && is_file( $path . '/block.json' ) ) );

		Pickup_Blocks::register_block_types();
	}

	/**
	 * The JS registers `block.json` as its metadata and WooCommerce reads the SERVER-registered
	 * `parent`; both read this one file, so its contract is pinned here. The parent is the PLURAL
	 * `checkout-shipping-methods-block` — the rates list; the singular one is the delivery/pickup
	 * toggle.
	 */
	public function test_block_json_declares_a_forced_inner_block_of_the_shipping_methods_block(): void {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/blocks/shipping-pickup/block.json' ), true );

		$this->assertSame( Pickup_Blocks::BLOCK, $json['name'] );
		$this->assertSame( [ 'woocommerce/checkout-shipping-methods-block' ], $json['parent'] );
		// `force` defaults to `lock.default.remove` when the client does not say so.
		$this->assertTrue( $json['attributes']['lock']['default']['remove'] );
		$this->assertFalse( $json['supports']['inserter'] );
	}

	public function test_i18n_strings_carry_every_key_the_bundle_reads_and_are_english_msgids(): void {
		$strings = Pickup_Blocks::i18n_strings();

		foreach ( [ 'required' ] as $key ) {
			$this->assertArrayHasKey( $key, $strings );
			$this->assertSame( 1, preg_match( '/^[\x20-\x7E…—]+$/u', $strings[ $key ] ), "msgid for \"$key\" must be English" );
		}
	}
}
