<?php
/**
 * Tests for the generic Ed25519 envelope verifier.
 *
 * Reproduces the published cross-repo test vector (woodev-core s126) byte-for-byte
 * and exercises the kid rule, strict base64, length checks, and the sodium-absent
 * safe-default path. NO site/plugin/time semantics here — those belong to callers.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';

/**
 * Class LicenseEnvelopeVerifierTest.
 */
class LicenseEnvelopeVerifierTest extends TestCase {

	/**
	 * The published test-vector public key (base64), seed = chr(1) x 32.
	 *
	 * @var string
	 */
	private const VECTOR_PUBKEY = 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=';

	/**
	 * The published test-vector signature (base64).
	 *
	 * @var string
	 */
	private const VECTOR_SIGNATURE = 'NPbp0Hce2UmggaOjNboRHhu4niepq/GdcBQDlHqIVl+3OJGuCy69sQdze4f97uhm4Hnny5EB3EcFfx1MbQb6DA==';

	/**
	 * The exact 120-byte canonical JSON of the vector payload.
	 *
	 * @var string
	 */
	private const VECTOR_CANONICAL = '{"expires_at":1750723200,"issued_at":1749513600,"license_required":false,"plugin_id":"216","site":"https://example.com"}';

	/**
	 * Aliases wp_json_encode to native json_encode.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);
	}

	/**
	 * The vector payload in insertion order (NOT sorted — canonical_json must sort it).
	 *
	 * @return array<string, mixed>
	 */
	private function vector_payload(): array {
		return array(
			'site'             => 'https://example.com',
			'plugin_id'        => '216',
			'license_required' => false,
			'issued_at'        => 1749513600,
			'expires_at'       => 1750723200,
		);
	}

