<?php
/**
 * Realistic shipping fixture tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-plugin-loader-definition.php';

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\Support\Pilot_Fixture_WP_Stubs;
use Woodev\Tests\Unit\Support\Pilot_Testable_Framework_Resolver;

/**
 * Class RealisticShippingFixtureTest
 */
class RealisticShippingFixtureTest extends TestCase {

	use Pilot_Fixture_WP_Stubs;

	/**
	 * Realistic file-based shipping fixtures should load through the Platform v2 path.
	 */
	public function test_realistic_shipping_fixture_loads_through_explicit_loader_definition(): void {
		$this->install_woocommerce_class_stubs();
		$this->mock_wordpress_runtime_functions( true );

		$fixture = dirname( __DIR__ ) . '/_fixtures/woodev-realistic-shipping-plugin/woodev-realistic-shipping-plugin.php';

		require_once $fixture;

		$resolver             = new Pilot_Testable_Framework_Resolver();
		$resolver->wc_version = '7.0.0';

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$accepted = $resolver->register_loader_definition( \woodev_realistic_shipping_plugin_loader_definition() );

		$resolver->load_plugins();

		$plugin = \woodev_realistic_shipping_plugin();

		$this->assertTrue( $accepted );
		$this->assertCount( 1, $resolver->get_active_plugins() );
		$this->assertInstanceOf( \Woodev\Framework\Woocommerce_Plugin::class, $plugin );
		$this->assertInstanceOf( \Woodev\Framework\Shipping\Shipping_Plugin::class, $plugin );
		$this->assertTrue( class_exists( 'Woodev_Realistic_Shipping_Method', false ) );
		$this->assertTrue( class_exists( 'Woodev_Realistic_Pickup_Shipping_Method', false ) );
		$this->assertSame(
			[
				'woodev_realistic_shipping'        => 'Woodev_Realistic_Shipping_Method',
				'woodev_realistic_pickup_shipping' => 'Woodev_Realistic_Pickup_Shipping_Method',
			],
			$plugin->get_fixture_shipping_method_classes()
		);
	}
}
