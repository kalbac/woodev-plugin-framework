<?php
/**
 * Tests for the Woodev_License_Command_Deactivate_Plugin command handler (s8-p4).
 *
 * Covers:
 *   - Active plugin → deactivate_plugins called once → 'executed', notice + hook + log
 *     in the pinned order (sequence capture: deactivated → notice → hook → log).
 *   - Already-inactive → 'already', NO deactivate_plugins call (dispatcher consumes the nonce).
 *   - Multisite network-active → 'network_active_unsupported', NO deactivation (§9.9).
 *   - Plugin-API require gate: gates on the FULL function set (is_plugin_active alone
 *     must NOT skip the require — critic finding s8-p4#1, separate-process test).
 *   - deactivate_plugins throwing → Throwable propagates, NOTHING persists, hook never
 *     fires (update_option NEVER — moving write_notice earlier fails the test).
 *   - Anti-pirate invariant: real engine + Woodev_License double; every persistence
 *     seam except the single notices-option update_option is forbidden.
 *   - Power boundary: vocabulary is EXACTLY ['deactivate_plugin']; hostile payload
 *     args never influence the deactivation target (registry binding only).
 *   - Notice rendering: §9.9 dedup (two instances → one render) and a license-free
 *     surviving plugin still renders a deactivated plugin's notice (render runs
 *     BEFORE the is_need_license early-return in notices()).
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
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/commands/interface-license-command.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/commands/class-license-command-deactivate-plugin.php';

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';

/**
 * Class LicenseCommandDeactivateTest.
 */
class LicenseCommandDeactivateTest extends TestCase {

	/**
	 * Plugin id used in all tests.
	 *
	 * @var string
	 */
	private const PLUGIN_ID = 'woodev-test-plugin';

	/**
	 * Plugin file (basename) returned by the mock.
	 *
	 * @var string
	 */
	private const PLUGIN_FILE = 'woodev-test-plugin/woodev-test-plugin.php';

	/**
	 * Sample payload passed to execute().
	 *
	 * @var array<string, mixed>
	 */
	private const PAYLOAD = array(
		'protocol'   => 1,
		'command'    => 'deactivate_plugin',
		'site'       => 'https://example.com',
		'plugin_id'  => '216',
		'nonce'      => 'aabbccdd11223344aabbccdd11223344',
		'issued_at'  => 1_700_000_000,
		'expires_at' => 1_700_001_000,
	);

	/**
	 * The Woodev_Plugin mock backing the engine built by make_engine().
	 *
	 * Exposed so tests can pin the log() seam directly on the right mock
	 * (Mockery demeter-chain expectations land on the wrong object).
	 *
	 * @var \Mockery\MockInterface|null
	 */
	private $plugin_mock = null;

	/**
	 * Stubs the plugin-API functions and builds a command handler.
	 *
	 * ALL THREE plugin-API functions used by execute()'s require gate are
	 * stubbed (not only the ones a given scenario reaches) so
	 * needs_plugin_api() sees is_plugin_active + is_plugin_active_for_network
	 * defined in ordinary tests; the separate-process require-gate test below
	 * deliberately bypasses this helper.
	 *
	 * @param bool $is_active      Whether is_plugin_active() returns true.
	 * @param bool $is_multisite   Whether is_multisite() returns true.
	 * @param bool $network_active Whether is_plugin_active_for_network() returns true.
	 * @return \Woodev_License_Command_Deactivate_Plugin
	 */
	private function make_handler(
		bool $is_active = true,
		bool $is_multisite = false,
		bool $network_active = false
	): \Woodev_License_Command_Deactivate_Plugin {

		Functions\when( 'is_multisite' )->justReturn( $is_multisite );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( $network_active );
		Functions\when( 'is_plugin_active' )->justReturn( $is_active );

		return new \Woodev_License_Command_Deactivate_Plugin();
	}

	/**
	 * Builds a mock Woodev_Plugins_License (the "engine") whose plugin returns the
	 * given id and file. The plugin mock is stored in $this->plugin_mock; it is
	 * STRICT: any un-expected method call (including a stray log()) throws.
	 * Executed-path tests must add an explicit log() expectation via expect_log_once().
	 *
	 * @param string $plugin_id   Plugin id.
	 * @param string $plugin_file Plugin file basename.
	 * @return \Mockery\MockInterface
	 */
	private function make_engine(
		string $plugin_id = self::PLUGIN_ID,
		string $plugin_file = self::PLUGIN_FILE
	): object {

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_id' )->andReturn( $plugin_id );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( $plugin_file );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test Plugin' );

