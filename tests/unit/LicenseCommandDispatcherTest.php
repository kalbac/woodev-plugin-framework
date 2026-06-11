<?php
/**
 * Signed-command dispatcher pipeline tests (§9.4 + §9.9).
 *
 * The dispatcher is transport-neutral: REST (s8-p3) and pull (s8-p5) both feed it.
 * It runs the FROZEN §9.4 validation pipeline and, only after the atomic nonce claim,
 * dispatches to a registered command handler. Every rejection path is ZERO-side-effect
 * (no add_option/update_option except the rate transient on steps 2–6, no handler call,
 * no do_action) — each rejection test asserts that explicitly (silent-failure focus).
 *
 * Envelopes are signed with the s8-p1 fixture keypair (seed 0x01 x 32); the fixture
 * pubkey is injected via the same woodev_license_authority_pubkey filter the claims
 * store uses. Time flows through one overridable now() seam (a probe subclass) so the
 * skew / TTL / expiry assertions never sleep.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-nonce-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-dispatcher.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-acks.php';

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';
require_once dirname( __DIR__, 2 ) . '/woodev/handlers/class-cron-handler.php';

/**
 * A dispatcher with an injectable clock + nonce store for deterministic tests.
 */
class Probe_Command_Dispatcher extends \Woodev_License_Command_Dispatcher {

	/**
	 * Fixed "current" time.
	 *
	 * @var int
	 */
	public static $fixed_now = 1_700_000_000;

	/**
	 * Returns the injected fixed time.
	 *
	 * @return int
	 */
	protected static function now(): int {
		return self::$fixed_now;
	}
}

/**
 * Class LicenseCommandDispatcherTest.
 */
class LicenseCommandDispatcherTest extends TestCase {

	/**
	 * Fixed clock value used across the time-sensitive tests.
	 *
	 * @var int
	 */
	private const NOW = 1_700_000_000;

	/**
	 * Sets the fixture pubkey filter, the fixed clock, and resets the static registry.
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

		// The fixture pubkey is only resolvable with ext-sodium; the source-assertion
		// wiring tests below run regardless (they call no crypto).
		$pub_b64 = function_exists( 'sodium_crypto_sign_seed_keypair' ) ? $this->fixture_keypair()[1] : '';
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) use ( $pub_b64 ) {
				return 'woodev_license_authority_pubkey' === $hook ? $pub_b64 : $value;
			}
		);

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		// woodev_normalize_site() helpers.
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $string ) {
				return rtrim( (string) $string, '/\\' );
			}
		);

		Probe_Command_Dispatcher::$fixed_now = self::NOW;
		Probe_Command_Dispatcher::reset_commands_for_tests();

		$this->reset_license_registry();
	}

	/**
	 * Resets the static state after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Probe_Command_Dispatcher::reset_commands_for_tests();
		$this->reset_license_registry();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/* ----------------------------------------------------------------------- *
	 * Fixtures
	 * ----------------------------------------------------------------------- */

	/**
	 * Builds the fixture keypair from the published seed (0x01 x 32).
	 *
	 * @return array{0: string, 1: string} [ raw secret key, base64 pubkey ]
	 */
	private function fixture_keypair(): array {
		if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium not available in this PHP runtime.' );
		}

