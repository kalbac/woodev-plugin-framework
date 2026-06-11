<?php
/**
 * Contract parity test — pins every installed-site contract string and protocol
 * constant frozen by S3.3 (webhooks-spec §5, plan §9.8).
 *
 * These literals are INSTALLED-SITE DATA CONTRACTS. Once shipped they join the
 * never-break list: changing any pinned value here is a release-blocking break
 * for sites in the field (stored options, the public route, the wire protocol
 * the woodev-core server implements against, the public hook plugins listen to).
 * If one of these assertions fails, the fix is to revert the code change — not
 * to update the assertion — unless the operator explicitly approves a
 * protocol-version bump.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-nonce-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-acks.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-dispatcher.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/commands/interface-license-command.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/commands/class-license-command-deactivate-plugin.php';

use Brain\Monkey\Functions;
use Woodev_License_Command_Acks;
use Woodev_License_Command_Dispatcher;
use Woodev_License_Command_Nonce_Store;
use Woodev_License_Envelope_Verifier;

/**
 * Class LicenseCommandContractParityTest.
 */
class LicenseCommandContractParityTest extends TestCase {

	/**
	 * The frozen HTTP status map — full-array pin, value AND order.
	 *
	 * @return void
	 */
	public function test_http_map_frozen(): void {
		$this->assertSame(
			array(
				'executed'                   => 200,
				'already'                    => 200,
				'malformed'                  => 400,
				'unsupported_protocol'       => 400,
				'unsupported_command'        => 400,
				'invalid_window'             => 400,
				'bad_signature'              => 401,
				'site_mismatch'              => 401,
				'unknown_plugin'             => 404,
				'network_active_unsupported' => 409,
				'expired'                    => 410,
				'replayed'                   => 410,
				'rate_limited'               => 429,
				'failed'                     => 500,
			),
			Woodev_License_Command_Dispatcher::HTTP_MAP
		);
	}

	/**
	 * Protocol constants frozen by plan §9.8.
	 *
	 * @return void
	 */
	public function test_protocol_constants_frozen(): void {
		$this->assertSame( 1, Woodev_License_Command_Dispatcher::PROTOCOL_VERSION );
		$this->assertSame( 8192, Woodev_License_Command_Dispatcher::MAX_BODY_BYTES );
		$this->assertSame( 8, Woodev_License_Command_Dispatcher::JSON_DEPTH );
		$this->assertSame( 300, Woodev_License_Command_Dispatcher::CLOCK_SKEW );
		$this->assertSame( 'woodev_license_cmd_rl', Woodev_License_Command_Dispatcher::RATE_LIMIT_TRANSIENT );
		$this->assertSame( 60, Woodev_License_Command_Dispatcher::RATE_LIMIT_WINDOW );
		$this->assertSame( 30, Woodev_License_Command_Dispatcher::RATE_LIMIT_MAX );
		$this->assertSame( 64, Woodev_License_Command_Dispatcher::MAX_COMMAND_LENGTH );
		$this->assertSame( 255, Woodev_License_Command_Dispatcher::MAX_SITE_LENGTH );
		$this->assertSame( 20, Woodev_License_Command_Dispatcher::MAX_PLUGIN_ID_LENGTH );
		$this->assertSame( 64, Woodev_License_Command_Dispatcher::MAX_KID_LENGTH );
		$this->assertSame( 16, Woodev_License_Command_Dispatcher::MAX_ARGS_ENTRIES );
		$this->assertSame( 255, Woodev_License_Command_Dispatcher::MAX_ARG_SCALAR_LENGTH );
		$this->assertSame( 14 * DAY_IN_SECONDS, Woodev_License_Command_Nonce_Store::MAX_TTL );
		$this->assertSame( 1209600, Woodev_License_Command_Nonce_Store::MAX_TTL );
		$this->assertSame( 300, Woodev_License_Command_Nonce_Store::STUCK_TAKEOVER_AFTER );
		$this->assertSame( 100, Woodev_License_Command_Nonce_Store::MAX_NONCE_ENTRIES );
		$this->assertSame( 50, Woodev_License_Command_Acks::MAX_PENDING_ACKS );
		$this->assertSame( 30 * DAY_IN_SECONDS, Woodev_License_Command_Acks::RETENTION_SECONDS );
		$this->assertSame( 2592000, Woodev_License_Command_Acks::RETENTION_SECONDS );
		$this->assertSame( 64, Woodev_License_Envelope_Verifier::SIGNATURE_BYTES );
		$this->assertSame( 32, Woodev_License_Envelope_Verifier::PUBLIC_KEY_BYTES );
	}

