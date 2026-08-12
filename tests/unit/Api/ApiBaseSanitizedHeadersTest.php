<?php
/**
 * Unit tests for Woodev_API_Base::get_sanitized_request_headers() /
 * get_secret_request_header_names() — issue #288.
 *
 * Woodev_API_Base::broadcast_request() hands the sanitized request headers to the
 * documented `woodev_{api_id}_api_request_performed` action, i.e. to any attached
 * request logger. Before this fix, the sanitizer masked ONLY the `Authorization`
 * header by exact key match; any other credential header (e.g. a vendor-specific
 * `X-Secret`) rode the broadcast in plaintext. This file pins:
 *
 * - `Authorization` stays masked (regression guard — the payment-gateway tree
 *   shares this base class);
 * - every other header name in the default {@see \Woodev_API_Base::get_secret_request_header_names()}
 *   list is masked too;
 * - the match is case-insensitive, while the returned array preserves the
 *   ORIGINAL key casing the request actually sent;
 * - a subclass can extend the list with its own header name;
 * - a non-secret header passes through untouched.
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
	 * Exposes the protected sanitizer under test.
	 *
	 * @return array<string, string>
	 */
	public function get_sanitized_headers_for_test(): array {
		return $this->get_sanitized_request_headers();
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
 * base class does not know about — proves the extension seam works.
 */
class Testable_Api_Base_With_Custom_Secret extends Testable_Api_Base {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	protected function get_secret_request_header_names(): array {
		return array_merge( parent::get_secret_request_header_names(), [ 'X-Custom-Secret' ] );
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
	 * A subclass extending get_secret_request_header_names() gets its custom
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
}
