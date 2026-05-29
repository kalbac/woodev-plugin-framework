<?php
/**
 * Platform-neutral deprecation helper tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-lifecycle.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';

/**
 * Test API implementation exposing the deprecated TLS wrapper.
 */
class Testable_Deprecated_API extends \Woodev_API_Base {

	/**
	 * Plugin test double.
	 *
	 * @var object
	 */
	private $plugin;

	/**
	 * Constructs the test API wrapper.
	 *
	 * @param object $plugin Plugin test double.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Gets a new request instance.
	 *
	 * @param array<string,mixed> $args Request arguments.
	 * @return object|null
	 */
	protected function get_new_request( $args = [] ) {
		return null;
	}

	/**
	 * Gets the plugin instance.
	 *
	 * @return object
	 */
	protected function get_plugin() {
		return $this->plugin;
	}
}

/**
 * Class PlatformNeutralDeprecationTest.
 */
class PlatformNeutralDeprecationTest extends TestCase {

	/**
	 * Base-owned deprecated methods should use WordPress core helpers, not WooCommerce wrappers.
	 *
	 * @return void
	 */
	public function test_base_owned_modules_do_not_use_woocommerce_deprecation_wrappers(): void {
		$files = [
			dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php',
			dirname( __DIR__, 2 ) . '/woodev/class-lifecycle.php',
			dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php',
		];

		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );

			$this->assertIsString( $contents );
			$this->assertStringNotContainsString( 'wc_deprecated_function(', $contents, $file );
			$this->assertStringNotContainsString( 'wc_deprecated_argument(', $contents, $file );
		}
	}

	/**
	 * The API TLS compatibility wrapper should keep delegating after using the WordPress deprecation helper.
	 *
	 * @return void
	 */
	public function test_api_tls_wrapper_uses_wordpress_deprecation_helper(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'require_tls_1_2' )->once()->andReturn( true );

		Functions\expect( '_deprecated_function' )
			->once()
			->with( 'Woodev_API_Base::require_tls_1_2', '1.1.6', 'Woodev_Plugin::require_tls_1_2()' );

		$api = new Testable_Deprecated_API( $plugin );

		$this->assertTrue( $api->require_tls_1_2() );
	}

	/**
	 * The lifecycle deprecated update hook should use the WordPress deprecation helper.
	 *
	 * @return void
	 */
	public function test_lifecycle_update_wrapper_uses_wordpress_deprecation_helper(): void {
		Functions\expect( '_deprecated_function' )
			->once()
			->with( 'Woodev_Lifecycle::do_update', '1.2.0' );

		$lifecycle = ( new \ReflectionClass( \Woodev_Lifecycle::class ) )->newInstanceWithoutConstructor();

		$lifecycle->do_update();
	}
}
