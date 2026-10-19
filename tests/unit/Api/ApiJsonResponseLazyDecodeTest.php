<?php
/**
 * Lazy JSON response decoding tests for #1269.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/abstract-api-json-response.php';

class Testable_Lazy_JSON_Response extends \Woodev_API_JSON_Response {

	/** @var int */
	public $decode_count = 0;

	protected function decode_response_data() {
		$this->decode_count++;

		return parent::decode_response_data();
	}
}

/** @group api */
class ApiJsonResponseLazyDecodeTest extends TestCase {

	public function test_constructor_and_raw_string_do_not_decode(): void {
		$body = '{"points":[{"id":1}]}';
		$response = new Testable_Lazy_JSON_Response( $body );

		$this->assertSame( 0, $response->decode_count );
		$this->assertSame( $body, $response->to_string() );
		$this->assertSame( 0, $response->decode_count );
	}

	public function test_response_data_decodes_once_and_preserves_object_shape(): void {
		$response = new Testable_Lazy_JSON_Response( '{"points":[{"id":1}]}' );

		$first = $response->response_data;
		$again = $response->response_data;

		$this->assertSame( 1, $response->decode_count );
		$this->assertSame( $first, $again );
		$this->assertInstanceOf( \stdClass::class, $first );
		$this->assertSame( 1, $first->points[0]->id );
	}

	public function test_array_and_invalid_json_keep_json_decode_shape(): void {
		$array_response = new Testable_Lazy_JSON_Response( '[1,2]' );
		$this->assertSame( [ 1, 2 ], $array_response->response_data );

		$invalid_response = new Testable_Lazy_JSON_Response( '{invalid' );
		$this->assertNull( $invalid_response->response_data );
	}

	public function test_magic_field_reads_share_the_cached_decode(): void {
		$response = new Testable_Lazy_JSON_Response( '{"status":"ok"}' );

		$this->assertTrue( isset( $response->status ) );
		$this->assertSame( 'ok', $response->status );
		$this->assertSame( 1, $response->decode_count );
	}
}
