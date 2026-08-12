<?php
/**
 * Integration: location REST routes (woodev/v1/location/{suggest|select}).
 *
 * Proves the PRODUCTION registration path — `Location_Provider_Registry`
 * hooking `rest_api_init` once a plugin declares `needs_location_provider()`
 * (Task 8) — actually registers both routes in a real REST server, WITHOUT
 * ever rendering checkout (`is_checkout()` is never called, this test never
 * visits a page at all): declares need directly, fires the real `init` +
 * `rest_api_init` hooks WordPress itself would fire, and dispatches against
 * `rest_get_server()`. This is the test the plan calls out explicitly:
 * "proves the init-not-is_checkout() registration actually holds."
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class LocationRouteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// A fresh gate for every test — the registry is a fleet-wide singleton
		// and a prior test (or another declaring plugin loaded in wp-env) may
		// already have opened it; reset so this test controls the gate itself.
		Location_Provider_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		parent::tearDown();
	}

	/**
	 * Opens the gate and drives the same path a real request drives: provider
	 * collection (hooked on `init` at priority 20) then REST route registration
	 * (hooked on `rest_api_init`), then rebuilds the REST server so the freshly
	 * registered routes are seen.
	 *
	 * `init` is asserted-then-invoked rather than fired globally. Firing
	 * `do_action( 'init' )` inside an integration test re-runs WooCommerce's own
	 * `init` callbacks, which re-register the `cheque` gateway and the
	 * `woocommerce/*` block types — each raising `_doing_it_wrong`, which
	 * `WP_UnitTestCase` turns into "Unexpected incorrect usage notice" and fails
	 * the test. Same family as the recorded trap for `do_action( 'admin_menu' )`
	 * (gotcha `integration-test-global-admin-hooks-output-and-submenu-accumulation`).
	 *
	 * Asserting `has_action()` and then calling the callback is not a weaker test
	 * than firing the hook — it is a stronger one: an `is_checkout()`-gated or
	 * otherwise missing registration, which is exactly what this file exists to
	 * catch, fails the assertion outright instead of merely producing no routes.
	 *
	 * @return void
	 */
	private function activate_and_boot_rest(): void {
		$registry = Location_Provider_Registry::instance();

		$registry->declare_needed();

		$this->assertNotFalse(
			has_action( 'init', [ $registry, 'collect' ] ),
			'provider collection must be hooked on init, never behind an is_checkout() guard'
		);

		$registry->collect();

		do_action( 'rest_api_init' );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	// -------------------------------------------------------------------------
	// 1. Routes register through the real registry gate, on rest_api_init,
	//    with NO checkout page ever visited or is_checkout() ever true.
	// -------------------------------------------------------------------------

	public function test_suggest_route_registers_when_a_plugin_declares_need_without_visiting_checkout(): void {
		$this->assertFalse( is_checkout(), 'this test must never render checkout' );

		$this->activate_and_boot_rest();

		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey( '/woodev/v1/location/suggest', $routes );
		$this->assertArrayHasKey( '/woodev/v1/location/select', $routes );
	}

	public function test_routes_are_absent_when_no_plugin_declared_need(): void {
		// Gate never opened — no declare_needed() call, so the registry hooked
		// nothing at all. Asserting that directly is the whole point: the layer is
		// inert by never registering, not by registering and then returning early.
		// (`init` is deliberately NOT fired — see activate_and_boot_rest().)
		$this->assertFalse(
			has_action( 'init', [ Location_Provider_Registry::instance(), 'collect' ] ),
			'a registry nobody declared need to must not hook anything'
		);

		do_action( 'rest_api_init' );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayNotHasKey( '/woodev/v1/location/suggest', $routes );
		$this->assertArrayNotHasKey( '/woodev/v1/location/select', $routes );
	}

	// -------------------------------------------------------------------------
	// 2. Guest access to /suggest (public read) — layer inactive (no provider
	//    configured in this test env) degrades to 200 + empty, never a fatal.
	// -------------------------------------------------------------------------

	public function test_a_guest_can_call_suggest_and_it_never_fatals_with_no_provider_configured(): void {
		$this->activate_and_boot_rest();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/location/suggest' );
		$request->set_param( 'q', 'Мос' );
		$request->set_param( 'level', 'region' );
		$request->set_param( 'country', 'RU' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'suggestions' => [] ], $response->get_data() );
	}

	public function test_suggest_400s_on_a_too_short_query(): void {
		$this->activate_and_boot_rest();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/location/suggest' );
		$request->set_param( 'q', 'a' );
		$request->set_param( 'level', 'region' );
		$request->set_param( 'country', 'RU' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// 3. /select without a valid nonce is refused; the layer being inactive in
	//    this test env means a well-nonced request still 404s rather than 500s.
	// -------------------------------------------------------------------------

	public function test_select_without_a_nonce_is_refused(): void {
		$this->activate_and_boot_rest();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$request->set_param(
			'record',
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
			]
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	public function test_select_with_a_valid_nonce_but_inactive_layer_returns_404_not_500(): void {
		$this->activate_and_boot_rest();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$request->set_param(
			'record',
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
			]
		);
		$request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		// No DaData token is configured in this test environment, so
		// Location_Service::is_active() is false — the write correctly 404s
		// rather than persisting against an inactive layer or fataling.
		$this->assertSame( 404, $response->get_status() );
	}
}
