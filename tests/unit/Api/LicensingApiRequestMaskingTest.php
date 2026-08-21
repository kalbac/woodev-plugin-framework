<?php
/**
 * Unit tests for license-key masking in logged licensing API requests — #395.
 *
 * With `WOODEV_LICENSE_DEBUG` on, every licensing request (the weekly
 * check_license cron, activation, deactivation, an update check) reached
 * `Woodev_API_Base::get_request_data_for_broadcast()`, which is handed
 * straight to `Woodev_Plugin::log_api_request()` — the customer's license key
 * reached the log in CLEAR TEXT, twice: once in the `uri` field (the query
 * string `Woodev_Licensing_API_Request::get_path()` builds from
 * `http_build_query( $this->get_params() )`, `license` among them), and once
 * in the `body` field (`to_string_safe()` simply aliased `to_string()`, a
 * `print_r()` dump of every param, masking nothing).
 *
 * Request headers already had a masking convention
 * ({@see \Woodev_API_Base::mask_secret_values()}, exercised by
 * ApiBaseSanitizedHeadersTest.php) — the fix for #395 reuses that exact
 * routine for the license param instead of inventing a second, differently
 * shaped one, via `Woodev_API_Base::get_sanitized_request_path()` /
 * `get_sanitized_request_uri()`, and `Woodev_Licensing_API_Request::get_path_safe()`
 * / `to_string_safe()`.
 *
 * An independent critic review rejected the first round of this fix: both
 * seams FAILED OPEN for any request class that didn't implement its own
 * `get_path_safe()`/override `to_string_safe()` — falling back to the raw,
 * unmasked path/body by default — and a transport-thrown WP_Error message
 * could carry the raw URI straight into an exception, bypassing masking
 * entirely. The round-two fix made both fail SAFE by default: an
 * unconditional, regex-based redaction
 * ({@see \Woodev_API_Base::get_secret_param_names()} /
 * {@see \Woodev_API_Base::redact_secret_query_params()}) now runs on every
 * path, URI (after the `woodev_{api_id}_api_request_uri` filter, not
 * before), and WP_Error message — regardless of whether the concrete
 * request class implements any masking of its own.
 *
 * This file pins:
 *
 * - the real wire-bound getters ({@see \Woodev_Licensing_API_Request::get_path()},
 *   {@see \Woodev_Licensing_API_Request::to_string()}) still carry the REAL
 *   license key — masking must affect ONLY what is logged, never what is sent;
 * - the logging-only getters ({@see \Woodev_Licensing_API_Request::get_path_safe()},
 *   {@see \Woodev_Licensing_API_Request::to_string_safe()}) mask the license
 *   key, using the same fixed placeholder as header masking
 *   ({@see \Woodev_API_Base::SECRET_VALUE_MASK});
 * - non-secret params (edd_action, item_id, ...) pass through unmasked, on
 *   both sides;
 * - end to end, {@see \Woodev_API_Base::get_request_data_for_broadcast()}
 *   contains no clear-text license key in either `uri` or `body`, while the
 *   getters actually used to perform the request
 *   ({@see \Woodev_API_Base::get_request_uri()}, {@see \Woodev_API_Base::get_request_body()})
 *   are byte-for-byte unaffected by the fix.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/licensing/api/class-licensing-api-request.php';

/**
 * Minimal concrete Woodev_API_Base double wired with a real
 * Woodev_Licensing_API_Request, exposing both the actual-wire getters and the
 * logging (broadcast) getter for direct comparison — without pulling in the
 * HTTP transport or the Woodev_Plugin/Woodev_Licensing_API machinery this fix
 * does not touch.
 */
class Testable_Licensing_Request_Api_Base extends \Woodev_API_Base {

	public function __construct() {
		$this->request_uri = 'https://woodev.ru/';
	}

	/**
	 * Seeds the request under test.
	 *
	 * @param \Woodev_Licensing_API_Request $request Request instance.
	 * @return void
	 */
	public function set_request_for_test( \Woodev_Licensing_API_Request $request ): void {
		$this->request = $request;
	}

	/**
	 * Exposes the getter actually used to perform the request.
	 *
	 * @return string
	 */
	public function get_request_uri_for_test(): string {
		return $this->get_request_uri();
	}

	/**
	 * Exposes the getter actually used to build the request body sent over the wire.
	 *
	 * @return string
	 */
	public function get_request_body_for_test(): string {
		return $this->get_request_body();
	}