		$this->plugin_mock = $plugin;

		$engine = Mockery::mock( \Woodev_Plugins_License::class );
		$engine->shouldReceive( 'get_plugin' )->andReturn( $plugin );

		return $engine;
	}

	/**
	 * Pins the plugin log seam: exactly ONE call with a non-empty string message.
	 *
	 * @return void
	 */
	private function expect_log_once(): void {
		$this->plugin_mock->shouldReceive( 'log' )
			->once()
			->with(
				Mockery::on(
					static function ( $message ): bool {
						return is_string( $message ) && '' !== $message;
					}
				)
			);
	}

	/* ----------------------------------------------------------------------- *
	 * Happy path
	 * ----------------------------------------------------------------------- */

	/**
	 * Active plugin: deactivate_plugins called once with correct args, returns
	 * 'executed'; notice written, hook fired with the payload, log written once.
	 *
	 * @return void
	 */
	public function test_active_plugin_deactivated_returns_executed(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true );

		// Notice option write.
		Functions\expect( 'get_option' )
			->once()
			->with( 'woodev_license_remote_deactivation_notices', array() )
			->andReturn( array() );
		Functions\expect( 'update_option' )
			->once()
			->with(
				'woodev_license_remote_deactivation_notices',
				Mockery::type( 'array' ),
				'no'
			)
			->andReturn( true );

		// deactivate_plugins called exactly once: single target, no silent, no network.
		Functions\expect( 'deactivate_plugins' )
			->once()
			->with( self::PLUGIN_FILE, false, false );

		// Hook fired once with the payload.
		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )
			->once()
			->with( self::PAYLOAD );

		// One log line, non-empty (critic finding s8-p4#5).
		$this->expect_log_once();

		$status = $handler->execute( $engine, self::PAYLOAD );

		$this->assertSame( 'executed', $status );
	}

	/**
	 * Executed-path ordering is pinned: deactivate_plugins → notice write → hook →
	 * log, captured through a sequence array (critic finding s8-p4#5). Moving
	 * write_notice() before deactivate_plugins(), or the hook before the notice,
	 * fails this test.
	 *
	 * @return void
	 */
	public function test_executed_path_sequence_deactivate_notice_hook_log(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true );

		$sequence = array();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			static function () use ( &$sequence ) {
				$sequence[] = 'notice';
				return true;
			}
		);
		Functions\when( 'deactivate_plugins' )->alias(
			static function () use ( &$sequence ) {
				$sequence[] = 'deactivated';
			}
		);

		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )
			->once()
			->whenHappen(
				static function () use ( &$sequence ) {
					$sequence[] = 'hook';
				}
			);

		$this->plugin_mock->shouldReceive( 'log' )
			->once()
			->with(
				Mockery::on(
					static function ( $message ): bool {
						return is_string( $message ) && '' !== $message;
					}
				)
			)
			->andReturnUsing(
				static function () use ( &$sequence ) {
					$sequence[] = 'log';
				}
			);

		$this->assertSame( 'executed', $handler->execute( $engine, self::PAYLOAD ) );

		$this->assertSame(
			array( 'deactivated', 'notice', 'hook', 'log' ),
			$sequence,
			'Executed-path order is frozen: deactivate → notice → hook → log.'
		);
	}

	/**
	 * Already-inactive plugin: 'already' returned, deactivate_plugins NOT called.
	 *
	 * @return void
	 */
	public function test_already_inactive_returns_already_no_deactivation(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( false );

		Functions\expect( 'deactivate_plugins' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'do_action' )->never();

		$status = $handler->execute( $engine, self::PAYLOAD );

		$this->assertSame( 'already', $status );
	}

	/**
	 * Multisite + network-active: 'network_active_unsupported', NO deactivation (§9.9).
	 *
	 * @return void
	 */
	public function test_multisite_network_active_returns_network_active_unsupported(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true, true, true );

		Functions\expect( 'deactivate_plugins' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'do_action' )->never();

		$status = $handler->execute( $engine, self::PAYLOAD );

		$this->assertSame( 'network_active_unsupported', $status );
	}

	/**
	 * Multisite, but NOT network-active: treats as a regular per-site deactivation.
	 *
	 * @return void
	 */
	public function test_multisite_per_site_activated_proceeds_normally(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true, true, false );

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'deactivate_plugins' )->justReturn( null );

		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )->once();
		$this->expect_log_once();

		$status = $handler->execute( $engine, self::PAYLOAD );

		$this->assertSame( 'executed', $status );
	}

	/* ----------------------------------------------------------------------- *
	 * Plugin-API require gate (critic finding s8-p4#1)
	 * ----------------------------------------------------------------------- */

	/**
	 * is_plugin_active() defined but is_plugin_active_for_network() /
	 * deactivate_plugins() absent → needs_plugin_api() is TRUE and execute()
	 * performs the require (asserted via get_included_files() against the
	 * tests/wp-admin/includes/plugin.php stand-in that ABSPATH resolves to).
	 *
	 * Separate process: Brain Monkey function definitions persist per process
	 * (gotcha testing/brain-monkey-function-pollution), so "absent" is only
	 * provable in a fresh one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_partial_plugin_api_triggers_require(): void {

		// Define ONLY is_plugin_active (+ is_multisite for the execute() flow);
		// the other two plugin-API functions stay undefined in this fresh process.
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertTrue(
			\Woodev_License_Command_Deactivate_Plugin::needs_plugin_api(),
			'is_plugin_active() alone must NOT satisfy the plugin-API gate (critic s8-p4#1).'
		);

		$engine  = $this->make_engine();
		$handler = new \Woodev_License_Command_Deactivate_Plugin();

		// Already-inactive path: exercises the require without calling the
		// still-undefined deactivate_plugins().
		$this->assertSame( 'already', $handler->execute( $engine, self::PAYLOAD ) );

		$fixture = realpath( ABSPATH . 'wp-admin/includes/plugin.php' );
		$this->assertNotFalse( $fixture, 'The unit-suite plugin.php stand-in must exist under tests/.' );
		$this->assertContains(
			$fixture,
			array_map( 'realpath', get_included_files() ),
			'execute() must require_once wp-admin/includes/plugin.php when ANY plugin-API function is missing.'
		);
	}

	/**
	 * All three plugin-API functions defined → needs_plugin_api() is false
	 * (the require gate stays closed).
	 *
	 * @return void
	 */
	public function test_full_plugin_api_reports_no_require_needed(): void {

		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'deactivate_plugins' )->justReturn( null );

		$this->assertFalse( \Woodev_License_Command_Deactivate_Plugin::needs_plugin_api() );
	}

	/* ----------------------------------------------------------------------- *
	 * Failure path (Throwable propagation → dispatcher catches → 'failed')
	 * ----------------------------------------------------------------------- */

	/**
	 * deactivate_plugins throwing propagates out of execute() AND nothing persists:
	 * update_option is NEVER called (so moving write_notice() before
	 * deactivate_plugins() fails this test — critic finding s8-p4#3) and the hook
	 * never fires. The expected exception is the EXACT RuntimeException — a Mockery
	 * violation (e.g. an unexpected update_option call) is a different class and
	 * fails the assertion.
	 *
	 * @return void
	 */
	public function test_deactivate_plugins_throwing_propagates_throwable(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true );

		Functions\when( 'deactivate_plugins' )->alias(
			static function () {
				throw new \RuntimeException( 'WP deactivation error' );
			}
		);

		// §9.9 hook-failure semantics: zero persistence, no hook.
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'do_action' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'WP deactivation error' );

		$handler->execute( $engine, self::PAYLOAD );
	}

	/**
	 * When the dispatcher catches a Throwable from the handler, the result is
	 * 'failed'/500 and the nonce stays in 'processing' (not consumed).
	 *
	 * @return void
	 */
	public function test_dispatcher_catches_throwable_returns_failed(): void {

		$engine = $this->make_engine();
		$this->seed_license_registry( '216', $engine );

		$this->arm_wpdb();

		// Stub the nonce store happy path.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_unserialize' )->returnArg();

		// mark_consumed must NOT run (nonce stays processing → §9.1 takeover retry).
		Functions\expect( 'update_option' )->never();

		// Register a throwing handler.
		\Woodev_License_Command_Dispatcher::reset_commands_for_tests();
		\Woodev_License_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () {
				throw new \RuntimeException( 'Simulated deactivation failure' );
			}
		);

		Functions\when( 'wp_json_encode' )->alias( static fn( $d, $o = 0, $dep = 512 ) => json_encode( $d, $o, $dep ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u, $c ) );
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium not available.' );
		}

		$keypair    = sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) );
		$secret_key = sodium_crypto_sign_secretkey( $keypair );
		$pub_b64    = base64_encode( sodium_crypto_sign_publickey( $keypair ) );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) use ( $pub_b64 ) {
				return 'woodev_license_authority_pubkey' === $hook ? $pub_b64 : $value;
			}
		);

		$payload   = array(
			'protocol'   => 1,
			'command'    => 'deactivate_plugin',
			'site'       => 'https://example.com',
			'plugin_id'  => '216',
			'nonce'      => 'aabbccdd11223344aabbccdd11223300',
			'issued_at'  => time() - 10,
			'expires_at' => time() + 1000,
		);
		$canonical = \Woodev_License_Envelope_Verifier::canonical_json( $payload );
		$envelope  = array(
			'payload'   => $payload,
			'signature' => base64_encode( sodium_crypto_sign_detached( $canonical, $secret_key ) ),
		);

		$result = \Woodev_License_Command_Dispatcher::handle_envelope( $envelope, 'inbound' );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 500, $result['http'] );
	}

	/* ----------------------------------------------------------------------- *
	 * Notice option
	 * ----------------------------------------------------------------------- */

	/**
	 * Notice option is written on 'executed' path only: Russian message, count-neutral,
	 * no _n() call, keyed by plugin id, autoload 'no'.
	 *
	 * @return void
	 */
	public function test_notice_option_written_on_executed(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true );

		$written_value = null;

		Functions\when( 'get_option' )->justReturn( array() );

		Functions\expect( 'update_option' )
			->once()
			->with(
				'woodev_license_remote_deactivation_notices',
				Mockery::on(
					static function ( $v ) use ( &$written_value ) {
						$written_value = $v;
						return is_array( $v );
					}
				),
				'no'
			)
			->andReturn( true );

		Functions\when( 'deactivate_plugins' )->justReturn( null );
		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )->once();
		$this->expect_log_once();

		$handler->execute( $engine, self::PAYLOAD );

		$this->assertIsArray( $written_value );
		$this->assertArrayHasKey( self::PLUGIN_ID, $written_value );
		$entry = $written_value[ self::PLUGIN_ID ];

		// Must have 'message' and 'ts' keys (plan decision 10 schema).
		$this->assertArrayHasKey( 'message', $entry );
		$this->assertArrayHasKey( 'ts', $entry );
		$this->assertIsString( $entry['message'] );
		$this->assertNotEmpty( $entry['message'] );
		$this->assertIsInt( $entry['ts'] );
	}

	/**
	 * Notice option is NOT written on 'already' path.
	 *
	 * @return void
	 */
	public function test_notice_option_not_written_on_already(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( false );

		Functions\expect( 'update_option' )->never();

		$handler->execute( $engine, self::PAYLOAD );
	}

	/**
	 * Notice option is NOT written on 'network_active_unsupported' path.
	 *
	 * @return void
	 */
	public function test_notice_option_not_written_on_network_active_unsupported(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true, true, true );

		Functions\expect( 'update_option' )->never();

		$handler->execute( $engine, self::PAYLOAD );
	}

	/* ----------------------------------------------------------------------- *
	 * Hook contract
	 * ----------------------------------------------------------------------- */

	/**
	 * Hook 'woodev_{id}_remote_deactivated' is fired AFTER deactivate_plugins returns,
	 * with EXACTLY the payload array as its single argument.
	 *
	 * @return void
	 */
	public function test_hook_fired_after_successful_deactivation_with_payload(): void {

		$engine   = $this->make_engine();
		$handler  = $this->make_handler( true );
		$received = null;

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'deactivate_plugins' )->justReturn( null );
		$this->expect_log_once();

		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )
			->once()
			->whenHappen(
				static function ( $payload ) use ( &$received ) {
					$received = $payload;
				}
			);

		$handler->execute( $engine, self::PAYLOAD );

		$this->assertSame( self::PAYLOAD, $received );
	}

	/**
	 * Hook is NOT fired on 'already' path.
	 *
	 * @return void
	 */
	public function test_hook_not_fired_on_already(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( false );

		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )->never();

		$handler->execute( $engine, self::PAYLOAD );
	}

	/**
	 * Hook is NOT fired on 'network_active_unsupported' path.
	 *
	 * @return void
	 */
	public function test_hook_not_fired_on_network_active_unsupported(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true, true, true );

		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )->never();

		$handler->execute( $engine, self::PAYLOAD );
	}

	/* ----------------------------------------------------------------------- *
	 * Idempotency
	 * ----------------------------------------------------------------------- */

	/**
	 * Two deliveries of an already-inactive plugin both return 'already', and
	 * deactivate_plugins is never called on either (§9.9 idempotency test).
	 *
	 * @return void
	 */
	public function test_idempotency_already_inactive_deactivate_never_called(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( false );

		Functions\expect( 'deactivate_plugins' )->never();
		Functions\expect( 'update_option' )->never();

		$this->assertSame( 'already', $handler->execute( $engine, self::PAYLOAD ) );
		$this->assertSame( 'already', $handler->execute( $engine, self::PAYLOAD ) );
	}

	/* ----------------------------------------------------------------------- *
	 * Anti-pirate invariant — no license-state writes on ANY persistence seam
	 * ----------------------------------------------------------------------- */

	/**
	 * The executed path performs EXACTLY ONE persistent write — the notices option
	 * via update_option — and every other persistence seam is forbidden: add_option,
	 * delete_option, update_site_option, delete_site_option, set_transient,
	 * delete_transient all expect never (critic finding s8-p4#2). The engine is a
	 * REAL Woodev_Plugins_License instance with a Woodev_License double injected,
	 * so any save()/delete()/update() on stored license state fails the test too.
	 *
	 * @return void
	 */
	public function test_no_license_state_writes_on_executed_path(): void {

		$handler = $this->make_handler( true );

		// License-store double: ANY state write on it fails the test.
		$license_double = Mockery::mock( \Woodev_License::class );
		$license_double->shouldNotReceive( 'save' );
		$license_double->shouldNotReceive( 'delete' );
		$license_double->shouldNotReceive( 'update' );

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_id' )->andReturn( self::PLUGIN_ID );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( self::PLUGIN_FILE );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test Plugin' );
		$plugin->shouldReceive( 'log' )
			->once()
			->with(
				Mockery::on(
					static function ( $message ): bool {
						return is_string( $message ) && '' !== $message;
					}
				)
			);

		// REAL engine (not a mock): execute() goes through the real get_plugin(),
		// and the injected license double guards the stored-license-state seam.
		$engine     = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();
		$properties = array(
			'plugin'         => $plugin,
			'woodev_license' => $license_double,
		);
		foreach ( $properties as $name => $value ) {
			$property = new \ReflectionProperty( \Woodev_Plugins_License::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $engine, $value );
		}

		// The ONE allowed write: the notices option, exactly once, autoload 'no'.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'update_option' )
			->once()
			->with( 'woodev_license_remote_deactivation_notices', Mockery::type( 'array' ), 'no' )
			->andReturn( true );

		// EVERY other persistence seam is forbidden.
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'update_site_option' )->never();
		Functions\expect( 'delete_site_option' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_transient' )->never();

		Functions\when( 'deactivate_plugins' )->justReturn( null );
		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )->once();

		$this->assertSame( 'executed', $handler->execute( $engine, self::PAYLOAD ) );
	}

	/* ----------------------------------------------------------------------- *
	 * D-W1 power boundary
	 * ----------------------------------------------------------------------- */

	/**
	 * After ensure_default_commands() on a clean registry the vocabulary is
	 * EXACTLY ['deactivate_plugin'] — count 1, the real handler instance, and
	 * delete_plugin absent (critic finding s8-p4#6a). A second
	 * ensure_default_commands() call is a no-op (idempotent static guard).
	 *
	 * @return void
	 */
	public function test_v1_vocabulary_is_exactly_deactivate_plugin(): void {

		\Woodev_License_Command_Dispatcher::reset_commands_for_tests();
		\Woodev_License_Command_Deactivate_Plugin::reset_notice_dedup_for_tests(); // Resets the defaults guard too.

		\Woodev_License_Command_Deactivate_Plugin::ensure_default_commands();
		\Woodev_License_Command_Deactivate_Plugin::ensure_default_commands(); // Idempotent.

		$commands_prop = new \ReflectionProperty( \Woodev_License_Command_Dispatcher::class, 'commands' );
		if ( PHP_VERSION_ID < 80100 ) {
			$commands_prop->setAccessible( true );
		}
		$vocab = $commands_prop->getValue();

		$this->assertCount( 1, $vocab, 'The v1 vocabulary holds EXACTLY one command (D-W1).' );
		$this->assertSame( array( 'deactivate_plugin' ), array_keys( $vocab ) );
		$this->assertInstanceOf( \Woodev_License_Command_Deactivate_Plugin::class, $vocab['deactivate_plugin'] );
		$this->assertArrayNotHasKey( 'delete_plugin', $vocab, 'delete_plugin must NOT be in the v1 vocabulary (D-W1).' );
	}

	/**
	 * Hostile payload fields never influence the deactivation target: with
	 * args {file: 'evil/evil.php'} and a foreign plugin_id, deactivate_plugins
	 * receives EXACTLY the registry target's get_plugin_file(), and the hook
	 * name binds to the TARGET's get_id(), not the payload (critic s8-p4#6b).
	 *
	 * @return void
	 */
	public function test_hostile_payload_args_never_influence_deactivation_target(): void {

		$engine  = $this->make_engine();
		$handler = $this->make_handler( true );

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		// EXACT argument pin: the registry target's file — nothing from the payload.
		Functions\expect( 'deactivate_plugins' )
			->once()
			->with( self::PLUGIN_FILE, false, false );

		$hostile = array_merge(
			self::PAYLOAD,
			array(
				'plugin_id' => '999',
				'args'      => array( 'file' => 'evil/evil.php' ),
			)
		);

		// Hook name uses the TARGET's id ('woodev-test-plugin'), not payload plugin_id.
		Actions\expectDone( 'woodev_' . self::PLUGIN_ID . '_remote_deactivated' )
			->once()
			->with( $hostile );

		$this->expect_log_once();

		$this->assertSame( 'executed', $handler->execute( $engine, $hostile ) );
	}

	/* ----------------------------------------------------------------------- *
	 * Command name
	 * ----------------------------------------------------------------------- */

	/**
	 * get_name() returns 'deactivate_plugin'.
	 *
	 * @return void
	 */
	public function test_get_name_returns_deactivate_plugin(): void {

		$handler = new \Woodev_License_Command_Deactivate_Plugin();

		$this->assertSame( 'deactivate_plugin', $handler->get_name() );
	}

	/* ----------------------------------------------------------------------- *
	 * Notice rendering (§9.9 dedup + license-free surviving plugin)
	 * ----------------------------------------------------------------------- */

	/**
	 * Two license engine instances sharing ONE stored deactivation notice → the notice
	 * is rendered exactly once per request (static per-request dedup guard, §9.9).
	 *
	 * @return void
	 */
	public function test_notice_dedup_two_instances_one_stored_notice(): void {

		$notice_entry = array(
			'message' => 'Плагин Test Plugin был удалённо деактивирован: истёк срок лицензии.',
			'ts'      => 1_700_000_000,
		);

		\Woodev_License_Command_Deactivate_Plugin::reset_notice_dedup_for_tests();

		Functions\when( 'get_option' )->justReturn( array( self::PLUGIN_ID => $notice_entry ) );

		// Count add_admin_notice calls across BOTH instances.
		$notice_add_count = 0;
		$notice_handler   = Mockery::mock( \Woodev_Admin_Notice_Handler::class );
		$notice_handler->shouldReceive( 'add_admin_notice' )
			->andReturnUsing(
				static function () use ( &$notice_add_count ) {
					$notice_add_count++;
				}
			);

		$engine_a = $this->make_engine_for_notices( $notice_handler );
		$engine_b = $this->make_engine_for_notices( $notice_handler );

		\Woodev_License_Command_Deactivate_Plugin::render_remote_deactivation_notices( $engine_a );
		\Woodev_License_Command_Deactivate_Plugin::render_remote_deactivation_notices( $engine_b );

		$this->assertSame(
			1,
			$notice_add_count,
			'Two instances with one stored notice must render the notice exactly once (static dedup guard).'
		);
	}

	/**
	 * A LICENSE-FREE surviving plugin (is_need_license() === false) still renders a
	 * stored deactivation notice for ANOTHER plugin: the render call sits BEFORE the
	 * is_need_license() early-return in Woodev_Plugins_License::notices() (critic
	 * finding s8-p4#4 — moving it after the guard fails this test).
	 *
	 * @return void
	 */
	public function test_license_free_surviving_plugin_still_renders_foreign_notice(): void {

		\Woodev_License_Command_Deactivate_Plugin::reset_notice_dedup_for_tests();

		// A stored notice for a DIFFERENT (deactivated) plugin.
		Functions\when( 'get_option' )->justReturn(
			array(
				'other-plugin' => array(
					'message' => 'Плагин Other был удалённо деактивирован: истёк срок лицензии.',
					'ts'      => 1_700_000_000,
				),
			)
		);

		$rendered_ids   = array();
		$notice_handler = Mockery::mock( \Woodev_Admin_Notice_Handler::class );
		$notice_handler->shouldReceive( 'add_admin_notice' )
			->once()
			->andReturnUsing(
				static function ( $message, $message_id ) use ( &$rendered_ids ) {
					$rendered_ids[] = $message_id;
				}
			);

		// The surviving plugin declares itself license-free.
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $notice_handler );

		// REAL engine so the REAL notices() control flow (render BEFORE the
		// is_need_license early-return) is what is under test.
		$engine   = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'plugin' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $engine, $plugin );

		$engine->notices();

		$this->assertSame(
			array( 'woodev_other-plugin_remote_deactivated' ),
			$rendered_ids,
			'A license-free surviving plugin must still render the deactivated plugin\'s notice.'
		);
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------------- */

	/**
	 * Builds an engine mock suitable for notice-rendering tests.
	 *
	 * @param \Mockery\MockInterface $notice_handler Notice handler mock.
	 * @return \Mockery\MockInterface Engine mock.
	 */
	private function make_engine_for_notices( object $notice_handler ): object {

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_id' )->andReturn( self::PLUGIN_ID );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test Plugin' );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $notice_handler );

		$engine = Mockery::mock( \Woodev_Plugins_License::class );
		$engine->shouldReceive( 'get_plugin' )->andReturn( $plugin );

		return $engine;
	}

	/**
	 * Installs a wpdb double (needed by the dispatcher nonce-store path).
	 *
	 * @return \Mockery\MockInterface
	 */
	private function arm_wpdb(): object {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $t ) => $t );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	/**
	 * Seeds the Woodev_Plugins_License static registry.
	 *
	 * @param string $plugin_id Download id.
	 * @param object $engine    Engine instance.
	 * @return void
	 */
	private function seed_license_registry( string $plugin_id, object $engine ): void {
		$prop = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$reg               = (array) $prop->getValue();
		$reg[ $plugin_id ] = $engine;
		$prop->setValue( null, $reg );
	}

	/**
	 * Resets the Woodev_Plugins_License static registries.
	 *
	 * @return void
	 */
	private function reset_license_registry(): void {
		foreach ( array( 'registered_instances', 'ambiguous_download_ids' ) as $name ) {
			$prop = new \ReflectionProperty( \Woodev_Plugins_License::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$prop->setAccessible( true );
			}
			$prop->setValue( null, array() );
		}
	}

	/**
	 * Reset statics after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\Woodev_License_Command_Dispatcher::reset_commands_for_tests();
		$this->reset_license_registry();
		\Woodev_License_Command_Deactivate_Plugin::reset_notice_dedup_for_tests();
		$this->plugin_mock = null;
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}
}
