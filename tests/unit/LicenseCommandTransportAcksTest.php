<?php
/**
 * Transport hook-in tests for pull-fallback command delivery + structured acks.
 *
 * Covers:
 * - check_license ($api_params) WITHOUT consumed_command_nonces when store is empty
 *   (field ABSENT — request shape byte-for-byte identical to pre-ack shape).
 * - check_license ($api_params) WITH consumed_command_nonces as structured entries
 *   when there are pending acks.
 * - Updater get_api_params() likewise (both cases).
 * - Response acks_received clears exactly those nonces from the store.
 * - dispatch() throw → ack store untouched (outage grace §3.2).
 * - Lost-ack redelivery: pending acks survive a response WITHOUT acks_received;
 *   they are attached again on the next request.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

// Minimal includes needed for dispatch() path.
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-response.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-authority-claims.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-nonce-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-dispatcher.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-acks.php';
require_once dirname( __DIR__, 2 ) . '/woodev/plugin-updater/class-plugin-updater.php';

/**
 * Class LicenseCommandTransportAcksTest.
 */
class LicenseCommandTransportAcksTest extends TestCase {

	/**
	 * Fixed "now" timestamp used across tests.
	 *
	 * @var int
	 */
	private const NOW = 1_700_000_000;

	/**
	 * dispatch() now consumes pull commands on EVERY successful response (critic
	 * ruling s8-p5 #1) — consume_pull_commands() normalises object-shaped payloads
	 * via wp_json_encode, so it must be stubbed for all dispatch-path tests.
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
	 * Resets dispatcher/registry statics the pull-consumption test seeds.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\Woodev_License_Command_Dispatcher::reset_commands_for_tests();

		foreach ( array( 'registered_instances', 'ambiguous_download_ids' ) as $name ) {
			$property = new \ReflectionProperty( \Woodev_Plugins_License::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, array() );
		}

		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Builds a Woodev_Plugins_License instance with a mocked plugin and api_handler.
	 *
	 * @param object      $api_handler   Pre-configured api_handler double.
	 * @param object|null $plugin_double Optional plugin double (if null, one is created).
	 * @return \Woodev_Plugins_License
	 */
	private function make_license_engine( $api_handler, $plugin_double = null ): \Woodev_Plugins_License {
		if ( null === $plugin_double ) {
			$plugin_double = Mockery::mock( \Woodev_Plugin::class );
			$plugin_double->shouldReceive( 'get_download_id' )->andReturn( 216 );
			$plugin_double->shouldReceive( 'get_version' )->andReturn( '2.0.0' );
			$plugin_double->shouldReceive( 'get_id' )->andReturn( 'test_plugin' );
		}

		$engine = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();

		$prop_plugin = new \ReflectionProperty( \Woodev_Plugins_License::class, 'plugin' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop_plugin->setAccessible( true );
		}
		$prop_plugin->setValue( $engine, $plugin_double );

		$prop_api = new \ReflectionProperty( \Woodev_Plugins_License::class, 'api_handler' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop_api->setAccessible( true );
		}
		$prop_api->setValue( $engine, $api_handler );

		return $engine;
	}

