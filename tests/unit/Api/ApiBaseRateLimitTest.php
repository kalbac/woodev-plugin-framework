<?php
/**
 * Unit tests for the HTTP 429 handling of `Woodev_API_Base::handle_response()` (card #954).
 *
 * A plain `Woodev_API_Exception` a validation threw for a 429 is re-typed as
 * `Woodev_API_Rate_Limit_Exception`, carrying the `Retry-After` wait: seconds, an HTTP date, or
 * nothing when the header is absent or unreadable. The wait is clamped to a day. A 429 is neither a
 * transport failure (the server did not act) nor a plain refusal; a subclass of the base exception
 * is left alone, like the transport re-typing does.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-transport-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-rate-limit-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-request-purpose.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

/**
 * Drives the real `handle_response()`; the carrier's own validation throws `$validation_class`.
 */
class Testable_Api_Base_For_Rate_Limit_Test extends \Woodev_API_Base {

	/** @var class-string<\Woodev_API_Exception> the exception class the carrier's validation throws */
	public $validation_class = \Woodev_API_Exception::class;

	/** @var bool whether the carrier's validation throws at all */
	public $validation_throws = true;

	/**
	 * @param mixed $response whatever the transport returned.
	 * @return mixed
	 */
	public function handle_response_for_test( $response ) {
		return $this->handle_response( $response );
	}

	protected function do_pre_parse_response_validation() {
		if ( $this->validation_throws ) {
			throw new $this->validation_class( 'Too Many Requests', 429 );
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

/** A third-party exception class: a subclass of the base. */
class Third_Party_Rate_Limit_Test_Exception extends \Woodev_API_Exception {}

/**
 * @covers \Woodev_API_Base::handle_response
 * @covers \Woodev_API_Rate_Limit_Exception
 */
final class ApiBaseRateLimitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 'Too Many Requests' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"slow down"}' );
	}

	/**
	 * @param int|string $status  the HTTP status.
	 * @param array      $headers the response headers.
	 * @param string     $class   the exception class the validation throws.
	 * @param bool       $throws  whether the validation throws.
	 * @return \Woodev_API_Exception|null what handle_response() threw.
	 */
	private function failure_for( $status, array $headers, string $class = \Woodev_API_Exception::class, bool $throws = true ): ?\Woodev_API_Exception {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( $headers );

		$api                    = new Testable_Api_Base_For_Rate_Limit_Test();
		$api->validation_class  = $class;
		$api->validation_throws = $throws;

		try {
			$api->handle_response_for_test( [ 'stubbed' => true ] );
		} catch ( \Woodev_API_Exception $exception ) {
			return $exception;
		}

		return null;
	}

	public function test_a_429_with_retry_after_in_seconds_is_a_rate_limit_exception_carrying_the_wait(): void {
		$exception = $this->failure_for( 429, [ 'retry-after' => '120' ] );

		$this->assertInstanceOf( \Woodev_API_Rate_Limit_Exception::class, $exception );
		$this->assertSame( 120, $exception->get_retry_after() );
		$this->assertSame( 'Too Many Requests', $exception->getMessage(), 'message and code are kept' );
		$this->assertSame( 429, $exception->getCode() );
		$this->assertInstanceOf( \Woodev_API_Exception::class, $exception, 'every existing catch still matches' );
	}

	public function test_a_429_with_retry_after_as_an_http_date_carries_the_seconds_until_then(): void {
		$date = gmdate( 'D, d M Y H:i:s', time() + 300 ) . ' GMT';

		$exception = $this->failure_for( 429, [ 'Retry-After' => $date ] );

		$this->assertInstanceOf( \Woodev_API_Rate_Limit_Exception::class, $exception );
		$this->assertGreaterThanOrEqual( 298, $exception->get_retry_after() );
		$this->assertLessThanOrEqual( 300, $exception->get_retry_after() );
	}

	public function test_a_429_without_retry_after_is_a_rate_limit_exception_with_no_wait(): void {
		$exception = $this->failure_for( 429, [] );

		$this->assertInstanceOf( \Woodev_API_Rate_Limit_Exception::class, $exception );
		$this->assertNull( $exception->get_retry_after(), 'the caller then uses its own schedule' );
	}

