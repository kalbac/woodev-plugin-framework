<?php
/**
 * Realistic payment fixture tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-plugin-loader-definition.php';

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\Support\Pilot_Fixture_WP_Stubs;
use Woodev\Tests\Unit\Support\Pilot_Testable_Framework_Resolver;

/**
 * Class RealisticPaymentFixtureTest
 */
class RealisticPaymentFixtureTest extends TestCase {

	use Pilot_Fixture_WP_Stubs;

	/**
	 * Realistic file-based payment fixtures should load through the Platform v2 path.
	 */
	public function test_realistic_payment_fixture_loads_through_explicit_loader_definition(): void {
		$this->install_woocommerce_class_stubs();
		$this->mock_wordpress_runtime_functions( true );

		$fixture = dirname( __DIR__ ) . '/_fixtures/woodev-realistic-payment-plugin/woodev-realistic-payment-plugin.php';

		require_once $fixture;

		$resolver             = new Pilot_Testable_Framework_Resolver();
		$resolver->wc_version = '7.0.0';

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$accepted = $resolver->register_loader_definition( \woodev_realistic_payment_plugin_loader_definition() );

		$resolver->load_plugins();

		$plugin = \woodev_realistic_payment_plugin();

		$this->assertTrue( $accepted );
		$this->assertCount( 1, $resolver->get_active_plugins() );
		$this->assertInstanceOf( \Woodev\Framework\Woocommerce_Plugin::class, $plugin );
		$this->assertInstanceOf( \Woodev_Payment_Gateway_Plugin::class, $plugin );
		$this->assertTrue( class_exists( 'Woodev_Realistic_Gateway', false ) );
		$this->assertTrue( is_subclass_of( 'Woodev_Realistic_Gateway', \Woodev_Payment_Gateway::class ) );
		$this->assertSame(
			[ 'Woodev_Realistic_Gateway' ],
			$plugin->get_fixture_gateway_class_names()
		);

		$this->assert_restored_gateway_base_methods_execute();
	}

	/**
	 * Verifies restored Woodev_Payment_Gateway base methods execute correctly.
	 *
	 * These methods were removed by an over-aggressive cleanup while surviving
	 * code still called them (e.g. is_available() -> currency_is_accepted()).
	 * They were restored from the pre-cleanup version; this exercises the pure
	 * getters via reflection without constructing the full WooCommerce gateway
	 * runtime, so no payment business logic executes.
	 *
	 * @return void
	 */
	private function assert_restored_gateway_base_methods_execute(): void {
		$gateway = ( new \ReflectionClass( 'Woodev_Realistic_Gateway' ) )->newInstanceWithoutConstructor();

		$set = static function ( string $property, $value ) use ( $gateway ): void {
			$reflection = new \ReflectionProperty( \Woodev_Payment_Gateway::class, $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection->setAccessible( true );
			}
			$reflection->setValue( $gateway, $value );
		};

		$set( 'currencies', [ 'RUB', 'USD' ] );
		$this->assertTrue( $gateway->currency_is_accepted( 'RUB' ) );
		$this->assertFalse( $gateway->currency_is_accepted( 'XXX' ) );
		$this->assertSame( [ 'RUB', 'USD' ], $gateway->get_accepted_currencies() );

		$set( 'currencies', [] );
		$this->assertTrue( $gateway->currency_is_accepted( 'XXX' ) );

		$set( 'environment', 'test' );
		$this->assertSame( 'test', $gateway->get_environment() );
		$this->assertTrue( $gateway->is_test_environment() );
		$this->assertFalse( $gateway->is_production_environment() );

		$set( 'debug_mode', 'off' );
		$this->assertTrue( $gateway->debug_off() );
		$this->assertFalse( $gateway->debug_log() );

		$set( 'debug_mode', 'both' );
		$this->assertTrue( $gateway->debug_log() );
		$this->assertTrue( $gateway->debug_checkout() );

		$set( 'enable_csc', 'yes' );
		$this->assertTrue( $gateway->csc_enabled() );
		$this->assertTrue( $gateway->csc_required() );

		$set( 'inherit_settings', 'yes' );
		$this->assertTrue( $gateway->inherit_settings() );

		$set( 'enable_customer_decline_messages', 'yes' );
		$this->assertTrue( $gateway->is_detailed_customer_decline_messages_enabled() );

		$set( 'plugin', \woodev_realistic_payment_plugin() );
		$this->assertSame( \woodev_realistic_payment_plugin(), $gateway->get_plugin() );

		$this->assertFalse( $gateway->is_direct_gateway() );
	}
}
