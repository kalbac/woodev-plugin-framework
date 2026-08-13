<?php
/**
 * Unit tests for Woodev_API_Base::get_sanitized_request_headers() /
 * get_sanitized_response_headers() / get_secret_header_names() — issues #288, #300.
 *
 * Woodev_API_Base::broadcast_request() hands the sanitized request AND response
 * headers to the documented `woodev_{api_id}_api_request_performed` action, i.e. to
 * any attached request logger. #288 fixed the outgoing half: before it, the
 * sanitizer masked ONLY the `Authorization` request header by exact key match, so
 * any other credential header (e.g. a vendor-specific `X-Secret`) rode the
 * broadcast in plaintext. #300 is the symmetric fix for the incoming half: response
 * headers were broadcast completely raw — no sanitizer ran on them at all, so a
 * `Set-Cookie` (session token) or a token-refresh header (`X-Auth-Token` etc.)
 * returned by the carrier leaked into the log unmasked. This file pins:
 *
 * - `Authorization` stays masked on the request side (regression guard — the
 *   payment-gateway tree shares this base class);
 * - every other header name in the default {@see \Woodev_API_Base::get_secret_header_names()}
 *   list is masked too, on BOTH the request and the response side;
 * - `Set-Cookie` is masked on the response side;
 * - the match is case-insensitive, while the returned array preserves the
 *   ORIGINAL key casing the request/response actually carried;
 * - a subclass can extend the list with its own header name, and the extension
 *   covers both directions through the single shared seam;
 * - a non-secret header passes through untouched, on both sides;
 * - response headers that are `null` (no response received yet) pass through
 *   unchanged rather than being coerced into an array.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

/**
 * Minimal concrete Woodev_API_Base double exposing the protected header
 * sanitizer surface for direct assertions, without pulling in the HTTP/broadcast
 * machinery this fix does not touch.
 */
class Testable_Api_Base extends \Woodev_API_Base {

	/**
	 * Seeds the request headers under test.
	 *
	 * @param array<string, string> $headers Header name/value pairs, casing as sent.
	 * @return void
	 */
	public function set_headers_for_test( array $headers ): void {
		$this->set_request_headers( $headers );
	}

	/**
	 * Seeds the response headers under test, bypassing the HTTP transport.
	 *
	 * @param array<string, string> $headers Header name/value pairs, casing as received.
	 * @return void
	 */
	public function set_response_headers_for_test( array $headers ): void {
		$this->response_headers = $headers;
	}

	/**
	 * Exposes the protected request-header sanitizer under test.
	 *
	 * @return array<string, string>
	 */
	public function get_sanitized_headers_for_test(): array {
		return $this->get_sanitized_request_headers();
	}

