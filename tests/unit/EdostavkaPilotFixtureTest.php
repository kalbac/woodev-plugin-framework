<?php
/**
 * Edostavka-shaped pilot fixture tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-plugin-loader-definition.php';

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\Support\Pilot_Fixture_WP_Stubs;
use Woodev\Tests\Unit\Support\Pilot_Testable_Framework_Resolver;

/**
 * Class EdostavkaPilotFixtureTest
 */
class EdostavkaPilotFixtureTest extends TestCase {

	use Pilot_Fixture_WP_Stubs;

	/**
	 * The edostavka-shaped pilot fixture should load through the Platform v2 path
	 * and preserve the installed-site shipping method ID and settings option key.
	 */
	public function test_edostavka_pilot_fixture_loads_through_explicit_loader_definition(): void {
		$this->install_woocommerce_class_stubs();
		$this->mock_wordpress_runtime_functions();

		$fixture = dirname( __DIR__ ) . '/_fixtures/woodev-edostavka-pilot-plugin/woodev-edostavka-pilot-plugin.php';

		require_once $fixture;

		$this->assertFalse(
			class_exists( 'Woodev_Edostavka_Pilot_Plugin', false ),
			'Fixture plugin class must not be loaded before resolver invokes the callback.'
		);
		$this->assertFalse(
			class_exists( 'Woodev_Edostavka_Pilot_Shipping_Method', false ),
			'Fixture shipping method class must not be loaded before the new load path runs.'
		);

		$resolver             = new Pilot_Testable_Framework_Resolver();
		$resolver->wc_version = '7.0.0';

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\expect( 'add_filter' )
			->once()
			->with( 'woocommerce_shipping_methods', \Mockery::type( 'array' ) )
			->andReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return $value;
			}
		);

		$accepted = $resolver->register_loader_definition( \woodev_edostavka_pilot_plugin_loader_definition() );

		$resolver->load_plugins();

		$plugin = \woodev_edostavka_pilot_plugin();

		$this->assertTrue( $accepted, 'edostavka-shaped definition accepted by resolver' );
		$this->assertCount( 1, $resolver->get_active_plugins() );
		$this->assertInstanceOf( \Woodev\Framework\Woocommerce_Plugin::class, $plugin );
		$this->assertInstanceOf( \Woodev\Framework\Shipping\Shipping_Plugin::class, $plugin );
		$this->assertTrue( class_exists( 'Woodev_Edostavka_Pilot_Shipping_Method', false ) );
		// installed-site contract: shipping method id must be exactly 'edostavka'.
		$this->assertSame(
			[ 'edostavka' => 'Woodev_Edostavka_Pilot_Shipping_Method' ],
			$plugin->get_fixture_shipping_method_classes()
		);
		$this->assertSame(
			[ 'edostavka' => 'Woodev_Edostavka_Pilot_Shipping_Method' ],
			$plugin->register_shipping_methods( [] ),
			'Real Shipping_Plugin registration must preserve the edostavka method ID.'
		);
		// installed-site contract: settings option key preserved.
		$this->assertSame( 'woocommerce_edostavka_settings', $plugin->get_fixture_settings_option_name() );
	}
}
