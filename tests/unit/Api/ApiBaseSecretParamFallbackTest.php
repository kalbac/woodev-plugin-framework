<?php
/**
 * Unit tests for Woodev_API_Base's fail-safe DEFAULT redaction — #395,
 * round-two critic fix (Blocking 1 & 2).
 *
 * The first round of the #395 fix (see LicensingApiRequestMaskingTest.php)
 * only made `Woodev_Licensing_API_Request` mask its own `license` param, via
 * an opt-in `get_path_safe()` / `to_string_safe()` seam on `Woodev_API_Base`.
 * An independent critic review rejected it: any OTHER request class — a
 * future one, or an existing third-party extension — that carries a
 * credential in its path/body but does NOT implement that opt-in seam still
 * logged it raw by default. Worse, `Woodev_API_Base::get_sanitized_request_uri()`
 * applied the `woodev_{api_id}_api_request_uri` filter AFTER masking, so a
 * filter that itself appended a secret-bearing param bypassed masking
 * entirely.
 *
 * This file pins the round-two fix using a minimal `Woodev_API_Request`
 * double that implements NEITHER `get_path_safe()` nor a masking
 * `to_string_safe()` — i.e. exactly the "unknown request class" scenario the
 * critic described:
 *
 * - {@see \Woodev_API_Base::get_sanitized_request_path()} masks a known
 *   secret param name even with no `get_path_safe()` to delegate to;
 * - {@see \Woodev_API_Base::get_sanitized_request_uri()} masks the same,
 *   end to end;
 * - a secret param appended by the `woodev_{api_id}_api_request_uri` filter
 *   itself is ALSO masked — proving redaction runs after the filter, not
 *   before;
 * - a non-secret param passes through the fallback untouched.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

/**
 * A minimal Woodev_API_Request implementation carrying a secret in its path
 * under a common param name, implementing NEITHER `get_path_safe()` nor a
 * masking `to_string_safe()` — the "unknown request class" scenario Blocking
 * 1 was about.
 */
class Testable_Unknown_Secret_Carrying_Request implements \Woodev_API_Request {

	/** @var string */
	private $path;

	/**
	 * @param string $path Raw request path, query string included.
	 */
	public function __construct( string $path ) {
		$this->path = $path;
	}

	/** @return string */
	public function get_method() {
		return 'GET';
	}

	/** @return string */
	public function get_path() {
		return $this->path;
	}

	/** @return string */
	public function to_string() {
		return '';
	}

	/**
	 * Deliberately unsafe — mirrors what any interface-conforming request
	 * class could still do before Woodev_API_JSON_Request's own default was
	 * fixed. Not exercised by the path/URI tests in this file, but present
	 * to satisfy the Woodev_API_Request interface.
	 *
	 * @return string
	 */
	public function to_string_safe() {
		return $this->to_string();
	}
}

/**
 * A minimal Woodev_API_Request implementation for the BLOCKING 2 body tests:
 * a POST request whose `to_string_safe()` deliberately aliases `to_string()`
 * — the "custom request class that forgot to mask its own body" scenario —
 * carrying a secret in a raw, non-JSON body shape.
 */
class Testable_Unknown_Secret_Carrying_Body_Request implements \Woodev_API_Request {

	/** @var string */
	private $body;

	/**
	 * @param string $body Raw request body, as `to_string()`/`to_string_safe()` return it.
	 */
	public function __construct( string $body ) {
		$this->body = $body;
	}

	/** @return string */
	public function get_method() {
		return 'POST';
	}

	/** @return string */
	public function get_path() {
		return '/v1/status';
	}

	/** @return string */
	public function to_string() {
		return $this->body;
	}

	/**
	 * Deliberately unsafe — mirrors what any interface-conforming request
	 * class could still do: alias `to_string_safe()` straight to `to_string()`
	 * without masking anything. This is the "the base promises body
	 * redaction and does not apply it" scenario Blocking 2 was about.
	 *
	 * @return string
	 */
	public function to_string_safe() {
		return $this->to_string();
	}
}

/**
 * Minimal concrete Woodev_API_Base double exposing the protected path/URI
 * sanitizers under test, without pulling in the HTTP/broadcast machinery
 * this fix does not touch.
 */
class Testable_Api_Base_For_Fallback_Test extends \Woodev_API_Base {

	public function __construct() {
		$this->request_uri = 'https://vendor.example';
	}

