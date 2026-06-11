<?php
/**
 * Integration: woodev/v1/license-command endpoint.
 *
 * Verifies the public POST woodev/v1/license-command route end-to-end in a real
 * WordPress environment (wp-env).
 *
 * --- Design choices ---
 *
 * PUBLIC ROUTE — no cookie/nonce setup:
 *   The endpoint is authenticated solely by the Ed25519 envelope signature. Core's
 *   rest_cookie_check_errors() semantics do NOT apply here — do NOT copy the
 *   nonce/cookie scaffolding from LicenseRestAuthTest (gotcha
 *   testing/integration rest-cookie-nonce-auth-semantics). An unauthenticated POST
 *   is the normal case.
 *
 * FIXTURE KEYPAIR (seed 0x01 × 32):
 *   The fixture pubkey from s8-p1 (base64 "iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=")
 *   is injected via the 'woodev_license_authority_pubkey' filter — the same filter
 *   seam the dispatcher uses (class-license-command-dispatcher.php get_public_key()).
 *   This avoids touching the WOODEV_LICENSE_AUTHORITY_PUBKEY constant (which is the
 *   production key placeholder, never set in tests).
 *
 * COMMAND REGISTRATION (sealed registry — holistic-round ruling):
 *   The dispatcher's vocabulary is SEALED (no public register_command()); tests
 *   inject via the reflection seam on the private $commands property. We inject a
 *   stub 'deactivate_plugin' callable that returns 'executed' to exercise the full
 *   200-executed happy path end-to-end without deactivating a real fixture plugin.
 *   The registry is SNAPSHOT (raw value, including the pristine null lazy state)
 *   before the stub injection and restored verbatim in tearDown, so the test
 *   leaves the registry exactly as found.
 *
 * PLUGIN ID = '9999' (unique test-only id, disambiguation):
 *   The wp-env test environment loads all three test fixtures
 *   (woodev-test-plugin, woodev-test-payment-gateway, woodev-test-shipping-method),
 *   all of which return get_download_id() = 0. Three registrations for the same
 *   download_id flag it as AMBIGUOUS in Woodev_Plugins_License, causing the
 *   dispatcher to reject with unknown_plugin (§9.3). To avoid this, we inject a
 *   synthetic engine for download_id '9999' via reflection into the static registry,
 *   and sign envelopes with plugin_id: '9999'. This is a pure in-memory seam with no
 *   side effects: the synthetic instance is never activated, and the registry is
 *   cleaned up in tearDown(). This approach exercises the full 200-executed path with
 *   an unambiguous id while keeping all other fixture plugins intact.
 *
 * SITE BINDING:
 *   The wp-env test environment sets WP_TESTS_DOMAIN = 'example.org'. home_url()
 *   returns 'http://example.org'. woodev_normalize_site() on that yields
 *   'http://example.org'. Envelopes are signed with site = 'http://example.org'.
 *
 * NONCE ISOLATION:
 *   Each test uses a UNIQUE nonce (generated via bin2hex(random_bytes(16))) so
 *   nonce-store entries from one test never affect another.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

use WP_REST_Request;

/**
 * Class LicenseCommandEndpointTest.
 */
class LicenseCommandEndpointTest extends TestCase {

	/**
	 * The REST route path for the command endpoint.
	 *
	 * @var string
	 */
	private const ROUTE = '/woodev/v1/license-command';

	/**
	 * The synthetic plugin_id used in all signed envelopes.
	 *
	 * Must be unique across all loaded test fixtures (all of which use '0') to
	 * avoid the §9.3 ambiguous-download-id flag. See class docblock.
	 *
	 * @var string
	 */
	private const PLUGIN_ID = '9999';

	/**
	 * Fixture keypair seed (0x01 × 32). Matches the s8-p1 published test vector.
	 *
	 * @var string
	 */
	private const FIXTURE_SEED = "\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01";

	/**
	 * The fixture secret key (64-byte sodium sign key), loaded in setUp.
	 *
	 * @var string|null
	 */
	private ?string $fixture_secret = null;

