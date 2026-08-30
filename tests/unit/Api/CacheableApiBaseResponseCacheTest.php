<?php
/**
 * Unit tests for Woodev_Cacheable_API_Base::save_response_to_cache().
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/abstract-cacheable-api-base.php';

/**
 * Concrete cacheable API double exposing the cache-writing seam.
 */
class Testable_Cacheable_Api_Base extends \Woodev_Cacheable_API_Base {

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
		return 'woodev_test_cacheable_api_response';
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
	 * @return null
	 */
	protected function get_plugin() {
		return null;
	}
}

/**
 * Regression coverage for the response transient's safe transport shape.
 */
final class CacheableApiBaseResponseCacheTest extends TestCase {

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
}
