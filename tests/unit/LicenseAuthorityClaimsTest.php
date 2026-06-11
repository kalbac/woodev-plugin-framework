<?php
/**
 * Tests for the §4 signed-claim store Woodev_License_Authority_Claims.
 *
 * Exercises consume_from_response() / get_verified() / verify_claim(). The claim is
 * the SOLE thing that can make is_license_required() return false, so every test
 * pins the safe-default direction: ANY doubt → license required, never store an
 * unverified envelope, never delete a previously-stored valid claim on a bad refresh.
 *
 * Envelopes are signed at RUNTIME with the published fixture keypair (seed = chr(1)
 * x 32) so each test controls expires_at relative to the real clock — the published
 * vector's fixed expiry is in the past, and time() is not stubbable here (it is a
 * PHP internal not in patchwork.json). Cross-repo canonical-JSON / signature parity
 * is pinned by LicenseEnvelopeVerifierTest against the published vector; this suite
 * pins the claim SEMANTICS (site/plugin/expiry binding + store IO) on top of it.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-authority-claims.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';

/**
 * Class LicenseAuthorityClaimsTest.
 */
class LicenseAuthorityClaimsTest extends TestCase {

	/**
	 * The published test-vector public key (base64), seed = chr(1) x 32.
	 *
	 * @var string
	 */
	private const VECTOR_PUBKEY = 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=';

	/**
	 * The option name the test plugin's claim is stored under.
	 *
	 * @var string
	 */
	private const CLAIM_OPTION = 'woodev_test_plugin_license_required';

