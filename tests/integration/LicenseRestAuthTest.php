<?php
/**
 * Integration: License REST Auth Test
 *
 * Spec §7 ('bad nonce -> rest_forbidden/401-403'): proves the framework's
 * woodev/v1 license routes are protected by BOTH layers that guard them in a
 * real WordPress request:
 *
 *  1. Core REST cookie-nonce auth (rest_cookie_check_errors, hooked on
 *     rest_authentication_errors). For a cookie-authenticated request, a present-
 *     but-invalid nonce is rejected with rest_cookie_invalid_nonce (status 403)
 *     BEFORE any route permission_callback runs; a missing nonce demotes the
 *     session to anonymous (wp_set_current_user(0)) and returns true. With a
 *     valid nonce the gate passes (returns true).
 *
 *     Core only treats the request as cookie-authenticated when the global
 *     $wp_rest_auth_cookie === true (set by wp_validate_auth_cookie() in a real
 *     request); wp_set_current_user() does NOT set it. The tests therefore set
 *     that global explicitly to simulate the cookie-auth path, and read/write the
 *     nonce via the superglobals the function actually inspects
 *     ($_REQUEST['_wpnonce'] or $_SERVER['HTTP_X_WP_NONCE']) — NOT the
 *     WP_REST_Request object.
 *
 *  2. The controller's own manage_options capability gate (check_permissions).
 *     A logged-in SUBSCRIBER with a VALID nonce still gets woodev_license_forbidden
 *     (403); an administrator passes it and reaches our handler — which then 404s
 *     (woodev_license_unknown_plugin) for an unregistered plugin id. Reaching THAT
 *     error proves the request got PAST auth into our handler without making any
 *     store-API call.
 *
 * Modelled on the existing integration tests (TestCase / WP_UnitTestCase): factory
 * user creation + wp_set_current_user, rest_get_server(), WP_REST_Request, and
 * $server->dispatch(). The cookie-nonce gate is exercised through the exact core
 * function WordPress hooks onto rest_authentication_errors (rest_cookie_check_errors),
 * which reads the X-WP-Nonce header off the current request — the same code path a
 * real browser request takes.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

use WP_REST_Request;

/**
 * Class LicenseRestAuthTest
 */
class LicenseRestAuthTest extends TestCase {

	/**
	 * A plugin id that is intentionally NOT in the license registry, so a request
	 * that gets past auth lands on the controller's 404 (woodev_license_unknown_plugin)
	 * instead of dispatching to the store. This is how we assert "past auth" cheaply.
	 *
	 * @var string
	 */
	private const UNKNOWN_PLUGIN_ID = 'no-such-plugin-9999';

	/**
	 * The verify route path for the unknown plugin id.
	 *
	 * @var string
	 */
	private const VERIFY_ROUTE = '/woodev/v1/licenses/no-such-plugin-9999/verify';