	/**
	 * Seeds the request under test.
	 *
	 * @param \Woodev_API_Request $request Request instance.
	 * @return void
	 */
	public function set_request_for_test( \Woodev_API_Request $request ): void {
		$this->request = $request;
	}

	/**
	 * Exposes the protected path sanitizer under test.
	 *
	 * @return string
	 */
	public function get_sanitized_request_path_for_test(): string {
		return $this->get_sanitized_request_path();
	}

	/**
	 * Exposes the protected URI sanitizer under test.
	 *
	 * @return string
	 */
	public function get_sanitized_request_uri_for_test(): string {
		return $this->get_sanitized_request_uri();
	}

	/**
	 * Exposes the protected body sanitizer under test.
	 *
	 * @return string
	 */
	public function get_sanitized_request_body_for_test() {
		return $this->get_sanitized_request_body();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_api_id() {
		return 'test_vendor';
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
 * Class ApiBaseSecretParamFallbackTest.
 */
final class ApiBaseSecretParamFallbackTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
	}

	/**
	 * @return void
	 */
	public function test_unknown_request_class_path_is_masked_by_default(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?token=' . $token . '&format=json' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_path = $api->get_sanitized_request_path_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_path,
			'BLOCKING 1: an unknown request class with no get_path_safe() must not leak its secret param by default'
		);
		$this->assertStringContainsString( 'format=json', $safe_path, 'a non-secret param must survive the fallback untouched' );
	}

	/**
	 * @return void
	 */
	public function test_unknown_request_class_uri_is_masked_by_default(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?token=' . $token );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_uri = $api->get_sanitized_request_uri_for_test();

		$this->assertStringNotContainsString( $token, $safe_uri, 'BLOCKING 1: the fallback URI must not leak the secret param either' );
	}