	/**
	 * Invokes the private dispatch() method on a license engine.
	 *
	 * @param \Woodev_Plugins_License $engine     The engine.
	 * @param string                  $action     The action.
	 * @param string                  $license_key The license key.
	 * @return mixed
	 */
	private function call_dispatch( $engine, string $action = 'check_license', string $license_key = 'KEY-123' ) {
		$method = new \ReflectionMethod( $engine, 'dispatch' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( $engine, $action, $license_key );
	}

	/**
	 * Builds a Woodev_Plugin_Updater bypassing constructor.
	 *
	 * @param array<string, mixed> $api_data  The api_data fields.
	 * @param string               $slug      The slug.
	 * @param object|null          $plugin    Plugin double for injection.
	 * @return \Woodev_Plugin_Updater
	 */
	private function make_updater( array $api_data, string $slug, $plugin = null ): \Woodev_Plugin_Updater {
		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();

		$api_prop = new \ReflectionProperty( \Woodev_Plugin_Updater::class, 'api_data' );
		if ( PHP_VERSION_ID < 80100 ) {
			$api_prop->setAccessible( true );
		}
		$api_prop->setValue( $updater, $api_data );

		$slug_prop = new \ReflectionProperty( \Woodev_Plugin_Updater::class, 'slug' );
		if ( PHP_VERSION_ID < 80100 ) {
			$slug_prop->setAccessible( true );
		}
		$slug_prop->setValue( $updater, $slug );

		$beta_prop = new \ReflectionProperty( \Woodev_Plugin_Updater::class, 'beta' );
		if ( PHP_VERSION_ID < 80100 ) {
			$beta_prop->setAccessible( true );
		}
		$beta_prop->setValue( $updater, false );

		if ( null !== $plugin ) {
			$plugin_prop = new \ReflectionProperty( \Woodev_Plugin_Updater::class, 'plugin' );
			if ( PHP_VERSION_ID < 80100 ) {
				$plugin_prop->setAccessible( true );
			}
			$plugin_prop->setValue( $updater, $plugin );
		}

		return $updater;
	}

	/**
	 * Invokes a private method on an object.
	 *
	 * @param object $object     The target object.
	 * @param string $method     The method name.
	 * @param array  $args       Arguments.
	 * @return mixed
	 */
	private function call_private( $object, string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->invokeArgs( $object, $args );
	}

	/**
	 * Sets a private property on an object.
	 *
	 * @param object $object   The target.
	 * @param string $property Property name.
	 * @param mixed  $value    The value.
	 * @return void
	 */
	private function set_private( $object, string $property, $value ): void {
		$ref = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$ref->setValue( $object, $value );
	}

	/**
	 * Stubs WP functions for option IO, translating acks option reads/writes through a
	 * variable reference for assertions.
	 *
	 * @param mixed $stored Reference variable that mirrors the acks option value.
	 * @return void
	 */
	private function stub_option_io( &$stored ): void {
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

	// -----------------------------------------------------------------------
	// check_license $api_params shape
	// -----------------------------------------------------------------------

	/**
	 * dispatch() with NO pending acks produces a $api_params array that is
	 * byte-for-byte identical to the pre-ack shape — the consumed_command_nonces
	 * field must be ABSENT.
	 *
	 * @return void
	 */
	public function test_dispatch_params_no_pending_acks_field_absent(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		// No pending acks — acks option returns empty.
		$ack_stored = false;
		$this->stub_option_io( $ack_stored );

		$captured_params = null;
		$api_handler     = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function ( $params ) use ( &$captured_params ) {
				$captured_params = $params;
				// Return a truthy response to not throw.
				$response = Mockery::mock();
				$response->shouldReceive( 'get_response_data' )->andReturn( (object) array() );
				return $response;
			}
		);

		$engine = $this->make_license_engine( $api_handler );

		try {
			$this->call_dispatch( $engine, 'check_license', 'KEY-123' );
		} catch ( \Exception $e ) {
			// May throw "Cannot get license data" — that's fine.
		}

		// The FULL pre-change shape, byte-for-byte (critic ruling s8-p5 #5): the
		// consumed_command_nonces field must be ABSENT and every existing field
		// must carry exactly the pre-ack value, in the pre-ack order.
		$this->assertSame(
			array(
				'edd_action' => 'check_license',
				'license'    => 'KEY-123',
				'item_id'    => 216,
				'url'        => 'https://example.com',
				'version'    => '2.0.0',
			),
			$captured_params,
			'No-pending-acks: $api_params must equal the pre-ack shape byte-for-byte (EDD wire contract).'
		);
	}

	/**
	 * dispatch() WITH pending acks adds consumed_command_nonces as structured entries
	 * to $api_params.
	 *
	 * @return void
	 */
	public function test_dispatch_params_with_pending_acks_field_present(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		// Use current time so the entry is not pruned by the 30-day retention window.
		$pending_entry = array(
			'nonce'    => str_repeat( 'a', 32 ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => time(),
		);

		$ack_stored = array( $pending_entry );
		$this->stub_option_io( $ack_stored );

		$captured_params = null;
		$api_handler     = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function ( $params ) use ( &$captured_params ) {
				$captured_params = $params;
				$response = Mockery::mock();
				$response->shouldReceive( 'get_response_data' )->andReturn( (object) array() );
				return $response;
			}
		);

		$engine = $this->make_license_engine( $api_handler );

		try {
			$this->call_dispatch( $engine, 'check_license', 'KEY-123' );
		} catch ( \Exception $e ) {
			// May throw — that's fine.
		}

		$this->assertIsArray( $captured_params );
		$this->assertArrayHasKey(
			'consumed_command_nonces',
			$captured_params,
			'consumed_command_nonces must be present when there are pending acks.'
		);
		$this->assertSame( array( $pending_entry ), $captured_params['consumed_command_nonces'] );
	}

	// -----------------------------------------------------------------------
	// Updater get_api_params() shape
	// -----------------------------------------------------------------------

	/**
	 * get_api_params() with NO pending acks is byte-for-byte identical to the
	 * pre-ack shape — consumed_command_nonces must be ABSENT.
	 *
	 * @return void
	 */
	public function test_updater_params_no_pending_acks_field_absent(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );

		$ack_stored = false;
		$this->stub_option_io( $ack_stored );

		$updater = $this->make_updater(
			array( 'license' => 'KEY-123', 'item_id' => 216, 'version' => '2.0.0' ),
			'woodev-test-plugin'
		);

		$params = $this->call_private( $updater, 'get_api_params' );

		// Exact pre-change shape.
		$this->assertSame(
			array(
				'edd_action'  => 'get_version',
				'license'     => 'KEY-123',
				'item_id'     => 216,
				'version'     => '2.0.0',
				'slug'        => 'woodev-test-plugin',
				'beta'        => false,
				'php_version' => phpversion(),
				'wp_version'  => '6.5',
				'url'         => 'https://example.com/',
			),
			$params,
			'No-pending-acks: params must equal the pre-ack shape byte-for-byte (EDD wire contract).'
		);
	}

