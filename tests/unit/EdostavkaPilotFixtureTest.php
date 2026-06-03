<?php
/**
 * Edostavka-shaped pilot fixture tests.
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
	class Edostavka_Pilot_WP_REST_Controller_Stub {}

	class_alias( Edostavka_Pilot_WP_REST_Controller_Stub::class, 'WP_REST_Controller' );
}

if ( ! class_exists( '\WC_Shipping_Method', false ) ) {
	/**
	 * Minimal WooCommerce shipping method stub for isolated unit construction.
	 */
	class Edostavka_Pilot_WC_Shipping_Method_Stub {}

	class_alias( Edostavka_Pilot_WC_Shipping_Method_Stub::class, 'WC_Shipping_Method' );
}

if ( ! class_exists( '\WC_Integration', false ) ) {
	/**
	 * Minimal WooCommerce integration stub for isolated unit construction.
	 */
	class Edostavka_Pilot_WC_Integration_Stub {}

	class_alias( Edostavka_Pilot_WC_Integration_Stub::class, 'WC_Integration' );
}

/**
 * Test resolver exposing a controlled WooCommerce version and framework path.
 */
class Edostavka_Pilot_Testable_Framework_Resolver extends \Woodev\Framework\Framework_Resolver {

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
 * Class EdostavkaPilotFixtureTest
 */
class EdostavkaPilotFixtureTest extends TestCase {

	/**
	 * The edostavka-shaped pilot fixture should load through the Platform v2 path
	 * and preserve the installed-site shipping method ID and settings option key.
	 */
	public function test_edostavka_pilot_fixture_loads_through_explicit_loader_definition(): void {
		$this->mock_wordpress_runtime_functions();

		$fixture = dirname( __DIR__ ) . '/_fixtures/woodev-edostavka-pilot-plugin/woodev-edostavka-pilot-plugin.php';

		require_once $fixture;

		$resolver             = new Edostavka_Pilot_Testable_Framework_Resolver();
		$resolver->wc_version = '7.0.0';

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$accepted = $resolver->register_loader_definition( \woodev_edostavka_pilot_plugin_loader_definition() );

		$resolver->load_plugins();

		$plugin = \woodev_edostavka_pilot_plugin();

		$this->assertTrue( $accepted, 'edostavka-shaped definition accepted by resolver' );
		$this->assertCount( 1, $resolver->get_active_plugins() );
		$this->assertInstanceOf( \Woodev\Framework\Woocommerce_Plugin::class, $plugin );
		$this->assertInstanceOf( \Woodev\Framework\Shipping\Shipping_Plugin::class, $plugin );
		// installed-site contract: shipping method id must be exactly 'edostavka'.
		$this->assertSame(
			[ 'edostavka' => 'Woodev_Edostavka_Pilot_Shipping_Method' ],
			$plugin->get_fixture_shipping_method_classes()
		);
		// installed-site contract: settings option key preserved.
		$this->assertSame( 'woocommerce_edostavka_settings', $plugin->get_fixture_settings_option_name() );
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