	/**
	 * Skips a test when the libsodium extension is unavailable.
	 *
	 * CI explicitly enables ext-sodium (`extensions: sodium` in the setup-php steps
	 * of .github/workflows/ci.yml), and test_sodium_available_on_ci fails loudly if
	 * that ever regresses — so the cross-repo test vector is ALWAYS reproduced on CI.
	 * Only a local developer CLI without ext-sodium skips the keypair math; the
	 * verifier's safe-default (return null) is still covered by
	 * test_sodium_absent_returns_null.
	 *
	 * @return void
	 */
	private function require_sodium(): void {
		if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium not available in this PHP runtime.' );
		}
	}

	/**
	 * On CI, ext-sodium MUST be present — the vector tests must never silently skip.
	 *
	 * Always runs (never skips). Locally without ext-sodium it passes as a no-op;
	 * on CI (CI env var set by GitHub Actions) it fails loudly if sodium disappears
	 * from the workflow, because a skipped vector test would otherwise hide a broken
	 * crypto contract behind a green build.
	 *
	 * @return void
	 */
	public function test_sodium_available_on_ci(): void {
		if ( false === getenv( 'CI' ) ) {
			// Not on CI: nothing to enforce — local sodium-less CLIs stay green.
			$this->addToAssertionCount( 1 );

			return;
		}

		$this->assertTrue(
			function_exists( 'sodium_crypto_sign_verify_detached' ),
			'ext-sodium is missing on CI — the Ed25519 vector tests are being skipped silently. Restore `extensions: sodium` in .github/workflows/ci.yml.'
		);
	}

	/**
	 * Builds the fixture keypair from the published seed.
	 *
	 * @return array{0: string, 1: string} [ raw 32-byte pubkey, base64 pubkey ]
	 */
	private function fixture_keypair(): array {
		$keypair = sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) );
		$raw_pub = sodium_crypto_sign_publickey( $keypair );

		return array( $raw_pub, base64_encode( $raw_pub ) );
	}

	/**
	 * The fixture public key reproduces the published vector.
	 *
	 * @return void
	 */
	public function test_fixture_pubkey_matches_vector(): void {
		$this->require_sodium();

		[ , $pub_b64 ] = $this->fixture_keypair();

		$this->assertSame( self::VECTOR_PUBKEY, $pub_b64 );
	}

	/**
	 * canonical_json() produces the exact 120-byte published string.
	 *
	 * @return void
	 */
	public function test_canonical_json_matches_vector(): void {
		$canonical = \Woodev_License_Envelope_Verifier::canonical_json( $this->vector_payload() );

		$this->assertSame( self::VECTOR_CANONICAL, $canonical );
		$this->assertSame( 120, strlen( $canonical ) );
	}

	/**
	 * canonical_json() byte contract beyond the flat ASCII vector.
	 *
	 * Pins the exact bytes for nested sorting, SORT_STRING key order, unescaped
	 * UTF-8, numeric encoding, and empty-array values, so any drift in the
	 * canonicalization (the signed byte stream) breaks loudly.
	 *
	 * @dataProvider canonical_contract_provider
	 *
	 * @param array<string, mixed> $payload  Payload to canonicalize.
	 * @param string               $expected Exact expected canonical bytes.
	 * @return void
	 */
	public function test_canonical_json_contract( array $payload, string $expected ): void {
		$this->assertSame( $expected, \Woodev_License_Envelope_Verifier::canonical_json( $payload ) );
	}

	/**
	 * Canonicalization contract cases (expected byte strings pinned empirically).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function canonical_contract_provider(): array {
		return array(
			// Nested arrays are recursively key-sorted, not just the top level.
			'nested keys sorted recursively' => array(
				array(
					'b' => array(
						'z' => 1,
						'a' => 2,
					),
					'a' => 1,
				),
				'{"a":1,"b":{"a":2,"z":1}}',
			),
			// SORT_STRING order: "10" < "9" lexicographically (a default numeric
			// ksort would yield 9 before 10) — the cross-repo contract is string order.
			'sort_string not numeric order'  => array(
				array(
					'9'  => 'b',
					'10' => 'a',
				),
				'{"10":"a","9":"b"}',
			),
			// JSON_UNESCAPED_UNICODE: raw UTF-8 bytes, never \uXXXX escapes.
			'unicode value unescaped'        => array(
				array(
					'site' => 'https://example.com',
					'note' => 'Привет',
				),
				'{"note":"Привет","site":"https://example.com"}',
			),
			// Numeric contract: float 1.0 encodes as 1 — indistinguishable from int 1
			// (no JSON_PRESERVE_ZERO_FRACTION); fractional floats keep their fraction.
			// The server must emit ints for int-valued fields so signatures match.
			'int and float encoding'         => array(
				array(
					'i' => 1,
					'f' => 1.0,
					'g' => 1.5,
				),
				'{"f":1,"g":1.5,"i":1}',
			),
			// An empty PHP array encodes as [] (a JSON list), never {} — the recorded
			// contract for empty args containers.
			'empty array value is a list'    => array(
				array( 'args' => array() ),
				'{"args":[]}',
			),
		);
	}

	/**
	 * The published signature verifies and verify() returns the payload.
	 *
	 * @return void
	 */
	public function test_verify_happy_path(): void {
		$this->require_sodium();

		[ , $pub_b64 ] = $this->fixture_keypair();

		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => self::VECTOR_SIGNATURE,
		);

		$this->assertSame( $this->vector_payload(), \Woodev_License_Envelope_Verifier::verify( $envelope, $pub_b64 ) );
	}

	/**
	 * Signature over the canonical JSON matches what sodium produces (sanity).
	 *
	 * @return void
	 */
	public function test_signature_reproduces_vector(): void {
		$this->require_sodium();

		$keypair  = sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) );
		$secret   = sodium_crypto_sign_secretkey( $keypair );
		$canonical = \Woodev_License_Envelope_Verifier::canonical_json( $this->vector_payload() );

		$signature = base64_encode( sodium_crypto_sign_detached( $canonical, $secret ) );

		$this->assertSame( self::VECTOR_SIGNATURE, $signature );
	}

	/**
	 * A tampered payload byte → null.
	 *
	 * @return void
	 */
	public function test_tampered_payload_rejected(): void {
		$this->require_sodium();

		[ , $pub_b64 ] = $this->fixture_keypair();

		$payload                = $this->vector_payload();
		$payload['license_required'] = true; // flip the value the attacker wants.

		$envelope = array(
			'payload'   => $payload,
			'signature' => self::VECTOR_SIGNATURE,
		);

		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( $envelope, $pub_b64 ) );
	}

	/**
	 * A wrong (but valid-shape) public key → null.
	 *
	 * @return void
	 */
	public function test_wrong_key_rejected(): void {
		$this->require_sodium();

		$wrong_keypair = sodium_crypto_sign_seed_keypair( str_repeat( "\x02", 32 ) );
		$wrong_pub_b64 = base64_encode( sodium_crypto_sign_publickey( $wrong_keypair ) );

		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => self::VECTOR_SIGNATURE,
		);

		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( $envelope, $wrong_pub_b64 ) );
	}

	/**
	 * Loose (non-strict) base64 in the signature → null.
	 *
	 * @return void
	 */
	public function test_loose_base64_signature_rejected(): void {
		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => '!!!notb64',
		);

		// Rejected at the strict-base64 step, before any sodium call.
		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( $envelope, self::VECTOR_PUBKEY ) );
	}

	/**
	 * A signature that decodes to the wrong length (not 64 bytes) → null.
	 *
	 * @dataProvider bad_signature_length_provider
	 *
	 * @param int $length Decoded signature length to fabricate.
	 * @return void
	 */
	public function test_bad_signature_length_rejected( int $length ): void {
		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => base64_encode( str_repeat( "\x00", $length ) ),
		);

		// Rejected at the 64-byte length check, before any sodium call.
		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( $envelope, self::VECTOR_PUBKEY ) );
	}

	/**
	 * Off-by-one signature lengths.
	 *
	 * @return array<string, array{0: int}>
	 */
	public function bad_signature_length_provider(): array {
		return array(
			'63 bytes' => array( 63 ),
			'65 bytes' => array( 65 ),
		);
	}

	/**
	 * A non-array payload or non-string signature → null.
	 *
	 * @return void
	 */
	public function test_schema_sanity_rejected(): void {
		// All rejected at the schema-sanity step, before any sodium call.
		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( array( 'payload' => 'x', 'signature' => self::VECTOR_SIGNATURE ), self::VECTOR_PUBKEY ) );
		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( array( 'payload' => $this->vector_payload(), 'signature' => 123 ), self::VECTOR_PUBKEY ) );
		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( array( 'signature' => self::VECTOR_SIGNATURE ), self::VECTOR_PUBKEY ) );
	}

	/**
	 * A kid that does not match the derived key id → null.
	 *
	 * @return void
	 */
	public function test_kid_mismatch_rejected(): void {
		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => self::VECTOR_SIGNATURE,
			'kid'       => 'deadbeefdeadbeef',
		);

		// Rejected at the kid check, before any sodium call.
		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( $envelope, self::VECTOR_PUBKEY ) );
	}

	/**
	 * A PRESENT kid of any non-string shape → null (presence is binding).
	 *
	 * kid sits outside the signed payload: if `kid => null` were treated like an
	 * absent kid, an attacker could erase a mismatching kid without breaking the
	 * signature. Every present-but-invalid shape must reject.
	 *
	 * @dataProvider bad_kid_shape_provider
	 *
	 * @param mixed $kid Present kid value of an invalid shape.
	 * @return void
	 */
	public function test_present_kid_with_bad_shape_rejected( $kid ): void {
		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => self::VECTOR_SIGNATURE,
			'kid'       => $kid,
		);

		// Rejected at the kid check, before any sodium call.
		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( $envelope, self::VECTOR_PUBKEY ) );
	}

	/**
	 * Present-but-invalid kid shapes.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function bad_kid_shape_provider(): array {
		return array(
			'kid is null'  => array( null ),
			'kid is int'   => array( 123 ),
			'kid is array' => array( array() ),
		);
	}

	/**
	 * A kid matching the derived key id (first-16-hex of sha256 raw pubkey) → passes.
	 *
	 * @return void
	 */
	public function test_kid_match_passes(): void {
		$this->require_sodium();

		[ $raw_pub, $pub_b64 ] = $this->fixture_keypair();

		$kid = substr( hash( 'sha256', $raw_pub ), 0, 16 );

		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => self::VECTOR_SIGNATURE,
			'kid'       => $kid,
		);

		$this->assertSame( $this->vector_payload(), \Woodev_License_Envelope_Verifier::verify( $envelope, $pub_b64 ) );
	}

	/**
	 * An empty / placeholder public key (the default constant value) → null.
	 *
	 * @return void
	 */
	public function test_empty_pubkey_rejected(): void {
		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => self::VECTOR_SIGNATURE,
		);

		// Explicit empty key (mirrors the placeholder constant '').
		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( $envelope, '' ) );
	}

	/**
	 * A loose-base64 / wrong-length public key → null (key is not 32 raw bytes).
	 *
	 * @return void
	 */
	public function test_bad_pubkey_length_rejected(): void {
		$envelope = array(
			'payload'   => $this->vector_payload(),
			'signature' => self::VECTOR_SIGNATURE,
		);

		$this->assertNull( \Woodev_License_Envelope_Verifier::verify( $envelope, base64_encode( str_repeat( "\x00", 31 ) ) ) );
	}

	/**
	 * The sodium-absent path returns null without a fatal Error.
	 *
	 * Isolated in a separate process because PHP cannot un-define sodium once it
	 * exists in the running interpreter (gotcha testing/brain-monkey-function-pollution).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_sodium_absent_returns_null(): void {
		\Brain\Monkey\setUp();
		\Brain\Monkey\Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);

		// Force the verifier to take the "no sodium" branch deterministically.
		\Brain\Monkey\Functions\when( 'function_exists' )->alias(
			static function ( $name ) {
				if ( 'sodium_crypto_sign_verify_detached' === $name ) {
					return false;
				}

				return \function_exists( $name );
			}
		);

		require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';

		$envelope = array(
			'payload'   => array(
				'site'             => 'https://example.com',
				'plugin_id'        => '216',
				'license_required' => false,
				'issued_at'        => 1749513600,
				'expires_at'       => 1750723200,
			),
			'signature' => self::VECTOR_SIGNATURE,
		);

		$result = \Woodev_License_Envelope_Verifier::verify( $envelope, self::VECTOR_PUBKEY );

		\Brain\Monkey\tearDown();

		$this->assertNull( $result );
	}
}
