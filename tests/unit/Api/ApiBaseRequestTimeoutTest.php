<?php
/**
 * Unit tests for the HTTP timeout by call purpose (card #954).
 *
 * `Woodev_API_Base::get_request_args()` takes its timeout from an overridable
 * `get_request_timeout( $purpose )`, passed through `woodev_{api_id}_request_timeout`. The purpose
 * is the ambient scope the framework sets with `Woodev_API_Request_Purpose::run()` — rates 8 s,
 * reference 20 s, export 30 s, anything else (every non-shipping API) the historical 60 s.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-transport-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-request-purpose.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

/**
 * The real `get_request_args()`, with everything that needs a plugin stubbed out.
 */
class Testable_Api_Base_For_Timeout_Test extends \Woodev_API_Base {

	/** @var int|null what an API that opts in returns from get_request_timeout() for every purpose; null = the base default */
	public $timeout_override;

	/** @var string[] every purpose get_request_timeout() was asked about */
	public $asked = [];

	/**
	 * @return array the request args the API would hand to wp_remote_request().
	 */
	public function args(): array {
		return $this->get_request_args();
	}

	protected function get_request_timeout( string $purpose ): int {
		$this->asked[] = $purpose;

		return null !== $this->timeout_override ? $this->timeout_override : parent::get_request_timeout( $purpose );
	}

	protected function get_api_id() {
		return 'timeout_test';
	}

	protected function get_request_user_agent() {
		return 'timeout-test/1.0';
	}

	protected function get_new_request( $args = [] ) {
		return null;
	}

	protected function get_plugin() {
		return null;
	}
}

/**
 * @covers \Woodev_API_Base::get_request_args
 * @covers \Woodev_API_Base::get_request_timeout
 * @covers \Woodev_API_Base::get_request_purpose
 * @covers \Woodev_API_Request_Purpose
 */
final class ApiBaseRequestTimeoutTest extends TestCase {

