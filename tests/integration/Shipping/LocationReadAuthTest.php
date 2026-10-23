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
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.10
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Tests\Integration\TestCase;

class LocationReadAuthTest extends TestCase {

	/** @var mixed */
	private $original_rest_auth_cookie;

	/** @var array<string, mixed> */
	private $original_cookies;

	protected function setUp(): void {
		parent::setUp();

		$this->original_rest_auth_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$this->original_cookies          = $_COOKIE;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		// Destroying a session expires its cookie; headers are already sent in a test run and WooCommerce
		// reports that as a notice under WP_DEBUG.
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
	}

	protected function tearDown(): void {
		$GLOBALS['wp_rest_auth_cookie'] = $this->original_rest_auth_cookie;
		$_COOKIE                        = $this->original_cookies;
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		remove_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		wp_set_current_user( 0 );

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
		$expiration = time() + DAY_IN_SECONDS;
		$expiring   = time() + DAY_IN_SECONDS - HOUR_IN_SECONDS;
		$to_hash    = $user_id . '|' . $expiration;

		return $user_id . '||' . $expiration . '||' . $expiring . '||' . hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
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
}