	/**
	 * The fixture public key (base64-encoded), loaded in setUp.
	 *
	 * @var string|null
	 */
	private ?string $fixture_pubkey_b64 = null;

	/**
	 * The normalized site URL used in all signed envelopes.
	 *
	 * @var string
	 */
	private string $site;

	/**
	 * Snapshot of the dispatcher's command registry taken BEFORE this test's
	 * stub injection — the RAW property value (array OR the pristine null lazy
	 * state), restored exactly in tearDown so the test leaves the registry as
	 * found.
	 *
	 * @var array<string, callable|object>|null
	 */
	private $commands_snapshot = null;

	/**
	 * Whether the registry snapshot was actually taken this test. Guards the
	 * tearDown restore: a setUp skip/exception BEFORE the snapshot (e.g. the
	 * sodium skip) must NOT replace the real registry with the empty default.
	 *
	 * @var bool
	 */
	private bool $snapshot_taken = false;

	/**
	 * The exact pubkey-filter callback added in setUp, kept so tearDown removes
	 * ONLY this callback (never unrelated filters on the same hook).
	 *
	 * @var callable|null
	 */
	private $pubkey_filter = null;

	/**
	 * Boots the REST server, injects the fixture pubkey, and registers a stub command.
	 *
	 * Global state discipline (critic finding 3): the dispatcher command registry is
	 * SNAPSHOT before the stub registration and restored verbatim in tearDown; the
	 * pubkey filter is removed by exact callback+priority, never remove_all_filters().
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium not available in this PHP runtime (CI runs it).' );
		}

		$keypair                    = sodium_crypto_sign_seed_keypair( self::FIXTURE_SEED );
		$this->fixture_secret       = sodium_crypto_sign_secretkey( $keypair );
		$this->fixture_pubkey_b64   = base64_encode( sodium_crypto_sign_publickey( $keypair ) );

		// Inject the fixture pubkey via the dispatcher's filter seam. This is the
		// same seam the dispatcher reads in get_public_key() and the unit tests in
		// LicenseCommandDispatcherTest use. The production constant
		// WOODEV_LICENSE_AUTHORITY_PUBKEY is a placeholder ('') and is never set
		// in the test environment. The callback is kept in a property so tearDown
		// can remove EXACTLY this filter (not unrelated ones on the hook).
		$pub_b64             = $this->fixture_pubkey_b64;
		$this->pubkey_filter = static function () use ( $pub_b64 ): string {
			return $pub_b64;
		};
		add_filter( 'woodev_license_authority_pubkey', $this->pubkey_filter, 99 );

		// SNAPSHOT the dispatcher's command registry (raw value, may be the
		// pristine null lazy state), THEN inject the stub via the reflection seam
		// (sealed registry — no public register_command() exists by ruling). The
		// stub exercises the full 200-executed path end-to-end without
		// deactivating a real fixture plugin.
		$this->commands_snapshot = $this->get_dispatcher_commands();
		$this->snapshot_taken    = true;

		$stubbed                        = is_array( $this->commands_snapshot ) ? $this->commands_snapshot : array();
		$stubbed['deactivate_plugin']   = static function (): string {
			return 'executed';
		};
		$this->set_dispatcher_commands( $stubbed );

		// Inject a synthetic engine for the test plugin_id '9999' into the static
		// registry via reflection. This sidesteps the §9.3 ambiguous-id problem
		// caused by all three wp-env fixtures sharing download_id = 0. The engine
		// is a real Woodev_Plugins_License mock backed by the loaded test plugin
		// instance, so the dispatcher can resolve it and hand it to the stub handler.
		$this->inject_synthetic_engine( self::PLUGIN_ID );

		// Derive the normalized site URL for envelope construction. The test env
		// sets WP_TESTS_DOMAIN = 'example.org'; home_url() = 'http://example.org'.
		$this->site = (string) woodev_normalize_site( (string) home_url() );

		// Boot the REST server (fires rest_api_init and registers all routes).
		rest_get_server();
	}

	/**
	 * Restores the exact pre-test global state: removes ONLY our pubkey filter,
	 * restores the dispatcher command registry from the snapshot, and removes
	 * the synthetic engine.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->pubkey_filter ) {
			remove_filter( 'woodev_license_authority_pubkey', $this->pubkey_filter, 99 );
			$this->pubkey_filter = null;
		}

		if ( $this->snapshot_taken ) {
			$this->set_dispatcher_commands( $this->commands_snapshot );
			$this->snapshot_taken = false;
		}

		$this->remove_synthetic_engine( self::PLUGIN_ID );
		parent::tearDown();
	}

	/* ----------------------------------------------------------------------- *
	 * Route registration sanity
	 * ----------------------------------------------------------------------- */

