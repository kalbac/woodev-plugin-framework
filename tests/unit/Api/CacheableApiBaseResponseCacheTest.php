<?php
/**
 * Unit tests for Woodev_Cacheable_API_Base::save_response_to_cache().
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/abstract-cacheable-api-base.php';

/**
 * Concrete cacheable API double exposing the cache-writing seam.
 */
class Testable_Cacheable_Api_Base extends \Woodev_Cacheable_API_Base {

	/** @var string transient key reported by the double */
	public $transient_key = 'woodev_test_cacheable_api_response';

	/** @var int|null cap returned by the class-level override, null = keep the base default */
	public $max_bytes_override = null;

	/** @var object plugin double recording log() calls */
	public $plugin;

	public function __construct() {
		$this->plugin = new class() {
			/** @var string[] */
			public $logged = [];

			public function get_id() {
				return 'test_plugin';
			}

			public function log( $message ) {
				$this->logged[] = $message;
			}
		};
	}

	/**
	 * Writes a response through the protected cache seam.
	 *
	 * @param array<string, mixed> $response HTTP response.
	 * @return void
	 */
	public function save_response_to_cache_for_test( array $response ): void {
		$this->save_response_to_cache( $response );
	}

	/**
	 * Runs a raw HTTP response through the real handle_response() path.
	 *
	 * @param array<string, mixed> $response HTTP response.
	 * @return mixed whatever handle_response() hands back to the caller.
	 */
	public function handle_response_for_test( array $response ) {
		return $this->handle_response( $response );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	protected function is_request_cacheable() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $raw_response_body Raw response body.
	 * @return object
	 */
	protected function get_parsed_response( $raw_response_body ) {
		return (object) [ 'raw' => $raw_response_body ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function broadcast_request() {}

	/**
	 * Seeds the processed response data consumed by the cache writer.
	 *
	 * @param array<string, mixed> $headers Response headers.
	 * @param string               $body    Response body.
	 * @param int                  $code    HTTP response code.
	 * @param string               $message HTTP response message.
	 * @return void
	 */
	public function seed_response_for_test( array $headers, string $body, int $code, string $message ): void {
		$this->response_headers = $headers;
		$this->raw_response_body = $body;
		$this->response_code = $code;
		$this->response_message = $message;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_request_transient_key() {
		return $this->transient_key;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	protected function get_request_cache_lifetime() {
		return 300;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return null
	 */
	protected function get_new_request( $args = [] ) {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	protected function get_request_cache_max_bytes(): int {
		return null === $this->max_bytes_override ? parent::get_request_cache_max_bytes() : $this->max_bytes_override;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return object
	 */
	protected function get_plugin() {
		return $this->plugin;
	}
}

/**
 * Regression coverage for the response transient's safe transport shape.
 */
final class CacheableApiBaseResponseCacheTest extends TestCase {

	/**
	 * Stubs the serializer and clears the once-per-page-load log memo.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'maybe_serialize' )->alias( 'serialize' );
		Functions\when( 'delete_transient' )->justReturn( true );

		$memo = new \ReflectionProperty( \Woodev_Cacheable_API_Base::class, 'oversized_response_logged' );
		if ( PHP_VERSION_ID < 80100 ) {
			$memo->setAccessible( true );
		}
		$memo->setValue( null, [] );
	}

	/**
	 * Builds an API double whose response body is $body_bytes long.
	 *
	 * @param int $body_bytes Body length in bytes.
	 * @return Testable_Cacheable_Api_Base
	 */
	private function api_with_body_of( int $body_bytes ): Testable_Cacheable_Api_Base {
		$api = new Testable_Cacheable_Api_Base();
		$api->seed_response_for_test( [], str_repeat( 'x', $body_bytes ), 200, 'OK' );

		return $api;
	}

	/**
	 * Captures set_transient() calls.
	 *
	 * @return \ArrayObject
	 */
	private function capture_transient_writes(): \ArrayObject {
		$writes = new \ArrayObject();

		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration ) use ( $writes ) {
				$writes[] = [ $key, $value, $expiration ];

				return true;
			}
		);

		return $writes;
	}

	/**
	 * The cache preserves body, response metadata and non-secret headers needed
	 * by existing consumers, while never persisting credential-bearing headers
	 * or the parsed cookie objects that duplicate Set-Cookie values.
	 *
	 * @return void
	 */
	public function test_cache_excludes_response_credentials_but_keeps_required_response_data(): void {
		$stored = null;

		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration ) use ( &$stored ) {
				$stored = [
					'key'        => $key,
					'value'      => $value,
					'expiration' => $expiration,
				];

				return true;
			}
		);

		$api = new Testable_Cacheable_Api_Base();
		$api->seed_response_for_test(
			[
				'Set-Cookie'     => 'carrier_session=secret-cookie-value',
				'X-Current-Page' => '2',
				'X-Auth-Token'   => 'secret-token-value',
			],
			'{"rates":[]}',
			200,
			'OK'
		);
		$api->save_response_to_cache_for_test(
			[
				'headers'       => [
					'Set-Cookie'      => 'carrier_session=secret-cookie-value',
					'X-Current-Page'  => '2',
					'X-Auth-Token'    => 'secret-token-value',
				],
				'body'          => '{"rates":[]}',
				'response'      => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'       => [ (object) [ 'value' => 'secret-cookie-value' ] ],
				'http_response' => (object) [ 'cookies' => [ 'secret-cookie-value' ] ],
			]
		);

		$this->assertSame( 'woodev_test_cacheable_api_response', $stored['key'] );
		$this->assertSame( 300, $stored['expiration'] );
		$this->assertSame( '{"rates":[]}', $stored['value']['body'] );
		$this->assertSame( [ 'code' => 200, 'message' => 'OK' ], $stored['value']['response'] );
		$this->assertSame( '2', $stored['value']['headers']['X-Current-Page'] );
		$this->assertSame( \Woodev_API_Base::SECRET_VALUE_MASK, $stored['value']['headers']['Set-Cookie'] );
		$this->assertSame( \Woodev_API_Base::SECRET_VALUE_MASK, $stored['value']['headers']['X-Auth-Token'] );
		$this->assertArrayNotHasKey( 'cookies', $stored['value'] );
		$this->assertArrayNotHasKey( 'http_response', $stored['value'] );
		$this->assertStringNotContainsString( 'secret-cookie-value', serialize( $stored['value'] ) );
		$this->assertStringNotContainsString( 'secret-token-value', serialize( $stored['value'] ) );
	}
	/**
	 * A payload under the default 512 KB cap is cached.
	 *
	 * @return void
	 */
	public function test_small_payload_is_cached(): void {
		$writes = $this->capture_transient_writes();

		$this->api_with_body_of( 1024 )->save_response_to_cache_for_test( [] );

		$this->assertCount( 1, $writes );
	}

	/**
	 * Captures delete_transient() calls.
	 *
	 * @return \ArrayObject
	 */
	private function capture_transient_deletes(): \ArrayObject {
		$deletes = new \ArrayObject();

		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( $deletes ) {
				$deletes[] = $key;

				return true;
			}
		);

		return $deletes;
	}

	/**
	 * A payload over the default cap is not cached.
	 *
	 * @return void
	 */
	public function test_payload_over_the_default_cap_is_not_cached(): void {
		$writes = $this->capture_transient_writes();

		$this->api_with_body_of( \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES + 1 )->save_response_to_cache_for_test( [] );

		$this->assertCount( 0, $writes );
	}

	/**
	 * An oversized fresh response removes the older entry under the same key, so a
	 * forced refresh never leaves a stale response to be served next time (#952).
	 *
	 * @return void
	 */
	public function test_oversized_response_deletes_the_existing_cached_entry(): void {
		$writes  = $this->capture_transient_writes();
		$deletes = $this->capture_transient_deletes();

		$this->api_with_body_of( \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES + 1 )->save_response_to_cache_for_test( [] );

		$this->assertCount( 0, $writes );
		$this->assertSame( [ 'woodev_test_cacheable_api_response' ], $deletes->getArrayCopy() );
	}

	/**
	 * A response that is cached never deletes the entry it just wrote.
	 *
	 * @return void
	 */
	public function test_cached_response_does_not_delete_the_entry(): void {
		$this->capture_transient_writes();
		$deletes = $this->capture_transient_deletes();

		$this->api_with_body_of( 1024 )->save_response_to_cache_for_test( [] );

		$this->assertCount( 0, $deletes );
	}

	/**
	 * Through the real handle_response() path an oversized response is still handed
	 * back to the caller, uncached, with the stale entry removed.
	 *
	 * @return void
	 */
	public function test_oversized_response_is_still_returned_by_handle_response(): void {
		$writes  = $this->capture_transient_writes();
		$deletes = $this->capture_transient_deletes();
		$body    = str_repeat( 'x', \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES + 1 );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 'OK' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );

		$result = ( new Testable_Cacheable_Api_Base() )->handle_response_for_test(
			[
				'headers'  => [],
				'body'     => $body,
				'response' => [ 'code' => 200, 'message' => 'OK' ],
			]
		);

		$this->assertSame( $body, $result->raw );
		$this->assertCount( 0, $writes, 'not cached' );
		$this->assertCount( 1, $deletes, 'stale entry removed' );
	}

	/**
	 * A filter value that is not a number is ignored: the cap stays, never lifts.
	 *
	 * @dataProvider non_numeric_filter_values
	 *
	 * @param mixed $bad_value Value the filter returns.
	 * @return void
	 */
	public function test_non_numeric_filter_value_keeps_the_cap( $bad_value ): void {
		$writes = $this->capture_transient_writes();

		Filters\expectApplied( 'woodev_plugin_test_plugin_api_request_cache_max_bytes' )->andReturn( $bad_value );

		$this->api_with_body_of( \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES + 1 )->save_response_to_cache_for_test( [] );

		$this->assertCount( 0, $writes );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function non_numeric_filter_values(): array {
		return [
			'false'          => [ false ],
			'null'           => [ null ],
			'empty string'   => [ '' ],
			'unit suffix'    => [ '1M' ],
		];
	}

	/**
	 * The cap is measured on the serialized form: a body just under the cap that
	 * serializes above it is refused, the one that serializes at it is kept.
	 *
	 * @return void
	 */
	public function test_cap_is_measured_on_the_serialized_payload(): void {
		$writes = $this->capture_transient_writes();

		$api = $this->api_with_body_of( 100 );
		$api->max_bytes_override = strlen( serialize( [
			'headers'  => [],
			'body'     => str_repeat( 'x', 100 ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		] ) );
		$api->save_response_to_cache_for_test( [] );
		$this->assertCount( 1, $writes, 'exactly at the cap is cached' );

		$api->max_bytes_override -= 1;
		$api->save_response_to_cache_for_test( [] );
		$this->assertCount( 1, $writes, 'one byte over the cap is not' );
	}

	/**
	 * The class-level override moves the cap.
	 *
	 * @return void
	 */
	public function test_class_override_changes_the_cap(): void {
		$writes = $this->capture_transient_writes();

		$api = $this->api_with_body_of( 2048 );
		$api->max_bytes_override = 1024;
		$api->save_response_to_cache_for_test( [] );
		$this->assertCount( 0, $writes );

		$api->max_bytes_override = 4096;
		$api->save_response_to_cache_for_test( [] );
		$this->assertCount( 1, $writes );
	}

	/**
	 * The plugin-scoped filter moves the cap, and has the last word over the class.
	 *
	 * @return void
	 */
	public function test_filter_changes_the_cap(): void {
		$writes = $this->capture_transient_writes();

		Filters\expectApplied( 'woodev_plugin_test_plugin_api_request_cache_max_bytes' )
			->once()
			->with( \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES, null )
			->andReturn( 1024 );

		$this->api_with_body_of( 2048 )->save_response_to_cache_for_test( [] );
		$this->assertCount( 0, $writes );
	}

	/**
	 * A raised filter value lets an oversized-by-default payload through.
	 *
	 * @return void
	 */
	public function test_filter_can_raise_the_cap(): void {
		$writes = $this->capture_transient_writes();

		Filters\expectApplied( 'woodev_plugin_test_plugin_api_request_cache_max_bytes' )->andReturn( 4 * 1024 * 1024 );

		$this->api_with_body_of( \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES + 1 )->save_response_to_cache_for_test( [] );
		$this->assertCount( 1, $writes );
	}

	/**
	 * Zero or a negative cap disables the check, through either seam.
	 *
	 * @return void
	 */
	public function test_zero_or_negative_cap_means_no_cap(): void {
		$writes = $this->capture_transient_writes();

		$api = $this->api_with_body_of( \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES * 2 );

		$api->max_bytes_override = 0;
		$api->save_response_to_cache_for_test( [] );
		$this->assertCount( 1, $writes );

		$api->max_bytes_override = -1;
		$api->save_response_to_cache_for_test( [] );
		$this->assertCount( 2, $writes );
	}

	/**
	 * A filter returning 0 also removes the cap.
	 *
	 * @return void
	 */
	public function test_filter_returning_zero_means_no_cap(): void {
		$writes = $this->capture_transient_writes();

		Filters\expectApplied( 'woodev_plugin_test_plugin_api_request_cache_max_bytes' )->andReturn( 0 );

		$this->api_with_body_of( \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES * 2 )->save_response_to_cache_for_test( [] );
		$this->assertCount( 1, $writes );
	}

	/**
	 * The skip is logged once per request key per page load, without leaking the body.
	 *
	 * @return void
	 */
	public function test_skip_is_logged_once_per_request_key(): void {
		$this->capture_transient_writes();

		$api = $this->api_with_body_of( \Woodev_Cacheable_API_Base::DEFAULT_CACHE_MAX_BYTES + 1 );
		$api->save_response_to_cache_for_test( [] );
		$api->save_response_to_cache_for_test( [] );

		$this->assertCount( 1, $api->plugin->logged );
		$this->assertStringContainsString( 'not cached', $api->plugin->logged[0] );
		$this->assertStringContainsString( 'woodev_test_cacheable_api_response', $api->plugin->logged[0] );
		$this->assertStringNotContainsString( 'xxxx', $api->plugin->logged[0] );

		// A different request key is a different report.
		$api->transient_key = 'woodev_test_other_key';
		$api->save_response_to_cache_for_test( [] );
		$this->assertCount( 2, $api->plugin->logged );
	}

	/**
	 * A cached response is never logged as skipped.
	 *
	 * @return void
	 */
	public function test_nothing_is_logged_when_the_response_is_cached(): void {
		$this->capture_transient_writes();

		$api = $this->api_with_body_of( 10 );
		$api->save_response_to_cache_for_test( [] );

		$this->assertSame( [], $api->plugin->logged );
	}
}