	/**
	 * BLOCKING 1, second half: a filter on the `woodev_{api_id}_api_request_uri`
	 * hook that ITSELF appends a secret-bearing param must not bypass masking
	 * — redaction must run AFTER the filter, not before.
	 *
	 * @return void
	 */
	public function test_a_secret_param_appended_by_the_uri_filter_is_also_masked(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status' );

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $token ) {

				if ( 'woodev_test_vendor_api_request_uri' === $tag ) {
					return $value . '?token=' . $token;
				}

				return $value;
			}
		);

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_uri = $api->get_sanitized_request_uri_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_uri,
			'a secret param appended by the uri filter must still be masked — redaction runs AFTER the filter'
		);
	}

	// -------------------------------------------------------------------------
	// #395 Round 3, BLOCKING 1: nested/percent-encoded/camelCase param names
	// must still be recognised, not only the exact flat spelling.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_percent_encoded_nested_param_name_is_masked_by_default(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?token%5Bprimary%5D=' . $token . '&format=json' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_path = $api->get_sanitized_request_path_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_path,
			'BLOCKING 1: http_build_query()-style token%5Bprimary%5D= must be masked, exactly the shape Woodev_Licensing_API_Request::get_path() can emit'
		);
		$this->assertStringContainsString( 'format=json', $safe_path, 'a non-secret param must survive untouched' );
	}

	/**
	 * @return void
	 */
	public function test_literal_bracket_nested_param_name_is_masked_by_default(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?token[primary]=' . $token );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_path = $api->get_sanitized_request_path_for_test();

		$this->assertStringNotContainsString( $token, $safe_path, 'BLOCKING 1: the un-encoded token[primary]= form must be masked too' );
	}

	/**
	 * @dataProvider provider_camel_case_secret_name_variants
	 *
	 * @param string $param_name camelCase variant of a name already on the default list.
	 * @return void
	 */
	public function test_camel_case_variant_of_a_listed_name_is_masked_by_default( string $param_name ): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?' . $param_name . '=' . $token );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_path = $api->get_sanitized_request_path_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_path,
			"BLOCKING 1: the camelCase spelling '{$param_name}' of a name already on the default list must be masked too"
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provider_camel_case_secret_name_variants(): array {
		return [
			'apiKey'     => [ 'apiKey' ],
			'licenseKey' => [ 'licenseKey' ],
			'accessToken' => [ 'accessToken' ],
		];
	}

	/**
	 * A param name that merely CONTAINS a secret name as a substring must not
	 * be mistaken for the secret param itself — the fix for BLOCKING 1 must
	 * not turn exact-name matching into substring matching.
	 *
	 * @return void
	 */
	public function test_a_param_name_merely_containing_a_secret_name_is_not_masked(): void {

		$value   = 'not-a-secret-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?mylicense=' . $value );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_path = $api->get_sanitized_request_path_for_test();

		$this->assertStringContainsString( $value, $safe_path, 'a param name that only CONTAINS "license" must not be masked' );
	}

	// -------------------------------------------------------------------------
	// #395 Round 3, BLOCKING 2: the base must apply its own fail-safe
	// redaction to whatever to_string_safe() returns, instead of trusting it
	// outright — a POST body from a request class that forgot to mask itself
	// must still not reach the log with a raw secret in it.
	// -------------------------------------------------------------------------

	/**
	 * A form-encoded (`name=value`) POST body from a request class whose
	 * `to_string_safe()` naively aliases `to_string()` must still be masked
	 * by the base's own fail-safe pass.
	 *
	 * @return void
	 */
	public function test_unknown_request_class_form_encoded_post_body_is_masked_by_default(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Body_Request( 'token=' . $token . '&format=json' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_body = (string) $api->get_sanitized_request_body_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_body,
			'BLOCKING 2: a POST body the request class did not mask itself must still not leak its secret param by default'
		);
		$this->assertStringContainsString( 'format=json', $safe_body, 'a non-secret param must survive the fallback untouched' );
	}

	/**
	 * An XML-shaped (`<name>value</name>`) POST body from a request class
	 * whose `to_string_safe()` masks nothing — exactly what
	 * {@see \Woodev_API_XML_Request::to_string_safe()} does today, it only
	 * prettifies the XML — must still be masked by the base's own fail-safe
	 * pass.
	 *
	 * @return void
	 */
	public function test_unknown_request_class_xml_post_body_is_masked_by_default(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Body_Request( '<Token>' . $token . '</Token><Format>json</Format>' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_body = (string) $api->get_sanitized_request_body_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_body,
			'BLOCKING 2: an XML-shaped POST body the request class did not mask itself must still not leak its secret param by default'
		);
		$this->assertStringContainsString( '<Format>json</Format>', $safe_body, 'a non-secret element must survive the fallback untouched' );
	}

	/**
	 * A GET/HEAD request never has a body — the fallback must not attempt to
	 * mask a body that {@see \Woodev_API_Base::get_sanitized_request_body()}
	 * short-circuits to `''` for.
	 *
	 * @return void
	 */
	public function test_get_request_body_stays_empty(): void {

		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?token=irrelevant' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$this->assertSame( '', $api->get_sanitized_request_body_for_test() );
	}

	// -------------------------------------------------------------------------
	// #395 Round 4, BLOCKING: a secret nested under a NON-secret param name
	// must still be masked — the round-3 matcher only ever compared the FIRST
	// bracket segment of a key against the denylist, so `a[token]=SECRET`
	// (literal or percent-encoded) sailed through untouched because only `a`
	// was checked, and `a` isn't itself a secret name.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_secret_nested_under_a_non_secret_param_name_is_masked_in_the_path(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?a[token]=' . $token . '&format=json' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_path = $api->get_sanitized_request_path_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_path,
			'#395 Round 4, BLOCKING: a[token]=SECRET must be masked even though the top-level name "a" is not itself a secret'
		);
		$this->assertStringContainsString( 'format=json', $safe_path, 'a non-secret sibling param must survive untouched' );
	}

	/**
	 * @return void
	 */
	public function test_percent_encoded_secret_nested_under_a_non_secret_param_name_is_masked_in_the_path(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?a%5Btoken%5D=' . $token . '&format=json' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_path = $api->get_sanitized_request_path_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_path,
			'#395 Round 4, BLOCKING: the percent-encoded a%5Btoken%5D=SECRET form must be masked too'
		);
		$this->assertStringContainsString( 'format=json', $safe_path, 'a non-secret sibling param must survive untouched' );
	}

	/**
	 * The same nesting gap, exercised via a form-encoded POST BODY instead of
	 * a query string — {@see \Woodev_API_Base::redact_secret_query_params()}
	 * is the shared matcher behind both call sites.
	 *
	 * @return void
	 */
	public function test_secret_nested_under_a_non_secret_param_name_is_masked_in_the_body(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Body_Request( 'a[token]=' . $token . '&format=json' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_body = (string) $api->get_sanitized_request_body_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_body,
			'#395 Round 4, BLOCKING: a[token]=SECRET must be masked in a body too'
		);
		$this->assertStringContainsString( 'format=json', $safe_body, 'a non-secret sibling param must survive untouched' );
	}

	// -------------------------------------------------------------------------
	// #395 Round 4, SHOULD-FIX 1: `+` (an encoded space in a query string) must
	// still be recognised as a separator between words in a param name, e.g.
	// `api+key` must match the same denylist entry as `api_key`/`apiKey`.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_plus_separated_secret_param_name_is_masked_in_the_path(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Request( '/v1/status?api+key=' . $token . '&format=json' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_path = $api->get_sanitized_request_path_for_test();

		$this->assertStringNotContainsString(
			$token,
			$safe_path,
			'#395 Round 4, SHOULD-FIX 1: api+key=SECRET must be masked — "+" is an encoded space, so this is the same name as api_key'
		);
		$this->assertStringContainsString( 'format=json', $safe_path, 'a non-secret sibling param must survive untouched' );
	}

	/**
	 * @return void
	 */
	public function test_plus_separated_secret_param_name_is_masked_in_the_body(): void {

		$token   = 'super-secret-token-value';
		$request = new Testable_Unknown_Secret_Carrying_Body_Request( 'api+key=' . $token . '&format=json' );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_body = (string) $api->get_sanitized_request_body_for_test();

		$this->assertStringNotContainsString( $token, $safe_body, '#395 Round 4, SHOULD-FIX 1: api+key=SECRET must be masked in a body too' );
		$this->assertStringContainsString( 'format=json', $safe_body, 'a non-secret sibling param must survive untouched' );
	}

	// -------------------------------------------------------------------------
	// #395 Round 4, SHOULD-FIX 2: a JSON body must be redacted by parsing it,
	// never by scanning its raw text for a `name=value` shape — the round-3
	// regex backstop could mistake a `name=value`-looking SUBSTRING inside an
	// unrelated string VALUE for a real param and truncate it, corrupting an
	// otherwise-valid JSON body it was never meant to touch.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_json_body_secret_key_is_masked_without_corrupting_the_json(): void {

		$secret = 'super-secret-token-value';
		$body   = json_encode(
			[
				'note'     => 'contact support, token=should-stay-exactly-here for reference',
				'password' => $secret,
				'item_id'  => 42,
			]
		);

		$request = new Testable_Unknown_Secret_Carrying_Body_Request( $body );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_body = (string) $api->get_sanitized_request_body_for_test();
		$decoded   = json_decode( $safe_body, true );

		$this->assertIsArray( $decoded, 'SHOULD-FIX 2: the redacted body must still be valid, parseable JSON' );
		$this->assertStringNotContainsString( $secret, $safe_body, 'the password value must not leak' );
		$this->assertSame(
			\Woodev_API_Base::SECRET_VALUE_MASK,
			$decoded['password'],
			'the password key must be masked by key, not by scanning the text'
		);
		$this->assertSame(
			'contact support, token=should-stay-exactly-here for reference',
			$decoded['note'],
			'SHOULD-FIX 2: a name=value-looking SUBSTRING inside an unrelated string value must survive untouched'
		);
		$this->assertSame( 42, $decoded['item_id'], 'a non-secret param must survive untouched' );
	}

	/**
	 * A secret nested inside a JSON object (not only at the top level) must
	 * also be masked — the structural JSON walk redacts by key at ANY depth,
	 * same as the query-string walk.
	 *
	 * @return void
	 */
	public function test_json_body_nested_secret_key_is_masked(): void {

		$secret  = 'super-secret-token-value';
		$body    = json_encode(
			[
				'auth' => [
					'token' => $secret,
				],
				'format' => 'json',
			]
		);
		$request = new Testable_Unknown_Secret_Carrying_Body_Request( $body );

		$api = new Testable_Api_Base_For_Fallback_Test();
		$api->set_request_for_test( $request );

		$safe_body = (string) $api->get_sanitized_request_body_for_test();
		$decoded   = json_decode( $safe_body, true );

		$this->assertIsArray( $decoded, 'the redacted body must still be valid JSON' );
		$this->assertStringNotContainsString( $secret, $safe_body );
		$this->assertSame( \Woodev_API_Base::SECRET_VALUE_MASK, $decoded['auth']['token'] );
		$this->assertSame( 'json', $decoded['format'], 'a non-secret sibling param must survive untouched' );
	}
}