	/** @var array<int, array{0: string, 1: array}> every (tag, args) the timeout filter saw */
	private array $filter_calls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->filter_calls = [];

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
	}

	private function timeout_now( ?\Woodev_API_Base $api = null ): int {
		$api = $api ?? new Testable_Api_Base_For_Timeout_Test();

		return $api->args()['timeout'];
	}

	/** @return array<string, array{0: string, 1: int}> */
	public function purpose_provider(): array {
		return [
			'rates'     => [ \Woodev_API_Request_Purpose::RATES, 8 ],
			'reference' => [ \Woodev_API_Request_Purpose::REFERENCE, 20 ],
			'export'    => [ \Woodev_API_Request_Purpose::EXPORT, 30 ],
			'default'   => [ \Woodev_API_Request_Purpose::DEFAULT_PURPOSE, 60 ],
			'unknown'   => [ 'something-else', 60 ],
		];
	}

	/**
	 * @dataProvider purpose_provider
	 */
	public function test_the_framework_default_timeout_follows_the_purpose( string $purpose, int $seconds ): void {
		$timeout = \Woodev_API_Request_Purpose::run( $purpose, fn() => $this->timeout_now() );

		$this->assertSame( $seconds, $timeout );
	}

	public function test_an_api_nobody_marked_keeps_the_historical_minute(): void {
		$this->assertSame( 60, $this->timeout_now(), 'payment gateways, licensing and location providers keep 60 s' );
	}

	public function test_an_api_overrides_the_timeout_of_a_purpose(): void {
		$api                   = new Testable_Api_Base_For_Timeout_Test();
		$api->timeout_override = 12;

		$timeout = \Woodev_API_Request_Purpose::run( \Woodev_API_Request_Purpose::RATES, fn() => $this->timeout_now( $api ) );

		$this->assertSame( 12, $timeout );
		$this->assertSame( [ \Woodev_API_Request_Purpose::RATES ], $api->asked, 'the override is told the purpose' );
	}

	public function test_the_filter_has_the_last_word_and_is_named_after_the_api_id(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null, ...$rest ) {
				$this->filter_calls[] = [ $tag, array_merge( [ $value ], $rest ) ];

				return 'woodev_timeout_test_request_timeout' === $tag ? 45 : $value;
			}
		);

		$timeout = \Woodev_API_Request_Purpose::run( \Woodev_API_Request_Purpose::EXPORT, fn() => $this->timeout_now() );

		$this->assertSame( 45, $timeout );

		$timeout_calls = array_values(
			array_filter(
				$this->filter_calls,
				static fn( array $call ) => 'woodev_timeout_test_request_timeout' === $call[0]
			)
		);

		$this->assertCount( 1, $timeout_calls );
		$this->assertSame( 30, $timeout_calls[0][1][0], 'the filter starts from the method value' );
		$this->assertSame( \Woodev_API_Request_Purpose::EXPORT, $timeout_calls[0][1][1], 'and is told the purpose' );
		$this->assertInstanceOf( \Woodev_API_Base::class, $timeout_calls[0][1][2] );
	}

	/** @return array<string, array{0: mixed}> */
	public function unusable_filter_value_provider(): array {
		return [
			'zero'       => [ 0 ],
			'negative'   => [ -5 ],
			'a string'   => [ 'soon' ],
			'null'       => [ null ],
			'an array'   => [ [ 10 ] ],
		];
	}

	/**
	 * @dataProvider unusable_filter_value_provider
	 * @param mixed $returned what a misbehaving filter returns.
	 */
	public function test_a_filter_that_returns_no_positive_number_falls_back_to_the_method_value( $returned ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $returned ) {
				return 'woodev_timeout_test_request_timeout' === $tag ? $returned : $value;
			}
		);

		$timeout = \Woodev_API_Request_Purpose::run( \Woodev_API_Request_Purpose::RATES, fn() => $this->timeout_now() );

		$this->assertSame( 8, $timeout );
	}

	public function test_the_purpose_is_restored_after_the_call_even_when_it_throws(): void {
		try {
			\Woodev_API_Request_Purpose::run(
				\Woodev_API_Request_Purpose::RATES,
				static function () {
					throw new \Woodev_API_Transport_Exception( 'cURL error 28: timed out' );
				}
			);
			$this->fail( 'the exception must propagate' );
		} catch ( \Woodev_API_Transport_Exception $exception ) {
			$this->assertSame( 'cURL error 28: timed out', $exception->getMessage() );
		}

		$this->assertSame( \Woodev_API_Request_Purpose::DEFAULT_PURPOSE, \Woodev_API_Request_Purpose::current() );
		$this->assertSame( 60, $this->timeout_now(), 'the next, unrelated call is back to the minute' );
	}

	public function test_scopes_nest_and_each_one_restores_its_parent(): void {
		$seen = [];

		\Woodev_API_Request_Purpose::run(
			\Woodev_API_Request_Purpose::EXPORT,
			function () use ( &$seen ) {
				$seen[] = \Woodev_API_Request_Purpose::current();

				\Woodev_API_Request_Purpose::run(
					\Woodev_API_Request_Purpose::REFERENCE,
					function () use ( &$seen ) {
						$seen[] = \Woodev_API_Request_Purpose::current();
					}
				);

				$seen[] = \Woodev_API_Request_Purpose::current();
			}
		);

		$this->assertSame(
			[ \Woodev_API_Request_Purpose::EXPORT, \Woodev_API_Request_Purpose::REFERENCE, \Woodev_API_Request_Purpose::EXPORT ],
			$seen
		);
		$this->assertSame( \Woodev_API_Request_Purpose::DEFAULT_PURPOSE, \Woodev_API_Request_Purpose::current() );
	}

	public function test_run_returns_what_the_callback_returns(): void {
		$this->assertSame( 'answer', \Woodev_API_Request_Purpose::run( \Woodev_API_Request_Purpose::EXPORT, static fn() => 'answer' ) );
	}
}
