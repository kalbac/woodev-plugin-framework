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

	/**
	 * The DaData provider's own store-level token option — the ONLY thing
	 * {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::is_configured()}
	 * (and therefore {@see \Woodev\Framework\Shipping\Location\Location_Service::is_active()})
	 * checks once the registry gate is open (this file always opens it via
	 * {@see self::activate_and_boot_rest()}).
	 *
	 * @var string
	 */
	private const OPTION_DADATA_TOKEN = 'woodev_location_token';

	/**
	 * The literal session key
	 * {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store} persists a
	 * guest's current location record under — mirrors that class' own PRIVATE
	 * `STORAGE_KEY` constant. Session key names are an installed-site DATA
	 * contract this repo's rules treat as release-blocking-stable, so hardcoding
	 * the literal here carries the same reasoning as
	 * {@see self::OPTION_DADATA_TOKEN} above, which already does the same for a
	 * different store's option name.
	 *
	 * @var string
	 */
	private const CUSTOMER_LOCATION_SESSION_KEY = 'woodev_customer_location';

	/**
	 * The option's value as found at the start of THIS test, captured in
	 * {@see self::setUp()} and restored in {@see self::tearDown()}. `false`
	 * means the option did not exist at all.
	 *
	 * @var string|false
	 */
	private $original_dadata_token;

	protected function setUp(): void {
		parent::setUp();

		// A fresh gate for every test — the registry is a fleet-wide singleton
		// and a prior test (or another declaring plugin loaded in wp-env) may
		// already have opened it; reset so this test controls the gate itself.
		Location_Provider_Registry::instance()->reset_for_tests();

		// The rig environment may carry `woodev_location_token` already seeded
		// by `Woodev_Test_Credential_Seeder` (tests/_fixtures/woodev-test-shipping-method/
		// class-test-credential-seeder.php) from the rig's own WOODEV_TEST_DADATA_TOKEN
		// wp-config constant — that seeding happens once, at fixture-plugin bootstrap,
		// long before this test runs, and is idempotent (never re-seeds over a
		// non-empty option), so it is never "reset" between tests by the environment
		// itself. A test asserting INACTIVE-layer behaviour that merely relies on the
		// CI container happening to have no token defined is fragile in ANY
		// environment, CI included — the option is DB state, not something an env
		// constant check can stand in for. Every test in this file therefore starts
		// from a known, explicitly-neutralised baseline; a test that needs the ACTIVE
		// path opts back in via self::make_location_layer_active().
		$this->original_dadata_token = get_option( self::OPTION_DADATA_TOKEN, false );
		delete_option( self::OPTION_DADATA_TOKEN );
	}

	protected function tearDown(): void {
		if ( false === $this->original_dadata_token ) {
			delete_option( self::OPTION_DADATA_TOKEN );
		} else {
			update_option( self::OPTION_DADATA_TOKEN, $this->original_dadata_token );
		}

		Location_Provider_Registry::instance()->reset_for_tests();
		$this->forget_persisted_guest_location();

		parent::tearDown();
	}

	/**
	 * Clears any guest location record
	 * {@see self::test_select_with_a_valid_nonce_and_active_layer_persists_and_returns_200()}
	 * persisted into `WC()->session` — that object is a process-wide singleton
	 * PHPUnit never tears down between test CLASSES (only the DB rolls back per
	 * test), so a record this file explicitly writes for a GUEST otherwise
	 * survives into every later integration test that runs in the SAME PHP
	 * process. Confirmed root cause of a CI-only PickupRouteTest failure
	 * (issue #159 follow-up): the stale record — posted here with no settlement
	 * name at all — leaked into `PickupRouteTest`, whose bulk fixture's
	 * record-vs-legacy locality matching (Task 15) correctly returned zero
	 * points for a nameless record, failing two assertions with NO change to
	 * that file, purely because this file happened to run first (its default,
	 * declaration-order position) and never cleaned up its own write.
	 *
	 * `WC()->session->set( $key, null )` is enough — {@see \WC_Session::get()}
	 * uses `isset()` against its internal store, which is `false` for a `null`
	 * entry, so the very next read behaves exactly like "no record was ever
	 * written", the same shape {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::get()}
	 * already treats as absent.
	 *
	 * @return void
	 */
	private function forget_persisted_guest_location(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->set( self::CUSTOMER_LOCATION_SESSION_KEY, null );
	}

	/**
	 * Opts a test back into the ACTIVE path: seeds a fake (never network-reaching)
	 * token into the same option `Dadata_Provider::is_configured()` reads, making
	 * `Location_Service::is_active()` true for the rest of this test. Safe on any
	 * environment — nothing this file exercises with the layer active ever calls
	 * the provider's own HTTP client, see
	 * {@see self::test_select_with_a_valid_nonce_and_active_layer_persists_and_returns_200()}'s
	 * own docblock for why `/select` in particular never does.
	 *
	 * @param string $token the fake token to seed.
	 *
	 * @return void
	 */
	private function make_location_layer_active( string $token = 'test-integration-token' ): void {
		update_option( self::OPTION_DADATA_TOKEN, $token );
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
	// 2. Guest access to /suggest (public read) — layer inactive (own
	//    precondition: setUp() neutralises OPTION_DADATA_TOKEN regardless of
	//    what the environment seeded) degrades to 200 + empty, never a fatal.
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
	// 3. /select without a valid nonce is refused; the layer being inactive
	//    (own precondition, see setUp()) means a well-nonced request still
	//    404s rather than 500s. The next section (4) proves the OTHER half —
	//    a well-nonced request against an ACTIVE layer persists and 200s.
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

		// setUp() neutralised OPTION_DADATA_TOKEN, so Location_Service::is_active()
		// is false regardless of what the environment seeded — the write correctly
		// 404s rather than persisting against an inactive layer or fataling.
		$this->assertSame( 404, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// 4. /select with a valid nonce AND an active layer actually persists —
	//    the ACTIVE-path counterpart to section 3, own precondition via
	//    make_location_layer_active(), never dependent on what the rig/CI
	//    environment happens to seed.
	// -------------------------------------------------------------------------

	/**
	 * `handle_select_request()` never calls the provider's own HTTP client —
	 * {@see \Woodev\Framework\Shipping\Location\Location_Service::set_customer_record()}
	 * is a thin pass-through to
	 * {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::set()}, a
	 * local WC session/customer-meta write — so seeding a FAKE token via
	 * make_location_layer_active() is enough to prove the active path end to
	 * end without ever reaching a third-party API (unlike `/suggest`, which
	 * calls `$provider->suggest()` and is therefore intentionally NOT exercised
	 * here in the active state).
	 *
	 * @return void
	 */
	public function test_select_with_a_valid_nonce_and_active_layer_persists_and_returns_200(): void {
		$this->activate_and_boot_rest();
		$this->make_location_layer_active();

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

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'key'   => 'dadata:fias-1',
				'level' => 'settlement',
			],
			$response->get_data()['current']
		);
	}

	// -------------------------------------------------------------------------
	// 5. Regression guard — a persisted guest record must not leak into the
	//    next test (root cause of a CI-only PickupRouteTest failure).
	// -------------------------------------------------------------------------

	/**
	 * Root-cause regression guard: `WC()->session` is a process-wide singleton
	 * PHPUnit never resets between test CLASSES (only the DB rolls back per
	 * test), so the record
	 * {@see self::test_select_with_a_valid_nonce_and_active_layer_persists_and_returns_200()}
	 * persists for a GUEST customer survives into whichever integration test
	 * runs next in the same PHP process unless something clears it. That is
	 * exactly what broke `PickupRouteTest::test_a_guest_can_read_points()` and
	 * `PickupRouteTest::test_each_returned_point_carries_a_selectable_verdict()`
	 * on CI: no `.phpunit.result.cache` there to coincidentally reorder
	 * `PickupRouteTest` ahead of this file, so the leaked, nameless record made
	 * `Woodev_Test_Bulk_Point_Source`'s record-vs-legacy locality matching
	 * (Task 15) correctly — but unexpectedly, from that file's own point of
	 * view — return zero points. Every local run happened to carry a STALE
	 * `.phpunit.result.cache` (gitignored, absent on a fresh CI checkout) that
	 * reordered the previously-failing `PickupRouteTest` first, masking the
	 * pollution — every "integration green" claim made against this branch
	 * before this fix was therefore false.
	 *
	 * Invokes {@see self::forget_persisted_guest_location()} directly via
	 * reflection — the same test-seam idiom `PickupRouteTest` already uses for
	 * a private property this class has no reason to expose publicly — so this
	 * test proves the CLEANUP HELPER itself works, independent of which other
	 * test class PHPUnit happens to run next.
	 *
	 * @return void
	 */
	public function test_persisted_guest_location_record_is_cleared_by_the_teardown_helper(): void {
		$this->activate_and_boot_rest();
		$this->make_location_layer_active();

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

		$this->assertSame( 200, $response->get_status(), 'setup precondition: the write must actually persist.' );
		$this->assertNotNull(
			WC()->session->get( self::CUSTOMER_LOCATION_SESSION_KEY ),
			'setup precondition: the session must actually hold the record before it is cleared.'
		);

		$method = new \ReflectionMethod( self::class, 'forget_persisted_guest_location' );
		$method->setAccessible( true );
		$method->invoke( $this );

		$this->assertNull(
			WC()->session->get( self::CUSTOMER_LOCATION_SESSION_KEY ),
			'the session must no longer hold a location record once the teardown helper runs.'
		);
	}
}
