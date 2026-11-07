<?php
/**
 * Where a plugin's «Настройки» link points, per plugin type (the shipping carrier is covered in
 * Shipping/ShippingPluginNotConfiguredTest): a generic plugin goes to its tab on `woodev-settings`
 * and has no link when it has no tab; a payment gateway stays on the WooCommerce gateway screen.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\{Settings_Page_Registry, Settings_Provider};

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/handlers/blocks-handler.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-plugin.php';

if ( ! class_exists( '\WP_REST_Controller', false ) ) {
	/**
	 * Minimal WordPress REST controller stub for isolated unit construction.
	 */
	class WP_REST_Controller_Test_Stub_For_Settings_Url {

		/** @var string */
		protected $namespace;

		/** @var string */
		protected $rest_base;
	}

	class_alias( WP_REST_Controller_Test_Stub_For_Settings_Url::class, 'WP_REST_Controller' );
}

/**
 * A generic plugin whose tabs the test sets.
 */
class Settings_Url_Generic_Plugin_Fixture extends \Woodev_Plugin {

	/** @var array<int,mixed> providers returned by get_settings_providers() */
	public array $providers = [];

	public function get_id() {
		return 'acme-generic';
	}

	public function get_settings_providers(): array {
		return $this->providers;
	}

	protected function get_file() {
		return 'acme-generic/acme-generic.php';
	}

	public function get_plugin_name() {
		return 'Acme Generic';
	}

	public function get_download_id() {
		return 0;
	}
}

/**
 * A payment-gateway plugin with no tab on the Woodev settings page.
 */
class Settings_Url_Gateway_Plugin_Fixture extends \Woodev_Payment_Gateway_Plugin {

	public function get_id() {
		return 'acme-gateway';
	}

	protected function get_file(): string {
		return 'acme-gateway/acme-gateway.php';
	}

	public function get_plugin_name(): string {
		return 'Acme Gateway';
	}

	public function get_download_id(): int {
		return 0;
	}
}

/**
 * @covers \Woodev_Plugin::get_settings_url
 * @covers \Woodev_Plugin::get_settings_link
 * @covers \Woodev_Payment_Gateway_Plugin::get_settings_url
 * @covers \Woodev\Framework\Settings\Settings_Page_Registry::get_tab_url
 */
final class PluginSettingsUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
	}

	private function provider( string $id ): Settings_Provider {
		return Settings_Provider::create( $id, 'Acme', Mockery::mock( \Woodev_Abstract_Settings::class ), [], [] );
	}

	private function generic(): Settings_Url_Generic_Plugin_Fixture {
		return ( new \ReflectionClass( Settings_Url_Generic_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();
	}

	public function test_the_tab_url_builder_encodes_the_tab_id(): void {
		$this->assertSame( 'https://shop.test/wp-admin/admin.php?page=woodev-settings&tab=cdek', Settings_Page_Registry::get_tab_url( 'cdek' ) );
		$this->assertSame( 'https://shop.test/wp-admin/admin.php?page=woodev-settings&tab=a%20b', Settings_Page_Registry::get_tab_url( 'a b' ) );
	}

	public function test_a_generic_plugin_with_a_tab_links_to_that_tab(): void {

		$plugin            = $this->generic();
		$plugin->providers = [ $this->provider( 'acme-generic' ) ];

		$this->assertSame( 'https://shop.test/wp-admin/admin.php?page=woodev-settings&tab=acme-generic', $plugin->get_settings_url() );
		$this->assertSame(
			'<a href="https://shop.test/wp-admin/admin.php?page=woodev-settings&tab=acme-generic">Настройки</a>',
			$plugin->get_settings_link()
		);
	}

	public function test_a_generic_plugin_links_to_its_first_tab(): void {

		$plugin            = $this->generic();
		$plugin->providers = [ $this->provider( 'first' ), $this->provider( 'second' ) ];

		$this->assertStringEndsWith( 'tab=first', $plugin->get_settings_url() );
	}

	public function test_a_generic_plugin_without_a_tab_has_no_settings_link(): void {

		$plugin = $this->generic();

		$this->assertSame( '', $plugin->get_settings_url() );
		$this->assertSame( '', $plugin->get_settings_link() );
	}

	public function test_a_payment_gateway_keeps_the_woocommerce_gateway_screen(): void {

		$plugin = ( new \ReflectionClass( Settings_Url_Gateway_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		$url = $plugin->get_settings_url( 'acme_gateway' );

		$this->assertStringContainsString( 'page=wc-settings&tab=checkout&section=acme_gateway', $url );
		$this->assertStringNotContainsString( 'woodev-settings', $url );
	}
}