	/**
	 * get_api_params() WITH pending acks adds consumed_command_nonces.
	 *
	 * @return void
	 */
	public function test_updater_params_with_pending_acks_field_present(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );

		// Use current time so the entry is not pruned by the 30-day retention window.
		$pending_entry = array(
			'nonce'    => str_repeat( 'b', 32 ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => time(),
		);

		$ack_stored = array( $pending_entry );
		$this->stub_option_io( $ack_stored );

		$updater = $this->make_updater(
			array( 'license' => 'KEY-123', 'item_id' => 216, 'version' => '2.0.0' ),
			'woodev-test-plugin'
		);

		$params = $this->call_private( $updater, 'get_api_params' );

		$this->assertArrayHasKey(
			'consumed_command_nonces',
			$params,
			'consumed_command_nonces must be present when there are pending acks.'
		);
		$this->assertSame( array( $pending_entry ), $params['consumed_command_nonces'] );

		// All existing fields still present.
		$this->assertSame( 'get_version', $params['edd_action'] );
		$this->assertSame( 'https://example.com/', $params['url'] );
	}

	// -----------------------------------------------------------------------
	// acks_received clears exactly those nonces
	// -----------------------------------------------------------------------

	/**
	 * A response containing acks_received removes exactly those nonces from the
	 * pending ack store; unconfirmed entries survive.
	 *
	 * @return void
	 */
	public function test_dispatch_acks_received_clears_exactly_named_nonces(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$nonce_a = str_repeat( 'a', 32 );
		$nonce_b = str_repeat( 'b', 32 );
		$now     = time();

		// Use current time so the entries are not pruned by the 30-day retention window.
		$initial = array(
			array( 'nonce' => $nonce_a, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => $now ),
			array( 'nonce' => $nonce_b, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => $now ),
		);

		$ack_stored = $initial;
		$this->stub_option_io( $ack_stored );

		// Response sends back acks_received for nonce_a only (nonce_b not acknowledged).
		$response_obj            = (object) array();
		$response_obj->acks_received = array( $nonce_a );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function () use ( $response_obj ) {
				$response = Mockery::mock();
				$response->shouldReceive( 'get_response_data' )->andReturn( $response_obj );
				return $response;
			}
		);

