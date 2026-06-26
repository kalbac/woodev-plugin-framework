<?php
/**
 * Integration: settings page REST routes (woodev/v1/settings).
 *
 * Proves the aggregated controller registers under woodev/v1 and that a real
 * dispatch honours the capability gate: an editor (lacks manage_options) is
 * forbidden, an administrator gets the «Карьер» tab schema back.
 *
 * Modelled on SetupWizardRestTest (rest_get_server / WP_REST_Request / dispatch).
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

use Woodev\Framework\Settings\Settings_Page_Registry;
use WP_REST_Request;

class SettingsPageRestTest extends TestCase {

	/**
	 * Registers the test plugin's provider and rebuilds the REST server so the
	 * settings controller (re-hooked on rest_api_init) registers its routes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( woodev_test_plugin() );

		// Force a fresh REST server so rest_api_init fires with the re-added hook.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function test_settings_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey( '/woodev/v1/settings', $routes );
		$this->assertArrayHasKey( '/woodev/v1/settings/(?P<provider_id>[\w-]+)', $routes );
	}

	public function test_get_schema_forbidden_for_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/settings' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_get_schema_returns_quarry_tab_for_admin(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/settings' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = array_column( is_array( $data ) ? ( $data['tabs'] ?? [] ) : [], 'id' );
		$this->assertContains( 'quarry', $ids );
	}
}
