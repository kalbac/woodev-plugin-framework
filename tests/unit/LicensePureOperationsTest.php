<?php
/**
 * Transport-agnostic pure license operations tests.
 *
 * Covers the single-writer operations extracted onto Woodev_Plugins_License
 * (activate()/deactivate()/set_beta_enabled()/get_state()) plus the static
 * instance registry. These are the REST controller's only write path, so the
 * stored-data contract is release-blocking: the tests pin the exact option
 * names, the EDD dispatch parameters, the woodev_extensions transient, and the
 * Woodev_License::save()/delete() payload byte-for-byte against the legacy
 * admin_init handlers + register_setting sanitize callback that this task
 * deletes.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';

/**
 * Class LicensePureOperationsTest.
 */
class LicensePureOperationsTest extends TestCase {

	/**
	 * Value the broad get_option() stub returns for any '*_license_key' option.
	 *
	 * Defaults to '' (no key stored), which makes a fresh Woodev_License::get()
	 * early-return a clean status-'' object. Individual tests override this to
	 * 'KEY-123' to model the deactivation contract: the license-DATA option is
	 * deleted but the *_license_key option is PRESERVED, so a post-delete
	 * re-instantiation must surface the preserved key in the returned state.
	 *
	 * @var string
	 */
	private string $license_key_option_value = '';

	/**
	 * Resets the static instance registry before each test (cross-test pollution guard).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->license_key_option_value = '';
		$this->reset_registry();
		$this->stub_message_builder_functions();
	}

	/**
	 * Resets the static instance registry after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_registry();
		parent::tearDown();
	}

	/**
	 * activate(): writes woodev_{id}_license_key via update_option, dispatches
	 * edd_action=activate_license, clears the woodev_extensions transient on a
	 * 'valid' response, and persists the payload via Woodev_License::save() —
	 * exactly the byte-for-byte writes of the legacy activate path
	 * (Settings-API sanitize callback wrote the key option; the admin_init
	 * handler dispatched + saved).
	 *
	 * @return void
	 */
	public function test_activate_writes_same_options_as_legacy_path(): void {
		$payload = (object) [ 'license' => 'valid', 'expires' => 'lifetime' ];

		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );

		$response = Mockery::mock();
		$response->license = 'valid';
		$response->shouldReceive( 'get_response_data' )->once()->andReturn( $payload );

		// Starts un-activated ('') so activate() does not short-circuit on the
		// already-valid parity check; save() flips the recorded status to 'valid'.
		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = '';
		$woodev_license->expires   = 'lifetime';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->shouldReceive( 'save' )->once()->with( $payload )->andReturnUsing(
			static function () use ( $woodev_license ) {
				$woodev_license->license = 'valid';
				return true;
			}
		);

