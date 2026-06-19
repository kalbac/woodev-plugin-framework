<?php
/**
 * Unit tests for Woodev_Account_Signer.
 *
 * Byte-exactness guard: the signature must equal an independently-computed HMAC
 * over the documented canonical payload (host, request_uri, method-upper, body,
 * timestamp) — the round-trip cross-check against the connector's Signer.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-signer.php';

/**
 * @covers \Woodev_Account_Signer
 */
final class AccountSignerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// wp_json_encode == json_encode for these ASCII payloads.
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
	}

	public function test_sign_matches_documented_contract_byte_for_byte(): void {
		$parts = array(
			'host'        => 'localhost:8090',
			'request_uri' => '/wp-json/woodev-account/v1/oauth/me',
			'method'      => 'get',           // lower-case on purpose: must be upper-cased.
			'body'        => '',
			'timestamp'   => '1750000000',
		);
		$key = 'secret-key-abc';

		// Independent expectation computed exactly as the connector documents.
		$expected = hash_hmac(
			'sha256',
			json_encode(
				array(
					'host'        => 'localhost:8090',
					'request_uri' => '/wp-json/woodev-account/v1/oauth/me',
					'method'      => 'GET',
					'body'        => '',
					'timestamp'   => '1750000000',
				)
			),
			$key
		);

		$this->assertSame( $expected, \Woodev_Account_Signer::sign( $parts, $key ) );
	}

	public function test_sign_includes_body_and_is_key_order_stable(): void {
		// Parts supplied OUT OF ORDER must still produce the fixed-order payload.
		$parts = array(
			'timestamp'   => '42',
			'body'        => '{"request_token":"abc","home_url":"http:\/\/x"}',
			'method'      => 'POST',
			'request_uri' => '/wp-json/woodev-account/v1/oauth/access_token',
			'host'        => 'woodev.ru',
		);
		$key = 'k';

		$expected = hash_hmac(
			'sha256',
			json_encode(
				array(
					'host'        => 'woodev.ru',
					'request_uri' => '/wp-json/woodev-account/v1/oauth/access_token',
					'method'      => 'POST',
					'body'        => '{"request_token":"abc","home_url":"http:\/\/x"}',
					'timestamp'   => '42',
				)
			),
			$key
		);

		$this->assertSame( $expected, \Woodev_Account_Signer::sign( $parts, $key ) );
	}

	public function test_missing_parts_default_to_empty_string(): void {
		$expected = hash_hmac(
			'sha256',
			json_encode(
				array( 'host' => '', 'request_uri' => '', 'method' => '', 'body' => '', 'timestamp' => '' )
			),
			'k'
		);

		$this->assertSame( $expected, \Woodev_Account_Signer::sign( array(), 'k' ) );
	}
}
