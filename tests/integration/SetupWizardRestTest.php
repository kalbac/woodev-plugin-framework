<?php
/**
 * Integration: Setup Wizard REST routes.
 *
 * Proves the neutral woodev/v1 setup routes register in a real WordPress REST
 * server (the test plugin opts into a minimal wizard via
 * build_setup_wizard_handler), and that the controller's capability gate behaves
 * in a real dispatch: an editor (logged in, lacks manage_options) is forbidden,
 * an administrator passes and finalizes the wizard.
 *
 * Modelled on LicenseRestAuthTest (rest_get_server / WP_REST_Request / dispatch).
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

use WP_REST_Request;

/**
 * Class SetupWizardRestTest
 */
class SetupWizardRestTest extends TestCase {

	/**
	 * The complete route for the test plugin's wizard.
	 *
	 * @var string
	 */
	private const COMPLETE_ROUTE = '/woodev/v1/woodev-test-plugin/setup/complete';

	/**
	 * Boots the REST server (fires rest_api_init once → registers the woodev/v1
	 * controllers stored in the registrar, including the test plugin's wizard).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		rest_get_server();
	}

	/**
	 * The setup routes are registered under woodev/v1.
	 *
	 * @return void
	 */
	public function test_setup_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey(
			'/woodev/v1/woodev-test-plugin/setup/complete',
			$routes,
			'The wizard complete route must be registered.'
		);
		$this->assertArrayHasKey(
			'/woodev/v1/woodev-test-plugin/setup/steps/(?P<step_id>[\w-]+)',
			$routes,
			'The wizard step-save route must be registered.'
		);
	}

	/**
	 * An editor (logged in, lacks manage_options) is forbidden by the capability
	 * gate. Editor is used rather than subscriber per the wp-admin reachability
	 * gotcha (wc-blocks-subscriber-wp-admin-403-test).
	 *
	 * @return void
	 */
	public function test_complete_is_forbidden_for_editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request  = new WP_REST_Request( 'POST', self::COMPLETE_ROUTE );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame(
			403,
			$response->get_status(),
			'An editor lacks manage_options and must be forbidden by the wizard capability gate.'
		);
	}

	/**
	 * An administrator passes the capability gate and finalizes the wizard.
	 *
	 * @return void
	 */
	public function test_complete_succeeds_for_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', self::COMPLETE_ROUTE );
		$request->set_param( 'state', 'completed' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( is_array( $data ) ? ( $data['complete'] ?? false ) : false );
		$this->assertSame( 'completed', is_array( $data ) ? ( $data['state'] ?? '' ) : '' );
	}
}
