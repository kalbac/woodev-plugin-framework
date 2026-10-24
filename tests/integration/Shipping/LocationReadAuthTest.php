<?php
/**
 * Integration: a location READ must keep a logged-in customer authenticated (#1426, critic round 1).
 *
 * `location-cascade.js` lets a GUEST read `/location/suggest` and `/location/list` without a REST nonce
 * (a stale one from a cached checkout page made core answer 403), but must keep SIGNING the reads of a
 * logged-in customer. This is the core + WooCommerce behaviour that rule rests on, against the real
 * classes:
 *
 * - `rest_cookie_check_errors()` demotes a cookie-authenticated request that carries no nonce to
 *   user 0; a request signed with a valid `wp_rest` nonce keeps its user.
 * - `Location_Controller::bridge_wc_session()` then starts the WooCommerce session
 *   (`wc_load_cart()` → `WC_Session_Handler::init_session_cookie()`): for a customer's numeric session
 *   cookie that is a DESTROYED session (the cart goes) when the request is logged out, and the same
 *   session, intact, when it is still the customer.
 *
 * The second half of the file (#1436) drives the REAL routes, `/location/suggest` and `/location/list`,
 * against a seeded WooCommerce session row (a cart plus a stored region) and asserts what the customer is
 * left with: the signed read keeps the cart and the `within` scope, the unsigned read of a logged-in
 * customer loses both, and a guest's unsigned read is untouched.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.10
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Location\{Location_Provider_Registry, Location_Record};
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class LocationReadAuthTest extends TestCase {

	/** @var mixed */
	private $original_rest_auth_cookie;

	/** @var array<string, mixed> */
	private $original_cookies;

	/** The region the seeded chain holds; `within` names it. */
	private const REGION_KEY = 'test-list:mo';

	/** @var mixed */
	private $original_wc_session;

	/** @var mixed */
	private $original_wc_cart;

	/** @var mixed */
	private $original_wc_customer;

	/** @var mixed */
	private $original_server_nonce;

	/** @var mixed */
	private $original_request_nonce;

	protected function setUp(): void {
		parent::setUp();

		$this->original_rest_auth_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$this->original_cookies          = $_COOKIE;
		$this->original_wc_session       = WC()->session;
		$this->original_wc_cart          = WC()->cart;
		$this->original_wc_customer      = WC()->customer;
		$this->original_server_nonce     = $_SERVER['HTTP_X_WP_NONCE'] ?? null;
		$this->original_request_nonce    = $_REQUEST['_wpnonce'] ?? null;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		// Destroying a session expires its cookie; headers are already sent in a test run and WooCommerce
		// reports that as a notice under WP_DEBUG.
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
	}

	protected function tearDown(): void {
		$GLOBALS['wp_rest_auth_cookie'] = $this->original_rest_auth_cookie;
		$_COOKIE                        = $this->original_cookies;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		if ( null !== $this->original_server_nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = $this->original_server_nonce;
		}
		if ( null !== $this->original_request_nonce ) {
			$_REQUEST['_wpnonce'] = $this->original_request_nonce;
		}
		remove_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		wp_set_current_user( 0 );

		// A request below raises its own session handler; its shutdown save would write a stray row after the
		// test's transaction is gone, and the next class expects the guest session the bootstrap built.
		if ( WC()->session !== $this->original_wc_session && null !== WC()->session ) {
			remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 );
		}

		WC()->session  = $this->original_wc_session;
		WC()->cart     = $this->original_wc_cart;
		WC()->customer = $this->original_wc_customer;

		Location_Provider_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Runs core's cookie check the way the REST server does for a cookie-authenticated request, then
	 * reports who the request is afterwards.
	 */
	private function user_after_cookie_check( int $user_id, ?string $nonce ): int {
		wp_set_current_user( $user_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;

		if ( null !== $nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		}

		$this->assertTrue( rest_cookie_check_errors( null ) );

		return get_current_user_id();
	}

	/**
	 * A WooCommerce session cookie for a numeric (logged-in customer's) session id, signed the way
	 * `WC_Session_Handler::set_customer_session_cookie()` signs it.
	 */
	private function numeric_session_cookie( int $user_id ): string {
		return $this->session_cookie( (string) $user_id );
	}

	/** A session cookie for any session id, a customer's numeric one or a guest's `t_` hash. */
	private function session_cookie( string $session_id ): string {
		$expiration = time() + DAY_IN_SECONDS;
		$expiring   = time() + DAY_IN_SECONDS - HOUR_IN_SECONDS;
		$to_hash    = $session_id . '|' . $expiration;

		return $session_id . '||' . $expiration . '||' . $expiring . '||' . hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
	}

	/** The id the WooCommerce session handler settles on for a request that carries that cookie. */
	private function session_customer_id_for( int $user_id ): string {
		$handler = new \WC_Session_Handler();

		$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] = $this->numeric_session_cookie( $user_id );

		$handler->init_session_cookie();

		return (string) $handler->get_customer_id();
	}

	public function test_an_unsigned_request_demotes_a_cookie_authenticated_customer_to_user_zero(): void {
		$customer = self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( 0, $this->user_after_cookie_check( $customer, null ) );
	}

	public function test_a_request_signed_with_a_valid_nonce_keeps_the_customer(): void {
		$customer = self::factory()->user->create( [ 'role' => 'customer' ] );

		wp_set_current_user( $customer );
		$nonce = wp_create_nonce( 'wp_rest' );

		$this->assertSame( $customer, $this->user_after_cookie_check( $customer, $nonce ) );
	}

	public function test_a_logged_out_request_destroys_the_customers_numeric_session(): void {
		$customer = self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->user_after_cookie_check( $customer, null );

		$this->assertNotSame(
			(string) $customer,
			$this->session_customer_id_for( $customer ),
			'An unsigned read leaves WooCommerce a logged-out request holding a customer\'s numeric session cookie.'
		);
	}

	public function test_a_signed_request_keeps_the_customers_numeric_session(): void {
		$customer = self::factory()->user->create( [ 'role' => 'customer' ] );

		wp_set_current_user( $customer );
		$nonce = wp_create_nonce( 'wp_rest' );

		$this->user_after_cookie_check( $customer, $nonce );

		$this->assertSame( (string) $customer, $this->session_customer_id_for( $customer ) );
	}

	// -------------------------------------------------------------------------
	// #1436 — the real routes, against a seeded session row.
	// -------------------------------------------------------------------------

	/** Opens the location layer the way production does and points it at the fixture list provider. */
	private function boot_location_routes(): void {
		// The store's explicit choice; the rig may also seed a DaData token, which must not decide the provider.
		update_option( 'woodev_location_' . Location_Provider_Registry::SETTING_ACTIVE_PROVIDER, 'test-list' );

		// A reset drops the singleton, so the registry is fetched AFTER it.
		Location_Provider_Registry::instance()->reset_for_tests();

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		do_action( 'rest_api_init' );
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Seeds the `woocommerce_sessions` row a customer arrives with: two units of a real product in the cart and a
	 * stored location chain that holds a region (what `within` names). Written through the session handler itself,
	 * so the row has the shape and the cache entry WooCommerce reads back.
	 *
	 * @return int The product id in the cart.
	 */
	private function seed_session( string $session_id, int $logged_in_as ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Read auth fixture' );
		$product->set_regular_price( '100' );
		$product->save();

		$cart_key = WC()->cart->generate_cart_id( $product->get_id() );
		$region   = Location_Record::from_array(
			[
				'key'         => self::REGION_KEY,
				'provider_id' => 'test-list',
				'level'       => Location_Record::LEVEL_REGION,
				'country'     => 'RU',
				'region'      => [ 'name' => 'Московская область', 'type' => 'область' ],
				'label'       => 'Московская область',
			]
		);

		wp_set_current_user( $logged_in_as );
		$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] = $this->session_cookie( $session_id );

		$handler = new \WC_Session_Handler();
		$handler->init_session_cookie();
		$handler->set(
			'cart',
			[
				$cart_key => [
					'key'               => $cart_key,
					'product_id'        => $product->get_id(),
					'variation_id'      => 0,
					'variation'         => [],
					'quantity'          => 2,
					'data_hash'         => wc_get_cart_item_data_hash( $product ),
					'line_tax_data'     => [ 'subtotal' => [], 'total' => [] ],
					'line_subtotal'     => 200,
					'line_subtotal_tax' => 0,
					'line_total'        => 200,
					'line_tax'          => 0,
				],
			]
		);
		$handler->set(
			'woodev_customer_location',
			[
				'records'  => [ Location_Record::LEVEL_REGION => $region->to_array() ],
				'current'  => Location_Record::LEVEL_REGION,
				'implicit' => false,
				'saved_at' => time(),
			]
		);
		$handler->save_data();

		$this->assertSame( 1, $this->session_rows( $session_id ), 'setup precondition: the session row must exist.' );

		return $product->get_id();
	}

	/** How many `woocommerce_sessions` rows exist for a session id (0 or 1). */
	private function session_rows( string $session_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $session_id )
		);
	}

	/**
	 * Dispatches one read the way a cookie-authenticated browser request reaches the route: core's cookie check
	 * first (it demotes an unsigned request to user 0), then the route, starting from the state a real REST
	 * request begins in — no session, cart or customer loaded yet (gotcha
	 * `the-integration-suite-has-a-wc-session-a-rest-request-does-not`).
	 *
	 * @param int                  $user_id Logged-in user, or 0 for a guest.
	 * @param string               $session_id The WooCommerce session id the request's cookie carries.
	 * @param array<string, mixed> $params Request params.
	 */
	private function read( string $route, int $user_id, string $session_id, bool $signed, array $params ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] = $this->session_cookie( $session_id );

		unset( $_SERVER['HTTP_X_WP_NONCE'] );

		if ( $signed ) {
			$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		}

		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( $signed ? $user_id : 0, get_current_user_id() );

		WC()->session  = null;
		WC()->cart     = null;
		WC()->customer = null;

		$request = new WP_REST_Request( 'GET', $route );

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return rest_do_request( $request );
	}

	/** @return array<string, array{0: string, 1: array<string, string>}> */
	public function read_routes(): array {
		return [
			'suggest' => [ '/woodev/v1/location/suggest', [ 'q' => 'Зел', 'level' => 'settlement', 'country' => 'RU', 'within' => self::REGION_KEY ] ],
			'list'    => [ '/woodev/v1/location/list', [ 'level' => 'settlement', 'country' => 'RU', 'within' => self::REGION_KEY ] ],
		];
	}

	/** The settlement keys a response offers, whichever of the two routes produced it. */
	private function offered_keys( \WP_REST_Response $response ): array {
		$data = $response->get_data();

		return wp_list_pluck( $data['suggestions'] ?? $data['localities'], 'key' );
	}

	/**
	 * @dataProvider read_routes
	 */
	public function test_a_signed_read_keeps_the_customers_cart_and_within_scope( string $route, array $params ): void {
		$this->boot_location_routes();

		$customer   = self::factory()->user->create( [ 'role' => 'customer' ] );
		$product_id = $this->seed_session( (string) $customer, $customer );

		$response = $this->read( $route, $customer, (string) $customer, true, $params );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'applied', $data['within_status'], 'the stored region must still scope the search.' );
		$this->assertContains( 'test-list:zelenograd', $this->offered_keys( $response ) );
		$this->assertNotContains( 'test-list:spb', $this->offered_keys( $response ), 'only the stored region\'s settlements are offered.' );

		$this->assertNotNull( WC()->session, 'the route must raise the customer\'s session.' );
		$this->assertSame( (string) $customer, (string) WC()->session->get_customer_id() );
		$this->assertSame( 1, $this->session_rows( (string) $customer ), 'the session row must survive the read.' );

		$cart = WC()->session->get( 'cart' );
		$this->assertIsArray( $cart );
		$this->assertSame( $product_id, (int) reset( $cart )['product_id'] );
		$this->assertSame( 2, (int) reset( $cart )['quantity'] );

		// What the next page load does with that session: build the cart from it.
		( new \WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
		$this->assertSame( 2, WC()->cart->get_cart_contents_count(), 'the customer\'s cart must still load from the session.' );
	}

	/**
	 * @dataProvider read_routes
	 */
	public function test_an_unsigned_read_of_a_logged_in_customer_loses_the_cart_and_within_scope( string $route, array $params ): void {
		$this->boot_location_routes();

		$customer = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->seed_session( (string) $customer, $customer );

		$response = $this->read( $route, $customer, (string) $customer, false, $params );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'unknown_key', $response->get_data()['within_status'], 'the demoted request has no stored region.' );
		$this->assertSame( [], $this->offered_keys( $response ) );
		$this->assertNotNull( WC()->session );
		$this->assertNotSame( (string) $customer, (string) WC()->session->get_customer_id() );
		$this->assertSame( 0, $this->session_rows( (string) $customer ), 'this is why the client signs a logged-in customer\'s reads.' );
	}

	/**
	 * @dataProvider read_routes
	 */
	public function test_an_unsigned_guest_read_keeps_the_guests_cart_and_within_scope( string $route, array $params ): void {
		$this->boot_location_routes();

		$guest      = 't_' . substr( md5( 'woodev-1436-guest' ), 0, 30 );
		$product_id = $this->seed_session( $guest, 0 );

		$response = $this->read( $route, 0, $guest, false, $params );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'applied', $response->get_data()['within_status'] );
		$this->assertContains( 'test-list:zelenograd', $this->offered_keys( $response ) );

		$this->assertSame( $guest, (string) WC()->session->get_customer_id() );
		$this->assertSame( 1, $this->session_rows( $guest ) );

		$cart = WC()->session->get( 'cart' );
		$this->assertIsArray( $cart );
		$this->assertSame( $product_id, (int) reset( $cart )['product_id'] );
	}
}
