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
 * ({@see \Woodev_API_Base::mask_secret_headers()}, exercised by
 * ApiBaseSanitizedHeadersTest.php) — the fix for #395 reuses that exact
 * routine for the license param instead of inventing a second, differently
 * shaped one, via two new opt-in seams on `Woodev_API_Base`:
 * `get_sanitized_request_path()` / `get_sanitized_request_uri()`, mirroring
 * the existing `is_callable( ..., 'to_string_safe' )` opt-in already used for
 * the response body. `Woodev_Licensing_API_Request::get_path_safe()` /
 * `to_string_safe()` are the opt-in implementations.
 *
 * This file pins:
 *
 * - the real wire-bound getters ({@see \Woodev_Licensing_API_Request::get_path()},
 *   {@see \Woodev_Licensing_API_Request::to_string()}) still carry the REAL
 *   license key — masking must affect ONLY what is logged, never what is sent;
 * - the logging-only getters ({@see \Woodev_Licensing_API_Request::get_path_safe()},
 *   {@see \Woodev_Licensing_API_Request::to_string_safe()}) mask the license
 *   key, using the exact same convention as header masking (`*` repeated to
 *   the value's original length);
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

		$this->assertSame( str_repeat( '*', strlen( $license_key ) ), $params['license'] );
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
		$this->assertStringContainsString( str_repeat( '*', strlen( $license_key ) ), $safe_body );
	}

	/**
	 * @return void
	 */
	public function test_to_string_safe_leaves_non_secret_params_untouched(): void {

		$request = $this->make_request();

		$safe_body = (string) $request->to_string_safe();

		$this->assertStringContainsString( 'check_license', $safe_body );
		$this->assertStringContainsString( '123', $safe_body );
		$this->assertStringContainsString( 'example.test', $safe_body );
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
	 * before and after this fix.
	 *
	 * @return void
	 */
	public function test_actual_wire_request_is_unaffected_by_broadcast_masking(): void {

		$license_key = 'AAAA-BBBB-CCCC-DDDD-license-secret-value';
		$request     = $this->make_request( $license_key );

		$api = new Testable_Licensing_Request_Api_Base();
		$api->set_request_for_test( $request );

		// Exercise the logging codepath first, to prove it has no side effect
		// on the getters used to actually perform the request.
		$api->get_request_data_for_broadcast_for_test();

		$this->assertStringContainsString( $license_key, $api->get_request_uri_for_test() );
		$this->assertStringContainsString( $license_key, $api->get_request_body_for_test() );
	}
}
