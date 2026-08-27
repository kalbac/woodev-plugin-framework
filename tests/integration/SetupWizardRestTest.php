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
	 * The step-save route for the fixture wizard's settings step.
	 *
	 * @var string
	 */
	private const CONTACTS_STEP_ROUTE = '/woodev/v1/woodev-test-plugin/setup/steps/contacts';

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

	/**
	 * Issue #397, end to end through the REAL stack: a step rejected by the server
	 * reports WHICH field failed, under the key the client reads.
	 *
	 * The unit tests for this pin the controller against a mocked wizard and a
	 * mocked settings handler — they prove the controller's own shape and nothing
	 * about the collaborators. This dispatches the real route into the real
	 * fixture wizard and the real Woodev_Abstract_Settings, so the setting id in
	 * the map is one that genuinely exists and the message is the one the
	 * validation layer actually produces.
	 *
	 * `support_phone` is the field that can reach here at all: its rule is a
	 * plugin-supplied PHP callback with no JS equivalent, so the client (which
	 * mirrors every shared rule and blocks the advance itself) forwards the value
	 * and leaves the server as the authority.
	 *
	 * @return void
	 */
	public function test_save_step_reports_the_failing_field_under_the_errors_key(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', self::CONTACTS_STEP_ROUTE );
		$request->set_param( 'step_id', 'contacts' );
		$request->set_param(
			'values',
			array(
				'manager_email' => 'manager@example.com',
				'support_phone' => '12345',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), 'A phone that fails the server rule must be refused.' );

		$data = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( 'woodev_setup_invalid', $data['code'] ?? '' );

		$error_data = $data['data'] ?? array();

		$this->assertArrayHasKey(
			'errors',
			$error_data,
			'The client reads err.data.errors — without that key no field can ever be marked.'
		);
		$this->assertArrayNotHasKey(
			'field',
			$error_data,
			'The superseded bare-id key must be gone, not carried alongside.'
		);
		$this->assertSame(
			array( 'support_phone' => 'Введите номер из 11 цифр (например, 79991234567).' ),
			$error_data['errors'],
			'The map must name the failing setting and carry the real validation message.'
		);
	}

	/**
	 * Control for the test above: the same step with a phone that satisfies the
	 * server rule saves and persists, so the refusal above is attributable to the
	 * value and not to the step being unsaveable in this environment.
	 *
	 * @return void
	 */
	public function test_save_step_persists_a_valid_settings_step(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', self::CONTACTS_STEP_ROUTE );
		$request->set_param( 'step_id', 'contacts' );
		$request->set_param(
			'values',
			array(
				'manager_email' => 'manager@example.com',
				'support_phone' => '79991234567',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( is_array( $data ) ? ( $data['saved'] ?? false ) : false );

		$handler = \Woodev_Test_Plugin::instance()->get_settings_handler();
		$this->assertSame( '79991234567', $handler->get_value( 'support_phone' ) );
	}
}
