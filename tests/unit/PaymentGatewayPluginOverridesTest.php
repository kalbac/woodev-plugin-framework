<?php
/**
 * Payment-gateway override-chain tests.
 *
 * These pin the exact regression risk documented in
 * docs-internal/gotchas/handler-extraction-must-preserve-override-chain.md: a P4
 * handler extraction that self-registers its hook bound to a new handler instance
 * (instead of the plugin) would silently disable
 * Woodev_Payment_Gateway_Plugin::plugin_action_links()'s parent:: override, and eager
 * handler construction would double-log every live payment plugin because
 * Woodev_Payment_Gateway_Plugin::add_api_request_logging() deliberately no-ops the
 * base's registration. Woodev_Plugin keeps both as real, overridable, delegating
 * methods specifically so this chain keeps working — this file is the guard against
 * regressing that.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/handlers/blocks-handler.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-plugin.php';

if ( ! class_exists( '\WP_REST_Controller', false ) ) {
	/**
	 * Minimal WordPress REST controller stub for isolated unit construction.
	 */
	class WP_REST_Controller_Test_Stub_For_Overrides {

		/** @var string */
		protected $namespace;

		/** @var string */
		protected $rest_base;
	}

	class_alias( WP_REST_Controller_Test_Stub_For_Overrides::class, 'WP_REST_Controller' );
}

/**
 * Testable payment-gateway plugin exposing a controllable get_settings_link() spy and
 * a reflection-settable $gateways list, without running the (heavy) real constructor.
 */
class Testable_Gateway_Plugin_For_Overrides extends \Woodev_Payment_Gateway_Plugin {

	/**
	 * Plugin ids that get_settings_link() was called with, in call order.
	 *
	 * @var array<int,string|null>
	 */
	public $settings_link_calls = [];

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file(): string {
		return 'acme-gateway/acme-gateway.php';
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'Acme Gateway';
	}

	/**
	 * Gets the download ID.
	 *
	 * @return int
	 */
	public function get_download_id(): int {
		return 0;
	}

	/**
	 * Records the call and returns a deterministic, per-id settings link.
	 *
	 * @param string|null $plugin_id Plugin/gateway identifier.
	 * @return string
	 */
	public function get_settings_link( $plugin_id = null ) {
		$this->settings_link_calls[] = $plugin_id;

		return sprintf( '<a href="settings-%s">Настройки</a>', $plugin_id );
	}

	/**
	 * No documentation link for this fixture.
	 *
	 * @return string|null
	 */
	public function get_documentation_url() {
		return null;
	}

	/**
	 * No support link for this fixture.
	 *
	 * @return string|null
	 */
	public function get_support_url() {
		return null;
	}

	/**
	 * No reviews link for this fixture.
	 *
	 * @return string
	 */
	public function get_reviews_url() {
		return '';
	}

	/**
	 * This fixture never requires a license.
	 *
	 * @return bool
	 */
	public function is_need_license(): bool {
		return false;
	}
}

/**
 * Class PaymentGatewayPluginOverridesTest.
 */
class PaymentGatewayPluginOverridesTest extends TestCase {

	/**
	 * Builds a testable gateway plugin (no constructor) with the given gateway ids
	 * populated on the private $gateways property, as get_gateway_ids() requires.
	 *
	 * @param array<string,mixed> $gateways Gateway id => config map.
	 * @return Testable_Gateway_Plugin_For_Overrides
	 */
	private function make_plugin( array $gateways ): Testable_Gateway_Plugin_For_Overrides {
		$reflection = new \ReflectionClass( Testable_Gateway_Plugin_For_Overrides::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();

		$gateways_property = new \ReflectionProperty( \Woodev_Payment_Gateway_Plugin::class, 'gateways' );
		if ( PHP_VERSION_ID < 80100 ) {
			$gateways_property->setAccessible( true );
		}
		$gateways_property->setValue( $plugin, $gateways );

		return $plugin;
	}

	/**
	 * Woodev_Payment_Gateway_Plugin::plugin_action_links() must still reach
	 * parent::plugin_action_links() — proven by the base's own get_settings_link()
	 * call (for the base 'configure' entry) happening before the per-gateway calls,
	 * and by the base's 'configure' key being replaced with one 'configure_{id}' key
	 * per gateway.
	 *
	 * @return void
	 */
	public function test_gateway_override_reaches_base_via_parent_and_replaces_configure_link(): void {
		$plugin = $this->make_plugin(
			[
				'gw_one' => [ 'gateway_class_name' => 'Whatever_One' ],
				'gw_two' => [ 'gateway_class_name' => 'Whatever_Two' ],
			]
		);

		$links = $plugin->plugin_action_links( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		// 4 calls: the base's own (for 'configure', using $this->get_id(), called twice —
		// once in the truthiness check, once to assign) first, then one per gateway —
		// this is only possible if parent::plugin_action_links() ran.
		$this->assertCount( 4, $plugin->settings_link_calls );
		$this->assertSame( [ null, null ], array_slice( $plugin->settings_link_calls, 0, 2 ) );
		$this->assertSame( [ 'gw_one', 'gw_two' ], array_slice( $plugin->settings_link_calls, 2 ) );

		$this->assertArrayNotHasKey( 'configure', $links, 'The base "configure" key must be replaced by per-gateway links.' );
		$this->assertSame( [ 'configure_gw_one', 'configure_gw_two', 'deactivate' ], array_keys( $links ) );
		$this->assertSame( '<a href="settings-gw_one">Настройки</a>', $links['configure_gw_one'] );
		$this->assertSame( '<a href="settings-gw_two">Настройки</a>', $links['configure_gw_two'] );
	}

	/**
	 * Woodev_Payment_Gateway_Plugin::add_api_request_logging() must stay a no-op:
	 * calling it must never touch add_action, so the base's registration stays
	 * suppressed and gateways can log per-gateway instead (Woodev_Payment_Gateway
	 * logs via its own listener; unconditional handler self-registration would
	 * double-log every live gateway plugin).
	 *
	 * @return void
	 */
	public function test_add_api_request_logging_is_still_a_no_op(): void {
		$plugin = $this->make_plugin( [ 'gw_one' => [ 'gateway_class_name' => 'Whatever' ] ] );

		Functions\expect( 'add_action' )->never();

		$plugin->add_api_request_logging();
	}
}