	/**
	 * The SEALED command vocabulary (holistic-round ruling): no public mutation
	 * API exists on the dispatcher, and the effective vocabulary after the first
	 * internal build is EXACTLY ['deactivate_plugin'] backed by the real handler.
	 *
	 * @return void
	 */
	public function test_command_vocabulary_sealed_and_frozen(): void {
		// No public registration/reset API — the registry is sealed.
		$this->assertFalse(
			method_exists( Woodev_License_Command_Dispatcher::class, 'register_command' ),
			'register_command() must NOT exist (sealed-registry ruling).'
		);
		$this->assertFalse(
			method_exists( Woodev_License_Command_Dispatcher::class, 'reset_commands_for_tests' ),
			'reset_commands_for_tests() must NOT exist (tests use the reflection seam).'
		);

		// No OTHER public method may mutate the registry either: the registry
		// property is private and the only writer is the private get_commands().
		$registry_prop = new \ReflectionProperty( Woodev_License_Command_Dispatcher::class, 'commands' );
		$this->assertTrue( $registry_prop->isPrivate(), 'The command registry must stay private.' );

		$builder = new \ReflectionMethod( Woodev_License_Command_Dispatcher::class, 'get_commands' );
		$this->assertTrue( $builder->isPrivate(), 'get_commands() must stay private.' );

		// Effective vocabulary after first use === ['deactivate_plugin'].
		if ( PHP_VERSION_ID < 80100 ) {
			$registry_prop->setAccessible( true );
			$builder->setAccessible( true );
		}

		$snapshot = $registry_prop->getValue(); // Raw value — restored below.
		$registry_prop->setValue( null, null ); // Pristine lazy state.

		try {
			$vocabulary = $builder->invoke( null );

			$this->assertSame( array( 'deactivate_plugin' ), array_keys( $vocabulary ) );
			$this->assertInstanceOf(
				\Woodev_License_Command_Deactivate_Plugin::class,
				$vocabulary['deactivate_plugin'],
				'The sealed vocabulary is built with the REAL handler, not a stub.'
			);
		} finally {
			$registry_prop->setValue( null, $snapshot );
		}
	}