	/**
	 * Ensures the REST server is booted (which fires rest_api_init and therefore
	 * registers the woodev/v1 routes via the registrar) before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Booting the server fires rest_api_init exactly once, registering every
		// controller stored in Woodev_REST_V1_Registrar (incl. the license controller,
		// which boots when the test plugin's license subsystem adds its hooks).
		rest_get_server();
	}

	/**
	 * Resets the auth/request globals the nonce gate reads, so no cookie-auth or
	 * nonce state leaks into the next test. rest_cookie_check_errors() keys its
	 * cookie-auth path off the global $wp_rest_auth_cookie and reads the nonce
	 * from $_SERVER['HTTP_X_WP_NONCE'] / $_REQUEST['_wpnonce'].
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		$GLOBALS['wp_rest_auth_cookie'] = null;

		parent::tearDown();
	}

	/**
	 * Sanity: the verify route is actually registered under woodev/v1. If this
	 * fails, the later auth assertions would be meaningless (a 404 'no route').
	 *
	 * @return void
	 */
	public function test_verify_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey(
			'/woodev/v1/licenses/(?P<plugin_id>[\w-]+)/verify',
			$routes,
			'The woodev/v1 license verify route must be registered.'
		);
	}

	/**
	 * A cookie-authenticated request WITHOUT a nonce is treated by the core gate as
	 * unauthenticated: rest_cookie_check_errors() resets the current user to 0
	 * (anonymous) and returns true. The route is therefore reached as an anonymous
	 * visitor, so the controller's manage_options gate rejects it
	 * (woodev_license_forbidden, 403).
	 *
	 * NOTE: missing-nonce does NOT itself emit rest_cookie_invalid_nonce — core only
	 * errors when a nonce IS present but invalid (see the invalid-nonce test). The
	 * protection for the no-nonce case is the demotion to an anonymous user, which
	 * our capability gate then forbids. The function reads the nonce from the
	 * superglobals ($_REQUEST['_wpnonce'] / $_SERVER['HTTP_X_WP_NONCE']), and only
	 * runs its checks at all when the global $wp_rest_auth_cookie === true.
	 *
	 * @return void
	 */
	public function test_request_without_nonce_is_demoted_to_anonymous_and_forbidden(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate a cookie-authenticated request (the only path the gate inspects).
		$GLOBALS['wp_rest_auth_cookie'] = true;

		// No X-WP-Nonce header and no _wpnonce param at all.
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		// The core gate demotes the (cookie) session to anonymous: it returns true
		// (no auth error) but resets the current user to 0.
		$gate = rest_cookie_check_errors( null );
		$this->assertTrue(
			$gate,
			'A missing nonce is not itself an auth error — core demotes to anonymous and returns true.'
		);
		$this->assertSame( 0, get_current_user_id(), 'Missing nonce must reset the REST user to anonymous (0).' );

		// Reaching the route as anonymous, the capability gate forbids it.
		$request = new WP_REST_Request( 'POST', self::VERIFY_ROUTE );
		$request->set_param( 'license_key', 'KEY-123' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame(
			403,
			$response->get_status(),
			'An anonymous (no-nonce) request must be forbidden by the capability gate.'
		);

		$data = $response->get_data();
		$this->assertSame(
			'woodev_license_forbidden',
			is_array( $data ) ? ( $data['code'] ?? '' ) : '',
			'The capability gate must forbid an anonymous request.'
		);
	}

	/**
	 * A logged-in administrator with an INVALID nonce is likewise rejected by the
	 * core cookie-nonce gate (rest_cookie_invalid_nonce).
	 *
	 * @return void
	 */
	public function test_admin_with_invalid_nonce_is_rejected_by_cookie_nonce_gate(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate a cookie-authenticated request and supply an invalid nonce via the
		// superglobal the gate reads.
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = 'this-is-not-a-valid-nonce';

		$error = rest_cookie_check_errors( null );

		$this->assertWPError(
			$error,
			'A cookie-authenticated user with a bad nonce must fail rest_cookie_check_errors.'
		);
		$this->assertSame( 'rest_cookie_invalid_nonce', $error->get_error_code() );
		$this->assertContainsAuthStatus( $error );
	}

	/**
	 * A logged-in administrator WITH a valid wp_rest nonce passes the cookie-nonce
	 * gate (rest_cookie_check_errors returns true), AND passes the
	 * controller's manage_options gate — reaching our handler, which then 404s
	 * (woodev_license_unknown_plugin) for the unregistered plugin id. Reaching that
	 * specific error proves the request got PAST auth without any store-API call.
	 *
	 * @return void
	 */
	public function test_admin_with_valid_nonce_passes_auth_and_reaches_handler(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate a cookie-authenticated request with a valid nonce in the superglobal.
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = wp_create_nonce( 'wp_rest' );

		// Cookie-nonce gate: a valid nonce yields no error (returns true).
		$this->assertTrue(
			rest_cookie_check_errors( null ),
			'A valid wp_rest nonce must pass the cookie-nonce gate.'
		);

		// Capability gate + handler: dispatch the actual request through the server.
		$request = new WP_REST_Request( 'POST', self::VERIFY_ROUTE );
		$request->add_header( 'X-WP-Nonce', $_SERVER['HTTP_X_WP_NONCE'] );
		$request->set_param( 'license_key', 'KEY-123' );

		$response = rest_get_server()->dispatch( $request );

		// 404 woodev_license_unknown_plugin == passed manage_options, reached our
		// handler, and resolve_license() failed on the unknown id. NOT a 401/403.
		$this->assertSame(
			404,
			$response->get_status(),
			'An admin with a valid nonce must get PAST auth to the handler 404 (unknown plugin), not an auth error.'
		);

		$data = $response->get_data();
		$this->assertSame(
			'woodev_license_unknown_plugin',
			is_array( $data ) ? ( $data['code'] ?? '' ) : '',
			'The handler error proves the request reached our controller (past auth).'
		);
	}

	/**
	 * The subordinate permission layer: a logged-in SUBSCRIBER with a VALID nonce
	 * passes the cookie-nonce gate but is rejected by the controller's
	 * manage_options gate (woodev_license_forbidden, 403).
	 *
	 * @return void
	 */
	public function test_subscriber_with_valid_nonce_is_forbidden_by_capability_gate(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// Simulate a cookie-authenticated request with a valid nonce in the superglobal.
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = wp_create_nonce( 'wp_rest' );

		// The nonce gate passes for a logged-in subscriber with a valid nonce (returns true).
		$this->assertTrue(
			rest_cookie_check_errors( null ),
			'A subscriber with a valid wp_rest nonce must pass the cookie-nonce gate.'
		);

		$request = new WP_REST_Request( 'POST', self::VERIFY_ROUTE );
		$request->add_header( 'X-WP-Nonce', $_SERVER['HTTP_X_WP_NONCE'] );
		$request->set_param( 'license_key', 'KEY-123' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame(
			403,
			$response->get_status(),
			'A subscriber must be rejected by the manage_options capability gate (403).'
		);

		$data = $response->get_data();
		$this->assertSame(
			'woodev_license_forbidden',
			is_array( $data ) ? ( $data['code'] ?? '' ) : '',
			'The capability gate must surface woodev_license_forbidden for a subscriber.'
		);
	}

	/**
	 * Asserts a WP_Error carries an HTTP auth status (401 or 403). Core may emit
	 * either depending on whether a user is determined, so accept both.
	 *
	 * @param \WP_Error $error The error to inspect.
	 *
	 * @return void
	 */
	private function assertContainsAuthStatus( \WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? ( $data['status'] ?? null ) : null;

		$this->assertContains(
			$status,
			array( 401, 403 ),
			'A cookie-nonce rejection must carry a 401 or 403 status.'
		);
	}
}