	public function test_an_unreadable_retry_after_is_no_wait(): void {
		$exception = $this->failure_for( 429, [ 'Retry-After' => 'soonish' ] );

		$this->assertInstanceOf( \Woodev_API_Rate_Limit_Exception::class, $exception );
		$this->assertNull( $exception->get_retry_after() );
	}

	public function test_a_429_is_neither_a_transport_failure_nor_re_typed_from_another_status(): void {
		$rate_limited = $this->failure_for( 429, [ 'Retry-After' => '5' ] );
		$refused      = $this->failure_for( 422, [ 'Retry-After' => '5' ] );
		$server_error = $this->failure_for( 503, [ 'Retry-After' => '5' ] );

		$this->assertNotInstanceOf( \Woodev_API_Transport_Exception::class, $rate_limited, 'the server did not act: not «unknown»' );
		$this->assertNotInstanceOf( \Woodev_API_Rate_Limit_Exception::class, $refused, 'a 422 stays a refusal' );
		$this->assertSame( \Woodev_API_Exception::class, get_class( $refused ) );
		$this->assertInstanceOf( \Woodev_API_Transport_Exception::class, $server_error, 'a 503 stays a transport failure whatever Retry-After says' );
		$this->assertNotInstanceOf( \Woodev_API_Rate_Limit_Exception::class, $server_error );
	}

	public function test_a_subclass_of_the_base_exception_is_left_alone(): void {
		$exception = $this->failure_for( 429, [ 'Retry-After' => '5' ], Third_Party_Rate_Limit_Test_Exception::class );

		$this->assertInstanceOf( Third_Party_Rate_Limit_Test_Exception::class, $exception );
		$this->assertNotInstanceOf( \Woodev_API_Rate_Limit_Exception::class, $exception );
	}

	public function test_the_base_adds_no_throw_for_a_429_no_validation_rejects(): void {
		$this->assertNull( $this->failure_for( 429, [ 'Retry-After' => '5' ], \Woodev_API_Exception::class, false ) );
	}

	/** @return array<string, array{0: string|null, 1: int|null}> */
	public function retry_after_provider(): array {
		return [
			'null'                       => [ null, null ],
			'empty'                      => [ '', null ],
			'blank'                      => [ '   ', null ],
			'zero'                       => [ '0', 0 ],
			'seconds'                    => [ '90', 90 ],
			'padded seconds'             => [ ' 90 ', 90 ],
			'a day exactly'              => [ '86400', 86400 ],
			'more than a day is clamped' => [ '90000', 86400 ],
			'an absurd number is clamped' => [ '99999999999999999999', 86400 ],
			'negative'                   => [ '-5', null ],
			'decimal'                    => [ '1.5', null ],
			'garbage'                    => [ 'later', null ],
		];
	}

	/**
	 * @dataProvider retry_after_provider
	 */
	public function test_parse_retry_after( ?string $value, ?int $expected ): void {
		$this->assertSame( $expected, \Woodev_API_Rate_Limit_Exception::parse_retry_after( $value, 1_000_000 ) );
	}

	public function test_parse_retry_after_reads_an_http_date_against_the_given_now(): void {
		$now = 1_700_000_000;

		$this->assertSame( 600, \Woodev_API_Rate_Limit_Exception::parse_retry_after( gmdate( 'D, d M Y H:i:s', $now + 600 ) . ' GMT', $now ) );
		$this->assertSame( 0, \Woodev_API_Rate_Limit_Exception::parse_retry_after( gmdate( 'D, d M Y H:i:s', $now - 600 ) . ' GMT', $now ), 'a date in the past is no wait' );
		$this->assertSame( 86400, \Woodev_API_Rate_Limit_Exception::parse_retry_after( gmdate( 'D, d M Y H:i:s', $now + 3 * 86400 ) . ' GMT', $now ), 'a far date is clamped' );
	}
}
