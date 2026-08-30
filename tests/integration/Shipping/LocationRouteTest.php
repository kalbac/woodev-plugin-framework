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

use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Service;
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

	/**
	 * `woocommerce_default_country` as found at the start of THIS test — same
	 * save/restore discipline as {@see self::$original_dadata_token}. Restored
	 * mainly for hygiene; see {@see self::setUp()}'s own comment for why the
	 * ACTUAL fix for #346/#333 in this file is the direct
	 * `WC()->customer->set_shipping_country()` seed below, not this option.
	 *
	 * @var string|false
	 */
	private $original_default_country;

	protected function setUp(): void {
		parent::setUp();

		// A fresh gate for every test — the registry is a fleet-wide singleton
		// and a prior test (or another declaring plugin loaded in wp-env) may
		// already have opened it; reset so this test controls the gate itself.
		Location_Provider_Registry::instance()->reset_for_tests();

		$this->original_default_country = get_option( 'woocommerce_default_country', false );
		update_option( 'woocommerce_default_country', 'RU' );

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

		if ( false === $this->original_default_country ) {
			delete_option( 'woocommerce_default_country' );
		} else {
			update_option( 'woocommerce_default_country', $this->original_default_country );
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
	 * Seeds `WC()->customer`'s LIVE shipping-country field (#346/#333) — the
	 * seam {@see \Woodev\Framework\Shipping\Location\Location_Service::customer_shipping_country()}
	 * reads FIRST, before falling through to
	 * {@see \Woodev\Framework\Shipping\Location\Location_Service::resolve_default_country()}'s
	 * store-setting floor. On a brand-new `WP_UnitTestCase` guest,
	 * `WC()->customer->get_shipping_country()` answers from
	 * `wc_get_customer_default_location()` (`woocommerce_default_customer_address`
	 * defaults to `geolocation`), which resolves to a hardcoded `US` fallback
	 * for the container's own non-routable IP — measured (s79), and NOT
	 * something `update_option( 'woocommerce_default_country', ... )` alone
	 * reaches, since the geolocation branch never consults that option at
	 * all. Every fixture record in this file is `country: RU`; called AFTER
	 * `wp_set_current_user( 0 )` in each test that needs it, since switching
	 * the current user can reload `WC()->customer`. Mirrors what a real
	 * checkout's own `update_checkout` AJAX would already have done by the
	 * time a customer reaches the location picker.
	 *
	 * @param string $country ISO-3166 alpha-2 country code.
	 *
	 * @return void
	 */
	private function seed_customer_shipping_country( string $country = 'RU' ): void {
		WC()->customer->set_shipping_country( $country );
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
		$this->assertSame(
			[
				'suggestions'     => [],
				'within_applied'  => false,
				'within_status'   => 'not_requested',
				'scope_narrowing' => Location_Provider::NARROWING_NOT_APPLICABLE,
			],
			$response->get_data()
		);
	}

	/**
	 * Issue #330's third point: `within_applied` rides along even on the
	 * degenerate "layer inactive" 200+empty branch — it must never be a key
	 * a client only sees once the layer becomes active. This is unit-covered
	 * exhaustively (`LocationControllerTest`) against a fake service; this one
	 * assertion instead proves the key survives the REAL REST dispatch path
	 * (route registration, param handling, `rest_ensure_response()`) with the
	 * PRODUCTION `Location_Service`+`Location_Controller` wiring — gotcha
	 * `the-integration-suite-has-a-wc-session-a-rest-request-does-not` does not
	 * apply here: this assertion is not session-shaped, `within` is never even
	 * sent, so there is nothing a stray always-present session could mask.
	 */
	public function test_a_guest_suggest_response_carries_within_applied_false_when_the_layer_is_inactive(): void {
		$this->activate_and_boot_rest();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/location/suggest' );
		$request->set_param( 'q', 'Мос' );
		$request->set_param( 'level', 'region' );
		$request->set_param( 'country', 'RU' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'within_applied', $response->get_data() );
		$this->assertFalse( $response->get_data()['within_applied'] );
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
		$this->seed_customer_shipping_country();

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
		// Issue #330: the response's `chain` must already carry this single write —
		// the multi-level accumulation is proven end-to-end by
		// self::test_select_response_chain_accumulates_across_the_cascade() below.
		$this->assertSame(
			[
				'key'   => 'dadata:fias-1',
				'level' => 'settlement',
			],
			$response->get_data()['chain']['settlement']
		);
	}

	/**
	 * Issue #330 (location-chain design §8), end to end through the REAL
	 * `Location_Service` -> `Customer_Location_Store` -> WC session, exactly
	 * the sequence `location-cascade.js`'s own cascade drives: a settlement
	 * pick persists, THEN an address pick persists — the SECOND `/select`
	 * response's `chain` must still carry the FIRST write's settlement
	 * alongside the new address, proving the store's chain-rebuild (design §3)
	 * and this controller's read-after-write (`handle_select_request()`) are
	 * wired together correctly, not merely each unit-tested in isolation
	 * against a fake.
	 */
	public function test_select_response_chain_accumulates_across_the_cascade(): void {
		$this->activate_and_boot_rest();
		$this->make_location_layer_active();

		wp_set_current_user( 0 );
		$this->seed_customer_shipping_country();

		$settlement_request = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$settlement_request->set_param(
			'record',
			[
				'key'         => 'dadata:settlement-1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
			]
		);
		$settlement_request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$settlement_response = rest_get_server()->dispatch( $settlement_request );
		$this->assertSame( 200, $settlement_response->get_status() );

		$address_request = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$address_request->set_param(
			'record',
			[
				'key'         => 'dadata:address-1',
				'provider_id' => 'dadata',
				'level'       => 'address',
				'country'     => 'RU',
				// The address must PROVE the settlement is still its ancestor — an
				// unprovable one is dropped by the store's chain rebuild (design §3,
				// tightened after the adversarial review found a Moscow settlement
				// surviving a Saint-Petersburg address). This is the shape the bundled
				// DaData provider publishes for every row.
				'ancestors'   => [ 'dadata:settlement-1' ],
			]
		);
		$address_request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$address_response = rest_get_server()->dispatch( $address_request );

		$this->assertSame( 200, $address_response->get_status() );

		$chain = $address_response->get_data()['chain'];

		$this->assertSame( [ 'key' => 'dadata:settlement-1', 'level' => 'settlement' ], $chain['settlement'] );
		$this->assertSame( [ 'key' => 'dadata:address-1', 'level' => 'address' ], $chain['address'] );
	}

	/**
	 * Issue #459 diagnosis: does a settlement pick's chain SURVIVE a fresh
	 * {@see Location_Service} instance reading the SAME persisted session — i.e.
	 * exactly what a subsequent page load's `Checkout_Config::build_location_block()`
	 * does (a brand-new PHP request, new object graph, same `WC()->session` cookie)?
	 * `location-cascade.js`'s own `prefill()`/`adoptChain()` (see that file's boot
	 * section) restore `entry.records` — and, through {@see refreshAddressLock},
	 * unlock the address field — ENTIRELY from `location.chain`/`location.current`
	 * in that rendered config block; nothing else feeds them. So if the persisted
	 * chain does not survive to a fresh `Location_Service`, the client-side restore
	 * machinery (verified correct by inspection) has nothing to restore FROM, and
	 * the address stays locked after a reload exactly as reported.
	 *
	 * Deliberately constructs `new Location_Service()` rather than reusing the
	 * REST controller's own service instance — the whole point is to rule out
	 * request-local memoization (`self::$unpersisted_default`) standing in for a
	 * genuine read-after-write from persisted storage.
	 *
	 * @return void
	 */
	public function test_a_fresh_service_instance_still_sees_a_settlement_persisted_by_an_earlier_select_call(): void {
		$this->activate_and_boot_rest();
		$this->make_location_layer_active();

		wp_set_current_user( 0 );
		$this->seed_customer_shipping_country();

		$settlement_request = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$settlement_request->set_param(
			'record',
			[
				'key'         => 'dadata:settlement-1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
			]
		);
		$settlement_request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$settlement_response = rest_get_server()->dispatch( $settlement_request );
		$this->assertSame( 200, $settlement_response->get_status() );

		// A FRESH service — models a brand-new request reading back what the
		// call above persisted, the same read `Checkout_Config::build()` does
		// on the customer's next page load.
		$fresh_service = new Location_Service();

		$current = $fresh_service->get_customer_record();
		$this->assertNotNull( $current, 'a fresh Location_Service must still see the persisted settlement record' );
		$this->assertSame( 'settlement', $current['record']->level() );
		$this->assertSame( 'dadata:settlement-1', $current['record']->key() );

		$chain = $fresh_service->get_customer_chain();
		$this->assertNotNull( $chain, 'a fresh Location_Service must still see the persisted chain' );
		$this->assertArrayHasKey( 'settlement', $chain['records'] );
		$this->assertSame( 'dadata:settlement-1', $chain['records']['settlement']->key() );
	}

	/**
	 * Issue #324. The ONE integration assertion that could have caught it, and the
	 * reason none did: this suite always has a `WC()->session`, guest included.
	 * WooCommerce loads session+cart from a single gate — `class-woocommerce.php:891`,
	 * `if ( $this->is_request( 'frontend' ) ) { wc_load_cart(); }` — and
	 * `is_request( 'frontend' )` excludes every REST request via
	 * `is_rest_api_request()`, which reads `$_SERVER['REQUEST_URI']`. Under PHPUnit
	 * that is not a REST URI, so WooCommerce boots as a frontend request and the
	 * session exists no matter what the route does. The test right above this one
	 * proves it, and asserting `persisted` alone proves nothing here: it is `true`
	 * either way.
	 *
	 * So the missing CONTEXT has to be reproduced, not the missing code. Dropping
	 * `WC()->session` immediately before the dispatch puts the route in exactly the
	 * state a real guest REST request starts from; the route must then raise its own
	 * session (as the Store API does with its own `wc_load_cart()` call) and the
	 * write must land.
	 *
	 * Reverting `handle_select_request()`'s `bridge_wc_session()` call reddens this
	 * test — gotcha `the-integration-suite-has-a-wc-session-a-rest-request-does-not`.
	 *
	 * @return void
	 */
	public function test_select_raises_its_own_session_when_the_request_has_none(): void {
		$this->activate_and_boot_rest();
		$this->make_location_layer_active();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$request->set_param(
			'record',
			[
				'key'         => 'dadata:fias-2',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
			]
		);
		$request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		// The state a real guest REST request begins in.
		WC()->session = null;

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( WC()->session, 'the route must raise a session of its own' );
		$this->assertNotNull(
			WC()->session->get( self::CUSTOMER_LOCATION_SESSION_KEY ),
			'and the record must actually land in it'
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
