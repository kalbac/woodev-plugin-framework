<?php
/**
 * Probe: what does the REAL validate_license() store for each licence-server answer?
 *
 * Research probe (branch research/license-validate-overwrite), not a fix. It drives the real
 * Woodev_Plugins_License::validate_license() through the real Woodev_Licensing_API response handling
 * (only the HTTP transport is canned) against an in-memory options table that already holds a
 * genuinely valid licence. The assertions state the DESIRED behaviour: an inconclusive answer keeps
 * the previous state. A case that fails today is a proven overwrite.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/abstract-api-json-response.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-transport-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-rate-limit-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-response.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-authority-claims.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';

/**
 * The real Woodev_Licensing_API with only the HTTP transport replaced by a canned answer.
 */
class Canned_Transport_Licensing_API extends \Woodev_Licensing_API {

	/** @var array|\WP_Error|null What wp_safe_remote_request() would have returned. */
	public $canned;

	/**
	 * Wires the pieces the (skipped) constructor would have.
	 *
	 * @param \Woodev_Plugin $plugin Plugin double.
	 * @return void
	 */
	public function wire( $plugin ): void {
		$this->request_uri = 'https://woodev.ru/';
		$this->set_request_content_type_header( 'application/json' );
		$this->set_request_accept_header( 'application/json' );
		$this->set_response_handler( 'Woodev_Licencing_API_Response' );

		$property = new \ReflectionProperty( \Woodev_Licensing_API::class, 'plugin' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $this, $plugin );
	}

	/** @var int Transport calls made. */
	public $calls = 0;

	/** @var \Throwable|null What make_request() threw, if anything. */
	public $thrown;