		$engine = $this->make_license_engine( $api_handler );

		try {
			$this->call_dispatch( $engine, 'check_license', 'KEY-123' );
		} catch ( \Exception $e ) {
			// May throw — that's fine.
		}

		// nonce_a cleared; nonce_b survives.
		$remaining_nonces = array_column( (array) $ack_stored, 'nonce' );
		$this->assertNotContains( $nonce_a, $remaining_nonces, 'Confirmed nonce_a was removed.' );
		$this->assertContains( $nonce_b, $remaining_nonces, 'Unconfirmed nonce_b survives (lost-ack redelivery).' );
	}

	// -----------------------------------------------------------------------
	// dispatch() throw → store untouched (outage grace §3.2)
	// -----------------------------------------------------------------------

	/**
	 * When dispatch() throws (transport outage), the ack store remains completely
	 * untouched — neither pending acks are confirmed nor cleared (§3.2 outage grace).
	 *
	 * @return void
	 */
	public function test_dispatch_throw_leaves_ack_store_untouched(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$pending_entry = array(
			'nonce'    => str_repeat( 'a', 32 ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => self::NOW,
		);

		// Pre-load the store with a pending entry (use current time to avoid retention pruning).
		$pending_entry['ts'] = time();
		$ack_stored          = array( $pending_entry );

		// Track writes to the ack option specifically.
		$ack_write_count = 0;
		Functions\when( 'get_option' )->alias(
			static function ( $name ) use ( &$ack_stored ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					return $ack_stored ?? false;
				}
				return false;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$ack_stored, &$ack_write_count ) {
				if ( \Woodev_License_Command_Acks::OPTION_NAME === $name ) {
					$ack_stored = $value;
					$ack_write_count++;
				}
				return true;
			}
		);

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->andThrow( new \Exception( 'Transport error.' ) );

		$engine = $this->make_license_engine( $api_handler );

		try {
			$this->call_dispatch( $engine, 'check_license', 'KEY-123' );
		} catch ( \Exception $e ) {
			// Expected — transport threw.
		}

		// The ack store must be untouched: the pending entry still present and no writes.
		$this->assertSame( 0, $ack_write_count, 'Ack store must not be written when dispatch() throws (outage grace §3.2).' );
		$this->assertCount( 1, (array) $ack_stored, 'Pending entry survives a transport throw.' );
	}

	// -----------------------------------------------------------------------
	// Lost-ack redelivery: pending acks survive a response without acks_received
	// -----------------------------------------------------------------------

	/**
	 * When the server does NOT include acks_received in its response, pending acks
	 * remain in the store and are redelivered on the next request (§9.9).
	 *
	 * @return void
	 */
	public function test_pending_acks_survive_response_without_acks_received(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		// Use current time so the entry is not pruned by the 30-day retention window.
		$pending_entry = array(
			'nonce'    => str_repeat( 'a', 32 ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => time(),
		);

		$ack_stored = array( $pending_entry );
		$this->stub_option_io( $ack_stored );

		// Response without acks_received.
		$response_obj           = (object) array( 'some_field' => 'value' );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function () use ( $response_obj ) {
				$response = Mockery::mock();
				$response->shouldReceive( 'get_response_data' )->andReturn( $response_obj );
				return $response;
			}
		);

		$engine = $this->make_license_engine( $api_handler );

		try {
			$this->call_dispatch( $engine, 'check_license', 'KEY-123' );
		} catch ( \Exception $e ) {
			// May throw — that's fine.
		}

		// Pending entry must still be present (lost-ack redelivery).
		$remaining_nonces = array_column( (array) $ack_stored, 'nonce' );
		$this->assertContains( str_repeat( 'a', 32 ), $remaining_nonces, 'Pending ack redelivered on next request when server does not ack.' );
	}

	// -----------------------------------------------------------------------
	// Re-review #1: acks_received may confirm ONLY the intersection with the
	// nonces THIS request actually sent (lost-ack protection §9.9).
	// -----------------------------------------------------------------------

	/**
	 * dispatch(): a response whose acks_received names a nonce that was NOT sent
	 * in this request (recorded while the request was in flight) must NOT clear
	 * that entry — only the sent-and-acknowledged nonce is confirmed.
	 *
	 * @return void
	 */
	public function test_dispatch_acks_received_for_unsent_nonce_survives(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$nonce_a = str_repeat( 'a', 32 ); // pending at request time → SENT.
		$nonce_b = str_repeat( 'b', 32 ); // recorded in flight → NOT sent.
		$now     = time();

		$ack_stored = array(
			array( 'nonce' => $nonce_a, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => $now ),
		);
		$this->stub_option_io( $ack_stored );

		// Rogue/buggy response acknowledges BOTH the sent nonce and the unsent one.
		$response_obj                = (object) array();
		$response_obj->acks_received = array( $nonce_a, $nonce_b );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function () use ( &$ack_stored, $response_obj, $nonce_b, $now ) {
				// Simulate an ack recorded WHILE the request is in flight.
				$ack_stored[] = array( 'nonce' => $nonce_b, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => $now );

				$response = Mockery::mock();
				$response->shouldReceive( 'get_response_data' )->andReturn( $response_obj );
				return $response;
			}
		);

		$engine = $this->make_license_engine( $api_handler );

		try {
			$this->call_dispatch( $engine, 'check_license', 'KEY-123' );
		} catch ( \Exception $e ) {
			// May throw — that's fine.
		}

		$remaining = array_column( (array) $ack_stored, 'nonce' );
		$this->assertNotContains( $nonce_a, $remaining, 'Sent + acknowledged nonce_a is confirmed.' );
		$this->assertContains( $nonce_b, $remaining, 'In-flight nonce_b was NOT sent in this request — it must SURVIVE (re-review #1).' );
	}

	/**
	 * Updater get_version_from_remote(): same intersection rule — only the nonce
	 * the request carried is confirmed; an in-flight entry named by acks_received
	 * survives. The sections-less response still returns false (pre-change contract).
	 *
	 * @return void
	 */
	public function test_updater_acks_received_confirms_only_sent_intersection(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );

		$nonce_a = str_repeat( 'a', 32 ); // pending at request time → SENT.
		$nonce_b = str_repeat( 'b', 32 ); // recorded in flight → NOT sent.
		$now     = time();

		$ack_stored = array(
			array( 'nonce' => $nonce_a, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => $now ),
		);
		$this->stub_option_io( $ack_stored );

		$response_obj                = (object) array();
		$response_obj->acks_received = array( $nonce_a, $nonce_b );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function () use ( &$ack_stored, $response_obj, $nonce_b, $now ) {
				// Simulate an ack recorded WHILE the request is in flight.
				$ack_stored[] = array( 'nonce' => $nonce_b, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => $now );

				$request = Mockery::mock();
				$request->shouldReceive( 'get_response_data' )->andReturn( $response_obj );
				return $request;
			}
		);

		$updater = $this->make_updater(
			array( 'license' => 'KEY-123', 'item_id' => 216, 'version' => '2.0.0' ),
			'woodev-test-plugin'
		);
		$this->set_private( $updater, 'api_handler', $api_handler );

		$result = $this->call_private( $updater, 'get_version_from_remote' );

		$this->assertFalse( $result, 'A sections-less response still returns false.' );

		$remaining = array_column( (array) $ack_stored, 'nonce' );
		$this->assertNotContains( $nonce_a, $remaining, 'Sent + acknowledged nonce_a is confirmed.' );
		$this->assertContains( $nonce_b, $remaining, 'In-flight nonce_b was NOT sent in this request — it must SURVIVE (re-review #1).' );
	}

	/**
	 * Updater: when this request sent NO acks, acks_received is ignored entirely —
	 * an in-flight entry named by a rogue response survives untouched.
	 *
	 * @return void
	 */
	public function test_updater_skips_confirmation_when_nothing_sent(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );

		$nonce_b = str_repeat( 'b', 32 ); // recorded in flight; nothing was sent.
		$now     = time();

		$ack_stored = false; // EMPTY store at request time → nothing sent.
		$this->stub_option_io( $ack_stored );

		$response_obj                = (object) array();
		$response_obj->acks_received = array( $nonce_b );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function () use ( &$ack_stored, $response_obj, $nonce_b, $now ) {
				// Simulate an ack recorded WHILE the request is in flight.
				$ack_stored = array(
					array( 'nonce' => $nonce_b, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => $now ),
				);

				$request = Mockery::mock();
				$request->shouldReceive( 'get_response_data' )->andReturn( $response_obj );
				return $request;
			}
		);

		$updater = $this->make_updater(
			array( 'license' => 'KEY-123', 'item_id' => 216, 'version' => '2.0.0' ),
			'woodev-test-plugin'
		);
		$this->set_private( $updater, 'api_handler', $api_handler );

		$result = $this->call_private( $updater, 'get_version_from_remote' );

		$this->assertFalse( $result );

		$remaining = array_column( (array) $ack_stored, 'nonce' );
		$this->assertContains( $nonce_b, $remaining, 'Nothing was sent → confirmation is skipped entirely; the in-flight entry survives.' );
	}

	// -----------------------------------------------------------------------
	// Re-review #2: command-only responses (no `sections`) still deliver
	// license_commands — pull consumption runs BEFORE the sections early-return.
	// -----------------------------------------------------------------------

	/**
	 * Updater get_version_from_remote(): a response WITHOUT `sections` but WITH
	 * license_commands consumes the command through the REAL pipeline (it executes
	 * and its ack is recorded), and the function still returns false exactly as
	 * before for a non-version payload.
	 *
	 * @return void
	 */
	public function test_updater_command_only_response_consumes_pull_commands_and_returns_false(): void {
		if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium not available in this PHP runtime.' );
		}

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
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
		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'add_option' )->justReturn( true );

		$ack_stored = false; // empty store.
		$this->stub_option_io( $ack_stored );

		// wpdb double for the nonce-store cap scan.
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static function ( $text ) {
				return $text;
			}
		);
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		// Fixture keypair (seed 0x01 x 32) + pubkey filter.
		$keypair = sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) );
		$secret  = sodium_crypto_sign_secretkey( $keypair );
		$pub_b64 = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) use ( $pub_b64 ) {
				return 'woodev_license_authority_pubkey' === $hook ? $pub_b64 : $value;
			}
		);

		$payload  = array(
			'protocol'   => 1,
			'command'    => 'deactivate_plugin',
			'site'       => 'https://example.com',
			'plugin_id'  => '216',
			'nonce'      => str_repeat( 'c', 32 ),
			'issued_at'  => time() - 10,
			'expires_at' => time() + 1000,
		);
		$envelope = array(
			'payload'   => $payload,
			'signature' => base64_encode(
				sodium_crypto_sign_detached( \Woodev_License_Envelope_Verifier::canonical_json( $payload ), $secret )
			),
		);

		$calls = 0;
		\Woodev_License_Command_Dispatcher::reset_commands_for_tests();
		\Woodev_License_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		// Register an engine for the command's target plugin id '216'.
		$registry_prop = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$registry_prop->setAccessible( true );
		}
		$registry_prop->setValue( null, array( '216' => Mockery::mock( \Woodev_Plugins_License::class ) ) );

		// Command-only response: license_commands present, NO sections.
		$response_obj = (object) array( 'license_commands' => array( $envelope ) );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function () use ( $response_obj ) {
				$request = Mockery::mock();
				$request->shouldReceive( 'get_response_data' )->andReturn( $response_obj );
				return $request;
			}
		);

		$updater = $this->make_updater(
			array( 'license' => 'KEY-123', 'item_id' => 216, 'version' => '2.0.0' ),
			'woodev-test-plugin'
		);
		$this->set_private( $updater, 'api_handler', $api_handler );

		$result = $this->call_private( $updater, 'get_version_from_remote' );

		$this->assertFalse( $result, 'A response without sections still returns false (pre-change contract).' );
		$this->assertSame( 1, $calls, 'The pull command in a command-only response MUST execute (re-review #2).' );

		// The executed ack was recorded for the next drain.
		$this->assertNotEmpty( $ack_stored );
		$this->assertSame( str_repeat( 'c', 32 ), $ack_stored[0]['nonce'] );
		$this->assertSame( 'executed', $ack_stored[0]['status'] );
	}

	// -----------------------------------------------------------------------
	// BLOCKER #1 regression: pull delivery works on the COMMON (empty-store) path
	// -----------------------------------------------------------------------

	/**
	 * dispatch() with an EMPTY ack store still consumes server-queued
	 * license_commands from the response — the pull-fallback must run on EVERY
	 * successful response, not only when pending acks were sent (critic ruling
	 * s8-p5 #1: gating consumption on $has_pending_acks disabled the feature
	 * for every site with an empty store, i.e. the common case).
	 *
	 * End-to-end through the REAL pipeline: a signed envelope rides the
	 * check_license response, the registered command executes, and the
	 * resulting 'executed' ack lands in the store.
	 *
	 * @return void
	 */
	public function test_dispatch_with_empty_ack_store_still_consumes_pull_commands(): void {
		if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium not available in this PHP runtime.' );
		}

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
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
		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'add_option' )->justReturn( true );

		// EMPTY ack store — the regression trigger.
		$ack_stored = false;
		$this->stub_option_io( $ack_stored );

		// wpdb double for the nonce-store cap scan.
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static function ( $text ) {
				return $text;
			}
		);
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		// Fixture keypair (seed 0x01 x 32) + pubkey filter.
		$keypair = sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) );
		$secret  = sodium_crypto_sign_secretkey( $keypair );
		$pub_b64 = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) use ( $pub_b64 ) {
				return 'woodev_license_authority_pubkey' === $hook ? $pub_b64 : $value;
			}
		);

		$payload  = array(
			'protocol'   => 1,
			'command'    => 'deactivate_plugin',
			'site'       => 'https://example.com',
			'plugin_id'  => '216',
			'nonce'      => str_repeat( 'a', 32 ),
			'issued_at'  => time() - 10,
			'expires_at' => time() + 1000,
		);
		$envelope = array(
			'payload'   => $payload,
			'signature' => base64_encode(
				sodium_crypto_sign_detached( \Woodev_License_Envelope_Verifier::canonical_json( $payload ), $secret )
			),
		);

		$calls = 0;
		\Woodev_License_Command_Dispatcher::reset_commands_for_tests();
		\Woodev_License_Command_Dispatcher::register_command(
			'deactivate_plugin',
			static function () use ( &$calls ) {
				$calls++;
				return 'executed';
			}
		);

		// The check_license response carries the pull-delivered command.
		$response_obj = (object) array( 'license_commands' => array( $envelope ) );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->once()->andReturnUsing(
			static function () use ( $response_obj ) {
				$response = Mockery::mock();
				$response->shouldReceive( 'get_response_data' )->andReturn( $response_obj );
				return $response;
			}
		);

		$engine = $this->make_license_engine( $api_handler );

		// Register the engine for the command's target plugin id '216'.
		$registry_prop = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$registry_prop->setAccessible( true );
		}
		$registry_prop->setValue( null, array( '216' => $engine ) );

		try {
			$this->call_dispatch( $engine, 'check_license', 'KEY-123' );
		} catch ( \Exception $e ) {
			// May throw post-consumption — irrelevant here.
		}

		$this->assertSame( 1, $calls, 'A server-queued pull command MUST execute even when the ack store is empty (critic #1).' );

		// And its terminal ack landed in the (previously empty) store.
		$this->assertNotEmpty( $ack_stored, 'The executed ack is recorded for the next drain.' );
		$this->assertSame( str_repeat( 'a', 32 ), $ack_stored[0]['nonce'] );
		$this->assertSame( 'executed', $ack_stored[0]['status'] );
	}
}
