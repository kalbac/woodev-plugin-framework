<?php
/**
 * Plugin action links tests.
 *
 * Woodev_Plugin::plugin_action_links() has never been extracted (see
 * docs-internal/gotchas/handler-extraction-must-preserve-override-chain.md — the
 * extraction was built, measured, and reverted because it required overridable-handler
 * scaffolding and grew the base instead of shrinking it). This file pins the two
 * installed-site contracts that live in this method: the `plugin_action_links_{basename}`
 * filter name/binding, and the shape of the links it produces.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

if ( ! class_exists( '\WP_REST_Controller', false ) ) {
	/**
	 * Minimal WordPress REST controller stub for isolated unit construction.
	 */
	class WP_REST_Controller_Test_Stub_For_Action_Links {

		/** @var string */
		protected $namespace;

		/** @var string */
		protected $rest_base;
	}

	class_alias( WP_REST_Controller_Test_Stub_For_Action_Links::class, 'WP_REST_Controller' );
}

/**
 * Testable plugin exposing controllable inputs for plugin_action_links().
 */
class Testable_Action_Links_Plugin extends \Woodev_Plugin {

	/**
	 * Configure link markup returned by get_settings_link().
	 *
	 * @var string
	 */
	public $stub_settings_link = '';

	/**
	 * Documentation URL returned by get_documentation_url().
	 *
	 * @var string|null
	 */
	public $stub_documentation_url = null;

	/**
	 * Support URL returned by get_support_url().
	 *
	 * @var string|null
	 */
	public $stub_support_url = null;

	/**
	 * Reviews URL returned by get_reviews_url().
	 *
	 * @var string
	 */
	public $stub_reviews_url = '';

	/**
	 * Whether the plugin needs a license, returned by is_need_license().
	 *
	 * @var bool
	 */
	public $stub_need_license = false;

	/**
	 * License instance returned by get_license_instance().
	 *
	 * @var object|null
	 */
	public $stub_license_instance = null;

	/**
	 * No-op dependency handler for isolated construction.
	 *
	 * @param array<string,mixed> $dependencies Dependency configuration.
	 * @return void
	 */
	protected function init_dependencies( $dependencies ) {}

	/**
	 * No-op admin message handler for isolated construction.
	 *
	 * @return void
	 */
	protected function init_admin_message_handler() {}

	/**
	 * No-op admin notice handler for isolated construction.
	 *
	 * @return void
	 */
	protected function init_admin_notice_handler() {}

	/**
	 * No-op license handler for isolated construction.
	 *
	 * @return void
	 */
	protected function init_license_handler() {}

	/**
	 * No-op hook deprecator for isolated construction.
	 *
	 * @return void
	 */
	protected function init_hook_deprecator() {}

	/**
	 * No-op lifecycle handler for isolated construction.
	 *
	 * @return void
	 */
	protected function init_lifecycle_handler() {}

	/**
	 * No-op REST API handler for isolated construction.
	 *
	 * @return void
	 */
	protected function init_rest_api_handler() {}

	/**
	 * No-op blocks handler for isolated construction.
	 *
	 * @return void
	 */
	protected function init_blocks_handler(): void {}

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file() {
		return 'acme-plugin/acme-plugin.php';
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Acme Plugin';
	}

	/**
	 * Gets the download ID.
	 *
	 * @return int
	 */
	public function get_download_id() {
		return 0;
	}

	/**
	 * Returns the stubbed settings link.
	 *
	 * @param string|null $plugin_id Optional plugin identifier (ignored by the stub).
	 * @return string
	 */
	public function get_settings_link( $plugin_id = null ) {
		return $this->stub_settings_link;
	}

	/**
	 * Returns the stubbed documentation URL.
	 *
	 * @return string|null
	 */
	public function get_documentation_url() {
		return $this->stub_documentation_url;
	}

	/**
	 * Returns the stubbed support URL.
	 *
	 * @return string|null
	 */
	public function get_support_url() {
		return $this->stub_support_url;
	}

	/**
	 * Returns the stubbed reviews URL.
	 *
	 * @return string
	 */
	public function get_reviews_url() {
		return $this->stub_reviews_url;
	}

	/**
	 * Returns whether the stubbed plugin needs a license.
	 *
	 * @return bool
	 */
	public function is_need_license(): bool {
		return $this->stub_need_license;
	}