	/**
	 * Exposes the protected response-header sanitizer under test.
	 *
	 * @return array<string, string>|null
	 */
	public function get_sanitized_response_headers_for_test() {
		return $this->get_sanitized_response_headers();
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
 * A subclass extending the default credential-header list with a header the
 * base class does not know about — proves the extension seam works, for both
 * the request and the response sanitizer, since both read the same list.
 */
class Testable_Api_Base_With_Custom_Secret extends Testable_Api_Base {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	protected function get_secret_header_names(): array {
		return array_merge( parent::get_secret_header_names(), [ 'X-Custom-Secret' ] );
	}
}

/**
 * Class ApiBaseSanitizedHeadersTest.
 */
final class ApiBaseSanitizedHeadersTest extends TestCase {

	/**
	 * Regression guard: Authorization must stay masked exactly as before.
	 *
	 * @return void
	 */
	public function test_authorization_header_is_masked(): void {
		$value = 'token-value-that-must-never-reach-the-log';

		$api = new Testable_Api_Base();
		$api->set_headers_for_test( [ 'Authorization' => $value ] );

		$headers = $api->get_sanitized_headers_for_test();

		$this->assertSame( str_repeat( '*', strlen( $value ) ), $headers['Authorization'] );
	}

	/**
	 * A non-Authorization credential header from the default list is masked too.
	 *
	 * @return void
	 */
	public function test_default_list_masks_a_non_authorization_credential_header(): void {
		$secret = 'secret-value-that-must-never-reach-the-log';

		$api = new Testable_Api_Base();
		$api->set_headers_for_test( [ 'X-Secret' => $secret ] );

		$headers = $api->get_sanitized_headers_for_test();

		$this->assertSame( str_repeat( '*', strlen( $secret ) ), $headers['X-Secret'] );
		$this->assertStringNotContainsString( $secret, print_r( $headers, true ), 'the secret leaked into the sanitized headers' );
	}

	/**
	 * HTTP header names are case-insensitive: a lowercase `x-secret` must be
	 * masked, and the returned array must keep the ORIGINAL key casing.
	 *
	 * @return void
	 */
	public function test_match_is_case_insensitive_but_preserves_original_key_casing(): void {
		$secret = 'secret-value-that-must-never-reach-the-log';

		$api = new Testable_Api_Base();
		$api->set_headers_for_test( [ 'x-secret' => $secret ] );

		$headers = $api->get_sanitized_headers_for_test();

		$this->assertArrayHasKey( 'x-secret', $headers );
		$this->assertArrayNotHasKey( 'X-Secret', $headers );
		$this->assertSame( str_repeat( '*', strlen( $secret ) ), $headers['x-secret'] );
	}

	/**
	 * A subclass extending get_secret_header_names() gets its custom
	 * header masked through the same shared logic — no divergent sanitizer needed.
	 *
	 * @return void
	 */
	public function test_subclass_can_extend_the_secret_header_name_list(): void {
		$secret = 'secret-value-that-must-never-reach-the-log';

		$api = new Testable_Api_Base_With_Custom_Secret();
		$api->set_headers_for_test( [ 'X-Custom-Secret' => $secret ] );

		$headers = $api->get_sanitized_headers_for_test();

		$this->assertSame( str_repeat( '*', strlen( $secret ) ), $headers['X-Custom-Secret'] );
	}

	/**
	 * Membership is decided by header NAME, never by the value being truthy.
	 *
	 * The first version of this fix guarded the mask with `! empty( $value )`, and
	 * `empty()` is true for the string `'0'` — so a credential whose value happens
	 * to be `0` (a perfectly valid opaque token) was handed to the request logger
	 * in clear text. Found by the Codex critic pass on #288.
	 *
	 * @return void
	 */
	public function test_a_falsey_but_valid_credential_value_is_still_masked(): void {
		$api = new Testable_Api_Base();
		$api->set_headers_for_test( [ 'X-Secret' => '0' ] );

		$headers = $api->get_sanitized_headers_for_test();

		$this->assertSame( '*', $headers['X-Secret'], "a credential of '0' must be masked, not passed through" );
	}

	/**
	 * Masking a genuinely empty value is harmless: the mask is as long as the
	 * value, so the header still logs as empty rather than as a row of stars.
	 *
	 * Pinned so a future "optimisation" that skips empty values cannot quietly
	 * reintroduce the `'0'` hole above.
	 *
	 * @return void
	 */
	public function test_an_empty_credential_value_stays_empty(): void {
		$api = new Testable_Api_Base();
		$api->set_headers_for_test( [ 'Authorization' => '' ] );

		$headers = $api->get_sanitized_headers_for_test();

		$this->assertSame( '', $headers['Authorization'] );
	}

	/**
	 * A header that carries no credential passes through unmasked.
	 *
	 * @return void
	 */
	public function test_non_secret_header_passes_through_untouched(): void {
		$api = new Testable_Api_Base();
		$api->set_headers_for_test( [ 'Content-Type' => 'application/json' ] );

		$headers = $api->get_sanitized_headers_for_test();

		$this->assertSame( 'application/json', $headers['Content-Type'] );
	}

	// -------------------------------------------------------------------------
	// #300 — response-header sanitization. Before this fix, get_response_data_for_broadcast()
	// handed get_response_headers() straight to the broadcast, with no sanitizer
	// at all: not even the single hardcoded name the request side had before #288.
	// -------------------------------------------------------------------------

	/**
	 * `Set-Cookie` is the response-side header the card calls out explicitly: a
	 * carrier may hand back a session cookie that must never reach the log.
	 *
	 * @return void
	 */
	public function test_set_cookie_response_header_is_masked(): void {
		$cookie = 'sessionid=deadbeefcafefeed; Path=/; HttpOnly';

		$api = new Testable_Api_Base();
		$api->set_response_headers_for_test( [ 'Set-Cookie' => $cookie ] );

		$headers = $api->get_sanitized_response_headers_for_test();

		$this->assertSame( str_repeat( '*', strlen( $cookie ) ), $headers['Set-Cookie'] );
		$this->assertStringNotContainsString( $cookie, print_r( $headers, true ), 'the cookie leaked into the sanitized response headers' );
	}

	/**
	 * A token-refresh response header from the shared default list (the same list
	 * the request side uses) is masked symmetrically on the response side too.
	 *
	 * @return void
	 */
	public function test_default_list_masks_a_credential_header_on_the_response_side(): void {
		$refreshed_token = 'refreshed-token-value-that-must-never-reach-the-log';

		$api = new Testable_Api_Base();
		$api->set_response_headers_for_test( [ 'X-Auth-Token' => $refreshed_token ] );

		$headers = $api->get_sanitized_response_headers_for_test();

		$this->assertSame( str_repeat( '*', strlen( $refreshed_token ) ), $headers['X-Auth-Token'] );
	}

	/**
	 * The response-side match is case-insensitive and preserves original key casing,
	 * mirroring the request-side guarantee.
	 *
	 * @return void
	 */
	public function test_response_match_is_case_insensitive_but_preserves_original_key_casing(): void {
		$cookie = 'sessionid=deadbeefcafefeed';

		$api = new Testable_Api_Base();
		$api->set_response_headers_for_test( [ 'set-cookie' => $cookie ] );

		$headers = $api->get_sanitized_response_headers_for_test();

		$this->assertArrayHasKey( 'set-cookie', $headers );
		$this->assertArrayNotHasKey( 'Set-Cookie', $headers );
		$this->assertSame( str_repeat( '*', strlen( $cookie ) ), $headers['set-cookie'] );
	}

	/**
	 * A subclass extending get_secret_header_names() gets its custom header
	 * masked on the response side too — one seam, both directions.
	 *
	 * @return void
	 */
	public function test_subclass_extension_covers_the_response_side_too(): void {
		$secret = 'secret-value-that-must-never-reach-the-log';

		$api = new Testable_Api_Base_With_Custom_Secret();
		$api->set_response_headers_for_test( [ 'X-Custom-Secret' => $secret ] );

		$headers = $api->get_sanitized_response_headers_for_test();

		$this->assertSame( str_repeat( '*', strlen( $secret ) ), $headers['X-Custom-Secret'] );
	}

	/**
	 * A response header that carries no credential passes through unmasked.
	 *
	 * @return void
	 */
	public function test_non_secret_response_header_passes_through_untouched(): void {
		$api = new Testable_Api_Base();
		$api->set_response_headers_for_test( [ 'Content-Type' => 'application/json' ] );

		$headers = $api->get_sanitized_response_headers_for_test();

		$this->assertSame( 'application/json', $headers['Content-Type'] );
	}

	/**
	 * Before any response is received (e.g. the transport itself failed),
	 * get_response_headers() is `null`. The sanitizer must pass that through
	 * unchanged rather than coercing it into an array, so the broadcast payload
	 * shape for a failed request is unaffected by this fix.
	 *
	 * @return void
	 */
	public function test_null_response_headers_pass_through_unchanged(): void {
		$api = new Testable_Api_Base();

		$this->assertNull( $api->get_sanitized_response_headers_for_test() );
	}
}
