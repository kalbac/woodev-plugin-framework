<?php
/**
 * Unit tests for the transport/carrier classification `Woodev_API_Base::handle_response()` gives
 * a failed call (card #945).
 *
 * A transport failure (a `WP_Error`) throws {@see \Woodev_API_Transport_Exception}: the server MAY
 * have acted on the request. The base adds no throw for a 5xx, a missing status or an empty body —
 * it only RE-TYPES the plain {@see \Woodev_API_Exception} a carrier's own validation threw for such a
 * response. A validation exception for a 4xx — a deliberate refusal — stays a plain
 * {@see \Woodev_API_Exception}, and a subclass of it is never re-typed, whatever the response.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-transport-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

/**
 * Drives the real `handle_response()` with the transport stubbed out; the carrier's own
 * validation is the `$validation_message` it throws when set.
 */
class Testable_Api_Base_For_Transport_Test extends \Woodev_API_Base {

	/** @var string|null message of the exception the carrier's pre-parse validation throws; null = none */
	public $validation_message;

	/** @var class-string<\Woodev_API_Exception> the class of that exception */
	public $validation_class = \Woodev_API_Exception::class;

	/**
	 * @param mixed $response whatever the transport returned.
	 * @return mixed
	 */
	public function handle_response_for_test( $response ) {
		return $this->handle_response( $response );
	}

	protected function do_pre_parse_response_validation() {
		if ( null !== $this->validation_message ) {
			throw new $this->validation_class( $this->validation_message, 42 );
		}
	}

	protected function get_parsed_response( $raw_response_body ) {
		return new \stdClass();
	}

	protected function broadcast_request() {}

	protected function get_new_request( $args = [] ) {
		return null;
	}

	protected function get_plugin() {
		return null;
	}
}

/**
 * A third-party exception class: a subclass of the base.
 */
class Third_Party_Api_Exception extends \Woodev_API_Exception {}

/**
 * @covers \Woodev_API_Base::handle_response
 * @covers \Woodev_API_Base::is_transport_level_response
 */
final class ApiBaseTransportExceptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
	}

	/**
	 * @param int|string $code    the HTTP status the transport reports.
	 * @param string     $body    the response body.
	 * @param string|null $message the carrier validation's exception message; null = it accepts the response.
	 * @param string      $class   the class of the exception the validation throws.
	 * @return \Woodev_API_Exception|null what handle_response() threw, or null.
	 */
	private function failure_for( $code, string $body, ?string $message = 'carrier text', string $class = \Woodev_API_Exception::class ): ?\Woodev_API_Exception {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$api                     = new Testable_Api_Base_For_Transport_Test();
		$api->validation_message = $message;
		$api->validation_class   = $class;

		try {
			$api->handle_response_for_test( [ 'stubbed' => true ] );
		} catch ( \Woodev_API_Exception $exception ) {
			return $exception;
		}

		return null;
	}

	public function test_a_wp_error_is_a_transport_exception(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );

		$error = new class() {
			public function get_error_message() {
				return 'cURL error 28: Operation timed out';
			}

			public function get_error_code() {
				return 'http_request_failed';
			}
		};

		try {
			( new Testable_Api_Base_For_Transport_Test() )->handle_response_for_test( $error );
			$this->fail( 'a transport error must throw' );
		} catch ( \Woodev_API_Transport_Exception $exception ) {
			$this->assertStringContainsString( 'timed out', $exception->getMessage() );
		}
	}

	public function test_a_transport_exception_is_still_an_api_exception(): void {
		$this->assertInstanceOf( \Woodev_API_Exception::class, new \Woodev_API_Transport_Exception( 'x' ) );
	}

	/**
	 * @dataProvider provide_transport_level_responses
	 *
	 * @param int|string $code the status.
	 * @param string     $body the body.
	 */
	public function test_a_validation_failure_on_a_transport_level_response_becomes_a_transport_exception( $code, string $body ): void {
		$exception = $this->failure_for( $code, $body );

		$this->assertInstanceOf( \Woodev_API_Transport_Exception::class, $exception );
		$this->assertSame( 'carrier text', $exception->getMessage(), 'the carrier text survives' );
		$this->assertSame( 42, $exception->getCode(), 'so does the code' );
		$this->assertInstanceOf( \Woodev_API_Exception::class, $exception->getPrevious() );
	}

	/**
	 * @return array<string, array{0: int|string, 1: string}>
	 */
	public function provide_transport_level_responses(): array {
		return [
			'500 with a body'         => [ 500, '{"error":"boom"}' ],
			'503'                     => [ 503, 'Service Unavailable' ],
			'no status at all'        => [ '', '{"x":1}' ],
			'200 with an empty body'  => [ 200, '' ],
			'200 with a blank body'   => [ 200, "  \n" ],
		];
	}

	/**
	 * @dataProvider provide_carrier_level_responses
	 *
	 * @param int    $code the status.
	 * @param string $body the body.
	 */
	public function test_a_validation_failure_on_a_carrier_level_response_stays_a_plain_exception( int $code, string $body ): void {
		$exception = $this->failure_for( $code, $body );

		$this->assertNotNull( $exception );
		$this->assertNotInstanceOf( \Woodev_API_Transport_Exception::class, $exception );
		$this->assertSame( 'carrier text', $exception->getMessage() );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public function provide_carrier_level_responses(): array {
		return [
			'400 with a body'          => [ 400, '{"error":"bad zip"}' ],
			'422'                      => [ 422, '{"errors":[]}' ],
			'404 with an empty body'   => [ 404, '' ],
			'200 with a carrier error' => [ 200, '{"error":"unknown pvz"}' ],
		];
	}

	/**
	 * @dataProvider provide_transport_level_responses
	 *
	 * @param int|string $code the status.
	 * @param string     $body the body.
	 */
	public function test_a_third_party_subclass_keeps_its_class_even_on_a_transport_level_response( $code, string $body ): void {
		$exception = $this->failure_for( $code, $body, 'carrier text', Third_Party_Api_Exception::class );

		$this->assertSame( Third_Party_Api_Exception::class, get_class( $exception ), 'a `catch ( My_API_Exception )` keeps matching' );
		$this->assertSame( 42, $exception->getCode(), 'and the code it carries is what a caller classifies it by' );
	}

	public function test_an_accepted_response_throws_nothing(): void {
		$this->assertNull( $this->failure_for( 200, '{"ok":true}', null ) );
	}
}