	/**
	 * Returns the stubbed license instance.
	 *
	 * @return object|null
	 */
	public function get_license_instance() {
		return $this->stub_license_instance;
	}
}

/**
 * Class PluginActionLinksTest.
 */
class PluginActionLinksTest extends TestCase {

	/**
	 * Defines the WordPress function stubs required by base plugin construction.
	 *
	 * Copied from WoocommercePluginTest — the recipe that lets a Woodev_Plugin
	 * subclass run its real constructor (including add_hooks()) in a unit context.
	 *
	 * @return void
	 */
	private function mock_wordpress_plugin_construction_functions(): void {
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_replace_recursive( $defaults, $args );
			}
		);
		Functions\when( 'plugin_dir_path' )->alias(
			static function ( string $file ): string {
				return trailingslashit( dirname( $file ) );
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
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'is_multisite' )->justReturn( false );
		// get_plugin_url() -> plugins_url(): needed since issue #759 made
		// init_license_handler() build a real Woodev_Plugins_License unconditionally.
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/wp-content/plugins/test-plugin' );
	}

	/**
	 * Constructing the plugin must wire the `plugin_action_links_{basename}` filter
	 * (installed-site contract) bound to the plugin instance itself — never to a
	 * separate handler object, which would bypass a subclass parent:: override
	 * (see Woodev_Payment_Gateway_Plugin::plugin_action_links()).
	 *
	 * @return void
	 */
	public function test_constructor_wires_plugin_action_links_filter_to_the_plugin_instance(): void {
		$this->mock_wordpress_plugin_construction_functions();

		$plugin = new Testable_Action_Links_Plugin( 'acme-plugin', '1.0.0' );

		$this->assertSame(
			10,
			Filters\has( 'plugin_action_links_acme-plugin/acme-plugin.php', [ $plugin, 'plugin_action_links' ] ),
			'plugin_action_links_{basename} must be registered against the plugin instance at priority 10.'
		);
	}

	/**
	 * plugin_action_links() adds the Configure/Docs/Support/Review entries when the
	 * corresponding accessor returns a value, in front of the incoming actions.
	 *
	 * @return void
	 */
	public function test_plugin_action_links_adds_available_entries_in_front_of_actions(): void {
		$reflection = new \ReflectionClass( Testable_Action_Links_Plugin::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();

		$plugin->stub_settings_link     = '<a href="settings">Настройки</a>';
		$plugin->stub_documentation_url = 'https://example.com/docs';
		$plugin->stub_support_url       = 'https://example.com/support';
		$plugin->stub_reviews_url       = 'https://example.com/reviews';

		$links = $plugin->plugin_action_links( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertSame( [ 'configure', 'docs', 'support', 'review', 'deactivate' ], array_keys( $links ) );
		$this->assertSame( '<a href="settings">Настройки</a>', $links['configure'] );
		$this->assertStringContainsString( 'https://example.com/docs', $links['docs'] );
		$this->assertStringContainsString( 'https://example.com/support', $links['support'] );
		$this->assertStringContainsString( 'https://example.com/reviews', $links['review'] );
	}

	/**
	 * plugin_action_links() omits entries whose accessor returns an empty value, and
	 * leaves the incoming actions untouched.
	 *
	 * @return void
	 */
	public function test_plugin_action_links_omits_missing_entries(): void {
		$reflection = new \ReflectionClass( Testable_Action_Links_Plugin::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();

		$links = $plugin->plugin_action_links( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertSame( [ 'deactivate' => '<a href="#">Deactivate</a>' ], $links );
	}

	/**
	 * plugin_action_links() adds a license link, labelled by validity, when the plugin
	 * needs a license and a license settings URL is available.
	 *
	 * @return void
	 */
	public function test_plugin_action_links_adds_license_entry_when_license_required(): void {
		$license = Mockery::mock();
		$license->shouldReceive( 'get_license_settings_url' )->andReturn( 'https://example.com/license' );
		$license->shouldReceive( 'is_license_valid' )->andReturn( false );

		$reflection = new \ReflectionClass( Testable_Action_Links_Plugin::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();

		$plugin->stub_need_license      = true;
		$plugin->stub_license_instance  = $license;

		$links = $plugin->plugin_action_links( [] );

		$this->assertArrayHasKey( 'license', $links );
		$this->assertStringContainsString( 'https://example.com/license', $links['license'] );
		$this->assertStringContainsString( 'Указать лицензию', $links['license'] );
	}
}
