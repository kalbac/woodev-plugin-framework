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

	/** What our controller says to the probe's one-character query (it answers it itself, before any provider). */
	private const OURS = '{"code":"woodev_location_invalid_query","message":"Слишком короткий поисковый запрос.","data":{"status":400}}';

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
		Functions\when( 'add_query_arg' )->alias(
			static fn( $args, $url ) => $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->alias( static fn( $value ) => $value instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $response, $name ) => $response['headers'][ $name ] ?? '' );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:string}>
	 */
	public function answers(): array {
		return [
			'ours: the controller refuses the short query' => [ 400, self::OURS, Guest_Access_Check::STATUS_OK ],
			'ours: the controller rate-limited the probe'  => [ 429, '{"code":"woodev_location_rate_limited","data":{"status":429}}', Guest_Access_Check::STATUS_OK ],
			'redirect to the home page'                    => [ 301, '', Guest_Access_Check::STATUS_REDIRECT ],
			'temporary redirect'                           => [ 302, '', Guest_Access_Check::STATUS_REDIRECT ],
			'a page with 200'                              => [ 200, '<!doctype html><html><body>Home</body></html>', Guest_Access_Check::STATUS_HTML ],
			'a page with 500'                              => [ 500, '<html>Critical error</html>', Guest_Access_Check::STATUS_HTML ],
			'401 rest_not_logged_in'                       => [ 401, '{"code":"rest_not_logged_in","data":{"status":401}}', Guest_Access_Check::STATUS_DENIED ],
			'403 rest_forbidden'                           => [ 403, '{"code":"rest_forbidden","data":{"status":403}}', Guest_Access_Check::STATUS_DENIED ],
			'403 page of a host or firewall (not JSON)'    => [ 403, '<html><h1>403 Forbidden</h1></html>', Guest_Access_Check::STATUS_UNVERIFIABLE ],
			'401 with an empty body'                       => [ 401, '', Guest_Access_Check::STATUS_UNVERIFIABLE ],
			'foreign JSON: no route'                       => [ 404, '{"code":"rest_no_route","message":"No route was found","data":{"status":404}}', Guest_Access_Check::STATUS_FOREIGN ],
			'foreign JSON: 200 with other fields'          => [ 200, '{"namespaces":["wp/v2"]}', Guest_Access_Check::STATUS_FOREIGN ],
			'WordPress missing-param error is not ours'    => [ 400, '{"code":"rest_missing_callback_param","data":{"params":["q"]}}', Guest_Access_Check::STATUS_FOREIGN ],
			'our code on the wrong status'                 => [ 200, self::OURS, Guest_Access_Check::STATUS_FOREIGN ],
			'a JSON scalar'                                => [ 200, '"ok"', Guest_Access_Check::STATUS_HTML ],
		];
	}

	/**
	 * @dataProvider answers
	 */
	public function test_classify_sorts_a_loopback_answer( int $code, string $body, string $expected ): void {
		$this->assertSame( $expected, Guest_Access_Check::classify( $code, $body ) );
	}

	/** A web-server Basic-auth challenge is the host refusing its own server, not WordPress closing the API. */
	public function test_a_basic_auth_challenge_is_neutral_even_with_a_json_body(): void {
		$body = '{"code":"rest_not_logged_in","data":{"status":401}}';

		$this->assertSame( Guest_Access_Check::STATUS_DENIED, Guest_Access_Check::classify( 401, $body ) );
		$this->assertSame( Guest_Access_Check::STATUS_UNVERIFIABLE, Guest_Access_Check::classify( 401, $body, true ) );
		$this->assertSame( Guest_Access_Check::STATUS_UNVERIFIABLE, Guest_Access_Check::classify( 401, '', true ) );
	}

	public function test_only_closed_statuses_are_problems(): void {
		foreach ( [ 'redirect', 'html', 'denied', 'foreign' ] as $status ) {
			$this->assertTrue( Guest_Access_Check::is_problem( $status ), $status );
		}
		foreach ( [ 'ok', 'unreachable', 'unverifiable' ] as $status ) {
			$this->assertFalse( Guest_Access_Check::is_problem( $status ), $status );
		}
	}

	public function test_probe_sends_every_required_argument_as_a_guest_and_does_not_follow_redirects(): void {
		$sent = null;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) use ( &$sent ) {
				$sent = [ $url, $args ];

				return [ 'response' => [ 'code' => 400 ], 'body' => self::OURS ];
			}
		);

		$this->assertSame( Guest_Access_Check::STATUS_OK, Guest_Access_Check::probe() );

		parse_str( (string) parse_url( $sent[0], PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'https://shop.test/wp-json/woodev/v1/location/suggest?', $sent[0] );
		// the route's three required arguments, so WordPress reaches its permission_callback and our controller
		$this->assertSame( [ 'q', 'level', 'country' ], array_keys( $query ) );
		// one character: under the controller's minimum, so it answers itself and no provider is called
		$this->assertSame( 1, strlen( $query['q'] ) );
		$this->assertSame( 0, $sent[1]['redirection'] );
		$this->assertSame( [], $sent[1]['cookies'] );
	}

	public function test_probe_follows_the_core_https_local_ssl_verify_contract(): void {
		$seen = [];
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) use ( &$seen ) {
				if ( 'https_local_ssl_verify' === $tag ) {
					$seen[] = [ $value, $args ];

					// a URL-dependent callback that hands back a certificate path, as core allows
					return '/etc/ssl/local-ca.pem';
				}

				return $value;
			}
		);
		$sent = null;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) use ( &$sent ) {
				$sent = [ $url, $args ];

				return [ 'response' => [ 'code' => 400 ], 'body' => self::OURS ];
			}
		);

		Guest_Access_Check::probe();

		$this->assertCount( 1, $seen );
		$this->assertFalse( $seen[0][0], 'The default is false, as in core.' );
		$this->assertSame( [ $sent[0] ], $seen[0][1], 'The request URL is the second argument.' );
		$this->assertSame( '/etc/ssl/local-ca.pem', $sent[1]['sslverify'], 'The result is passed on as it is, not cast to bool.' );
	}

	public function test_probe_reads_the_www_authenticate_header(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			[
				'response' => [ 'code' => 401 ],
				'body'     => '',
				'headers'  => [ 'www-authenticate' => 'Basic realm="staging"' ],
			]
		);

		$this->assertSame( Guest_Access_Check::STATUS_UNVERIFIABLE, Guest_Access_Check::probe() );
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

			$this->assertStringContainsString( 'https://shop.test/wp-json/woodev/v1/', $message, $status );
			$this->assertStringContainsString( 'REST API закрыт для покупателей', $message, $status );
			$this->assertStringNotContainsStringIgnoringCase( 'чекаут', $message, $status );
			$this->assertStringNotContainsStringIgnoringCase( 'фреймворк', $message, $status );
		}
	}

	/** The address is the one the site really serves: `?rest_route=` on plain permalinks, the subsite path on a multisite. */
	public function test_the_warning_names_the_address_the_site_really_uses(): void {
		Functions\when( 'rest_url' )->alias( static fn( $path = '' ) => 'https://shop.test/?rest_route=/' . $path );
		$this->assertStringContainsString( 'https://shop.test/?rest_route=/woodev/v1/', Guest_Access_Check::get_message( 'redirect' ) );
		$this->assertStringNotContainsString( '/wp-json/', Guest_Access_Check::get_message( 'redirect' ) );

		Functions\when( 'rest_url' )->alias( static fn( $path = '' ) => 'https://example.test/shop-two/wp-json/' . $path );
		$this->assertStringContainsString( 'https://example.test/shop-two/wp-json/woodev/v1/', Guest_Access_Check::get_message( 'denied' ) );
	}

	public function test_an_unverifiable_answer_is_a_neutral_site_health_result_with_no_blame(): void {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		$this->transients[ Guest_Access_Check::TRANSIENT ] = Guest_Access_Check::STATUS_UNVERIFIABLE;

		$result = Guest_Access_Check::run_site_health_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringNotContainsString( 'закрыт для покупателей', $result['description'] );
		$this->assertStringNotContainsString( 'безопасности', $result['description'] );
	}
}
