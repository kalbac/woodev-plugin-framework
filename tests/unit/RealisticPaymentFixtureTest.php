<?php
/**
 * Realistic payment fixture tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-plugin-loader-definition.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-resolver.php';

use Brain\Monkey\Functions;

if ( ! class_exists( '\WP_REST_Controller', false ) ) {
	/**
	 * Minimal WordPress REST controller stub for isolated unit construction.
	 */
	class Realistic_Payment_WP_REST_Controller_Stub {}

	class_alias( Realistic_Payment_WP_REST_Controller_Stub::class, 'WP_REST_Controller' );
}

if ( ! class_exists( '\WC_Payment_Gateway', false ) ) {
	/**
	 * Minimal WooCommerce payment gateway stub for isolated unit construction.
	 */
	class Realistic_Payment_WC_Payment_Gateway_Stub {}

	class_alias( Realistic_Payment_WC_Payment_Gateway_Stub::class, 'WC_Payment_Gateway' );
}

/**
 * Test resolver exposing a controlled WooCommerce version and framework path.
 */
class Realistic_Payment_Testable_Framework_Resolver extends \Woodev\Framework\Framework_Resolver {

	/** @var string|null WooCommerce version used for resolver assertions. */
	public ?string $wc_version = null;

	/**
	 * Returns the repository root as the selected framework path base.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	public function get_plugin_path( string $file ): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Gets the test WooCommerce version.
	 *
	 * @return string|null
	 */
	protected function get_wc_version(): ?string {
		return $this->wc_version;
	}
}

/**
 * Class RealisticPaymentFixtureTest
 */
class RealisticPaymentFixtureTest extends TestCase {

	/**
	 * Realistic file-based payment fixtures should load through the Platform v2 path.
	 */
	public function test_realistic_payment_fixture_loads_through_explicit_loader_definition(): void {
		$this->mock_wordpress_runtime_functions();

		$fixture = dirname( __DIR__ ) . '/_fixtures/woodev-realistic-payment-plugin/woodev-realistic-payment-plugin.php';

		require_once $fixture;

		$resolver             = new Realistic_Payment_Testable_Framework_Resolver();
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

	/**
	 * Defines WordPress function stubs required by isolated plugin construction.
	 *
	 * @return void
	 */
	private function mock_wordpress_runtime_functions(): void {
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_replace_recursive( $defaults, $args );
			}
		);
		Functions\when( 'plugin_dir_path' )->alias(
			static function ( string $file ): string {
				return rtrim( dirname( $file ), '/\\' ) . '/';
			}
		);
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'trailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' ) . '/';
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
	}
}