		$keypair = sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) );

		return array( sodium_crypto_sign_secretkey( $keypair ), base64_encode( sodium_crypto_sign_publickey( $keypair ) ) );
	}

	/**
	 * A valid v1 payload bound to the test site + plugin id, with a fresh window.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function valid_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'protocol'   => 1,
				'command'    => 'deactivate_plugin',
				'site'       => 'https://example.com',
				'plugin_id'  => '216',
				'nonce'      => str_repeat( 'a', 32 ),
				'issued_at'  => self::NOW - 10,
				'expires_at' => self::NOW + 1000,
			),
			$overrides
		);
	}

	/**
	 * Signs a payload with the fixture key and wraps it in an envelope.
	 *
	 * @param array<string, mixed> $payload The payload to sign.
	 * @return array<string, mixed>
	 */
	private function sign( array $payload ): array {
		[ $secret ] = $this->fixture_keypair();
		$canonical  = \Woodev_License_Envelope_Verifier::canonical_json( $payload );

		return array(
			'payload'   => $payload,
			'signature' => base64_encode( sodium_crypto_sign_detached( $canonical, $secret ) ),
		);
	}

	/**
	 * Registers a stub license engine for download id '216' whose plugin file is known.
	 *
	 * @return \Mockery\MockInterface The engine mock.
	 */
	private function register_target_plugin(): object {
		// In s8-p2 the dispatcher resolves the registered ENGINE and hands it to the
		// command handler verbatim; the handler (s8-p4) is what dereferences the plugin.
		$engine = Mockery::mock( \Woodev_Plugins_License::class );

		$this->seed_license_registry( '216', $engine );

		return $engine;
	}

	/**
	 * Asserts ZERO persistent writes / actions on a post-authentication rejection
	 * (steps 7–11): no option write of ANY kind (add/update/delete), no transient
	 * write, no handler side effect, no action fired.
	 *
	 * @return void
	 */
	private function expect_no_side_effects(): void {
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'deactivate_plugins' )->never();
		Functions\expect( 'do_action' )->never();
	}

	/**
	 * Asserts zero side effects EXCEPT the rate transient — the one write allowed
	 * on steps 2–6 rejections (§9.4). The transient write must target ONLY the
	 * frozen rate-limit key (asserted via the with() matcher).
	 *
	 * @return void
	 */
	private function expect_no_side_effects_except_rate_transient(): void {
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'deactivate_plugins' )->never();
		Functions\expect( 'do_action' )->never();

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )
			->once()
			->with( \Woodev_License_Command_Dispatcher::RATE_LIMIT_TRANSIENT, Mockery::type( 'array' ), Mockery::type( 'int' ) )
			->andReturn( true );
	}

	/**
	 * Installs a wpdb double whose option-row scan returns the given rows.
	 *
	 * @param array<int, object> $rows Option rows for fetch_rows().
	 * @return \Mockery\MockInterface
	 */
	private function arm_wpdb( array $rows = array() ): object {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => $text );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );

		$GLOBALS['wpdb'] = $wpdb;

		return $wpdb;
	}

	/**
	 * Arms the full happy path: registered target, empty nonce store, option stubs,
	 * and a counting 'deactivate_plugin' handler.
	 *
	 * @param int $calls Receives the handler call count by reference.
	 * @return void
	 */
	private function arm_happy_path( int &$calls ): void {
		$this->register_target_plugin();

		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		// Below cap a claim performs NO maintenance writes (lazy prune, §9.2 amended).
		Functions\expect( 'delete_option' )->never();

		$this->arm_wpdb();

		$calls = 0;
		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);
	}

	/* ----------------------------------------------------------------------- *
	 * Happy path
	 * ----------------------------------------------------------------------- */

	/**
	 * A valid signed envelope claims the nonce then dispatches to the registered
	 * command handler exactly once; result is executed/200.
	 *
	 * @return void
	 */
	public function test_valid_envelope_claims_then_dispatches_once(): void {
		$calls = 0;
		$this->arm_happy_path( $calls );

		$result = Probe_Command_Dispatcher::handle_envelope( $this->sign( $this->valid_payload() ), 'inbound' );

		$this->assertSame( 'executed', $result['status'] );
		$this->assertSame( 200, $result['http'] );
		$this->assertSame( 1, $calls, 'The command handler runs exactly once.' );
	}

	/**
	 * Ordering invariant (§9.1 lifecycle): the handler executes BEFORE the nonce is
	 * marked consumed — a crash after the action but before the consume write is
	 * healed by the takeover + idempotent re-execution, never the other way around.
	 *
	 * @return void
	 */
	public function test_handler_runs_before_nonce_consume(): void {
		$this->register_target_plugin();

		$sequence = array();

		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$sequence ) {
				if ( 'consumed' === ( $value['s'] ?? null ) ) {
					$sequence[] = 'consumed';
				}
				return true;
			}
		);

		$this->arm_wpdb();

		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$sequence ) {
				$sequence[] = 'handler';
				return 'executed';
			}
		);

		Probe_Command_Dispatcher::handle_envelope( $this->sign( $this->valid_payload() ), 'inbound' );

		$this->assertSame( array( 'handler', 'consumed' ), $sequence, 'Dispatch happens strictly before mark_consumed.' );
	}

	/**
	 * The same nonce delivered twice (inbound then pull) executes once — the second is
	 * 'replayed' / 410 (§9.9 pull-vs-inbound double delivery).
	 *
	 * @return void
	 */
	public function test_double_delivery_executes_once(): void {
		$this->register_target_plugin();

		// First delivery: fresh nonce; second: the winner's processing record is seen.
		$record = null;
		Functions\when( 'get_option' )->alias(
			static function () use ( &$record ) {
				return $record ?? false;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$record ) {
				if ( null !== $record ) {
					return false; // UNIQUE conflict on the second delivery.
				}
				$record = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$record ) {
				$record = $value;
				return true;
			}
		);
		Functions\when( 'maybe_unserialize' )->returnArg();

		$this->arm_wpdb();

		$calls = 0;
		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		$envelope = $this->sign( $this->valid_payload() );

		$first  = Probe_Command_Dispatcher::handle_envelope( $envelope, 'inbound' );
		$second = Probe_Command_Dispatcher::handle_envelope( $envelope, 'pull' );

		$this->assertSame( 'executed', $first['status'] );
		$this->assertSame( 'rejected', $second['status'] );
		$this->assertSame( 'replayed', $second['reason'] );
		$this->assertSame( 410, $second['http'] );
		$this->assertSame( 1, $calls, 'A double-delivered command executes exactly once.' );
	}

	/**
	 * An unknown command (not in the v1 vocabulary) consumes the nonce, executes NO
	 * action, and returns unsupported_command/400 (forward tolerance, B-2).
	 *
	 * @return void
	 */
	public function test_unknown_command_consumes_nonce_no_action(): void {
		$this->register_target_plugin();

		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();

		$this->arm_wpdb();

		// Vocabulary lookup happens AFTER the claim: mark_consumed must run, no handler.
		$consumed = null;
		Functions\expect( 'update_option' )->atLeast()->once()->andReturnUsing(
			static function ( $name, $value ) use ( &$consumed ) {
				if ( 'consumed' === ( $value['s'] ?? null ) ) {
					$consumed = $value;
				}
				return true;
			}
		);
		Functions\expect( 'deactivate_plugins' )->never();

		// Register a DIFFERENT command so the vocabulary is non-empty but lacks delete_plugin.
		Probe_Command_Dispatcher::register_command( 'deactivate_plugin', static fn() => 'executed' );

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload( array( 'command' => 'delete_plugin' ) ) ),
			'inbound'
		);

		$this->assertSame( 'unsupported_command', $result['status'] );
		$this->assertSame( 400, $result['http'] );
		$this->assertSame( 'unsupported_command', $consumed['r'] ?? null, 'The nonce is consumed with the unsupported_command status.' );
	}

	/* ----------------------------------------------------------------------- *
	 * Rejection pipeline — every step, zero side effects
	 * ----------------------------------------------------------------------- */

	/**
	 * Step 2: an oversized raw body → malformed/400, before any JSON work (§9.9).
	 *
	 * @return void
	 */
	public function test_oversized_body_malformed(): void {
		$this->expect_no_side_effects_except_rate_transient();

		$body   = str_repeat( 'x', \Woodev_License_Command_Dispatcher::MAX_BODY_BYTES + 1 );
		$result = Probe_Command_Dispatcher::handle_raw_body( $body );

		$this->assertSame( 'rejected', $result['status'] );
		$this->assertSame( 'malformed', $result['reason'] );
		$this->assertSame( 400, $result['http'] );
	}

	/**
	 * Step 2 boundary: a body of EXACTLY MAX_BODY_BYTES passes the size gate and —
	 * being a valid signed envelope padded with JSON-insignificant whitespace —
	 * executes end to end. One byte more (the oversized test above) is malformed.
	 *
	 * @return void
	 */
	public function test_body_exactly_max_bytes_accepted(): void {
		$calls = 0;
		$this->arm_happy_path( $calls );
		Functions\when( 'get_transient' )->justReturn( false );

		$body = (string) json_encode( $this->sign( $this->valid_payload() ) );
		$body = $body . str_repeat( ' ', \Woodev_License_Command_Dispatcher::MAX_BODY_BYTES - strlen( $body ) );

		$this->assertSame( \Woodev_License_Command_Dispatcher::MAX_BODY_BYTES, strlen( $body ) );

		$result = Probe_Command_Dispatcher::handle_raw_body( $body );

		$this->assertSame( 'executed', $result['status'] );
		$this->assertSame( 1, $calls, 'An exactly-at-cap body is processed normally.' );
	}

	/**
	 * Step 3: garbage JSON → malformed/400.
	 *
	 * @return void
	 */
	public function test_garbage_json_malformed(): void {
		$this->expect_no_side_effects_except_rate_transient();

		$result = Probe_Command_Dispatcher::handle_raw_body( '{not json' );

		$this->assertSame( 'malformed', $result['reason'] );
		$this->assertSame( 400, $result['http'] );
	}

	/**
	 * Step 3: schema violations (extra top key, wrong payload keys, scalar caps,
	 * bad nonce shape) → malformed/400. Run through handle_envelope (post-decode gate).
	 *
	 * @dataProvider schema_violation_provider
	 *
	 * @param array<string, mixed> $envelope The envelope to reject.
	 * @return void
	 */
	public function test_schema_violations_malformed( array $envelope ): void {
		$this->expect_no_side_effects_except_rate_transient();

		$result = Probe_Command_Dispatcher::handle_envelope( $envelope, 'inbound' );

		$this->assertSame( 'malformed', $result['reason'] );
		$this->assertSame( 400, $result['http'] );
	}

	/**
	 * Schema-violation envelopes (each must be rejected at the schema gate).
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function schema_violation_provider(): array {
		$base = array(
			'protocol'   => 1,
			'command'    => 'deactivate_plugin',
			'site'       => 'https://example.com',
			'plugin_id'  => '216',
			'nonce'      => str_repeat( 'a', 32 ),
			'issued_at'  => self::NOW - 10,
			'expires_at' => self::NOW + 1000,
		);

		return array(
			'extra top-level key'      => array( array( 'payload' => $base, 'signature' => 'x', 'kid' => 'k', 'extra' => 1 ) ),
			'missing payload key'      => array( array( 'payload' => array_diff_key( $base, array( 'nonce' => 1 ) ), 'signature' => 'x' ) ),
			'unexpected payload key'   => array( array( 'payload' => $base + array( 'evil' => 1 ), 'signature' => 'x' ) ),
			'command too long'         => array( array( 'payload' => array_merge( $base, array( 'command' => str_repeat( 'c', 65 ) ) ), 'signature' => 'x' ) ),
			'site too long'            => array( array( 'payload' => array_merge( $base, array( 'site' => 'https://' . str_repeat( 'a', 255 ) ) ), 'signature' => 'x' ) ),
			'plugin_id too long'       => array( array( 'payload' => array_merge( $base, array( 'plugin_id' => str_repeat( '1', 21 ) ) ), 'signature' => 'x' ) ),
			'nonce not 32 hex'         => array( array( 'payload' => array_merge( $base, array( 'nonce' => 'short' ) ), 'signature' => 'x' ) ),
			'nonce uppercase hex'      => array( array( 'payload' => array_merge( $base, array( 'nonce' => strtoupper( str_repeat( 'a', 32 ) ) ) ), 'signature' => 'x' ) ),
			'issued_at not int'        => array( array( 'payload' => array_merge( $base, array( 'issued_at' => '123' ) ), 'signature' => 'x' ) ),
			'expires_at not int'       => array( array( 'payload' => array_merge( $base, array( 'expires_at' => 1.5 ) ), 'signature' => 'x' ) ),
			'kid too long'             => array( array( 'payload' => $base, 'signature' => 'x', 'kid' => str_repeat( 'k', 65 ) ) ),
			'args too many entries'    => array( array( 'payload' => array_merge( $base, array( 'args' => array_fill( 0, 17, 'v' ) ) ), 'signature' => 'x' ) ),
			'args scalar too long'     => array( array( 'payload' => array_merge( $base, array( 'args' => array( str_repeat( 'v', 256 ) ) ) ), 'signature' => 'x' ) ),
			'args entry nested array'  => array( array( 'payload' => array_merge( $base, array( 'args' => array( 'a' => array( 'nested' ) ) ) ), 'signature' => 'x' ) ),
			'signature missing'        => array( array( 'payload' => $base ) ),
		);
	}

	/**
	 * Step 4: a signature that is not strict base64 of 64 bytes → bad_signature/401.
	 *
	 * @return void
	 */
	public function test_loose_signature_bad_signature(): void {
		$this->expect_no_side_effects_except_rate_transient();

		$envelope              = $this->sign( $this->valid_payload() );
		$envelope['signature'] = '!!!notb64';

		$result = Probe_Command_Dispatcher::handle_envelope( $envelope, 'inbound' );

		$this->assertSame( 'bad_signature', $result['reason'] );
		$this->assertSame( 401, $result['http'] );
	}

	/**
	 * Step 5/6: a kid that mismatches the embedded key → bad_signature/401.
	 *
	 * @return void
	 */
	public function test_kid_mismatch_bad_signature(): void {
		$this->expect_no_side_effects_except_rate_transient();

		$envelope        = $this->sign( $this->valid_payload() );
		$envelope['kid'] = 'deadbeefdeadbeef';

		$result = Probe_Command_Dispatcher::handle_envelope( $envelope, 'inbound' );

		$this->assertSame( 'bad_signature', $result['reason'] );
		$this->assertSame( 401, $result['http'] );
	}

	/**
	 * Step 6: a tampered payload (signature no longer matches) → bad_signature/401,
	 * and the verify happens BEFORE any site/plugin lookup (no registry needed).
	 *
	 * @return void
	 */
	public function test_tampered_signature_bad_signature(): void {
		$this->expect_no_side_effects_except_rate_transient();

		$envelope                       = $this->sign( $this->valid_payload() );
		$envelope['payload']['plugin_id'] = '999'; // payload no longer matches the signature.

		$result = Probe_Command_Dispatcher::handle_envelope( $envelope, 'inbound' );

		$this->assertSame( 'bad_signature', $result['reason'] );
		$this->assertSame( 401, $result['http'] );
	}

	/**
	 * Step 7: protocol !== 1 → unsupported_protocol/400, NO consumption, NO ack
	 * (the claim step has not run). Verified envelope, valid otherwise.
	 *
	 * @return void
	 */
	public function test_unsupported_protocol(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload( array( 'protocol' => 2 ) ) ),
			'inbound'
		);

		$this->assertSame( 'unsupported_protocol', $result['reason'] );
		$this->assertSame( 400, $result['http'] );
	}

	/**
	 * Step 8: a site that does not match home_url() → site_mismatch/401.
	 *
	 * @return void
	 */
	public function test_site_mismatch(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload( array( 'site' => 'https://evil.example' ) ) ),
			'inbound'
		);

		$this->assertSame( 'site_mismatch', $result['reason'] );
		$this->assertSame( 401, $result['http'] );
	}

	/**
	 * Step 9: an unknown plugin id (not registered) → unknown_plugin/404.
	 *
	 * @return void
	 */
	public function test_unknown_plugin(): void {
		$this->expect_no_side_effects();
		// No registry seeding → plugin id '216' is absent.

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload() ),
			'inbound'
		);

		$this->assertSame( 'unknown_plugin', $result['reason'] );
		$this->assertSame( 404, $result['http'] );
	}

	/**
	 * Step 9 pin: a NON-DIGIT plugin_id (e.g. 'abc') is schema-VALID (any string
	 * ≤ 20 chars passes step 3) and is rejected by the REGISTRY lookup as
	 * unknown_plugin — schema does not guess at id shapes.
	 *
	 * @return void
	 */
	public function test_non_digit_plugin_id_passes_schema_rejected_by_registry(): void {
		$this->register_target_plugin(); // registry holds '216' only.
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload( array( 'plugin_id' => 'abc' ) ) ),
			'inbound'
		);

		$this->assertSame( 'unknown_plugin', $result['reason'], 'Rejected by the registry, NOT by the schema gate (which would say malformed).' );
		$this->assertSame( 404, $result['http'] );
	}

	/**
	 * Step 9: an AMBIGUOUS plugin id (duplicate download id flagged) → unknown_plugin
	 * even though an engine is registered (§9.3, deterministic, no info leak).
	 *
	 * @return void
	 */
	public function test_ambiguous_plugin_id_is_unknown_plugin(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		// Flag '216' ambiguous via the license registry static.
		$this->seed_ambiguous_download_id( '216' );

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload() ),
			'inbound'
		);

		$this->assertSame( 'unknown_plugin', $result['reason'] );
		$this->assertSame( 404, $result['http'] );
	}

	/**
	 * Step 10: a skewed issued_at (more than CLOCK_SKEW in the future) → invalid_window.
	 *
	 * @return void
	 */
	public function test_skewed_issued_at_invalid_window(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign(
				$this->valid_payload(
					array(
						'issued_at'  => self::NOW + 600, // > 300s skew allowance.
						'expires_at' => self::NOW + 1000,
					)
				)
			),
			'inbound'
		);

		$this->assertSame( 'invalid_window', $result['reason'] );
		$this->assertSame( 400, $result['http'] );
	}

	/**
	 * Step 10: a TTL exceeding MAX_TTL → invalid_window.
	 *
	 * @return void
	 */
	public function test_ttl_over_max_invalid_window(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign(
				$this->valid_payload(
					array(
						'issued_at'  => self::NOW - 10,
						'expires_at' => self::NOW - 10 + \Woodev_License_Command_Nonce_Store::MAX_TTL + 1,
					)
				)
			),
			'inbound'
		);

		$this->assertSame( 'invalid_window', $result['reason'] );
		$this->assertSame( 400, $result['http'] );
	}

	/**
	 * Step 10 boundary: a TTL of EXACTLY MAX_TTL is accepted (the rule is
	 * `(expires_at - issued_at) <= MAX_TTL`) and the command executes.
	 *
	 * @return void
	 */
	public function test_ttl_exactly_max_executes(): void {
		$calls = 0;
		$this->arm_happy_path( $calls );

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign(
				$this->valid_payload(
					array(
						'issued_at'  => self::NOW - 10,
						'expires_at' => self::NOW - 10 + \Woodev_License_Command_Nonce_Store::MAX_TTL,
					)
				)
			),
			'inbound'
		);

		$this->assertSame( 'executed', $result['status'] );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Step 10 boundary: issued_at of EXACTLY now + CLOCK_SKEW is accepted (the rule
	 * is `issued_at <= now + 300`) and the command executes.
	 *
	 * @return void
	 */
	public function test_issued_at_at_skew_boundary_executes(): void {
		$calls = 0;
		$this->arm_happy_path( $calls );

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign(
				$this->valid_payload(
					array(
						'issued_at'  => self::NOW + \Woodev_License_Command_Dispatcher::CLOCK_SKEW,
						'expires_at' => self::NOW + \Woodev_License_Command_Dispatcher::CLOCK_SKEW + 1000,
					)
				)
			),
			'inbound'
		);

		$this->assertSame( 'executed', $result['status'] );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Step 10 precedence pin (frozen order): an envelope that is BOTH window-invalid
	 * (TTL > MAX_TTL) AND past its expires_at rejects as invalid_window — the window
	 * rules are checked before the `now > expires_at` expiry check (plan step 10).
	 *
	 * @return void
	 */
	public function test_expired_and_window_invalid_pins_invalid_window(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign(
				$this->valid_payload(
					array(
						// TTL = MAX_TTL + 100 (window-invalid) AND expires_at < now (expired).
						'issued_at'  => self::NOW - \Woodev_License_Command_Nonce_Store::MAX_TTL - 200,
						'expires_at' => self::NOW - 100,
					)
				)
			),
			'inbound'
		);

		$this->assertSame( 'invalid_window', $result['reason'], 'Window rules win over expiry per the frozen step-10 order.' );
		$this->assertSame( 400, $result['http'] );
	}

	/**
	 * Step 10: expires_at not greater than issued_at → invalid_window.
	 *
	 * @return void
	 */
	public function test_non_positive_window_invalid_window(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign(
				$this->valid_payload(
					array(
						'issued_at'  => self::NOW,
						'expires_at' => self::NOW, // not strictly greater.
					)
				)
			),
			'inbound'
		);

		$this->assertSame( 'invalid_window', $result['reason'] );
	}

	/**
	 * Step 10: now > expires_at → expired/410.
	 *
	 * @return void
	 */
	public function test_expired(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign(
				$this->valid_payload(
					array(
						'issued_at'  => self::NOW - 1000,
						'expires_at' => self::NOW - 1, // already expired.
					)
				)
			),
			'inbound'
		);

		$this->assertSame( 'expired', $result['reason'] );
		$this->assertSame( 410, $result['http'] );
	}

	/**
	 * Step 11: a replayed nonce (store says replayed) → replayed/410, no handler call.
	 *
	 * @return void
	 */
	public function test_replayed_nonce(): void {
		$this->register_target_plugin();
		$this->expect_no_side_effects();

		// The nonce already has a consumed record → claim returns 'replayed'.
		Functions\when( 'get_option' )->justReturn(
			array( 's' => 'consumed', 'r' => 'executed', 'c' => self::NOW, 'e' => self::NOW + 100 )
		);

		Probe_Command_Dispatcher::register_command( 'deactivate_plugin', static fn() => 'executed' );

		$result = Probe_Command_Dispatcher::handle_envelope( $this->sign( $this->valid_payload() ), 'inbound' );

		$this->assertSame( 'rejected', $result['status'] );
		$this->assertSame( 'replayed', $result['reason'] );
		$this->assertSame( 410, $result['http'] );
	}

	/**
	 * Step 11: store_full (cap reached) maps to rate_limited/429. The at-cap path
	 * DID prune — deleting only EXPIRED rows (the recorded §9.2 exception) — but
	 * created/mutated NO live row (no add_option/update_option, no live deletes).
	 *
	 * @return void
	 */
	public function test_store_full_maps_to_rate_limited(): void {
		$this->register_target_plugin();

		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? unserialize( $value ) : $value;
			}
		);
		// No live row is created or mutated on the store_full rejection.
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'set_transient' )->never();

		$prefix = \Woodev_License_Command_Nonce_Store::OPTION_PREFIX;
		$rows   = array();
		for ( $i = 0; $i < \Woodev_License_Command_Nonce_Store::MAX_NONCE_ENTRIES; $i++ ) {
			$rows[] = (object) array(
				'option_name'  => $prefix . str_pad( (string) $i, 32, '0', STR_PAD_LEFT ),
				'option_value' => serialize( array( 's' => 'processing', 'e' => self::NOW + 1000 ) ),
			);
		}

		// One EXPIRED row rides along: the at-cap prune must delete it (and ONLY it).
		$expired_name = $prefix . str_repeat( 'e', 32 );
		$rows[]       = (object) array(
			'option_name'  => $expired_name,
			'option_value' => serialize( array( 's' => 'consumed', 'e' => self::NOW - 1 ) ),
		);

		$this->arm_wpdb( $rows );

		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		Probe_Command_Dispatcher::register_command( 'deactivate_plugin', static fn() => 'executed' );

		$result = Probe_Command_Dispatcher::handle_envelope( $this->sign( $this->valid_payload() ), 'inbound' );

		$this->assertSame( 'rejected', $result['status'] );
		$this->assertSame( 'rate_limited', $result['reason'] );
		$this->assertSame( 429, $result['http'] );
		$this->assertSame( array( $expired_name ), $deleted, 'The at-cap prune ran and deleted ONLY the expired row.' );
	}

	/* ----------------------------------------------------------------------- *
	 * Rate limiting (handle_raw_body gate 1)
	 * ----------------------------------------------------------------------- */

	/**
	 * Gate 1: a window with n > 30 and a live t0 short-circuits to rate_limited/429
	 * without any further work — including NO counter write (the gate never counts).
	 *
	 * @return void
	 */
	public function test_rate_limit_gate_short_circuits(): void {
		Functions\when( 'get_transient' )->justReturn(
			array(
				'n'  => 31,
				't0' => self::NOW - 10, // inside the 60 s window.
			)
		);
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_raw_body( '{}' );

		$this->assertSame( 'rejected', $result['status'] );
		$this->assertSame( 'rate_limited', $result['reason'] );
		$this->assertSame( 429, $result['http'] );
	}

	/**
	 * A step 2–6 rejection starts a { n: 1, t0: now } window in the rate transient.
	 *
	 * @return void
	 */
	public function test_pre_auth_rejection_starts_rate_window(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$writes = array();
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$writes ) {
				$writes[] = array( $key, $value, $ttl );
				return true;
			}
		);

		// Oversized body = a step 2 rejection.
		Probe_Command_Dispatcher::handle_raw_body( str_repeat( 'x', \Woodev_License_Command_Dispatcher::MAX_BODY_BYTES + 1 ) );

		$this->assertCount( 1, $writes, 'A pre-authentication rejection writes the counter once.' );
		$this->assertSame( \Woodev_License_Command_Dispatcher::RATE_LIMIT_TRANSIENT, $writes[0][0] );
		$this->assertSame(
			array(
				'n'  => 1,
				't0' => self::NOW,
			),
			$writes[0][1]
		);
	}

	/**
	 * 31 counted rejections then the 32nd request is gated (n = 31 > 30 within the
	 * window) — exercised through a STATEFUL transient stub, end to end.
	 *
	 * @return void
	 */
	public function test_thirty_one_rejections_then_gated(): void {
		$stored = false;
		Functions\when( 'get_transient' )->alias(
			static function () use ( &$stored ) {
				return $stored;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$oversized = str_repeat( 'x', \Woodev_License_Command_Dispatcher::MAX_BODY_BYTES + 1 );

		for ( $i = 1; $i <= 31; $i++ ) {
			$result = Probe_Command_Dispatcher::handle_raw_body( $oversized );
			$this->assertSame( 'malformed', $result['reason'], "Rejection #{$i} is still counted, not gated." );
		}

		$this->assertSame( 31, $stored['n'] );

		// The 32nd request hits the gate (31 > 30) before any body work.
		$gated = Probe_Command_Dispatcher::handle_raw_body( $oversized );

		$this->assertSame( 'rate_limited', $gated['reason'] );
		$this->assertSame( 429, $gated['http'] );
		$this->assertSame( 31, $stored['n'], 'The gated request does not bump the counter.' );
	}

	/**
	 * The window NEVER extends under traffic: a rejection arriving 61 s after t0
	 * finds the window expired (t0-anchored, not TTL-anchored), is NOT gated even
	 * with n = 31, and RESETS the window to { 1, now }.
	 *
	 * @return void
	 */
	public function test_rate_window_resets_after_expiry(): void {
		$stored = array(
			'n'  => 31,
			't0' => self::NOW - 61, // 61 s ago — window (60 s) has expired.
		);
		Functions\when( 'get_transient' )->alias(
			static function () use ( &$stored ) {
				return $stored;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$result = Probe_Command_Dispatcher::handle_raw_body( str_repeat( 'x', \Woodev_License_Command_Dispatcher::MAX_BODY_BYTES + 1 ) );

		// Not gated (window expired despite n = 31), and the window restarted at now.
		$this->assertSame( 'malformed', $result['reason'] );
		$this->assertSame(
			array(
				'n'  => 1,
				't0' => self::NOW,
			),
			$stored,
			'An expired window resets to { 1, now } instead of accumulating.'
		);
	}

	/* ----------------------------------------------------------------------- *
	 * Rejection body shape
	 * ----------------------------------------------------------------------- */

	/**
	 * A rejection result carries ONLY status + reason + http — no internals.
	 *
	 * @return void
	 */
	public function test_rejection_body_has_no_internals(): void {
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload( array( 'site' => 'https://evil.example' ) ) ),
			'inbound'
		);

		$this->assertSame( array( 'status', 'reason', 'http' ), array_keys( $result ) );
		$this->assertSame( 'rejected', $result['status'] );
	}

	/* ----------------------------------------------------------------------- *
	 * §9.3 duplicate download id — real registration behavior
	 * ----------------------------------------------------------------------- */

	/**
	 * Two engines registering the SAME download id from DIFFERENT plugin ids:
	 * the FIRST registration wins, the id is flagged ambiguous, exactly ONE
	 * error_log line is emitted, and a command targeting the id → unknown_plugin.
	 *
	 * Exercised through the REAL register_instance() routine (not seeded statics).
	 *
	 * @return void
	 */
	public function test_duplicate_download_id_first_wins_flagged_and_rejected(): void {
		Functions\expect( 'error_log' )->once();

		$first  = $this->make_engine_with_plugin( 'plugin_a', 216 );
		$second = $this->make_engine_with_plugin( 'plugin_b', 216 );

		$this->invoke_register_instance( $first );

		// After the first registration: registered, NOT ambiguous.
		$this->assertSame( $first, \Woodev_Plugins_License::get_registered_instance( '216' ) );
		$this->assertFalse( \Woodev_Plugins_License::is_download_id_ambiguous( '216' ) );

		$this->invoke_register_instance( $second );

		// First wins; the id is now ambiguous (error_log fired exactly once above).
		$this->assertSame( $first, \Woodev_Plugins_License::get_registered_instance( '216' ), 'The FIRST registration is kept on collision.' );
		$this->assertTrue( \Woodev_Plugins_License::is_download_id_ambiguous( '216' ) );

		// A command targeting the ambiguous id is rejected unknown_plugin with zero
		// side effects (no info leak about the collision).
		$this->expect_no_side_effects();

		$result = Probe_Command_Dispatcher::handle_envelope( $this->sign( $this->valid_payload() ), 'inbound' );

		$this->assertSame( 'unknown_plugin', $result['reason'] );
		$this->assertSame( 404, $result['http'] );
	}

	/**
	 * A RE-registration of the SAME plugin id is not a collision: no ambiguity flag,
	 * no error_log.
	 *
	 * @return void
	 */
	public function test_same_plugin_reregistration_is_not_a_collision(): void {
		Functions\expect( 'error_log' )->never();

		$first  = $this->make_engine_with_plugin( 'plugin_a', 216 );
		$reload = $this->make_engine_with_plugin( 'plugin_a', 216 );

		$this->invoke_register_instance( $first );
		$this->invoke_register_instance( $reload );

		$this->assertSame( $first, \Woodev_Plugins_License::get_registered_instance( '216' ) );
		$this->assertFalse( \Woodev_Plugins_License::is_download_id_ambiguous( '216' ) );
	}

	/* ----------------------------------------------------------------------- *
	 * Weekly cron prune — once per request
	 * ----------------------------------------------------------------------- */

	/**
	 * N plugin instances hook the same weekly event, but the prune listener runs the
	 * store scan ONCE per request (static guard) — behavioral: the listener is
	 * invoked twice, the wpdb scan happens once.
	 *
	 * @return void
	 */
	public function test_cron_prune_listener_runs_once_per_request(): void {
		$this->reset_cron_prune_guard();

		$plugin  = Mockery::mock( \Woodev_Plugin::class );
		$handler = new \Woodev\Framework\Handlers\Cron_Handler( $plugin );

		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => $text );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		// The whole point: TWO listener invocations, ONE store scan.
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		$handler->prune_license_command_nonces();
		$handler->prune_license_command_nonces();

		$this->reset_cron_prune_guard();
		$this->addToAssertionCount( 1 ); // Mockery's once() on get_results is the assertion.
	}

	/* ----------------------------------------------------------------------- *
	 * Wiring (gotcha framework/includes-wiring)
	 * ----------------------------------------------------------------------- */

	/**
	 * includes() must require BOTH new files unconditionally, after the licensing
	 * block — the REST endpoint and pull-fallback both reach the dispatcher on
	 * requests that are neither admin nor WooCommerce-gated, so a conditional or
	 * missing require fatals in production (the test classmap masks the gap).
	 *
	 * @return void
	 */
	public function test_includes_requires_command_core_files(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-plugin.php' );

		foreach (
			array(
				'/licensing/class-license-command-nonce-store.php',
				'/licensing/class-license-command-dispatcher.php',
			) as $relative_path
		) {
			$this->assertStringContainsString(
				"require_once \$framework_path . '" . $relative_path . "';",
				$source,
				"class-plugin.php must require_once {$relative_path}."
			);

			// Exactly one require, so it is never accidentally double-loaded.
			$this->assertSame(
				1,
				substr_count( $source, "require_once \$framework_path . '" . $relative_path . "';" ),
				"{$relative_path} must be required exactly once."
			);
		}
	}

	/**
	 * The weekly prune is wired as an ADDED listener on the EXISTING
	 * woodev_weekly_scheduled_events hook — the hook name/recurrence is untouched.
	 *
	 * @return void
	 */
	public function test_cron_handler_wires_weekly_prune_listener(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/handlers/class-cron-handler.php' );

		$this->assertStringContainsString(
			"add_action( 'woodev_weekly_scheduled_events', array( \$this, 'prune_license_command_nonces' ) )",
			$source,
			'Cron_Handler must add the prune listener on the existing weekly hook.'
		);

		// The existing license-check listener on the same hook must remain.
		$this->assertStringContainsString(
			"add_action( 'woodev_weekly_scheduled_events', array( \$this, 'weekly_license_check' ) )",
			$source,
			'The existing weekly_license_check listener must remain on the hook.'
		);
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers — license registry statics
	 * ----------------------------------------------------------------------- */

	/**
	 * Builds a real (constructor-bypassed) license engine carrying a plugin double.
	 *
	 * @param string $plugin_id   The plugin id (get_id()).
	 * @param int    $download_id The EDD download id.
	 * @return \Woodev_Plugins_License
	 */
	private function make_engine_with_plugin( string $plugin_id, int $download_id ): \Woodev_Plugins_License {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_id' )->andReturn( $plugin_id );
		$plugin->shouldReceive( 'get_download_id' )->andReturn( $download_id );

		$engine = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();

		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'plugin' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $engine, $plugin );

		return $engine;
	}

	/**
	 * Invokes the private register_instance() routine on an engine.
	 *
	 * @param \Woodev_Plugins_License $engine The engine.
	 * @return void
	 */
	private function invoke_register_instance( \Woodev_Plugins_License $engine ): void {
		$method = new \ReflectionMethod( $engine, 'register_instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$method->invoke( $engine );
	}

	/**
	 * Resets the Cron_Handler once-per-request prune guard.
	 *
	 * @return void
	 */
	private function reset_cron_prune_guard(): void {
		$property = new \ReflectionProperty( \Woodev\Framework\Handlers\Cron_Handler::class, 'nonces_pruned' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, false );
	}

	/**
	 * Seeds one engine into the Woodev_Plugins_License static registry.
	 *
	 * @param string $plugin_id Download id key.
	 * @param object $engine    License engine (mock).
	 * @return void
	 */
	private function seed_license_registry( string $plugin_id, $engine ): void {
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$registry               = (array) $property->getValue();
		$registry[ $plugin_id ] = $engine;
		$property->setValue( null, $registry );
	}

	/**
	 * Flags a download id ambiguous in the license registry static.
	 *
	 * @param string $plugin_id Download id.
	 * @return void
	 */
	private function seed_ambiguous_download_id( string $plugin_id ): void {
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'ambiguous_download_ids' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$current               = (array) $property->getValue();
		$current[ $plugin_id ] = true;
		$property->setValue( null, $current );
	}

	/**
	 * Empties the Woodev_Plugins_License static registry + ambiguous set.
	 *
	 * @return void
	 */
	private function reset_license_registry(): void {
		foreach ( array( 'registered_instances', 'ambiguous_download_ids' ) as $name ) {
			$property = new \ReflectionProperty( \Woodev_Plugins_License::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, array() );
		}
	}

	/* ----------------------------------------------------------------------- *
	 * Ack writes — dispatcher records acks for BOTH transports (§9.5)
	 * ----------------------------------------------------------------------- */

	/**
	 * Builds a fixed-now ack store whose clock matches the Probe dispatcher.
	 *
	 * @return \Woodev_License_Command_Acks
	 */
	private function make_ack_store(): \Woodev_License_Command_Acks {
		return new \Woodev_License_Command_Acks(
			static function (): int {
				return Probe_Command_Dispatcher::$fixed_now;
			}
		);
	}

	/**
	 * Arms the ack store's option stubs and returns a reference to the stored value.
	 *
	 * After calling, the referenced $stored variable mirrors the option contents
	 * for assertions.
	 *
	 * @param mixed $stored Reference that will be updated by update_option stubs.
	 * @return void
	 */
	private function arm_ack_store_stubs( &$stored ): void {
		// get_option returns $stored only for the acks option; delegate others.
		Functions\when( 'get_option' )->alias(
			static function ( $name ) use ( &$stored ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					return $stored ?? false;
				}
				return false;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					$stored = $value;
				}
				return true;
			}
		);
	}

	/**
	 * A terminal 'executed' outcome on the inbound transport records an ack entry
	 * in the ack store: { nonce, status:'executed', terminal:true, protocol:1, ts }.
	 *
	 * @return void
	 */
	public function test_inbound_terminal_outcome_records_ack(): void {
		$this->register_target_plugin();

		$ack_stored = null;
		$this->arm_ack_store_stubs( $ack_stored );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		$calls = 0;
		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		$ack_store = $this->make_ack_store();

		Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload() ),
			'inbound',
			$ack_store
		);

		$this->assertNotNull( $ack_stored, 'Ack store was written.' );
		$this->assertCount( 1, (array) $ack_stored );
		$entry = $ack_stored[0];
		$this->assertSame( str_repeat( 'a', 32 ), $entry['nonce'] );
		$this->assertSame( 'executed', $entry['status'] );
		$this->assertTrue( $entry['terminal'] );
		$this->assertSame( 1, $entry['protocol'] );
	}

	/**
	 * A terminal 'already' outcome on the pull transport records an ack entry too.
	 * Both transports write acks (§9.5, the duplicate is harmless — server ignores
	 * already-cleared nonces).
	 *
	 * @return void
	 */
	public function test_pull_terminal_outcome_records_ack(): void {
		$this->register_target_plugin();

		$ack_stored = null;
		$this->arm_ack_store_stubs( $ack_stored );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () {
				return 'already';
			}
		);

		$ack_store = $this->make_ack_store();

		Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload() ),
			'pull',
			$ack_store
		);

		$this->assertNotNull( $ack_stored );
		$this->assertSame( 'already', $ack_stored[0]['status'] );
		$this->assertTrue( $ack_stored[0]['terminal'] );
	}

	/**
	 * A 'failed' outcome (handler threw) records a NON-terminal ack (retryable)
	 * and leaves the nonce in 'processing' state (§9.6, §9.1 takeover retry).
	 *
	 * @return void
	 */
	public function test_failed_outcome_records_retryable_ack_nonce_stays_processing(): void {
		$this->register_target_plugin();

		$ack_stored    = null;
		$nonce_records = array();

		Functions\when( 'get_option' )->alias(
			static function ( $name ) use ( &$nonce_records ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					return false;
				}
				return $nonce_records[ $name ] ?? false;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$nonce_records ) {
				$nonce_records[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$nonce_records, &$ack_stored ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					$ack_stored = $value;
				} else {
					$nonce_records[ $name ] = $value;
				}
				return true;
			}
		);
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () {
				throw new \RuntimeException( 'Simulated failure.' );
			}
		);

		$ack_store = $this->make_ack_store();

		$result = Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload() ),
			'inbound',
			$ack_store
		);

		$this->assertSame( 'failed', $result['status'] );

		// Ack is recorded but NOT terminal.
		$this->assertNotNull( $ack_stored );
		$this->assertSame( 'failed', $ack_stored[0]['status'] );
		$this->assertFalse( $ack_stored[0]['terminal'], 'failed ack is NOT terminal (retryable).' );

		// Nonce NOT consumed (stays processing → §9.1 takeover retry).
		$processing_found = false;
		foreach ( $nonce_records as $record ) {
			if ( is_array( $record ) && ( 'processing' === ( $record['s'] ?? null ) ) ) {
				$processing_found = true;
			}
		}
		$this->assertTrue( $processing_found, 'Nonce stays in processing state after failed execution.' );

		// mark_consumed() must NOT have been called (no consumed record).
		foreach ( $nonce_records as $record ) {
			if ( is_array( $record ) ) {
				$this->assertNotSame( 'consumed', $record['s'] ?? null, 'Nonce must not be consumed after failed execution.' );
			}
		}
	}

	/**
	 * Frozen lifecycle order (§9.1): action → ack record → mark nonce consumed.
	 * The ack is written BEFORE mark_consumed() — verified by side-effect ordering.
	 *
	 * @return void
	 */
	public function test_ack_recorded_before_nonce_consumed(): void {
		$this->register_target_plugin();

		$sequence = array();

		Functions\when( 'get_option' )->alias(
			static function ( $name ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					return false;
				}
				return false;
			}
		);
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$sequence ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					$sequence[] = 'ack';
				} elseif ( isset( $value['s'] ) && 'consumed' === $value['s'] ) {
					$sequence[] = 'consumed';
				}
				return true;
			}
		);
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$sequence ) {
				$sequence[] = 'action';
				return 'executed';
			}
		);

		$ack_store = $this->make_ack_store();
		Probe_Command_Dispatcher::handle_envelope(
			$this->sign( $this->valid_payload() ),
			'inbound',
			$ack_store
		);

		$this->assertSame(
			array( 'action', 'ack', 'consumed' ),
			$sequence,
			'Frozen lifecycle order: action → ack record → mark nonce consumed.'
		);
	}

	/* ----------------------------------------------------------------------- *
	 * consume_pull_commands() — pull-fallback (§3.2, D-W3)
	 * ----------------------------------------------------------------------- */

	/**
	 * consume_pull_commands() with a valid license_commands array executes each
	 * envelope through the full pipeline (minus HTTP gates 1–2).
	 *
	 * @return void
	 */
	public function test_consume_pull_commands_executes_valid_envelopes(): void {
		$this->register_target_plugin();

		$ack_stored = null;
		$this->arm_ack_store_stubs( $ack_stored );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		$calls = 0;
		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		$ack_store = $this->make_ack_store();

		$payload_a   = $this->valid_payload( array( 'nonce' => str_repeat( 'a', 32 ) ) );
		$payload_b   = $this->valid_payload( array( 'nonce' => str_repeat( 'b', 32 ) ) );
		$response    = array( 'license_commands' => array( $this->sign( $payload_a ), $this->sign( $payload_b ) ) );

		Probe_Command_Dispatcher::consume_pull_commands( $response, 'pull', $ack_store );

		$this->assertSame( 2, $calls, 'Both valid commands are executed.' );
	}

	/**
	 * A [valid, tampered, valid] pull array executes 2 and skips 1 with zero side
	 * effects for the tampered entry; the remaining entries still process (§9.9).
	 *
	 * @return void
	 */
	public function test_consume_pull_commands_skips_tampered_entry(): void {
		$this->register_target_plugin();

		// Ruled s8-p5 #3: the tampered (pull) rejection must NOT touch the rate transient.
		Functions\expect( 'set_transient' )->never();

		$ack_stored = null;
		$this->arm_ack_store_stubs( $ack_stored );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		$calls = 0;
		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		$ack_store = $this->make_ack_store();

		$valid_a  = $this->sign( $this->valid_payload( array( 'nonce' => str_repeat( 'a', 32 ) ) ) );
		$tampered = $this->sign( $this->valid_payload( array( 'nonce' => str_repeat( 'c', 32 ) ) ) );
		$tampered['payload']['plugin_id'] = '999'; // Tamper the payload after signing.
		$valid_b  = $this->sign( $this->valid_payload( array( 'nonce' => str_repeat( 'b', 32 ) ) ) );

		$response = array( 'license_commands' => array( $valid_a, $tampered, $valid_b ) );

		Probe_Command_Dispatcher::consume_pull_commands( $response, 'pull', $ack_store );

		$this->assertSame( 2, $calls, '2 valid commands executed, tampered entry skipped.' );
	}

	/**
	 * A malformed (non-array) entry in the pull array is skipped with zero side
	 * effects; remaining entries still process.
	 *
	 * @return void
	 */
	public function test_consume_pull_commands_skips_malformed_entry(): void {
		$this->register_target_plugin();

		// Ruled s8-p5 #3: malformed (pull) rejections must NOT touch the rate transient.
		Functions\expect( 'set_transient' )->never();

		$ack_stored = null;
		$this->arm_ack_store_stubs( $ack_stored );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		$calls = 0;
		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		$ack_store = $this->make_ack_store();

		$valid    = $this->sign( $this->valid_payload( array( 'nonce' => str_repeat( 'a', 32 ) ) ) );
		$response = array(
			'license_commands' => array(
				$valid,
				'not-an-array',   // malformed entry.
				array( 'garbage' => 'data' ), // schema-invalid entry.
			),
		);

		Probe_Command_Dispatcher::consume_pull_commands( $response, 'pull', $ack_store );

		$this->assertSame( 1, $calls, 'Only the valid command is executed.' );
	}

	/**
	 * Double delivery (same nonce inbound then pull) executes the command exactly once.
	 * This extends the existing double-delivery test to use consume_pull_commands().
	 *
	 * @return void
	 */
	public function test_consume_pull_commands_nonce_deduplicated_with_inbound(): void {
		$this->register_target_plugin();

		$record = null;
		$ack_stored = null;

		Functions\when( 'get_option' )->alias(
			static function ( $name ) use ( &$record, &$ack_stored ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					return $ack_stored ?? false;
				}
				return $record ?? false;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$record ) {
				if ( null !== $record ) {
					return false;
				}
				$record = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$record, &$ack_stored ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					$ack_stored = $value;
				} else {
					$record = $value;
				}
				return true;
			}
		);
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		$calls = 0;
		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		$ack_store = $this->make_ack_store();
		$envelope  = $this->sign( $this->valid_payload() );

		// First: inbound.
		Probe_Command_Dispatcher::handle_envelope( $envelope, 'inbound', $ack_store );

		// Second: pull via consume_pull_commands().
		Probe_Command_Dispatcher::consume_pull_commands(
			array( 'license_commands' => array( $envelope ) ),
			'pull',
			$ack_store
		);

		$this->assertSame( 1, $calls, 'Command executes exactly once even when delivered by both transports.' );
	}

	/**
	 * consume_pull_commands() tolerates an object-shaped response (json_decode
	 * without assoc=true) by converting the payload to array via wp_json_encode.
	 *
	 * @return void
	 */
	public function test_consume_pull_commands_tolerates_object_shaped_response(): void {
		$this->register_target_plugin();

		$ack_stored = null;
		$this->arm_ack_store_stubs( $ack_stored );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		$this->arm_wpdb();

		$calls = 0;
		Probe_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		$ack_store = $this->make_ack_store();

		// Simulate json_decode without assoc=true returning an object.
		$envelope     = $this->sign( $this->valid_payload() );
		$as_object    = json_decode( (string) json_encode( array( 'license_commands' => array( $envelope ) ) ) );

		Probe_Command_Dispatcher::consume_pull_commands( $as_object, 'pull', $ack_store );

		$this->assertSame( 1, $calls, 'Object-shaped response is normalised and the command executes.' );
	}

	/**
	 * consume_pull_commands() with no license_commands field in the response is a
	 * no-op (no side effects).
	 *
	 * @return void
	 */
	public function test_consume_pull_commands_no_license_commands_is_noop(): void {
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();

		$ack_store = new \Woodev_License_Command_Acks();
		Probe_Command_Dispatcher::consume_pull_commands( array( 'other_key' => 'value' ), 'pull', $ack_store );
		// No assertion needed: expect() declarations above verify zero writes.
		$this->addToAssertionCount( 1 );
	}

	/* ----------------------------------------------------------------------- *
	 * Pull-path rejections never touch the HTTP rate-limit transient (ruled
	 * s8-p5 critic #3) — the limit guards the public REST endpoint only.
	 * ----------------------------------------------------------------------- */

	/**
	 * A malformed (schema-invalid) pull entry is rejected WITHOUT any rate-limit
	 * transient write: reject_and_count() only counts when transport === 'inbound'.
	 *
	 * @return void
	 */
	public function test_pull_malformed_entry_does_not_touch_rate_limit_transient(): void {
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();

		$ack_store = $this->make_ack_store();

		Probe_Command_Dispatcher::consume_pull_commands(
			array( 'license_commands' => array( array( 'garbage' => 'data' ) ) ),
			'pull',
			$ack_store
		);

		// The expect()->never() declarations above are the assertions.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * A bad-signature pull entry (tampered after signing) is likewise rejected with
	 * ZERO rate-transient writes — the same envelope on the INBOUND transport DOES
	 * count (contrast pinned in test_pre_auth_rejection_starts_rate_window).
	 *
	 * @return void
	 */
	public function test_pull_bad_signature_does_not_touch_rate_limit_transient(): void {
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'get_transient' )->never();

		$ack_store = $this->make_ack_store();

		$tampered                         = $this->sign( $this->valid_payload() );
		$tampered['payload']['plugin_id'] = '999'; // signature no longer matches.

		Probe_Command_Dispatcher::consume_pull_commands(
			array( 'license_commands' => array( $tampered ) ),
			'pull',
			$ack_store
		);

		$this->addToAssertionCount( 1 );
	}

	/* ----------------------------------------------------------------------- *
	 * Default ack store — production inbound wiring writes acks (ruled
	 * s8-p5 critic #2): handle_raw_body() with NO injected store must still
	 * record the §9.5 ack via a lazily-constructed default store.
	 * ----------------------------------------------------------------------- */

	/**
	 * handle_raw_body() — the production REST wiring, no manual ack-store
	 * injection — records the §9.5 ack for a terminal outcome via the DEFAULT
	 * Woodev_License_Command_Acks store.
	 *
	 * @return void
	 */
	public function test_handle_raw_body_records_ack_via_default_store(): void {
		$this->register_target_plugin();

		$ack_stored = null;
		$this->arm_ack_store_stubs( $ack_stored );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		$this->arm_wpdb();

		Probe_Command_Dispatcher::register_command( 'deactivate_plugin', static fn() => 'executed' );

		$body   = (string) json_encode( $this->sign( $this->valid_payload() ) );
		$result = Probe_Command_Dispatcher::handle_raw_body( $body );

		$this->assertSame( 'executed', $result['status'] );
		$this->assertNotNull( $ack_stored, 'handle_raw_body must write the ack through the DEFAULT store (no injection).' );
		$this->assertCount( 1, (array) $ack_stored );
		$this->assertSame( str_repeat( 'a', 32 ), $ack_stored[0]['nonce'] );
		$this->assertSame( 'executed', $ack_stored[0]['status'] );
		$this->assertTrue( $ack_stored[0]['terminal'] );
		$this->assertSame( 1, $ack_stored[0]['protocol'] );
	}

	/* ----------------------------------------------------------------------- *
	 * Wiring: class-plugin.php requires class-license-command-acks.php
	 * ----------------------------------------------------------------------- */

	/**
	 * includes() must require class-license-command-acks.php within the licensing
	 * block (gotcha framework/includes-wiring).
	 *
	 * @return void
	 */
	public function test_includes_requires_command_acks_file(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-plugin.php' );

		$this->assertStringContainsString(
			"require_once \$framework_path . '/licensing/class-license-command-acks.php';",
			$source,
			'class-plugin.php must require_once class-license-command-acks.php.'
		);

		$this->assertSame(
			1,
			substr_count( $source, "require_once \$framework_path . '/licensing/class-license-command-acks.php';" ),
			'class-license-command-acks.php must be required exactly once.'
		);
	}
}