	/**
	 * The exact payload-key whitelist (frozen §9.8). The schema gate reads this
	 * constant, so the pin guards the wire schema itself.
	 *
	 * @return void
	 */
	public function test_payload_key_whitelist_frozen(): void {
		$this->assertSame(
			array( 'protocol', 'command', 'site', 'plugin_id', 'nonce', 'issued_at', 'expires_at' ),
			Woodev_License_Command_Dispatcher::PAYLOAD_KEYS
		);

		// The schema check must READ the constant (not a drifting local copy).
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-dispatcher.php'
		);
		$this->assertStringContainsString(
			'$required = self::PAYLOAD_KEYS;',
			$source,
			'is_payload_schema_valid() must read PAYLOAD_KEYS.'
		);
	}

	/**
	 * D-W3 carrier scope (holistic-round ruling): the command/ack machinery in
	 * dispatch() is gated on check_license at the attach site — source-asserted
	 * so the gate cannot silently disappear.
	 *
	 * @return void
	 */
	public function test_dispatch_carrier_scope_gate_frozen(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php'
		);

		$this->assertStringContainsString(
			"\$is_check_license = 'check_license' === self::strtolower( \$action );",
			$source,
			'dispatch() must derive the carrier-scope flag from the action.'
		);
		$this->assertStringContainsString(
			"( \$is_check_license && class_exists( 'Woodev_License_Command_Acks' ) )",
			$source,
			'The ack store (and thus consumed_command_nonces attach) must be check_license-gated.'
		);
		$this->assertStringContainsString(
			"if ( \$is_check_license && ( class_exists( 'Woodev_License_Command_Dispatcher' ) || \$has_pending_acks ) ) {",
			$source,
			'Pull consumption + ack drain must be check_license-gated.'
		);
	}

	/**
	 * The §4 claim option suffix: the claims store derives its option from
	 * get_plugin_option_name( 'license_required' ) — source-asserted (the
	 * resulting woodev_{id}_license_required option is an installed-site contract).
	 *
	 * @return void
	 */
	public function test_claim_option_suffix_frozen(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-authority-claims.php'
		);

		$this->assertStringContainsString(
			"get_plugin_option_name( 'license_required' )",
			$source,
			'The claim store must derive its option via get_plugin_option_name( license_required ).'
		);
	}

	/**
	 * Stored-option contracts: prefix + names + the pubkey constant name.
	 *
	 * @return void
	 */
	public function test_option_and_constant_names_frozen(): void {
		$this->assertSame( 'woodev_license_command_nonces_', Woodev_License_Command_Nonce_Store::OPTION_PREFIX );
		$this->assertSame( 'woodev_license_command_acks', Woodev_License_Command_Acks::OPTION_NAME );
		$this->assertSame( 'WOODEV_LICENSE_AUTHORITY_PUBKEY', Woodev_License_Envelope_Verifier::PUBKEY_CONSTANT );
		$this->assertSame(
			'woodev_license_remote_deactivation_notices',
			\Woodev_License_Command_Deactivate_Plugin::NOTICES_OPTION
		);
	}

	/**
	 * The §9.6 ack record schema: exact keys, protocol pin, terminal semantics.
	 *
	 * @return void
	 */
	public function test_ack_record_schema_frozen(): void {
		$stored = array();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$acks = new Woodev_License_Command_Acks(
			static function (): int {
				return 1700000000;
			}
		);

		$acks->record( str_repeat( 'a', 32 ), 'executed' );

		$this->assertCount( 1, $stored );
		$this->assertSame(
			array( 'nonce', 'status', 'terminal', 'protocol', 'ts' ),
			array_keys( $stored[0] )
		);
		$this->assertSame( str_repeat( 'a', 32 ), $stored[0]['nonce'] );
		$this->assertSame( 'executed', $stored[0]['status'] );
		$this->assertTrue( $stored[0]['terminal'] );
		$this->assertSame( 1, $stored[0]['protocol'] );
		$this->assertSame( 1700000000, $stored[0]['ts'] );
	}

	/**
	 * Wire field names (request + response) and the route string — source
	 * assertions on the committed transports, so a rename fails loudly.
	 *
	 * @return void
	 */
	public function test_wire_field_names_and_route_frozen(): void {
		$root = dirname( __DIR__, 2 );

		$dispatcher = (string) file_get_contents( $root . '/woodev/licensing/class-license-command-dispatcher.php' );
		$license    = (string) file_get_contents( $root . '/woodev/licensing/class-plugin-license.php' );
		$updater    = (string) file_get_contents( $root . '/woodev/plugin-updater/class-plugin-updater.php' );
		$controller = (string) file_get_contents( $root . '/woodev/licensing/api/class-rest-api-license-command.php' );
		$registrar  = (string) file_get_contents( $root . '/woodev/rest-api/class-rest-v1-registrar.php' );
		$claims     = (string) file_get_contents( $root . '/woodev/licensing/class-license-authority-claims.php' );

		// Code-level accessor tokens on the ACTUAL access sites (not comments) —
		// a rename cannot survive these pins via a docblock match.
		$this->assertStringContainsString( "\$response_data['license_commands']", $dispatcher, 'pull-array response key (array access site)' );
		$this->assertStringContainsString( "\$api_params['consumed_command_nonces']", $license, 'check_license ack request field' );
		$this->assertStringContainsString( "\$params['consumed_command_nonces']", $updater, 'updater ack request field' );
		$this->assertStringContainsString( "\$response_data['acks_received']", $license, 'ack confirmation response key (license, array access site)' );
		$this->assertStringContainsString( '$response->acks_received', $updater, 'ack confirmation response key (updater, object access site)' );
		$this->assertStringContainsString( '$response_data->license_authority', $claims, 'claim envelope response key (object access site)' );
		$this->assertStringContainsString( "\$response_data['license_authority']", $claims, 'claim envelope response key (array access site)' );
		$this->assertStringContainsString( "'/license-command'", $controller, 'route string' );
		$this->assertStringContainsString( "const ROUTE_NAMESPACE = 'woodev/v1'", $registrar, 'namespace' );
		$this->assertStringContainsString( "'__return_true'", $controller, 'locked permission model: auth IS the signature' );
	}

	/**
	 * The public hook contract: woodev_{plugin_id}_remote_deactivated with the
	 * verified payload array as its single argument.
	 *
	 * @return void
	 */
	public function test_remote_deactivated_hook_signature_frozen(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/licensing/commands/class-license-command-deactivate-plugin.php'
		);

		$this->assertStringContainsString(
			'do_action( "woodev_{$plugin_id}_remote_deactivated", $payload )',
			$source
		);
	}

	/**
	 * The shared normalization function exists under its cross-repo-contract name.
	 *
	 * @return void
	 */
	public function test_normalize_site_function_exists(): void {
		$this->assertTrue( function_exists( 'woodev_normalize_site' ) );
	}

	/**
	 * The production public key must be captured before S3.3 honors real claims
	 * or commands. Skipped until the operator runs the woodev-core wp-eval
	 * snippet post-deploy and embeds the value (plan decision 4).
	 *
	 * @return void
	 */
	public function test_production_pubkey_is_not_placeholder(): void {
		if ( '' === (string) WOODEV_LICENSE_AUTHORITY_PUBKEY ) {
			$this->markTestSkipped(
				'Awaiting production WOODEV_LICENSE_AUTHORITY_PUBKEY capture — woodev-core spec wp-eval snippet (operator step).'
			);
		}

		$raw = base64_decode( (string) WOODEV_LICENSE_AUTHORITY_PUBKEY, true );

		$this->assertNotFalse( $raw, 'Production pubkey must be strict base64.' );
		$this->assertSame( Woodev_License_Envelope_Verifier::PUBLIC_KEY_BYTES, strlen( (string) $raw ) );
	}
}
