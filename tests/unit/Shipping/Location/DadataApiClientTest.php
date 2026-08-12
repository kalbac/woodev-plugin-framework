<?php
/**
 * Unit tests for Dadata_Api_Client / Dadata_Api_Request / Dadata_Api_Response —
 * the framework API-layer plumbing under Dadata_Provider (Task 7).
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Client;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-client.php';

/**
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Client
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Request
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Response
 */
final class DadataApiClientTest extends TestCase {

	/** @var array{url: string, args: array<string, mixed>}|null */
	private ?array $last_request = null;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->last_request = null;
	}

	private function stub_http_response( int $code, string $body ): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 200 === $code ? 'OK' : 'Error' );

		Functions\when( 'wp_safe_remote_request' )->alias(
			function ( $url, $args ) {
				$this->last_request = [
					'url'  => $url,
					'args' => $args,
				];

				return [];
			}
		);
	}

	// -------------------------------------------------------------------------
	// Headers
	// -------------------------------------------------------------------------

	public function test_authorization_header_carries_the_token(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( new Dadata_Api_Client( 'my-token' ) )->suggest_address( 'q' );

		$this->assertSame( 'Token my-token', $this->last_request['args']['headers']['Authorization'] );
	}

	public function test_x_secret_header_is_absent_without_a_secret(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( new Dadata_Api_Client( 'tok' ) )->suggest_address( 'q' );

		$this->assertArrayNotHasKey( 'X-Secret', $this->last_request['args']['headers'] );
	}

	public function test_x_secret_header_is_sent_when_a_secret_is_configured(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( new Dadata_Api_Client( 'tok', 'my-secret' ) )->suggest_address( 'q' );

		$this->assertSame( 'my-secret', $this->last_request['args']['headers']['X-Secret'] );
	}

	// -------------------------------------------------------------------------
	// Endpoint / path / body construction
	// -------------------------------------------------------------------------

	public function test_suggest_address_posts_to_the_suggestions_host(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( new Dadata_Api_Client( 'tok' ) )->suggest_address( 'Моск', [ 'count' => 5 ] );

		$this->assertSame( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address', $this->last_request['url'] );
		$this->assertSame( 'POST', $this->last_request['args']['method'] );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'Моск', $body['query'] );
		$this->assertSame( 5, $body['count'] );
	}

	public function test_iplocate_address_gets_with_ip_in_the_query_string(): void {
		$this->stub_http_response( 200, '{"location":{"value":"x","data":{}}}' );
		( new Dadata_Api_Client( 'tok' ) )->iplocate_address( '1.2.3.4' );

		$this->assertSame( 'GET', $this->last_request['args']['method'] );
		$this->assertSame( '', $this->last_request['args']['body'] );
	}

	public function test_find_by_id_address_posts_the_fias_id_as_query(): void {
		$this->stub_http_response( 200, '{"suggestions":[{"value":"x","data":{}}]}' );
		( new Dadata_Api_Client( 'tok' ) )->find_by_id_address( 'fias-1' );

		$this->assertSame( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/address', $this->last_request['url'] );
		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'fias-1', $body['query'] );
	}

	public function test_find_by_id_address_returns_null_for_an_empty_suggestions_array(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		$this->assertNull( ( new Dadata_Api_Client( 'tok' ) )->find_by_id_address( 'unknown-fias' ) );
	}

	public function test_clean_address_posts_to_the_cleaner_host_with_an_array_body(): void {
		$this->stub_http_response( 200, '[{"result":"x"}]' );
		( new Dadata_Api_Client( 'tok', 'sec' ) )->clean_address( 'мск сухонска 11/-89' );

		$this->assertSame( 'https://cleaner.dadata.ru/api/v1/clean/address', $this->last_request['url'] );

		// The body must be a JSON ARRAY (batch shape), not an object.
		$decoded = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( [ 'мск сухонска 11/-89' ], $decoded );
		$this->assertStringStartsWith( '[', $this->last_request['args']['body'] );
	}

	// -------------------------------------------------------------------------
	// Response parsing — array-wrapped vs bare-object clean result
	// -------------------------------------------------------------------------

	public function test_clean_address_accepts_an_array_wrapped_response(): void {
		$this->stub_http_response( 200, '[{"result":"г Москва"}]' );

		$this->assertSame( [ 'result' => 'г Москва' ], ( new Dadata_Api_Client( 'tok', 'sec' ) )->clean_address( 'q' ) );
	}

	public function test_clean_address_accepts_a_bare_object_response(): void {
		$this->stub_http_response( 200, '{"result":"г Москва"}' );

		$this->assertSame( [ 'result' => 'г Москва' ], ( new Dadata_Api_Client( 'tok', 'sec' ) )->clean_address( 'q' ) );
	}

	public function test_suggest_address_converts_stdclass_tree_to_plain_arrays(): void {
		$this->stub_http_response( 200, '{"suggestions":[{"value":"x","data":{"region":"Москва","nested":{"a":1}}}]}' );

		$suggestions = ( new Dadata_Api_Client( 'tok' ) )->suggest_address( 'q' );

		$this->assertIsArray( $suggestions[0]['data'] );
		$this->assertSame( 'Москва', $suggestions[0]['data']['region'] );
		$this->assertIsArray( $suggestions[0]['data']['nested'] );
		$this->assertSame( 1, $suggestions[0]['data']['nested']['a'] );
	}

	// -------------------------------------------------------------------------
	// Error handling
	// -------------------------------------------------------------------------

	public function test_a_200_response_does_not_throw(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		$this->assertSame( [], ( new Dadata_Api_Client( 'tok' ) )->suggest_address( 'q' ) );
	}

	public function test_a_401_response_throws_a_woodev_api_exception(): void {
		$this->stub_http_response( 401, '' );

		$this->expectException( \Woodev_API_Exception::class );
		( new Dadata_Api_Client( 'bad-token' ) )->suggest_address( 'q' );
	}

	public function test_a_500_response_throws_a_woodev_api_exception(): void {
		$this->stub_http_response( 500, 'Internal Server Error' );

		$this->expectException( \Woodev_API_Exception::class );
		( new Dadata_Api_Client( 'tok' ) )->suggest_address( 'q' );
	}

	public function test_a_network_level_wp_error_throws_a_woodev_api_exception(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_safe_remote_request' )->justReturn( new \WP_Error( 'timeout', 'Timed out' ) );

		$this->expectException( \Woodev_API_Exception::class );
		( new Dadata_Api_Client( 'tok' ) )->suggest_address( 'q' );
	}
}
