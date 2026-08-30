<?php
/**
 * Unit tests for Woodev_API_Base's opt-in bot-protection challenge transport.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

class Testable_Api_Base_For_Challenge_Redirect_Test extends \Woodev_API_Base {

	/** @var array<int, array<string, mixed>|\WP_Error> */
	public array $responses = [];

	/** @var array<int, array{uri: string, args: array<string, mixed>}> */
	public array $calls = [];

	/** @param string $uri Request URI. @param array<string, mixed> $args Request arguments. @return array|\WP_Error */
	public function request_for_test( string $uri, array $args ) {
		return $this->do_remote_request_with_challenge_redirects( $uri, $args );
	}

	/** @param string $uri Request URI. @param array<string, mixed> $args Request arguments. @return array|\WP_Error */
	protected function do_remote_request( $uri, $args ) {
		$this->calls[] = [ 'uri' => $uri, 'args' => $args ];

		return array_shift( $this->responses );
	}

	/** @return string */
	protected function get_api_id() {
		return 'challenge-test';
	}

	/** @return null */
	protected function get_new_request( $args = [] ) {
		return null;
	}

	/** @return null */
	protected function get_plugin() {
		return null;
	}
}

class Testable_Api_Base_With_Challenge_Redirects extends Testable_Api_Base_For_Challenge_Redirect_Test {

	/** @return bool */
	protected function follow_challenge_redirects(): bool {
		return true;
	}
}

final class ApiBaseChallengeRedirectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_wp_error' )->alias( static function ( $response ): bool {
			return $response instanceof \WP_Error;
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static function ( array $response ): int {
			return $response['code'];
		} );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static function ( array $response ): array {
			return $response['headers'];
		} );
		Functions\when( 'apply_filters' )->alias( static function ( $tag, $value = null ) {
			return $value;
		} );
		Functions\when( 'do_action' )->justReturn( null );
	}

	/** @param int $code HTTP status code. @param array<string, mixed> $headers Response headers. @return array<string, mixed> */
	private function response( int $code, array $headers = [] ): array {
		return [ 'code' => $code, 'headers' => $headers ];
	}

	/** @return array<string, mixed> */
	private function request_args(): array {
		return [
			'method'  => 'POST',
			'body'    => '{"parcel":"123"}',
			'headers' => [ 'Authorization' => 'Bearer private-token' ],
		];
	}

	/** @return void */
	public function test_challenge_redirects_are_off_by_default(): void {
		$api            = new Testable_Api_Base_For_Challenge_Redirect_Test();
		$api->responses = [ $this->response( 307, [ 'Location' => '/challenge' ] ) ];
		$args           = $this->request_args();

		$response = $api->request_for_test( 'https://api.example.test/v1/orders', $args );

		$this->assertSame( 307, $response['code'] );
		$this->assertCount( 1, $api->calls );
		$this->assertSame( $args, $api->calls[0]['args'] );
	}

	/** @return void */
	public function test_307_repeats_the_same_method_and_body_with_the_challenge_cookie(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '/challenge', 'Set-Cookie' => 'testcookie=first; Path=/; HttpOnly' ] ), $this->response( 200 ) ];
		$args           = $this->request_args();

		$api->request_for_test( 'https://api.example.test/v1/orders', $args );

		$this->assertCount( 2, $api->calls );
		$this->assertSame( 'https://api.example.test/challenge', $api->calls[1]['uri'] );
		$this->assertSame( $args['method'], $api->calls[1]['args']['method'] );
		$this->assertSame( $args['body'], $api->calls[1]['args']['body'] );
		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_302_repeats_the_same_method_and_body(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 302, [ 'Location' => '/challenge', 'Set-Cookie' => 'testcookie=first; Path=/' ] ), $this->response( 200 ) ];
		$args           = $this->request_args();

		$api->request_for_test( 'https://api.example.test/v1/orders', $args );

		$this->assertCount( 2, $api->calls );
		$this->assertSame( $args['method'], $api->calls[1]['args']['method'] );
		$this->assertSame( $args['body'], $api->calls[1]['args']['body'] );
	}

	/** @return void */
	public function test_cookie_jar_is_reused_and_refreshed_after_a_non_redirect_response(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/' ] ), $this->response( 200, [ 'Set-Cookie' => 'testcookie=second; Path=/' ] ), $this->response( 200 ) ];
		$args           = $this->request_args();

		$api->request_for_test( 'https://api.example.test/v1/orders', $args );
		$api->request_for_test( 'https://api.example.test/v1/orders', $args );
		$api->request_for_test( 'https://api.example.test/v1/orders', $args );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
		$this->assertSame( 'testcookie=second', $api->calls[2]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_cross_host_redirect_is_refused_before_credentials_are_forwarded(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => 'https://attacker.example/challenge' ] ) ];

		$response = $api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'woodev_api_challenge_redirect_cross_origin', $response->get_error_code() );
		$this->assertCount( 1, $api->calls );
	}

	/** @return void */
	public function test_challenge_redirect_hop_limit_is_one(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '/first' ] ), $this->response( 307, [ 'Location' => '/second' ] ), $this->response( 200 ) ];

		$response = $api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 307, $response['code'] );
		$this->assertCount( 2, $api->calls );
		$this->assertSame( 'https://api.example.test/first', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_a_cookie_set_by_one_host_is_not_sent_to_a_different_host(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=host-a; Path=/' ] ),
			$this->response( 200 ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://host-a.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://host-b.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://host-a.example.test/v1/orders', $this->request_args() );

		$this->assertArrayNotHasKey( 'Cookie', $api->calls[1]['args']['headers'] );
		$this->assertSame( 'testcookie=host-a', $api->calls[2]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_the_challenge_redirect_cookie_filter_never_fires_when_challenge_redirects_are_disabled(): void {
		$api            = new Testable_Api_Base_For_Challenge_Redirect_Test();
		$api->responses = [ $this->response( 200 ) ];

		$applied_filters = [];
		Functions\when( 'apply_filters' )->alias( static function ( $tag, $value = null ) use ( &$applied_filters ) {
			$applied_filters[] = $tag;

			return $value;
		} );

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertNotContains( 'woodev_challenge-test_api_challenge_redirect_cookies', $applied_filters );
	}

	/** @return void */
	public function test_the_updated_action_does_not_fire_when_the_response_repeats_a_cookie_already_in_the_jar(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=same; Path=/' ] ),
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=same; Path=/' ] ),
		];

		$updated_action_fired_count = 0;
		Functions\when( 'do_action' )->alias( static function ( $tag ) use ( &$updated_action_fired_count ) {
			if ( 'woodev_challenge-test_api_challenge_redirect_cookies_updated' === $tag ) {
				$updated_action_fired_count++;
			}
		} );

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 1, $updated_action_fired_count );
	}

	/** @return void */
	public function test_origin_normalisation_treats_default_port_and_host_case_as_the_same_origin(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/' ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://h/x', $this->request_args() );
		$api->request_for_test( 'https://H:443/y', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_origin_normalisation_treats_a_different_scheme_as_a_different_origin(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/' ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://h', $this->request_args() );
		$api->request_for_test( 'http://h', $this->request_args() );

		$this->assertArrayNotHasKey( 'Cookie', $api->calls[1]['args']['headers'] );
	}
}