		$license = $this->make_license( $plugin, '', '', $woodev_license );
		$this->stub_api_handler( $license, 'activate_license', 'KEY-123', $response );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );
		Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_license_key', 'KEY-123' );
		Functions\expect( 'delete_transient' )->once()->with( 'woodev_extensions' );
		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->activate( 'KEY-123' );

		$this->assertSame( 'valid', $state['status'] );
		$this->assertSame( 'success', $state['message_variant'] );
	}

	/**
	 * activate(): a transport failure (dispatch throws) propagates without
	 * touching stored license DATA. Per the documented legacy-parity ordering, the
	 * key option IS written first (the legacy Settings API wrote it even when the
	 * activation call failed — identical end state), so this asserts:
	 *   - update_option(*_license_key) is called EXACTLY once, and
	 *   - the license-DATA write never happens: save() is never reached and the
	 *     woodev_extensions transient is never cleared.
	 *
	 * @return void
	 */
	public function test_activate_transport_failure_throws_and_leaves_license_untouched(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );

		$woodev_license          = Mockery::mock( \Woodev_License::class );
		$woodev_license->license = '';
		$woodev_license->shouldNotReceive( 'save' );

		$license = $this->make_license( $plugin, '', '', $woodev_license );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->andThrow( new \Exception( 'transport down' ) );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );
		// Key option written exactly once (parity), before the failing dispatch.
		Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_license_key', 'KEY-123' );
		// License-DATA writes never happen on transport failure.
		Functions\expect( 'delete_transient' )->never();

		$this->expectException( \Exception::class );

		$license->activate( 'KEY-123' );
	}

	/**
	 * activate(): an empty key throws and never dispatches or writes.
	 *
	 * @return void
	 */
	public function test_activate_empty_key_throws(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );

		$license = $this->make_license( $plugin, '', '', Mockery::mock() );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'update_option' )->never();

		$this->expectException( \Woodev_Plugin_Exception::class );

		$license->activate( '' );
	}

	/**
	 * activate(): when the stored license is already valid, early-return the
	 * current state without dispatching (parity with the legacy handler's
	 * is_license_valid() early-return). The key option IS still written first
	 * (parity: the Settings API wrote it on every save).
	 *
	 * @return void
	 */
	public function test_activate_already_valid_short_circuits_without_dispatch(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = 'valid';
		$woodev_license->expires   = 'lifetime';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->shouldNotReceive( 'save' );

		$license = $this->make_license( $plugin, 'KEY-123', 'valid', $woodev_license );

		$api_handler = Mockery::mock();
		$api_handler->shouldNotReceive( 'make_request' );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_license_key', 'KEY-123' );
		Functions\expect( 'delete_transient' )->never();
		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->activate( 'KEY-123' );

		$this->assertSame( 'valid', $state['status'] );
	}

	/**
	 * deactivate(): dispatches edd_action=deactivate_license, deletes the stored
	 * license object (Woodev_License::delete()), and NEVER writes/deletes the
	 * *_license_key option (parity: the legacy handler left the key option in
	 * place).
	 *
	 * @return void
	 */
	public function test_deactivate_deletes_license_data_but_keeps_key_option(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'get_id_underscored' )->andReturn( 'woodev_test_plugin' );

		$response          = Mockery::mock();
		$response->license = 'deactivated';

		$woodev_license          = Mockery::mock( \Woodev_License::class );
		$woodev_license->license = '';
		$woodev_license->shouldReceive( 'delete' )->once();

		$license = $this->make_license( $plugin, 'KEY-123', '', $woodev_license );
		$this->stub_api_handler( $license, 'deactivate_license', 'KEY-123', $response );

		// deactivate() re-instantiates Woodev_License for a fresh post-delete read;
		// with the key option absent (the broad get_option stub returns '' for the
		// *_license_key option) the new object reports status ''.
		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->deactivate();

		$this->assertIsArray( $state );
		$this->assertSame( '', $state['status'] );
		$this->assertSame( '', $state['license_key'] );
	}

	/**
	 * deactivate(): transport failure propagates as an exception.
	 *
	 * @return void
	 */
	public function test_deactivate_transport_failure_throws(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );

		$woodev_license          = Mockery::mock( \Woodev_License::class );
		$woodev_license->license = '';
		$woodev_license->shouldNotReceive( 'delete' );

		$license = $this->make_license( $plugin, 'KEY-123', '', $woodev_license );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->andThrow( new \Exception( 'transport down' ) );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );

		$this->expectException( \Exception::class );

		$license->deactivate();
	}

	/**
	 * set_beta_enabled(true) writes 'yes'; set_beta_enabled(false) deletes the
	 * option — exactly the legacy register_setting sanitize callback semantics.
	 *
	 * @return void
	 */
	public function test_set_beta_enabled_writes_yes_or_deletes_exactly_like_legacy_sanitize(): void {
		$plugin = $this->make_plugin_stub();

		$license = $this->make_license( $plugin, '', '', Mockery::mock() );

		Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_beta_version', 'yes' );
		$license->set_beta_enabled( true );

		Functions\expect( 'delete_option' )->once()->with( 'woodev_test_plugin_beta_version' );
		$license->set_beta_enabled( false );
	}

	/**
	 * get_state(): exact spec §4.1 array shape, with status/status_label/expires
	 * pulled from the raw license object and the booleans from the real accessors.
	 *
	 * @return void
	 */
	public function test_get_state_shape(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( true );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = 'valid';
		$woodev_license->expires   = 'lifetime';
		$woodev_license->item_name = 'Test Plugin';

		$license = $this->make_license( $plugin, 'KEY-123', 'valid', $woodev_license );

		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->get_state();

		$this->assertSame(
			[
				'plugin_id',
				'plugin_name',
				'license_key',
				'status',
				'status_label',
				'message',
				'message_variant',
				'expires',
				'is_valid',
				'is_active',
				'is_need_license',
				'beta_enabled',
			],
			array_keys( $state )
		);

		$this->assertSame( '216', $state['plugin_id'] );
		$this->assertSame( 'Test Plugin', $state['plugin_name'] );
		$this->assertSame( 'KEY-123', $state['license_key'] );
		$this->assertSame( 'valid', $state['status'] );
		$this->assertSame( 'License is valid', $state['status_label'] );
		$this->assertSame( 'lifetime', $state['expires'] );
		$this->assertTrue( $state['is_valid'] );
		$this->assertTrue( $state['is_active'] );
		$this->assertTrue( $state['is_need_license'] );
		$this->assertTrue( $state['beta_enabled'] );
		$this->assertSame( 'success', $state['message_variant'] );
	}

	/**
	 * get_state(): an empty status yields empty status_label and the 'info' variant.
	 *
	 * @return void
	 */
	public function test_get_state_empty_status_has_blank_label_and_info_variant(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = '';
		$woodev_license->expires   = '';
		$woodev_license->item_name = 'Test Plugin';

		$license = $this->make_license( $plugin, '', '', $woodev_license );

		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->get_state();

		$this->assertSame( '', $state['status'] );
		$this->assertSame( '', $state['status_label'] );
		$this->assertSame( 'info', $state['message_variant'] );
		$this->assertFalse( $state['beta_enabled'] );
	}

	/**
	 * MUST-FIX 1 (XSS boundary) — get_state()['message'] is always passed
	 * through wp_kses_post() before being returned, covering both the bootstrap
	 * payload and every REST response from a single sanitization point.
	 *
	 * Strategy: override the wp_kses_post stub (TestCase::setUp already
	 * registered it as a passthrough via stubEscapeFunctions()) by calling
	 * Functions\when()->alias() — Brain Monkey allows a later when() to
	 * supersede an earlier one for the same name. The alias closure returns a
	 * unique sentinel and increments a call counter. Asserting that the sentinel
	 * surfaces from get_state()['message'] and that the counter is exactly 1
	 * proves the XSS boundary is wired — no raw message can bypass kses.
	 *
	 * @return void
	 */
	public function test_get_state_message_is_sanitized_via_wp_kses_post(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		// license='' triggers the default 'Unlicensed' branch of build_message().
		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = '';
		$woodev_license->expires   = '';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->item_id   = 216;
		$woodev_license->payment_id = 1;
		$woodev_license->shouldReceive( 'get_license_key' )->andReturn( 'KEY-123' );

		$license = $this->make_license( $plugin, '', '', $woodev_license );

		// Override the passthrough stub registered by stubEscapeFunctions() in setUp.
		// Brain Monkey allows a later when()->alias() to supersede an earlier when().
		$sentinel        = '<!-- kses-boundary-sentinel -->';
		$kses_call_count = 0;
		Functions\when( 'wp_kses_post' )->alias(
			static function ( $raw ) use ( $sentinel, &$kses_call_count ) {
				$kses_call_count++;
				return $sentinel;
			}
		);

		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->get_state();

		// The sentinel must surface — no raw message can bypass wp_kses_post.
		$this->assertSame( $sentinel, $state['message'] );
		// Called exactly once per get_state() invocation.
		$this->assertSame( 1, $kses_call_count );
	}

	/**
	 * message_variant: every error-bucket status maps to 'error'.
	 *
	 * @dataProvider error_status_provider
	 *
	 * @param string $status Raw license status token.
	 * @return void
	 */
	public function test_message_variant_error_bucket( string $status ): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = $status;
		$woodev_license->expires   = '';
		$woodev_license->item_name = 'Test Plugin';

		$license = $this->make_license( $plugin, 'KEY-123', $status, $woodev_license );

		Functions\expect( 'current_time' )->andReturn( 1000 );

		$this->assertSame( 'error', $license->get_state()['message_variant'] );
	}

	/**
	 * Error-bucket status tokens.
	 *
	 * @return array<int, array<int, string>>
	 */
	public function error_status_provider(): array {
		return [
			[ 'expired' ],
			[ 'disabled' ],
			[ 'revoked' ],
			[ 'missing' ],
			[ 'missing_url' ],
			[ 'invalid' ],
			[ 'invalid_item_id' ],
			[ 'item_name_mismatch' ],
			[ 'key_mismatch' ],
			[ 'site_inactive' ],
			[ 'no_activations_left' ],
			[ 'license_not_activable' ],
		];
	}

	/**
	 * message_variant: a valid license with a non-lifetime expiry within
	 * MONTH_IN_SECONDS maps to 'warning' (expires-soon). The timestamp is
	 * computed the way Woodev_License_Messages::__construct() does.
	 *
	 * @return void
	 */
	public function test_message_variant_warning_when_expires_soon(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$now     = 1000000;
		$expires = (string) ( $now + ( DAY_IN_SECONDS * 5 ) ); // numeric timestamp, < MONTH_IN_SECONDS away.

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = 'valid';
		$woodev_license->expires   = $expires;
		$woodev_license->item_name = 'Test Plugin';

		$license = $this->make_license( $plugin, 'KEY-123', 'valid', $woodev_license );

		Functions\expect( 'current_time' )->andReturn( $now );

		$this->assertSame( 'warning', $license->get_state()['message_variant'] );
	}

	/**
	 * message_variant: a valid lifetime license maps to 'success', not 'warning'.
	 *
	 * @return void
	 */
	public function test_message_variant_success_for_lifetime(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = 'valid';
		$woodev_license->expires   = 'lifetime';
		$woodev_license->item_name = 'Test Plugin';

		$license = $this->make_license( $plugin, 'KEY-123', 'valid', $woodev_license );

		Functions\expect( 'current_time' )->andReturn( 1000 );

		$this->assertSame( 'success', $license->get_state()['message_variant'] );
	}

	/**
	 * License-free no-op: when is_need_license() is false, activate() does not
	 * dispatch or write anything and returns the current get_state().
	 *
	 * @return void
	 */
	public function test_license_free_activate_is_noop_returning_state(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = '';
		$woodev_license->expires   = '';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->shouldNotReceive( 'save' );

		$license = $this->make_license( $plugin, '', '', $woodev_license );

		$api_handler = Mockery::mock();
		$api_handler->shouldNotReceive( 'make_request' );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_transient' )->never();
		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->activate( 'KEY-123' );

		$this->assertIsArray( $state );
		$this->assertSame( '', $state['status'] );
	}

	/**
	 * License-free no-op: when is_need_license() is false, deactivate() does not
	 * dispatch or delete anything and returns the current get_state().
	 *
	 * @return void
	 */
	public function test_license_free_deactivate_is_noop_returning_state(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = '';
		$woodev_license->expires   = '';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->shouldNotReceive( 'delete' );

		$license = $this->make_license( $plugin, 'KEY-123', '', $woodev_license );

		$api_handler = Mockery::mock();
		$api_handler->shouldNotReceive( 'make_request' );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->deactivate();

		$this->assertIsArray( $state );
	}

	/**
	 * Anti-pirate invariant: is_valid/is_active in get_state() come from the real
	 * accessors (which consult ONLY is_license_required()), so a license-free
	 * plugin (is_need_license false) with an 'expired' status still reports
	 * is_valid=false / is_active=false.
	 *
	 * @return void
	 */
	public function test_anti_pirate_state_booleans_ignore_need_license_flag(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = 'expired';
		$woodev_license->expires   = '';
		$woodev_license->item_name = 'Test Plugin';

		$license = $this->make_license( $plugin, 'KEY-123', 'expired', $woodev_license );

		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->get_state();

		$this->assertFalse( $state['is_valid'] );
		$this->assertFalse( $state['is_active'] );
		$this->assertFalse( $state['is_need_license'] );
	}

	/**
	 * Static instance registry: the constructor records the instance keyed by the
	 * string download id; the static accessors expose it; unknown ids return null.
	 *
	 * @return void
	 */
	public function test_instance_registry_records_and_resolves_by_download_id(): void {
		$plugin = $this->make_plugin_stub();

		$license = $this->make_license( $plugin, '', '', Mockery::mock() );

		// make_license() bypasses the constructor; register manually the way the
		// constructor does, to exercise the static accessors deterministically.
		$this->register_instance( '216', $license );

		$this->assertSame( $license, \Woodev_Plugins_License::get_registered_instance( '216' ) );
		$this->assertNull( \Woodev_Plugins_License::get_registered_instance( 'nope' ) );
		$this->assertSame( [ '216' => $license ], \Woodev_Plugins_License::get_registered_instances() );
	}

	/**
	 * N2 — the real constructor records the instance into the static registry
	 * keyed by the (string) download id. Exercised via a full construction with a
	 * Woodev_Plugin mock + Brain Monkey stubs for the WP surface the constructor
	 * and add_hooks() touch (plus the collaborators it news up: Woodev_License and
	 * Woodev_Licensing_API).
	 *
	 * @return void
	 */
	public function test_constructor_registers_instance_in_static_registry(): void {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_plugin_url' )->andReturn( 'https://example.test' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test Plugin' );
		$plugin->shouldReceive( 'get_id_underscored' )->andReturn( 'woodev_test_plugin' );
		$plugin->shouldReceive( 'get_download_id' )->andReturn( 216 );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( 'test-plugin/test-plugin.php' );

		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );

		$license = new \Woodev_Plugins_License( $plugin );

		$this->assertSame( $license, \Woodev_Plugins_License::get_registered_instance( '216' ) );
	}

	/**
	 * MUST-FIX 3 — get_state()['expires'] returns the RAW property value with no
	 * type coercion: a numeric timestamp stays numeric and a 'Y-m-d H:i:s' string
	 * stays a string, both round-tripping through get_state() byte-identical.
	 *
	 * @dataProvider expires_raw_provider
	 *
	 * @param mixed $raw The raw expires value to seed and expect back unchanged.
	 * @return void
	 */
	public function test_get_state_expires_is_raw_uncoerced( $raw ): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = 'valid';
		$woodev_license->expires   = $raw;
		$woodev_license->item_name = 'Test Plugin';

		$license = $this->make_license( $plugin, 'KEY-123', 'valid', $woodev_license );

		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->get_state();

		$this->assertSame( $raw, $state['expires'] );
	}

	/**
	 * Raw expires values that must survive get_state() unchanged (no string cast).
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function expires_raw_provider(): array {
		return [
			'numeric timestamp (int)' => [ 1893456000 ],
			'date string'             => [ '2030-01-01 00:00:00' ],
		];
	}

	/**
	 * MUST-FIX 1 (regression) — after a successful deactivate(), get_state() must
	 * report a FRESH read: status '' and is_valid false. Uses a REAL Woodev_License
	 * seeded to status 'valid'; deactivate() deletes the data option then
	 * re-instantiates Woodev_License, which (with an absent key option) reports ''.
	 *
	 * If the re-instantiation line in deactivate() is removed, the stale in-memory
	 * object keeps license='valid' and this test goes RED on the status '' assert.
	 *
	 * Crucially, the deactivation contract PRESERVES the woodev_{id}_license_key
	 * option (only the license-DATA option is deleted). The re-sync line
	 * ($this->license_key = $this->woodev_license->key) is the SOLE thing that
	 * surfaces that preserved option value into the returned state: the in-memory
	 * license_key is intentionally seeded to a STALE value ('STALE-KEY', as it
	 * would be sent to the deactivate dispatch) that DIFFERS from the authoritative
	 * preserved key option ('KEY-123'). After deactivate(), the state must report
	 * the authoritative re-read key 'KEY-123', not the stale in-memory one — so
	 * removing the re-sync line takes the test RED on the license_key assert
	 * (it surfaces 'STALE-KEY' instead of the preserved 'KEY-123').
	 *
	 * @return void
	 */
	public function test_deactivate_reports_fresh_state_not_stale_valid(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'get_id_underscored' )->andReturn( 'woodev_test_plugin' );

		$response          = Mockery::mock();
		$response->license = 'deactivated';

		// Model the preserved key option: a fresh Woodev_License::get() reads
		// 'woodev_test_plugin_license_key' => 'KEY-123' (survives deactivation)
		// while the deleted license-DATA option returns false, so the re-read
		// object reports key='KEY-123' with status ''. This is the authoritative
		// post-deactivation key the re-sync line must surface.
		$this->license_key_option_value = 'KEY-123';

		// Real Woodev_License pre-seeded to a valid state (as it would be after a
		// prior activation) — newInstanceWithoutConstructor so we control fields.
		$woodev_license = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$woodev_license->license   = 'valid';
		$woodev_license->expires   = 'lifetime';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->key       = 'KEY-123';
		$woodev_license->item_id   = 216;
		$woodev_license->payment_id = 1;
		$this->set_private_property( $woodev_license, 'option_name', 'woodev_test_plugin_license' );
		$this->set_private_property( $woodev_license, 'plugin_id', 'woodev_test_plugin' );

		// Seed the in-memory license_key to a STALE value distinct from the
		// authoritative preserved key option, so only the re-sync line can produce
		// the correct post-deactivation 'KEY-123'.
		$license = $this->make_license( $plugin, 'STALE-KEY', '', $woodev_license );
		$this->stub_api_handler( $license, 'deactivate_license', 'STALE-KEY', $response );

		// delete() on the real object deletes the data option (key option survives).
		Functions\expect( 'delete_option' )->once()->with( 'woodev_test_plugin_license' );
		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );
		Functions\expect( 'current_time' )->andReturn( 1000 );

		// Sanity: before deactivate the seeded object is valid.
		$this->assertTrue( $license->is_license_valid() );

		$state = $license->deactivate();

		// The authoritative preserved key surfaces via the re-sync line, the status
		// is cleared, and the license is no longer valid — a fresh post-deactivation
		// read, not the stale in-memory key/status.
		$this->assertSame( 'KEY-123', $state['license_key'] );
		$this->assertSame( '', $state['status'] );
		$this->assertFalse( $state['is_valid'] );
	}

	/**
	 * MUST-FIX 5 (parity, real Woodev_License) — activate() happy path pins the
	 * release-blocking license-DATA option name and the woodev_license_saved hook:
	 * update_option('woodev_test_plugin_license', <payload>, false) is called and
	 * the action fires. Uses a REAL Woodev_License so save() runs unmocked.
	 *
	 * @return void
	 */
	public function test_activate_real_license_pins_option_name_and_saved_hook(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );

		$payload = (object) [ 'license' => 'valid', 'expires' => 'lifetime' ];

		$response          = Mockery::mock();
		$response->license = 'valid';
		$response->shouldReceive( 'get_response_data' )->andReturn( $payload );

		// Real Woodev_License starting un-activated ('') so activate() dispatches.
		$woodev_license = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$woodev_license->license   = '';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->item_id   = 216;
		$woodev_license->payment_id = 1;
		$this->set_private_property( $woodev_license, 'option_name', 'woodev_test_plugin_license' );
		$this->set_private_property( $woodev_license, 'plugin_id', 'woodev_test_plugin' );

		$license = $this->make_license( $plugin, '', '', $woodev_license );
		$this->stub_api_handler( $license, 'activate_license', 'KEY-123', $response );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );
		Functions\expect( 'delete_transient' )->once()->with( 'woodev_extensions' );
		Functions\expect( 'current_time' )->andReturn( 1000 );
		// Two update_option writes: the key option (parity) and the license DATA.
		Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_license_key', 'KEY-123' );
		Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_license', $payload, false );
		// The release-blocking save hook must fire exactly once.
		Actions\expectDone( 'woodev_license_saved' )->once();

		$license->activate( 'KEY-123' );
	}

	/**
	 * MUST-FIX 5 (parity, real Woodev_License) — deactivate() pins the
	 * release-blocking delete_option('woodev_test_plugin_license') + the
	 * woodev_license_deleted hook, and NEVER deletes the *_license_key option.
	 *
	 * @return void
	 */
	public function test_deactivate_real_license_pins_option_name_and_deleted_hook(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'get_id_underscored' )->andReturn( 'woodev_test_plugin' );

		$response          = Mockery::mock();
		$response->license = 'deactivated';

		$woodev_license = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$woodev_license->license   = 'valid';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->item_id   = 216;
		$woodev_license->payment_id = 1;
		$this->set_private_property( $woodev_license, 'option_name', 'woodev_test_plugin_license' );
		$this->set_private_property( $woodev_license, 'plugin_id', 'woodev_test_plugin' );

		$license = $this->make_license( $plugin, 'KEY-123', '', $woodev_license );
		$this->stub_api_handler( $license, 'deactivate_license', 'KEY-123', $response );

		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );
		Functions\expect( 'current_time' )->andReturn( 1000 );
		// The license DATA option is deleted; the *_license_key option never is.
		Functions\expect( 'delete_option' )->once()->with( 'woodev_test_plugin_license' );
		Functions\expect( 'delete_option' )->never()->with( 'woodev_test_plugin_license_key' );
		// The release-blocking delete hook must fire exactly once.
		Actions\expectDone( 'woodev_license_deleted' )->once();

		$license->deactivate();
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------------- */

	/**
	 * Builds a plugin stub exposing the option-name + identity accessors the
	 * operations need, with real-shaped option names.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function make_plugin_stub() {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_plugin_option_name' )->with( 'license_key' )->andReturn( 'woodev_test_plugin_license_key' );
		$plugin->shouldReceive( 'get_plugin_option_name' )->with( 'beta_version' )->andReturn( 'woodev_test_plugin_beta_version' );
		$plugin->shouldReceive( 'get_download_id' )->andReturn( 216 );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test Plugin' );
		$plugin->shouldReceive( 'get_version' )->andReturn( '2.0.0' );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false )->byDefault();

		return $plugin;
	}

	/**
	 * Builds a Woodev_Plugins_License bound to the given collaborators, bypassing
	 * the constructor.
	 *
	 * @param object $plugin         Plugin stub.
	 * @param string $license_key    Stored license key.
	 * @param string $status         Stored raw status (only used when $woodev_license is real).
	 * @param object $woodev_license License object (mock or real).
	 * @return \Woodev_Plugins_License
	 */
	private function make_license( $plugin, string $license_key, string $status, $woodev_license ): \Woodev_Plugins_License {
		$license = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();

		if ( '' !== $status && $woodev_license instanceof \Woodev_License ) {
			$woodev_license->license = $status;
		}

		$this->make_license_object_message_safe( $woodev_license );

		$this->set_private_property( $license, 'plugin', $plugin );
		$this->set_private_property( $license, 'license_key', $license_key );
		$this->set_private_property( $license, 'woodev_license', $woodev_license );
		$this->set_private_property( $license, 'item_name', 'Test Plugin' );

		return $license;
	}

	/**
	 * Stubs the api_handler private property so dispatch() returns the given
	 * response, asserting make_request() receives the EXACT, full EDD param array
	 * the legacy flow built — not just that the keys exist.
	 *
	 * @param \Woodev_Plugins_License $license  The license engine.
	 * @param string                  $action   Expected edd_action (e.g. 'activate_license').
	 * @param string                  $key      Expected license param.
	 * @param object                  $response Response stub make_request() returns.
	 * @return void
	 */
	private function stub_api_handler( $license, string $action, string $key, $response ): void {
		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )
			->once()
			->with( [
				'edd_action' => $action,
				'license'    => $key,
				'item_id'    => 216,                       // get_download_id() stub.
				'url'        => 'https://example.test',    // home_url() stub.
				'version'    => '2.0.0',                    // get_version() stub.
			] )
			->andReturn( $response );

		$this->set_private_property( $license, 'api_handler', $api_handler );
	}

	/**
	 * Registers an instance in the static registry via reflection.
	 *
	 * @param string $plugin_id Download id key.
	 * @param object $instance  License instance.
	 * @return void
	 */
	private function register_instance( string $plugin_id, $instance ): void {
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$registry               = $property->getValue();
		$registry[ $plugin_id ] = $instance;
		$property->setValue( null, $registry );
	}

	/**
	 * Resets the static instance registry to an empty array.
	 *
	 * @return void
	 */
	private function reset_registry(): void {
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, [] );
	}

	/**
	 * Ensures a license object can build its message without un-stubbed calls.
	 *
	 * get_state() always constructs Woodev_License_Messages and calls get_message();
	 * the message + renewal-link builders read get_license_key() / item_id /
	 * payment_id. Stub/seed them so message construction never blows up in unit
	 * context (the message content itself is not under assertion here).
	 *
	 * @param object $woodev_license License object (Mockery mock or real).
	 * @return void
	 */
	private function make_license_object_message_safe( $woodev_license ): void {

		if ( $woodev_license instanceof \Mockery\MockInterface ) {
			$woodev_license->shouldReceive( 'get_license_key' )->andReturn( 'KEY-123' )->byDefault();
			$woodev_license->item_id    = 216;
			$woodev_license->payment_id = 1;

			return;
		}

		if ( $woodev_license instanceof \Woodev_License ) {
			$woodev_license->item_id    = 216;
			$woodev_license->payment_id = 1;
		}
	}

	/**
	 * Registers benign stubs for the WP functions the license-message builder calls.
	 *
	 * get_state() builds a Woodev_License_Messages instance AND passes the raw
	 * message through wp_kses_post() before returning it. All these stubs keep
	 * message construction side-effect-free in unit context. Individual tests
	 * that need to observe the wp_kses_post boundary can override the stub via
	 * Functions\when('wp_kses_post')->alias(...) after this method returns.
	 *
	 * @return void
	 */
	private function stub_message_builder_functions(): void {
		Functions\stubs(
			[
				'home_url'        => 'https://example.test',
				'site_url'        => 'https://example.test',
				// wp_kses_post is not in Brain Monkey's stubEscapeFunctions() list;
				// register a passthrough so get_state() works in all other tests.
				'wp_kses_post'    => static function ( $content ) { return $content; },
				// Real Woodev_License instances (deactivate re-instantiation + parity
				// tests) call get_option() for the *_license_key option and the saved
				// payload. The key option returns $this->license_key_option_value
				// (default '', overridable per-test to model a PRESERVED key after
				// deactivation); the license-DATA option ('*_license' but NOT
				// '*_license_key') returns false so a fresh Woodev_License::get()
				// early-returns a clean (status '') object after the data option was
				// deleted; everything else returns the date format string the message
				// builder expects. Using when() + alias (not expect()) because Brain
				// Monkey ignores a later expect() for a name already stubbed via
				// stubs()/when() — per-test variance is routed through the instance
				// property instead.
				'get_option'      => function ( $name = '', $default = false ) {
					$key_suffix  = '_license_key';
					$data_suffix = '_license';
					$is_string   = is_string( $name );

					if ( $is_string && substr( $name, -strlen( $key_suffix ) ) === $key_suffix ) {
						return $this->license_key_option_value;
					}

					if ( $is_string && substr( $name, -strlen( $data_suffix ) ) === $data_suffix ) {
						return false;
					}

					return 'F j, Y';
				},
				'is_admin'        => false,
				'trailingslashit' => static function ( $url ) {
					return rtrim( (string) $url, '/' ) . '/';
				},
				'sanitize_title'  => static function ( $value ) {
					return strtolower( (string) $value );
				},
				'add_query_arg'   => static function () {
					return 'https://example.test/';
				},
				'wp_parse_args'   => static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				},
				'wp_parse_url'    => static function () {
					return 'example.test';
				},
				'date_i18n'       => static function ( $format, $timestamp = null ) {
					return (string) $timestamp;
				},
			]
		);
	}

	/**
	 * Sets a property value via reflection (handles protected/private).
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