	/**
	 * The license-command route is registered under woodev/v1. If this fails,
	 * the later endpoint assertions would be meaningless.
	 *
	 * @return void
	 */
	public function test_license_command_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey(
			'/woodev/v1/license-command',
			$routes,
			'The woodev/v1/license-command route must be registered.'
		);
	}

	/* ----------------------------------------------------------------------- *
	 * Happy path: fixture-signed valid envelope → 200 executed
	 * ----------------------------------------------------------------------- */

	/**
	 * A fixture-signed valid envelope for the synthetic plugin_id dispatches to the
	 * stub command and returns 200 { status: 'executed' }.
	 *
	 * @return void
	 */
	public function test_valid_signed_envelope_returns_200_executed(): void {
		$payload  = $this->make_payload( $this->unique_nonce() );
		$envelope = $this->sign( $payload );

		$response = $this->dispatch_post( wp_json_encode( $envelope ) );

		$this->assertSame( 200, $response->get_status(), 'Valid signed envelope must return 200.' );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'executed', $data['status'], 'Response status must be "executed".' );
		$this->assertArrayNotHasKey( 'reason', $data, 'No reason key on a terminal response.' );
	}

	/* ----------------------------------------------------------------------- *
	 * Replay protection: same envelope → 410 replayed
	 * ----------------------------------------------------------------------- */

	/**
	 * Immediate replay of the same envelope returns 410 { status: 'rejected',
	 * reason: 'replayed' }. The nonce store's atomic claim ensures the second
	 * request sees the already-claimed nonce.
	 *
	 * @return void
	 */
	public function test_replay_of_same_envelope_returns_410_replayed(): void {
		$nonce    = $this->unique_nonce();
		$envelope = $this->sign( $this->make_payload( $nonce ) );
		$body     = wp_json_encode( $envelope );

		// First request: executed.
		$first = $this->dispatch_post( $body );
		$this->assertSame( 200, $first->get_status(), 'First request must return 200.' );

		// Second request (same nonce, same body): replayed.
		$second = $this->dispatch_post( $body );

		$this->assertSame( 410, $second->get_status(), 'Replayed envelope must return 410.' );

		$data = $second->get_data();
		$this->assertSame( 'rejected', $data['status'] );
		$this->assertSame( 'replayed', $data['reason'] );
	}

	/* ----------------------------------------------------------------------- *
	 * Tampered signature → 401 bad_signature
	 * ----------------------------------------------------------------------- */

	/**
	 * A request with a tampered (corrupted) signature returns 401
	 * { status: 'rejected', reason: 'bad_signature' }.
	 *
	 * @return void
	 */
	public function test_tampered_signature_returns_401_bad_signature(): void {
		$payload  = $this->make_payload( $this->unique_nonce() );
		$envelope = $this->sign( $payload );

		// Corrupt a byte in the middle of the base64-decoded signature.
		$sig_raw               = base64_decode( $envelope['signature'], true );
		$sig_raw[32]           = chr( ord( $sig_raw[32] ) ^ 0xFF );
		$envelope['signature'] = base64_encode( $sig_raw );

		$response = $this->dispatch_post( wp_json_encode( $envelope ) );

		$this->assertSame( 401, $response->get_status(), 'Tampered signature must return 401.' );

		$data = $response->get_data();
		$this->assertSame( 'rejected', $data['status'] );
		$this->assertSame( 'bad_signature', $data['reason'] );
	}

	/* ----------------------------------------------------------------------- *
	 * Malformed body → 400 malformed
	 * ----------------------------------------------------------------------- */

	/**
	 * Garbage / non-JSON body returns 400 malformed.
	 *
	 * Two paths are acceptable:
	 *  a) Our controller receives the body and the dispatcher returns {status:'rejected',reason:'malformed'}.
	 *  b) WP Core rejects the malformed JSON at the REST layer before our handler
	 *     runs and returns a rest_* error (code, message, data) — also 400.
	 * Both result in a 400, which is the invariant we assert. The reason-code check
	 * is applied only when the response is from our own controller (status key present).
	 *
	 * @return void
	 */
	public function test_garbage_body_returns_400_malformed(): void {
		$response = $this->dispatch_post( '{this is not valid json]' );

		$this->assertSame( 400, $response->get_status(), 'Malformed body must return 400.' );

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response must be an array.' );

		// Our controller path: {status:'rejected', reason:'malformed'}.
		if ( isset( $data['status'] ) ) {
			$this->assertSame( 'rejected', $data['status'] );
			$this->assertSame( 'malformed', $data['reason'] );
		} else {
			// WP Core REST path: {code, message, data} — still 400.
			$this->assertArrayHasKey( 'code', $data, 'WP REST error must have a code.' );
		}
	}

	/**
	 * Oversized body (8193 bytes > MAX_BODY_BYTES = 8192) returns 400 malformed.
	 *
	 * Two paths are acceptable (see test_garbage_body_returns_400_malformed).
	 *
	 * @return void
	 */
	public function test_oversized_body_returns_400_malformed(): void {
		$response = $this->dispatch_post( str_repeat( 'x', 8193 ) );

		$this->assertSame( 400, $response->get_status(), 'Oversized body must return 400.' );

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response must be an array.' );

		// Our controller path: {status:'rejected', reason:'malformed'}.
		if ( isset( $data['status'] ) ) {
			$this->assertSame( 'rejected', $data['status'] );
			$this->assertSame( 'malformed', $data['reason'] );
		} else {
			// WP Core REST path: {code, message, data} — still 400.
			$this->assertArrayHasKey( 'code', $data, 'WP REST error must have a code.' );
		}
	}

	/* ----------------------------------------------------------------------- *
	 * No cookie/nonce setup required (public route)
	 * ----------------------------------------------------------------------- */

	/**
	 * The endpoint is PUBLIC — an unauthenticated POST with a valid signed envelope
	 * succeeds (200). This confirms no WP cookie/nonce is required (the gotcha
	 * testing/integration rest-cookie-nonce-auth-semantics does NOT apply here).
	 *
	 * @return void
	 */
	public function test_endpoint_is_public_no_nonce_required(): void {
		// Reset any cookie-auth state that might have leaked from another test.
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		// Confirm no auth state is present.
		$this->assertSame( 0, get_current_user_id(), 'Test must run as anonymous.' );

		$payload  = $this->make_payload( $this->unique_nonce() );
		$envelope = $this->sign( $payload );

		$response = $this->dispatch_post( wp_json_encode( $envelope ) );

		// A valid signed envelope from an unauthenticated caller succeeds.
		$this->assertSame( 200, $response->get_status(), 'Public endpoint must accept a valid unauthenticated signed request.' );
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------------- */

	/**
	 * Builds a valid v1 payload bound to the test environment.
	 *
	 * Uses the synthetic plugin_id '9999' (injected via reflection to avoid the
	 * §9.3 ambiguous-id collision from all fixture plugins sharing download_id = 0)
	 * and the normalised site URL from home_url().
	 *
	 * @param string               $nonce     32 lowercase hex characters.
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function make_payload( string $nonce, array $overrides = array() ): array {
		$now = time();
		return array_merge(
			array(
				'protocol'   => 1,
				'command'    => 'deactivate_plugin',
				'site'       => $this->site,
				'plugin_id'  => self::PLUGIN_ID,
				'nonce'      => $nonce,
				'issued_at'  => $now - 5,
				'expires_at' => $now + ( 7 * 24 * 3600 ),
			),
			$overrides
		);
	}

	/**
	 * Signs a payload with the fixture keypair and wraps it in an envelope.
	 *
	 * @param array<string, mixed> $payload The payload to sign.
	 * @return array<string, mixed>
	 */
	private function sign( array $payload ): array {
		$canonical = \Woodev_License_Envelope_Verifier::canonical_json( $payload );

		return array(
			'payload'   => $payload,
			'signature' => base64_encode( sodium_crypto_sign_detached( $canonical, $this->fixture_secret ) ),
		);
	}

	/**
	 * Generates a unique 32-character lowercase hex nonce.
	 *
	 * @return string
	 */
	private function unique_nonce(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Dispatches a POST request to the license-command endpoint.
	 *
	 * @param string $body Raw request body.
	 * @return \WP_REST_Response
	 */
	private function dispatch_post( string $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'application/json' );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Injects a minimal Woodev_Plugins_License stand-in for the given plugin_id
	 * into the static registry via reflection.
	 *
	 * The synthetic instance is backed by the real test plugin's Woodev_Plugin
	 * object so that $engine->plugin, $engine->get_state(), etc. work if called.
	 * Registered with the download_id key so the dispatcher can resolve it.
	 *
	 * @param string $plugin_id The download id key to register under.
	 * @return void
	 */
	private function inject_synthetic_engine( string $plugin_id ): void {
		// Use the test plugin instance as the backing plugin object.
		$test_plugin = woodev_test_plugin();

		// Build a minimal license engine via newInstanceWithoutConstructor() so we
		// don't trigger real HTTP calls or WP option writes. We only need the engine
		// to exist in the registry — the stub command handler ignores it.
		$ref_class = new \ReflectionClass( \Woodev_Plugins_License::class );
		$engine    = $ref_class->newInstanceWithoutConstructor();

		// Wire the plugin property so any engine method that dereferences it works.
		$plugin_prop = $ref_class->getProperty( 'plugin' );
		if ( PHP_VERSION_ID < 80100 ) {
			$plugin_prop->setAccessible( true );
		}
		$plugin_prop->setValue( $engine, $test_plugin );

		// Inject into the static registry.
		$instances_prop = $ref_class->getProperty( 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$instances_prop->setAccessible( true );
		}
		$registry               = (array) $instances_prop->getValue();
		$registry[ $plugin_id ] = $engine;
		$instances_prop->setValue( null, $registry );
	}

	/**
	 * Removes the synthetic engine from the registry after each test.
	 *
	 * @param string $plugin_id The download id key to remove.
	 * @return void
	 */
	private function remove_synthetic_engine( string $plugin_id ): void {
		$ref_class      = new \ReflectionClass( \Woodev_Plugins_License::class );
		$instances_prop = $ref_class->getProperty( 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$instances_prop->setAccessible( true );
		}
		$registry = (array) $instances_prop->getValue();
		unset( $registry[ $plugin_id ] );
		$instances_prop->setValue( null, $registry );
	}

	/**
	 * Reads the dispatcher's private static command registry via reflection.
	 *
	 * Returns the RAW value — array, or NULL for the pristine lazy state (the
	 * sealed registry builds itself on first use; restoring null preserves that).
	 *
	 * @return array<string, callable|object>|null
	 */
	private function get_dispatcher_commands(): ?array {
		$property = new \ReflectionProperty( \Woodev_License_Command_Dispatcher::class, 'commands' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		return $property->getValue();
	}

	/**
	 * Overwrites the dispatcher's private static command registry via reflection
	 * (used to restore the pre-test snapshot exactly — including null).
	 *
	 * @param array<string, callable|object>|null $commands The registry to restore.
	 * @return void
	 */
	private function set_dispatcher_commands( ?array $commands ): void {
		$property = new \ReflectionProperty( \Woodev_License_Command_Dispatcher::class, 'commands' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, $commands );
	}
}
