<?php
/**
 * Unit tests for Woodev_API_Base::get_sanitized_response_body() /
 * get_response_data_for_broadcast() — #427.
 *
 * #395 built a fail-safe-by-default redactor for the REQUEST side of
 * {@see \Woodev_API_Base}: {@see \Woodev_API_Base::get_sanitized_request_body()}
 * runs whatever `to_string_safe()` returns through
 * {@see \Woodev_API_Base::redact_secret_request_body()} unconditionally, so an
 * unknown/future request class that never masks its own body still cannot leak
 * a credential by default. The RESPONSE side had no such pass at all: every
 * concrete response class in this codebase ({@see \Woodev_API_JSON_Response},
 * {@see \Woodev_API_XML_Response}) aliases `to_string_safe()` straight onto the
 * raw `to_string()`, and {@see \Woodev_API_Base::get_sanitized_response_body()}
 * just returned that unredacted value.
 *
 * Worse, {@see \Woodev_API_Base::get_response_data_for_broadcast()} built the
 * `body` field as `get_sanitized_response_body() ?: get_raw_response_body()` —
 * so ANY response whose sanitized body was falsy (no response object yet, a
 * class with no `to_string_safe()`, or one returning `''`/`'0'`) fell straight
 * through to {@see \Woodev_API_Base::get_raw_response_body()}: the untouched
 * bytes off the wire, in exactly the case redaction exists to cover.
 *
 * This file pins the #427 fix:
 *
 * - a response body carrying a secret at the top level is masked, end to end,
 *   through {@see \Woodev_API_Base::get_response_data_for_broadcast()};
 * - a falsy-but-real `to_string_safe()` return value (`''`, `'0'`) no longer
 *   triggers the raw-body fallback — the second, undocumented leak behind #427;
 * - a JSON response body is walked structurally: the secret is redacted at
 *   depth, a harmless sibling stays readable;
 * - an unparseable response body (XML, a `print_r()` dump, free text) is
 *   whole-masked, the same fail-safe the request side already gets;
 * - the actual response object the caller receives — the wire bytes — is
 *   byte-for-byte unaffected: redaction is a LOG concern only.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

/**
 * A minimal Woodev_API_Response implementation carrying a secret in its body,
 * whose `to_string_safe()` deliberately aliases `to_string()` — exactly what
 * {@see \Woodev_API_JSON_Response::to_string_safe()} and
 * {@see \Woodev_API_XML_Response::to_string_safe()} do today (#427): a
 * response class that never masks its own body.
 */
class Testable_Unknown_Secret_Carrying_Response implements \Woodev_API_Response {

	/** @var string */
	private $body;

	/**
	 * @param string $body Raw response body, as `to_string()`/`to_string_safe()` return it.
	 */
	public function __construct( string $body ) {
		$this->body = $body;
	}

	/** @return string */
	public function to_string() {
		return $this->body;
	}

	/**
	 * Deliberately unsafe — mirrors what a response class in this codebase
	 * does today: alias `to_string_safe()` straight to `to_string()` without
	 * masking anything.
	 *
	 * @return string
	 */
	public function to_string_safe() {
		return $this->to_string();
	}
}

/**
 * A Woodev_API_Response double whose `to_string_safe()` returns a FALSY value
 * (`''` or `'0'`) that is nonetheless a deliberate return value, not "no
 * response" — the second #427 defect: the pre-fix `?:` fallback in
 * {@see \Woodev_API_Base::get_response_data_for_broadcast()} could not tell
 * this apart from "sanitization produced nothing", and fell back to the raw
 * body either way.
 */
class Testable_Falsy_To_String_Safe_Response implements \Woodev_API_Response {

	/** @var string */
	private $safe_value;

	/**
	 * @param string $safe_value The falsy value `to_string_safe()` returns.
	 */
	public function __construct( string $safe_value ) {
		$this->safe_value = $safe_value;
	}

	/** @return string */
	public function to_string() {
		return $this->safe_value;
	}

	/** @return string */
	public function to_string_safe() {
		return $this->safe_value;
	}
}

/**
 * Minimal concrete Woodev_API_Base double exposing the protected response-body
 * sanitizer and broadcast payload under test, without pulling in the
 * HTTP/broadcast machinery this fix does not touch.
 */
class Testable_Api_Base_For_Response_Body_Test extends \Woodev_API_Base {

	/**
	 * Seeds the response under test, bypassing the HTTP transport.
	 *
	 * @param object $response Response instance.
	 * @return void
	 */
	public function set_response_for_test( object $response ): void {
		$this->response = $response;
	}

	/**
	 * Seeds the raw response body under test — the untouched bytes off the
	 * wire, as {@see \Woodev_API_Base::handle_response()} would have stored
	 * them.
	 *
	 * @param string $raw_body Raw response body.
	 * @return void
	 */
	public function set_raw_response_body_for_test( string $raw_body ): void {
		$this->raw_response_body = $raw_body;
	}