	/**
	 * {@inheritDoc}
	 */
	public function make_request( array $api_params = [] ) {
		try {
			return parent::make_request( $api_params );
		} catch ( \Throwable $throwable ) {
			$this->thrown = $throwable;
			throw $throwable;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function do_remote_request( $request_uri, $request_args ) {
		++$this->calls;
		return $this->canned;
	}
}

/**
 * Class LicenseValidateOverwriteProbeTest.
 */
class LicenseValidateOverwriteProbeTest extends TestCase {

	private const OPTION = 'woodev_probe_plugin_license';

	/** @var array<string, mixed> In-memory options table. */
	private $options = [];

	/**
	 * Answers that cannot tell us anything about the licence: the stored state must survive each.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function inconclusive_answers(): array {
		$wrap = static function ( int $code, string $body, array $headers = [] ): array {
			return [
				'response' => [
					'code'    => $code,
					'message' => 'x',
				],
				'headers'  => $headers,
				'body'     => $body,
			];
		};

		return [
			'WP_Error / timeout'                     => [ new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) ],
			'HTTP 500 html'                          => [ $wrap( 500, '<html>Internal Server Error</html>' ) ],
			'HTTP 502 empty body'                    => [ $wrap( 502, '' ) ],
			'HTTP 503 json'                          => [ $wrap( 503, '{"message":"maintenance"}' ) ],
			'HTTP 429 with Retry-After'              => [ $wrap( 429, '{"message":"slow down"}', [ 'retry-after' => '60' ] ) ],
			'HTTP 404 json'                          => [ $wrap( 404, '{"code":"rest_no_route"}' ) ],
			'200 non-JSON (html challenge page)'     => [ $wrap( 200, '<html>Just a moment...</html>' ) ],
			'200 empty body'                         => [ $wrap( 200, '' ) ],
			'200 JSON null'                          => [ $wrap( 200, 'null' ) ],
			'200 JSON empty array'                   => [ $wrap( 200, '[]' ) ],
			'200 JSON empty object'                  => [ $wrap( 200, '{}' ) ],
			'200 JSON object without license field'  => [ $wrap( 200, '{"code":"maintenance","message":"back soon"}' ) ],
			'200 JSON success:true without license'  => [ $wrap( 200, '{"success":true}' ) ],
			'200 JSON bare string'                   => [ $wrap( 200, '"maintenance"' ) ],
			'200 JSON non-empty list'                => [ $wrap( 200, '[{"license":"invalid"}]' ) ],
		];
	}

	/**
	 * @dataProvider inconclusive_answers
	 *
	 * @param mixed $answer What the transport returns.
	 * @return void
	 */
	public function test_inconclusive_answer_keeps_a_valid_licence_valid( $answer ): void {
		$license = $this->make_engine( $answer );

		$license->validate_license( 'KEY-123' );

		// Guard against a false pass: the request must have reached the transport, and anything thrown
		// must be an API-layer exception (not a harness error swallowed by validate_license()).
		$api = $this->get_api( $license );
		$this->assertSame( 1, $api->calls, 'harness: transport was not reached' );
		if ( null !== $api->thrown ) {
			$this->assertInstanceOf( \Woodev_API_Exception::class, $api->thrown, 'harness: unexpected ' . get_class( $api->thrown ) . ': ' . $api->thrown->getMessage() );
		}

		$stored = $this->options[ self::OPTION ];
		$this->assertSame( 'valid', $stored->license, 'stored licence status was overwritten' );
		$this->assertTrue( $stored->success, 'stored success flag was overwritten' );
		$this->assertSame( '2099-01-01 00:00:00', $stored->expires, 'stored expiry was overwritten' );
		$this->assertTrue( $license->is_license_valid(), 'is_license_valid() flipped to false' );
		$this->assertTrue( $license->is_active(), 'is_active() flipped to false' );
	}

	/**
	 * Characterisation: every definite EDD answer IS written. Passing today — the decision under test
	 * is only which of them may flip a licence that is genuinely valid.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function definite_edd_answers(): array {
		return [
			'expired'              => [ 'expired', [ 'success' => true ] ],
			'disabled'             => [ 'disabled', [ 'success' => true ] ],
			'revoked'              => [ 'revoked', [ 'success' => true ] ],
			'inactive'             => [ 'inactive', [ 'success' => true ] ],
			'site_inactive'        => [ 'site_inactive', [ 'success' => true ] ],
			'invalid (missing)'    => [ 'invalid', [ 'success' => false, 'error' => 'missing' ] ],
			'item_name_mismatch'   => [ 'invalid', [ 'success' => false, 'error' => 'item_name_mismatch' ] ],
			'no_activations_left'  => [ 'invalid', [ 'success' => false, 'error' => 'no_activations_left' ] ],
			'invalid_item_id'      => [ 'invalid', [ 'success' => false, 'error' => 'invalid_item_id' ] ],
		];
	}

	/**
	 * @dataProvider definite_edd_answers
	 *
	 * @param string               $status Licence token in the body.
	 * @param array<string, mixed> $extra  Other body fields.
	 * @return void
	 */
	public function test_definite_edd_answer_is_stored_as_is( string $status, array $extra ): void {
		$body    = wp_json_encode_probe( array_merge( [ 'license' => $status ], $extra ) );
		$license = $this->make_engine(
			[
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => $body,
			]
		);

		$license->validate_license( 'KEY-123' );

		$this->assertSame( $status, $this->options[ self::OPTION ]->license );
		$this->assertFalse( $license->is_license_valid() );
	}

	/**
	 * Harness sanity: a complete valid answer is stored (proves the engine really writes through the real API layer).
	 *
	 * @return void
	 */
	public function test_complete_valid_answer_is_stored(): void {
		$license = $this->make_engine(
			[
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => '{"license":"valid","success":true,"expires":"2100-02-02 00:00:00"}',
			]
		);

		$license->validate_license( 'KEY-123' );

		$this->assertSame( 1, $this->get_api( $license )->calls );
		$this->assertSame( '2100-02-02 00:00:00', $this->options[ self::OPTION ]->expires );
		$this->assertTrue( $license->is_license_valid() );
	}

	/**
	 * Characterisation: a valid answer that omits `expires` wipes the stored expiry (a lifetime-looking value).
	 *
	 * @return void
	 */
	public function test_valid_answer_without_expires_keeps_the_stored_expiry(): void {
		$license = $this->make_engine(
			[
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => '{"license":"valid","success":true}',
			]
		);

		$license->validate_license( 'KEY-123' );

		$this->assertSame( 'valid', $this->options[ self::OPTION ]->license );
		$this->assertSame( '2099-01-01 00:00:00', $this->options[ self::OPTION ]->expires );
	}

	/**
	 * Builds a REAL engine over the real API layer, with a valid licence already stored.
	 *
	 * @param mixed $answer Canned transport answer.
	 * @return \Woodev_Plugins_License
	 */
	private function make_engine( $answer ): \Woodev_Plugins_License {
		$this->options = [
			self::OPTION                     => (object) [
				'license' => 'valid',
				'success' => true,
				'error'   => null,
				'expires' => '2099-01-01 00:00:00',
			],
			'woodev_probe_plugin_license_key' => 'KEY-123',
		];

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'get_site_url' )->justReturn( 'https://example.test' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'current_time' )->justReturn( 1_700_000_000 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'] ?? '';
			}
		);
		Functions\when( 'wp_remote_retrieve_response_message' )->alias(
			static function ( $response ) {
				return $response['response']['message'] ?? '';
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static function ( $response ) {
				return $response['headers'] ?? [];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, $header ) {
				return $response['headers'][ $header ] ?? '';
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'get_download_id' )->andReturn( 216 );
		$plugin->shouldReceive( 'get_version' )->andReturn( '2.0.0' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Probe Plugin' );
		$plugin->shouldReceive( 'get_plugin_option_name' )->andReturnUsing(
			static function ( $suffix ) {
				return 'woodev_probe_plugin_' . $suffix;
			}
		);
		$plugin->shouldReceive( 'require_tls_1_2' )->andReturn( false );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'probe-plugin' );
		$plugin->shouldReceive( 'get_id_dasherized' )->andReturn( 'probe-plugin' );
		$plugin->shouldReceive( 'get_id_underscored' )->andReturn( 'probe_plugin' );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( '/tmp/probe/probe.php' );
		$plugin->shouldReceive( 'log_api_request' )->andReturnNull();

		$api            = ( new \ReflectionClass( Canned_Transport_Licensing_API::class ) )->newInstanceWithoutConstructor();
		$api->canned    = $answer;
		$api->wire( $plugin );

		$woodev_license = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$this->set_private_property( $woodev_license, 'option_name', self::OPTION );
		$this->set_private_property( $woodev_license, 'plugin_id', 'woodev_probe_plugin' );
		$woodev_license->get();

		$claims = Mockery::mock( \Woodev_License_Authority_Claims::class );
		$claims->shouldReceive( 'consume_from_response' )->andReturnNull();
		$claims->shouldReceive( 'get_verified' )->andReturn( null );

		$license = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();
		$this->set_private_property( $license, 'plugin', $plugin );
		$this->set_private_property( $license, 'license_key', 'KEY-123' );
		$this->set_private_property( $license, 'woodev_license', $woodev_license );
		$this->set_private_property( $license, 'item_name', 'Probe Plugin' );
		$this->set_private_property( $license, 'api_handler', $api );
		$this->set_private_property( $license, 'authority_claims', $claims );

		return $license;
	}

	/**
	 * The canned API behind an engine.
	 *
	 * @param \Woodev_Plugins_License $license Engine.
	 * @return Canned_Transport_Licensing_API
	 */
	private function get_api( \Woodev_Plugins_License $license ): Canned_Transport_Licensing_API {
		$property = new \ReflectionProperty( $license, 'api_handler' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		return $property->getValue( $license );
	}

	/**
	 * Sets a property value via reflection.
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

/**
 * Local JSON encoder for the data-provider bodies (the test file must not depend on a WP function).
 *
 * @param array<string, mixed> $data Payload.
 * @return string
 */
function wp_json_encode_probe( array $data ): string {
	return (string) json_encode( $data );
}
