<?php
/**
 * Unit: the guest-access check of the public `woodev/v1` routes (#1631).
 *
 * The classifier sorts a loopback answer; the probe, the kept answer and the warning text are covered around it.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Rest_Api\Guest_Access_Check;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-guest-access-check.php';

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Guest_Access_Check
 */
final class GuestAccessCheckTest extends TestCase {

	/** What our route says to a request that carries no arguments. */
	private const OURS = '{"code":"rest_missing_callback_param","message":"Missing parameter(s): q, level","data":{"status":400,"params":["q","level"]}}';

	/** @var array<string,mixed> */
	private $transients = [];

	protected function setUp(): void {
		parent::setUp();

		$this->transients = [];

		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );

				return true;
			}
		);
		Functions\when( 'rest_url' )->alias( static fn( $path = '' ) => 'https://shop.test/wp-json/' . $path );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->alias( static fn( $value ) => $value instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:string}>
	 */
	public function answers(): array {
		return [
			'ours: 400 naming q'                  => [ 400, self::OURS, Guest_Access_Check::STATUS_OK ],
			'redirect to the home page'           => [ 301, '', Guest_Access_Check::STATUS_REDIRECT ],
			'temporary redirect'                  => [ 302, '', Guest_Access_Check::STATUS_REDIRECT ],
			'a page with 200'                     => [ 200, '<!doctype html><html><body>Home</body></html>', Guest_Access_Check::STATUS_HTML ],
			'a page with 500'                     => [ 500, '<html>Critical error</html>', Guest_Access_Check::STATUS_HTML ],
			'401 rest_not_logged_in'              => [ 401, '{"code":"rest_not_logged_in","data":{"status":401}}', Guest_Access_Check::STATUS_DENIED ],
			'403 forbidden'                       => [ 403, '{"code":"rest_forbidden","data":{"status":403}}', Guest_Access_Check::STATUS_DENIED ],
			'foreign JSON: no route'              => [ 404, '{"code":"rest_no_route","message":"No route was found","data":{"status":404}}', Guest_Access_Check::STATUS_FOREIGN ],
			'foreign JSON: 200 with other fields' => [ 200, '{"namespaces":["wp/v2"]}', Guest_Access_Check::STATUS_FOREIGN ],
			'400 that does not name q'            => [ 400, '{"code":"rest_missing_callback_param","data":{"params":["x"]}}', Guest_Access_Check::STATUS_FOREIGN ],
			'a JSON scalar'                       => [ 200, '"ok"', Guest_Access_Check::STATUS_HTML ],
		];
	}

	/**
	 * @dataProvider answers
	 */
	public function test_classify_sorts_a_loopback_answer( int $code, string $body, string $expected ): void {
		$this->assertSame( $expected, Guest_Access_Check::classify( $code, $body ) );
	}

	public function test_only_closed_statuses_are_problems(): void {
		foreach ( [ 'redirect', 'html', 'denied', 'foreign' ] as $status ) {
			$this->assertTrue( Guest_Access_Check::is_problem( $status ), $status );
		}
		foreach ( [ 'ok', 'unreachable' ] as $status ) {
			$this->assertFalse( Guest_Access_Check::is_problem( $status ), $status );
		}
	}

	public function test_probe_asks_as_a_guest_and_does_not_follow_redirects(): void {
		$sent = null;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) use ( &$sent ) {
				$sent = [ $url, $args ];

				return [ 'response' => [ 'code' => 400 ], 'body' => self::OURS ];
			}
		);

		$this->assertSame( Guest_Access_Check::STATUS_OK, Guest_Access_Check::probe() );
		$this->assertSame( 'https://shop.test/wp-json/woodev/v1/location/suggest', $sent[0] );
		$this->assertSame( 0, $sent[1]['redirection'] );
		$this->assertSame( [], $sent[1]['cookies'] );
	}

	public function test_a_failed_request_is_not_a_closed_api(): void {
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 7' ) );

		$status = Guest_Access_Check::probe();

		$this->assertSame( Guest_Access_Check::STATUS_UNREACHABLE, $status );
		$this->assertFalse( Guest_Access_Check::is_problem( $status ) );
	}

	public function test_the_answer_is_kept_and_asked_only_once(): void {
		$calls = 0;
		Functions\when( 'wp_remote_get' )->alias(
			function () use ( &$calls ) {
				++$calls;

				return [ 'response' => [ 'code' => 301 ], 'body' => '' ];
			}
		);

		$this->assertSame( 'redirect', Guest_Access_Check::get_status() );
		$this->assertSame( 'redirect', Guest_Access_Check::get_status() );
		$this->assertSame( 1, $calls );

		Guest_Access_Check::forget();
		Guest_Access_Check::get_status();
		$this->assertSame( 2, $calls );
	}

	public function test_the_cached_status_never_asks(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertNull( Guest_Access_Check::get_cached_status() );
	}

	public function test_the_warning_names_the_address_and_keeps_to_plain_words(): void {
		foreach ( [ 'redirect', 'html', 'denied', 'foreign' ] as $status ) {
			$message = Guest_Access_Check::get_message( $status );

			$this->assertStringContainsString( '/wp-json/woodev/v1/', $message, $status );
			$this->assertStringContainsString( 'REST API закрыт для покупателей', $message, $status );
			$this->assertStringNotContainsStringIgnoringCase( 'чекаут', $message, $status );
			$this->assertStringNotContainsStringIgnoringCase( 'фреймворк', $message, $status );
		}
	}
}