	/**
	 * Exposes the protected response-body sanitizer under test.
	 *
	 * @return string
	 */
	public function get_sanitized_response_body_for_test(): string {
		return $this->get_sanitized_response_body();
	}

	/**
	 * Exposes the protected response broadcast payload under test, end to end
	 * — i.e. through {@see \Woodev_API_Base::get_response_data_for_broadcast()}
	 * itself, not just the sanitizer it is supposed to call.
	 *
	 * @return array<string, mixed>
	 */
	public function get_response_data_for_broadcast_for_test(): array {
		return $this->get_response_data_for_broadcast();
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
 * Class ApiBaseSanitizedResponseBodyTest.
 */
final class ApiBaseSanitizedResponseBodyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
	}

	// -------------------------------------------------------------------------
	// A response body carrying a secret must not reach the log intact.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_unknown_response_class_json_body_secret_is_masked_by_default(): void {

		$secret   = 'super-secret-token-value';
		$body     = json_encode(
			[
				'token'   => $secret,
				'item_id' => 42,
			]
		);
		$response = new Testable_Unknown_Secret_Carrying_Response( (string) $body );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );

		$broadcast = $api->get_response_data_for_broadcast_for_test();

		$this->assertArrayHasKey( 'body', $broadcast );
		$this->assertStringNotContainsString(
			$secret,
			$broadcast['body'],
			'a response body carrying a secret must not reach the broadcast payload intact'
		);
		$this->assertStringNotContainsString(
			$secret,
			print_r( $broadcast, true ),
			'the secret leaked into the response broadcast payload'
		);
	}

	// -------------------------------------------------------------------------
	// #427, second defect: a falsy-but-real to_string_safe() must not fall
	// back to the raw, never-redacted response body.
	// -------------------------------------------------------------------------

	/**
	 * `to_string_safe()` returning `''` used to trigger the raw-body fallback.
	 * The raw body (what actually came back over the wire) carries a secret;
	 * the broadcast payload must still not contain it — an empty sanitized
	 * body must log as empty, never as "fall back to raw".
	 *
	 * @return void
	 */
	public function test_empty_to_string_safe_does_not_fall_back_to_the_raw_body(): void {

		$secret   = 'super-secret-token-value';
		$response = new Testable_Falsy_To_String_Safe_Response( '' );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );
		$api->set_raw_response_body_for_test( 'token=' . $secret );

		$broadcast = $api->get_response_data_for_broadcast_for_test();

		$this->assertSame( '', $broadcast['body'], 'an empty sanitized body must log as empty, not fall back to the raw body' );
		$this->assertStringNotContainsString(
			$secret,
			$broadcast['body'],
			'#427: an empty to_string_safe() must not fall back to the raw, unredacted response body'
		);
	}

	/**
	 * `to_string_safe()` returning the string `'0'` is the classic PHP
	 * falsy-but-valid-value trap (`'0' == false`). The raw body carries a
	 * secret; the broadcast payload must still not contain it.
	 *
	 * @return void
	 */
	public function test_falsy_string_zero_to_string_safe_does_not_fall_back_to_the_raw_body(): void {

		$secret   = 'super-secret-token-value';
		$response = new Testable_Falsy_To_String_Safe_Response( '0' );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );
		$api->set_raw_response_body_for_test( 'token=' . $secret );

		$broadcast = $api->get_response_data_for_broadcast_for_test();

		$this->assertStringNotContainsString(
			$secret,
			$broadcast['body'],
			"#427: a to_string_safe() of '0' must not fall back to the raw, unredacted response body"
		);
		$this->assertSame(
			\Woodev_API_Base::UNPARSEABLE_BODY_MASK,
			$broadcast['body'],
			"a JSON scalar body ('0' decodes to int 0) is masked in full, same rule as the request side"
		);
	}

	/**
	 * No response object at all (e.g. the transport itself failed, before a
	 * response was ever parsed) must not fall back to the raw body either.
	 *
	 * @return void
	 */
	public function test_no_response_object_does_not_fall_back_to_the_raw_body(): void {

		$secret = 'super-secret-token-value';

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_raw_response_body_for_test( 'token=' . $secret );

		$broadcast = $api->get_response_data_for_broadcast_for_test();

		$this->assertSame( '', $broadcast['body'] );
		$this->assertStringNotContainsString( $secret, $broadcast['body'] );
	}

	// -------------------------------------------------------------------------
	// A JSON response object: the secret is redacted at depth, harmless
	// siblings stay READABLE. A whole-masking regression would pass a naive
	// test and destroy the log's usefulness — this test would catch it.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_json_response_body_secret_key_is_masked_without_corrupting_the_json(): void {

		$secret = 'super-secret-token-value';
		$body   = json_encode(
			[
				'note'     => 'contact support, token=should-stay-exactly-here for reference',
				'password' => $secret,
				'item_id'  => 42,
			]
		);
		$response = new Testable_Unknown_Secret_Carrying_Response( (string) $body );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );

		$safe_body = $api->get_sanitized_response_body_for_test();
		$decoded   = json_decode( $safe_body, true );

		$this->assertIsArray( $decoded, 'the redacted response body must still be valid, parseable JSON' );
		$this->assertStringNotContainsString( $secret, $safe_body, 'the password value must not leak' );
		$this->assertSame(
			\Woodev_API_Base::SECRET_VALUE_MASK,
			$decoded['password'],
			'the password key must be masked by key, not by scanning the text'
		);
		$this->assertSame(
			'contact support, token=should-stay-exactly-here for reference',
			$decoded['note'],
			'a non-secret string value must survive untouched, readable, in the log'
		);
		$this->assertSame( 42, $decoded['item_id'], 'a non-secret param must survive untouched' );
	}

	/**
	 * A secret nested inside a JSON object (not only at the top level) must
	 * also be masked, with the non-secret sibling still readable.
	 *
	 * @return void
	 */
	public function test_json_response_body_nested_secret_key_is_masked(): void {

		$secret = 'super-secret-token-value';
		$body   = json_encode(
			[
				'auth' => [
					'access_token' => $secret,
				],
				'status' => 'active',
			]
		);
		$response = new Testable_Unknown_Secret_Carrying_Response( (string) $body );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );

		$safe_body = $api->get_sanitized_response_body_for_test();
		$decoded   = json_decode( $safe_body, true );

		$this->assertIsArray( $decoded, 'the redacted response body must still be valid JSON' );
		$this->assertStringNotContainsString( $secret, $safe_body );
		$this->assertSame( \Woodev_API_Base::SECRET_VALUE_MASK, $decoded['auth']['access_token'] );
		$this->assertSame( 'active', $decoded['status'], 'a non-secret sibling must survive untouched, readable, in the log' );
	}

	// -------------------------------------------------------------------------
	// An unparseable response body (XML, print_r(), free text) is whole-masked
	// — the base has no reliable way to tell a secret-named field apart from a
	// safe sibling one in a format it cannot parse and walk structurally.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_xml_response_body_is_masked_by_default(): void {

		$secret   = 'super-secret-token-value';
		$response = new Testable_Unknown_Secret_Carrying_Response( '<Token>' . $secret . '</Token><Status>active</Status>' );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );

		$safe_body = $api->get_sanitized_response_body_for_test();

		$this->assertStringNotContainsString( $secret, $safe_body );
		$this->assertSame( \Woodev_API_Base::UNPARSEABLE_BODY_MASK, $safe_body );
	}

	/**
	 * A `print_r()`-shaped body — the exact shape a licensing-style response
	 * class could produce if it ever stopped being JSON — is masked in full.
	 *
	 * @return void
	 */
	public function test_print_r_shaped_response_body_is_masked_by_default(): void {

		$secret   = 'super-secret-token-value';
		$response = new Testable_Unknown_Secret_Carrying_Response( print_r( [ 'token' => $secret, 'status' => 'active' ], true ) );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );

		$safe_body = $api->get_sanitized_response_body_for_test();

		$this->assertStringNotContainsString( $secret, $safe_body );
		$this->assertSame( \Woodev_API_Base::UNPARSEABLE_BODY_MASK, $safe_body );
	}

	/**
	 * @return void
	 */
	public function test_free_text_response_body_is_masked_by_default(): void {

		$secret   = 'super-secret-token-value';
		$response = new Testable_Unknown_Secret_Carrying_Response( "request failed\ntoken=" . $secret );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );

		$safe_body = $api->get_sanitized_response_body_for_test();

		$this->assertStringNotContainsString( $secret, $safe_body );
		$this->assertSame( \Woodev_API_Base::UNPARSEABLE_BODY_MASK, $safe_body );
	}

	// -------------------------------------------------------------------------
	// Redaction is a LOG concern only: the response object the caller actually
	// receives must be byte-for-byte unaffected.
	// -------------------------------------------------------------------------

	/**
	 * The response object's own `to_string()` — what a caller like
	 * {@see \Woodev_Licencing_API_Response::get_response_data()} actually
	 * reads — must still carry the real secret. Only the broadcast (logging)
	 * payload is redacted.
	 *
	 * @return void
	 */
	public function test_response_object_to_string_is_unaffected_by_broadcast_masking(): void {

		$secret   = 'super-secret-token-value';
		$body     = json_encode( [ 'token' => $secret ] );
		$response = new Testable_Unknown_Secret_Carrying_Response( (string) $body );

		$api = new Testable_Api_Base_For_Response_Body_Test();
		$api->set_response_for_test( $response );

		$broadcast = $api->get_response_data_for_broadcast_for_test();

		$this->assertStringContainsString(
			$secret,
			$response->to_string(),
			'the actual response object must still carry the real secret — redaction must not touch it'
		);
		$this->assertStringNotContainsString(
			$secret,
			$broadcast['body'],
			'only the broadcast (logging) payload may be redacted'
		);
	}
}
