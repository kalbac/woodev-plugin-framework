<?php
/**
 * Tests for Pickup_Blocks_Integration — the script/data half of the Checkout Blocks pickup-point
 * button (SP-11 C-2b, #1089).
 *
 * The class implements WooCommerce Blocks' `IntegrationInterface`, which does not exist in the unit
 * process (and must not: another test asserts its ABSENCE degrades gracefully). Each test therefore
 * runs in its own process and declares the interface itself.
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
 * @covers \Woodev\Framework\Shipping\Checkout\Blocks\Pickup_Blocks_Integration
 */
class PickupBlocksIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface', false ) ) {
			eval( 'namespace Automattic\WooCommerce\Blocks\Integrations; interface IntegrationInterface { public function get_name(); public function initialize(); public function get_script_handles(); public function get_editor_script_handles(); public function get_script_data(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/blocks/class-locality-blocks-integration.php';
		require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/blocks/class-pickup-blocks-integration.php';

		if ( ! class_exists( __NAMESPACE__ . '\Probed_Pickup_Integration', false ) ) {
			eval( 'namespace Woodev\Tests\Unit\Shipping\Checkout\Blocks; class Probed_Pickup_Integration extends \Woodev\Framework\Shipping\Checkout\Blocks\Pickup_Blocks_Integration { public static $bundles = 0; protected static function register_bundle(): void { ++self::$bundles; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		Pickup_Blocks::reset();
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		Pickup_Blocks::reset();
		parent::tearDown();
	}

	private function add_carrier(): void {
		$handler = Mockery::mock( Pickup_Handler::class );
		$handler->shouldReceive( 'blocks_descriptor' )->andReturn(
			[
				'pluginId'  => 'carrier',
				'fieldId'   => 'carrier_point',
				'configKey' => 'woodev_pickup_config_carrier',
			]
		);

		Pickup_Blocks::add_handler( $handler, 'carrier', 'carrier_point' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_it_is_named_for_the_data_key_the_bundle_reads(): void {
		$this->assertSame( 'woodev-shipping-pickup', ( new Probed_Pickup_Integration() )->get_name() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_it_shares_the_checkout_blocks_bundle_and_registers_it_itself(): void {
		$integration = new Probed_Pickup_Integration();
		$integration->initialize();

		// A store whose locality integration is absent still gets the bundle registered.
		$this->assertSame( 1, Probed_Pickup_Integration::$bundles );

		Functions\when( 'wp_script_is' )->justReturn( true );
		$this->assertSame( [ 'woodev-checkout-blocks' ], $integration->get_script_handles() );
		$this->assertSame( [], $integration->get_editor_script_handles() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unbuilt_bundle_names_no_handle_so_nothing_can_404(): void {
		Functions\when( 'wp_script_is' )->justReturn( false );

		$this->assertSame( [], ( new Probed_Pickup_Integration() )->get_script_handles() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_script_data_is_disabled_in_the_editor(): void {
		$this->add_carrier();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\expect( 'wp_enqueue_style' )->never();

		$this->assertSame( [ 'enabled' => false ], ( new Probed_Pickup_Integration() )->get_script_data() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_script_data_is_disabled_when_no_carrier_has_a_pickup_field(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$this->assertSame( [ 'enabled' => false ], ( new Probed_Pickup_Integration() )->get_script_data() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_script_data_publishes_the_transport_keys_and_never_a_nonce_or_a_selection(): void {
		$this->add_carrier();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_style_is' )->justReturn( true );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'woodev-checkout-blocks' );

		$data = ( new Probed_Pickup_Integration() )->get_script_data();

		$this->assertSame(
			[
				'enabled'   => true,
				'namespace' => 'woodev-shipping',
				'fields'    => [
					[
						'pluginId'  => 'carrier',
						'fieldId'   => 'carrier_point',
						'configKey' => 'woodev_pickup_config_carrier',
					],
				],
				'i18n'      => [
					'required'       => 'Please choose a pickup point.',
					'chooseLocality' => 'Choose your locality from the suggestions to see pickup points.',
				],
			],
			$data
		);
	}
}