	/**
	 * Aliases the WP helpers the normalizer + verifier need (pure, no WP loaded).
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
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return \parse_url( (string) $url );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );

		// Inject the fixture pubkey through the store's test seam: verify() reads the
		// embedded constant ('') by default, but the store applies the
		// woodev_license_authority_pubkey filter so tests can supply the fixture key.
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) {
				if ( 'woodev_license_authority_pubkey' === $hook ) {
					return self::VECTOR_PUBKEY;
				}

				return $value;
			}
		);
	}

	/**
	 * Skips a test when the libsodium extension is unavailable.
	 *
	 * Mirrors LicenseEnvelopeVerifierTest::require_sodium — CI enables ext-sodium so
	 * the binding semantics are always exercised there; a local sodium-less CLI skips
	 * the signing math (the safe-default no-store paths below run regardless).
	 *
	 * @return void
	 */
	private function require_sodium(): void {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			$this->markTestSkipped( 'ext-sodium not available in this PHP runtime.' );
		}
	}

	/**
	 * Builds a payload bound to example.com / plugin 216 at the given expiry window.
	 *
	 * @param int  $expires_at       The expiry timestamp.
	 * @param bool $license_required Whether the product needs a license.
	 * @param int  $issued_at        The issued-at timestamp.
	 * @return array<string, mixed>
	 */
	private function payload( int $expires_at, bool $license_required = false, int $issued_at = 1749513600 ): array {
		return array(
			'site'             => 'https://example.com',
			'plugin_id'        => '216',
			'license_required' => $license_required,
			'issued_at'        => $issued_at,
			'expires_at'       => $expires_at,
		);
	}

	/**
	 * Signs a payload with the fixture secret key into a { payload, signature } envelope.
	 *
	 * @param array<string, mixed> $payload The payload to sign.
	 * @return array<string, mixed>
	 */
	private function sign( array $payload ): array {
		$keypair   = sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) );
		$secret    = sodium_crypto_sign_secretkey( $keypair );
		$canonical = \Woodev_License_Envelope_Verifier::canonical_json( $payload );

		return array(
			'payload'   => $payload,
			'signature' => base64_encode( sodium_crypto_sign_detached( $canonical, $secret ) ),
		);
	}

	/**
	 * A far-future expiry so a runtime-signed claim is unexpired against real time().
	 *
	 * @return int
	 */
	private function far_future(): int {
		return time() + ( 10 * YEAR_IN_SECONDS );
	}

	/**
	 * Builds a claims store bound to a plugin stub at the given download id.
	 *
	 * @param int $download_id The plugin download id.
	 * @return \Woodev_License_Authority_Claims
	 */
	private function make_claims( int $download_id = 216 ): \Woodev_License_Authority_Claims {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_plugin_option_name' )->with( 'license_required' )->andReturn( self::CLAIM_OPTION )->byDefault();
		$plugin->shouldReceive( 'get_download_id' )->andReturn( $download_id )->byDefault();

		return new \Woodev_License_Authority_Claims( $plugin );
	}

	/**
	 * A fully verified, unexpired claim is stored under the per-plugin option,
	 * autoload false, value = the raw envelope array (never a bare boolean).
	 *
	 * @return void
	 */
	public function test_consume_stores_envelope_after_full_verification(): void {
		$this->require_sodium();

		$claims   = $this->make_claims();
		$envelope = $this->sign( $this->payload( $this->far_future() ) );

		Functions\expect( 'update_option' )->once()->with( self::CLAIM_OPTION, $envelope, false );

		$claims->consume_from_response( (object) array( 'license_authority' => $envelope ) );
	}

	/**
	 * A stdClass envelope (json object) is converted to an array before verification
	 * and stored as the array form.
	 *
	 * @return void
	 */
	public function test_consume_converts_object_envelope_to_array(): void {
		$this->require_sodium();

		$claims        = $this->make_claims();
		$array_form    = $this->sign( $this->payload( $this->far_future() ) );
		$object_form   = (object) array(
			'payload'   => (object) $array_form['payload'],
			'signature' => $array_form['signature'],
		);

		Functions\expect( 'update_option' )->once()->with( self::CLAIM_OPTION, $array_form, false );

		$claims->consume_from_response( array( 'license_authority' => $object_form ) );
	}

	/**
	 * An absent license_authority key is a no-op: the stored claim is KEPT
	 * (last-known-good within the outage window).
	 *
	 * @return void
	 */
	public function test_consume_absent_key_is_noop(): void {
		$claims = $this->make_claims();

		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		$claims->consume_from_response( (object) array( 'something_else' => 1 ) );
		$claims->consume_from_response( array() );
		$claims->consume_from_response( null );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A tampered payload never stores (and never deletes the previous claim).
	 *
	 * @return void
	 */
	public function test_consume_tampered_payload_does_not_store(): void {
		$this->require_sodium();

		$claims = $this->make_claims();

		$envelope                              = $this->sign( $this->payload( $this->far_future() ) );
		$envelope['payload']['license_required'] = true; // flip the value AFTER signing.

		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		$claims->consume_from_response( (object) array( 'license_authority' => $envelope ) );
	}

	/**
	 * A claim bound to a different site never stores.
	 *
	 * @return void
	 */
	public function test_consume_wrong_site_does_not_store(): void {
		$this->require_sodium();

		// home_url() resolves elsewhere → the site binding fails despite a valid sig.
		Functions\when( 'home_url' )->justReturn( 'https://attacker.example/' );

		$claims   = $this->make_claims();
		$envelope = $this->sign( $this->payload( $this->far_future() ) );

		Functions\expect( 'update_option' )->never();

		$claims->consume_from_response( (object) array( 'license_authority' => $envelope ) );
	}

	/**
	 * A claim whose plugin_id does not match this plugin's download id never stores.
	 *
	 * @return void
	 */
	public function test_consume_wrong_plugin_does_not_store(): void {
		$this->require_sodium();

		$claims   = $this->make_claims( 999 ); // 999 ≠ payload plugin_id '216'.
		$envelope = $this->sign( $this->payload( $this->far_future() ) );

		Functions\expect( 'update_option' )->never();

		$claims->consume_from_response( (object) array( 'license_authority' => $envelope ) );
	}

	/**
	 * An expired claim never stores at consume time (now > expires_at).
	 *
	 * @return void
	 */
	public function test_consume_expired_claim_does_not_store(): void {
		$this->require_sodium();

		$claims   = $this->make_claims();
		$envelope = $this->sign( $this->payload( time() - HOUR_IN_SECONDS ) ); // already expired.

		Functions\expect( 'update_option' )->never();

		$claims->consume_from_response( (object) array( 'license_authority' => $envelope ) );
	}

	/**
	 * get_verified() returns the payload for a valid, unexpired stored claim.
	 *
	 * @return void
	 */
	public function test_get_verified_returns_payload_when_valid(): void {
		$this->require_sodium();

		$payload  = $this->payload( $this->far_future() );
		$envelope = $this->sign( $payload );

		$claims = $this->make_claims();

		Functions\when( 'get_option' )->justReturn( $envelope );

		$this->assertSame( $payload, $claims->get_verified() );
	}

	/**
	 * get_verified() returns null when no claim is stored (absent option).
	 *
	 * @return void
	 */
	public function test_get_verified_null_when_absent(): void {
		$claims = $this->make_claims();

		Functions\when( 'get_option' )->justReturn( false );

		$this->assertNull( $claims->get_verified() );
	}

	/**
	 * get_verified() returns null for a stored-but-expired claim (grace boundary).
	 *
	 * @return void
	 */
	public function test_get_verified_null_when_expired(): void {
		$this->require_sodium();

		$envelope = $this->sign( $this->payload( time() - 1 ) ); // expired one second ago.

		$claims = $this->make_claims();

		Functions\when( 'get_option' )->justReturn( $envelope );

		$this->assertNull( $claims->get_verified() );
	}

	/**
	 * get_verified() honors the inclusive boundary: now === expires_at is still valid.
	 *
	 * @return void
	 */
	public function test_get_verified_valid_at_exact_expiry_boundary(): void {
		$this->require_sodium();

		// expires_at exactly equals the time() the store will read. Both calls resolve
		// within the same second under the unit runtime, so now <= expires_at holds.
		$now      = time();
		$envelope = $this->sign( $this->payload( $now ) );

		$claims = $this->make_claims();

		Functions\when( 'get_option' )->justReturn( $envelope );

		$this->assertSame( $envelope['payload'], $claims->get_verified() );
	}

	/**
	 * get_verified() returns null for a tampered-at-rest stored claim (re-verifies).
	 *
	 * @return void
	 */
	public function test_get_verified_null_when_tampered_at_rest(): void {
		$this->require_sodium();

		$envelope                                = $this->sign( $this->payload( $this->far_future() ) );
		$envelope['payload']['license_required'] = true; // attacker flips the stored option.

		$claims = $this->make_claims();

		Functions\when( 'get_option' )->justReturn( $envelope );

		$this->assertNull( $claims->get_verified() );
	}

	/**
	 * get_verified() returns null when the stored option is not an array (guard).
	 *
	 * @return void
	 */
	public function test_get_verified_null_when_option_not_array(): void {
		$claims = $this->make_claims();

		Functions\when( 'get_option' )->justReturn( 'corrupted-string' );

		$this->assertNull( $claims->get_verified() );
	}

	/**
	 * get_verified() memoizes per request: the second call performs ZERO further
	 * option reads (and thus zero sodium verifies). Proven by counting get_option().
	 *
	 * @return void
	 */
	public function test_get_verified_memoizes_per_request(): void {
		$this->require_sodium();

		$payload  = $this->payload( $this->far_future() );
		$envelope = $this->sign( $payload );

		$claims = $this->make_claims();

		$read_count = 0;
		Functions\when( 'get_option' )->alias(
			function () use ( &$read_count, $envelope ) {
				$read_count++;

				return $envelope;
			}
		);

		$first  = $claims->get_verified();
		$second = $claims->get_verified();

		$this->assertSame( $payload, $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $read_count, 'get_verified() must read the option exactly once per request (memoized).' );
	}

	/**
	 * A successful consume invalidates the memo so the next get_verified() re-reads.
	 *
	 * @return void
	 */
	public function test_consume_resets_memo(): void {
		$this->require_sodium();

		$envelope = $this->sign( $this->payload( $this->far_future() ) );

		$claims = $this->make_claims();

		$read_count = 0;
		Functions\when( 'get_option' )->alias(
			function () use ( &$read_count, $envelope ) {
				$read_count++;

				return $envelope;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );

		$claims->get_verified();                                                                       // read #1, memoized.
		$claims->consume_from_response( (object) array( 'license_authority' => $envelope ) );          // stores → resets memo.
		$claims->get_verified();                                                                       // read #2 (memo invalidated).

		$this->assertSame( 2, $read_count );
	}

	/**
	 * Double-normalization idempotence: a stored claim with the already-normalized
	 * site 'https://example.com' verifies against a raw home_url() of
	 * 'https://Example.com/' (mixed case + trailing slash). The verify-side
	 * normalizes BOTH operands, so the comparison is stable.
	 *
	 * @return void
	 */
	public function test_double_normalization_idempotence(): void {
		$this->require_sodium();

		Functions\when( 'home_url' )->justReturn( 'https://Example.com/' );

		$payload  = $this->payload( $this->far_future() );
		$envelope = $this->sign( $payload );

		$claims = $this->make_claims();

		Functions\when( 'get_option' )->justReturn( $envelope );

		$this->assertSame( $payload, $claims->get_verified() );
	}

	/**
	 * Consumption never calls switch_to_blog (multisite owner rule, decision 5):
	 * plain update_option on the current blog only.
	 *
	 * @return void
	 */
	public function test_consume_never_switches_blog(): void {
		$this->require_sodium();

		$claims   = $this->make_claims();
		$envelope = $this->sign( $this->payload( $this->far_future() ) );

		Functions\expect( 'switch_to_blog' )->never();
		Functions\expect( 'restore_current_blog' )->never();
		Functions\expect( 'update_option' )->once()->with( self::CLAIM_OPTION, $envelope, false );

		$claims->consume_from_response( (object) array( 'license_authority' => $envelope ) );
	}

	/**
	 * A Throwable raised inside the verification path must never escape
	 * consume_from_response() (it must not break validate_license()/the update flow).
	 *
	 * @return void
	 */
	public function test_consume_swallows_throwable(): void {
		$claims = $this->make_claims();

		// Force a Throwable from inside consumption: the pubkey filter throws.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook ) {
				if ( 'woodev_license_authority_pubkey' === $hook ) {
					throw new \RuntimeException( 'boom' );
				}

				return null;
			}
		);

		Functions\expect( 'update_option' )->never();

		// Must not throw — a runtime-signed envelope is irrelevant; any input that
		// reaches verification triggers the throwing filter.
		$claims->consume_from_response(
			(object) array(
				'license_authority' => array(
					'payload'   => $this->payload( 1750723200 ),
					'signature' => str_repeat( 'A', 88 ),
				),
			)
		);

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Sentinel for "license_required key absent from the payload".
	 *
	 * @var string
	 */
	private const ABSENT = '__ABSENT__';

	/**
	 * BLOCKER regression (critic finding 1): a SIGNED payload whose license_required
	 * is not a strict bool (0 / '' / null / [] / absent) must be rejected WHOLESALE —
	 * a loose `(bool)` cast downstream would read each of these as false = unlock.
	 * Asserts all three layers: consume never stores; get_verified() is null for the
	 * tampered-at-rest equivalent; and the real is_license_required() seam stays true.
	 *
	 * @dataProvider malformed_license_required_provider
	 *
	 * @param mixed $value The malformed license_required value (or the ABSENT sentinel).
	 * @return void
	 */
	public function test_malformed_license_required_rejects_whole_claim( $value ): void {
		$this->require_sodium();

		$payload = $this->payload( $this->far_future() );

		if ( self::ABSENT === $value ) {
			unset( $payload['license_required'] );
		} else {
			$payload['license_required'] = $value;
		}

		// Genuinely signed with the fixture key — only the TYPE gate can reject it.
		$envelope = $this->sign( $payload );

		$claims = $this->make_claims();

		// 1. Consume: never stored.
		Functions\expect( 'update_option' )->never();
		$claims->consume_from_response( (object) array( 'license_authority' => $envelope ) );

		// 2. At rest: a stored copy of this envelope never verifies.
		Functions\when( 'get_option' )->justReturn( $envelope );
		$this->assertNull( $claims->get_verified() );

		// 3. Enforcement seam: the REAL is_license_required() stays locked (true).
		$license = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();
		$this->set_private_property( $license, 'authority_claims', $claims );

		$this->assertTrue( $license->is_license_required() );
	}

	/**
	 * Malformed license_required values — every loosely-false-y non-bool plus absent.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function malformed_license_required_provider(): array {
		return array(
			'int zero'     => array( 0 ),
			'empty string' => array( '' ),
			'null'         => array( null ),
			'empty array'  => array( array() ),
			'absent key'   => array( self::ABSENT ),
		);
	}

	/**
	 * Sets a private property value via reflection.
	 *
	 * @param object $object   Object to update.
	 * @param string $property Property name.
	 * @param mixed  $value    Property value.
	 * @return void
	 */
	private function set_private_property( $object, string $property, $value ): void {
		$reflection_property = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection_property->setAccessible( true );
		}
		$reflection_property->setValue( $object, $value );
	}
}