	/**
	 * Exposes the full broadcast (logging) payload under test.
	 *
	 * @return array<string, mixed>
	 */
	public function get_request_data_for_broadcast_for_test(): array {
		return $this->get_request_data_for_broadcast();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Bypasses the Woodev_Plugin dependency this test double has none of.
	 *
	 * @return string
	 */
	protected function get_request_user_agent() {
		return 'woodev-test/1.0';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_api_id() {
		return 'test_license';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return null
	 */
	protected function get_new_request( $args = [] ) {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return null
	 */
	protected function get_plugin() {
		return null;
	}
}

/**
 * A response class instantiable by {@see \Woodev_API_Base::get_parsed_response()}
 * — this fix does not touch response parsing, only what is logged around it.
 */
class Testable_Licensing_Response_For_Wire_Test {

	/**
	 * @param string $raw_body Unused — only the constructor shape matters here.
	 */
	public function __construct( $raw_body ) {}
}

/**
 * A minimal Woodev_Plugin double satisfying the one call
 * {@see \Woodev_API_Base::perform_request()} makes on it directly.
 */
class Testable_License_Plugin_Stub {

	/**
	 * @return bool
	 */
	public function require_tls_1_2() {
		return false;
	}
}

/**
 * Extends the shared test double with a transport override that CAPTURES its
 * arguments instead of performing a real HTTP call, so
 * {@see \Woodev_API_Base::perform_request()} can be exercised end to end —
 * this is what the SHOULD-FIX critic finding asked the wire-parity test to
 * actually do: observe the transport's real arguments, not just re-read the
 * same getters the broadcast payload also reads.
 */
class Testable_Licensing_Request_Api_Base_With_Transport_Capture extends Testable_Licensing_Request_Api_Base {

	/** @var string|null */
	public $captured_request_uri;

	/** @var array<string, mixed>|null */
	public $captured_request_args;

	/** @var array<string, mixed>|null */
	public $captured_broadcast_request_data;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $request_uri
	 * @param array<string, mixed> $request_args
	 * @return array<string, mixed>
	 */
	protected function do_remote_request( $request_uri, $request_args ) {

		$this->captured_request_uri  = $request_uri;
		$this->captured_request_args = $request_args;

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'headers'  => [],
			'body'     => '',
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * Captures the broadcast (logging) payload alongside the raw transport
	 * arguments above, so both sides of the same {@see \Woodev_API_Base::perform_request()}
	 * call can be compared in one test.
	 *
	 * @return void
	 */
	protected function broadcast_request() {

		$this->captured_broadcast_request_data = $this->get_request_data_for_broadcast();

		parent::broadcast_request();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_response_handler() {
		return Testable_Licensing_Response_For_Wire_Test::class;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Testable_License_Plugin_Stub
	 */
	protected function get_plugin() {
		return new Testable_License_Plugin_Stub();
	}

	/**
	 * Exposes the protected perform_request() under test.
	 *
	 * @param \Woodev_Licensing_API_Request $request Request instance.
	 * @return mixed
	 */
	public function perform_request_for_test( $request ) {
		return $this->perform_request( $request );
	}
}

/**
 * A transport override that returns a WP_Error carrying the RAW request URI
 * it was given in its message — the concrete route from the license key to
 * `error_log()` the critic identified (Blocking 2): a transport override or
 * an `http_api_curl`-style filter is free to embed the URI in a failure
 * message, and {@see \Woodev_API_Base::handle_response()} used to hand that
 * message straight into the thrown exception unredacted.
 */
class Testable_Licensing_Request_Api_Base_With_Wp_Error_Transport extends Testable_Licensing_Request_Api_Base {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $request_uri
	 * @param array<string, mixed> $request_args
	 * @return \WP_Error
	 */
	protected function do_remote_request( $request_uri, $request_args ) {
		return new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: ' . $request_uri );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Testable_License_Plugin_Stub
	 */
	protected function get_plugin() {
		return new Testable_License_Plugin_Stub();
	}

	/**
	 * Exposes the protected perform_request() under test.
	 *
	 * @param \Woodev_Licensing_API_Request $request Request instance.
	 * @return mixed
	 */
	public function perform_request_for_test( $request ) {
		return $this->perform_request( $request );
	}
}

/**
 * Class LicensingApiRequestMaskingTest.
 */
final class LicensingApiRequestMaskingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
	}

	/**
	 * Stubs the WP HTTP-transport functions {@see \Woodev_API_Base::perform_request()}
	 * and {@see \Woodev_API_Base::handle_response()} call, so those methods can
	 * be exercised end to end without a real WordPress environment.
	 *
	 * @return void
	 */
	private function stub_wp_http_functions(): void {

		Functions\when( 'do_action' )->justReturn( null );

		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 'OK' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
	}

	/**
	 * Builds a real Woodev_Licensing_API_Request carrying a license key among
	 * other, non-secret params — mirrors the shape Woodev_Plugins_License::dispatch()
	 * actually sends.
	 *
	 * @param string $license_key License key to embed.
	 * @return \Woodev_Licensing_API_Request
	 */
	private function make_request( string $license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value' ): \Woodev_Licensing_API_Request {

		$request = new \Woodev_Licensing_API_Request();
		$request->get_license(
			[
				'edd_action' => 'check_license',
				'license'    => $license_key,
				'item_id'    => 123,
				'url'        => 'https://example.test',
			]
		);

		return $request;
	}

	/**
	 * Parses the query string off a `path?query` string into an assoc array,
	 * so assertions don't depend on http_build_query()'s exact percent-encoding.
	 *
	 * @param string $path_with_query A path as returned by get_path()/get_path_safe().
	 * @return array<string, string>
	 */
	private function parse_query( string $path_with_query ): array {

		[, $query] = array_pad( explode( '?', $path_with_query, 2 ), 2, '' );

		parse_str( (string) $query, $params );

		return $params;
	}

	// -------------------------------------------------------------------------
	// get_path() — the actual wire path — must stay untouched.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_get_path_still_carries_the_real_license_key(): void {

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$params = $this->parse_query( $request->get_path() );

		$this->assertSame( $license_key, $params['license'], 'the real request must carry the real license key' );
	}

	// -------------------------------------------------------------------------
	// get_path_safe() — the logging-only path — must mask the license key.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_get_path_safe_masks_the_license_key(): void {

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$safe_path = $request->get_path_safe();
		$params    = $this->parse_query( $safe_path );

		$this->assertSame( \Woodev_API_Base::SECRET_VALUE_MASK, $params['license'] );
		$this->assertStringNotContainsString( $license_key, $safe_path, 'the license key leaked into the sanitized path' );
	}

	/**
	 * @return void
	 */
	public function test_get_path_safe_leaves_non_secret_params_untouched(): void {

		$request = $this->make_request();

		$params = $this->parse_query( $request->get_path_safe() );

		$this->assertSame( 'check_license', $params['edd_action'] );
		$this->assertSame( '123', $params['item_id'] );
		$this->assertSame( 'https://example.test', $params['url'] );
	}

	/**
	 * get_path() and get_path_safe() must build the SAME path apart from the
	 * license value — the masking seam must not accidentally reorder, drop,
	 * or re-encode any other param.
	 *
	 * @return void
	 */
	public function test_get_path_and_get_path_safe_agree_on_everything_but_the_license_value(): void {

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$real_params = $this->parse_query( $request->get_path() );
		$safe_params = $this->parse_query( $request->get_path_safe() );

		$this->assertSame( array_keys( $real_params ), array_keys( $safe_params ) );

		unset( $real_params['license'], $safe_params['license'] );
		$this->assertSame( $real_params, $safe_params, 'only the license param may differ between the two paths' );
	}

	// -------------------------------------------------------------------------
	// to_string() vs to_string_safe() — the actual wire BODY must stay
	// untouched; the logging-only body must mask the license key.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_to_string_still_carries_the_real_license_key(): void {

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$this->assertStringContainsString( $license_key, (string) $request->to_string(), 'the real request body must carry the real license key' );
	}

	/**
	 * @return void
	 */
	public function test_to_string_safe_masks_the_license_key(): void {

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$safe_body = (string) $request->to_string_safe();

		$this->assertStringNotContainsString( $license_key, $safe_body, 'the license key leaked into the sanitized body' );
		$this->assertStringContainsString( \Woodev_API_Base::SECRET_VALUE_MASK, $safe_body );
	}

	/**
	 * @return void
	 */
	public function test_to_string_safe_leaves_non_secret_params_untouched(): void {

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$safe_body = (string) $request->to_string_safe();

		$this->assertStringContainsString( 'check_license', $safe_body );
		$this->assertStringContainsString( '123', $safe_body );
		$this->assertStringContainsString( 'example.test', $safe_body );

		// SHOULD-FIX: this test previously only asserted the non-secret params
		// were present, never that the secret was ABSENT — it passed both
		// before and after the fix and proved nothing about masking.
		$this->assertStringNotContainsString( $license_key, $safe_body, 'the license key must not appear in the sanitized body' );
	}

	// -------------------------------------------------------------------------
	// End to end: get_request_data_for_broadcast() — the array handed to the
	// woodev_{api_id}_api_request_performed action, i.e. to the request
	// logger — must not contain the license key in EITHER field, while the
	// getters that build the actually-sent request stay byte-for-byte
	// unaffected.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_broadcast_payload_masks_license_key_in_uri_and_body(): void {

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$api = new Testable_Licensing_Request_Api_Base();
		$api->set_request_for_test( $request );

		$broadcast = $api->get_request_data_for_broadcast_for_test();

		$this->assertArrayHasKey( 'uri', $broadcast );
		$this->assertArrayHasKey( 'body', $broadcast );
		$this->assertStringNotContainsString( $license_key, $broadcast['uri'], 'the license key leaked into the broadcast uri' );
		$this->assertStringNotContainsString( $license_key, $broadcast['body'], 'the license key leaked into the broadcast body' );
		$this->assertStringNotContainsString(
			$license_key,
			print_r( $broadcast, true ),
			'the license key leaked into the broadcast payload'
		);
	}

	/**
	 * The whole point of masking ONLY the broadcast/log path: the bytes
	 * actually sent to the licensing server must be byte-for-byte identical
	 * before and after this fix. Exercises the REAL perform_request()
	 * codepath end to end, with a transport override that CAPTURES its
	 * actual arguments — rather than re-reading the same getters the
	 * broadcast payload itself reads, which would pass even if masking
	 * corrupted the wire request.
	 *
	 * SHOULD-FIX: the previous version of this test called
	 * get_request_data_for_broadcast_for_test() and then asserted against
	 * get_request_uri_for_test()/get_request_body_for_test() — the same two
	 * getters perform_request() itself calls to build the wire request. It
	 * never observed the transport, so it passed identically before and
	 * after the fix and proved nothing about wire parity.
	 *
	 * @return void
	 */
	public function test_actual_wire_request_is_unaffected_by_broadcast_masking(): void {

		$this->stub_wp_http_functions();

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		// The pre-redaction ground truth: what the wire request SHOULD look
		// like, built independently of anything perform_request() does.
		$expected_uri  = 'https://woodev.ru/' . $request->get_path();
		$expected_body = $request->to_string();

		$api = new Testable_Licensing_Request_Api_Base_With_Transport_Capture();
		$api->set_request_for_test( $request );

		// do_remote_request() (captured, raw) runs BEFORE handle_response()
		// -> broadcast_request() (captured, sanitized) — the real production
		// order — so this proves the masking that runs second has no way to
		// reach back and affect what already went out first.
		$api->perform_request_for_test( $request );

		$this->assertStringContainsString( $license_key, $api->captured_request_uri, 'the real request must carry the real license key over the wire' );
		$this->assertSame( $expected_uri, $api->captured_request_uri, 'query encoding must be byte-for-byte unaffected by broadcast masking' );

		$this->assertStringContainsString( $license_key, $api->captured_request_args['body'], 'the real request body must carry the real license key over the wire' );
		$this->assertSame( $expected_body, $api->captured_request_args['body'], 'body bytes must be byte-for-byte unaffected by broadcast masking' );

		// And prove the masking actually ran, in the very same call, on the
		// sibling broadcast payload.
		$this->assertStringNotContainsString( $license_key, $api->captured_broadcast_request_data['uri'] );
		$this->assertStringNotContainsString( $license_key, $api->captured_broadcast_request_data['body'] );
	}

	// -------------------------------------------------------------------------
	// BLOCKING 2 regression guard: a transport-thrown WP_Error message must
	// be redacted before it reaches an exception (and, downstream,
	// Woodev_Plugin_Updater::get_version_from_remote()'s error_log()).
	// -------------------------------------------------------------------------

	/**
	 * A transport override (or an `http_api_curl`-style filter) can return a
	 * WP_Error whose message embeds the raw URI it was given — a real route
	 * third-party code can trigger, independent of any request-class-specific
	 * masking. Woodev_Plugin_Updater::get_version_from_remote() logs
	 * `$e->getMessage()` verbatim via error_log() on ANY \Throwable, so an
	 * unredacted WP_Error message here reaches the PHP error log regardless
	 * of WOODEV_LICENSE_DEBUG or the WooCommerce logger.
	 *
	 * @return void
	 */
	public function test_wp_error_transport_message_is_redacted_before_it_reaches_an_exception(): void {

		$this->stub_wp_http_functions();

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$api = new Testable_Licensing_Request_Api_Base_With_Wp_Error_Transport();
		$api->set_request_for_test( $request );

		try {
			$api->perform_request_for_test( $request );
			$this->fail( 'Expected a Woodev_API_Exception to be thrown.' );
		} catch ( \Woodev_API_Exception $e ) {
			$this->assertStringNotContainsString(
				$license_key,
				$e->getMessage(),
				'a raw transport WP_Error message leaked the license key into the exception'
			);
		}
	}
}
