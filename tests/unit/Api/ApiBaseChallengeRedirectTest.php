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

	/** @var int|null Overrides the real clock so tests can simulate elapsed time without sleeping. */
	public $now = null;

	/** @return bool */
	protected function follow_challenge_redirects(): bool {
		return true;
	}

	/** @return int */
	protected function get_challenge_redirect_current_time(): int {
		return $this->now ?? parent::get_challenge_redirect_current_time();
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

	/** @return void */
	public function test_the_callers_own_cookie_header_takes_precedence_over_the_remembered_jar(): void {
		$api                        = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses             = [
			$this->response( 200, [ 'Set-Cookie' => 'session=jar-value; Path=/' ] ),
			$this->response( 200 ),
		];
		$args                       = $this->request_args();
		$args['headers']['Cookie'] = 'session=caller-value';

		$api->request_for_test( 'https://api.example.test/v1/orders', $args );
		$api->request_for_test( 'https://api.example.test/v1/orders', $args );

		$this->assertSame( 'session=caller-value', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_the_updated_action_does_not_fire_when_the_response_has_no_set_cookie_header(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 200 ) ];

		$updated_action_fired = false;
		Functions\when( 'do_action' )->alias( static function ( $tag ) use ( &$updated_action_fired ) {
			if ( 'woodev_challenge-test_api_challenge_redirect_cookies_updated' === $tag ) {
				$updated_action_fired = true;
			}
		} );

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertFalse( $updated_action_fired );
	}

	/** @return void */
	public function test_max_age_zero_removes_the_cookie_from_the_jar(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/' ] ),
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=; Path=/; Max-Age=0' ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertArrayNotHasKey( 'Cookie', $api->calls[2]['args']['headers'] );
	}

	/** @return void */
	public function test_max_age_zero_removal_fires_the_updated_action_without_the_removed_name(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/' ] ),
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=; Path=/; Max-Age=0' ] ),
		];

		$updated_action_calls = [];
		Functions\when( 'do_action' )->alias( static function ( $tag, $cookies = null ) use ( &$updated_action_calls ) {
			if ( 'woodev_challenge-test_api_challenge_redirect_cookies_updated' === $tag ) {
				$updated_action_calls[] = $cookies;
			}
		} );

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertCount( 2, $updated_action_calls );
		$this->assertArrayNotHasKey( 'testcookie', $updated_action_calls[1] );
	}

	/** @return void */
	public function test_max_age_zero_for_an_unknown_cookie_name_changes_nothing_and_fires_nothing(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'ghost=; Max-Age=0' ] ),
			$this->response( 200 ),
		];

		$updated_action_fired = false;
		Functions\when( 'do_action' )->alias( static function ( $tag ) use ( &$updated_action_fired ) {
			if ( 'woodev_challenge-test_api_challenge_redirect_cookies_updated' === $tag ) {
				$updated_action_fired = true;
			}
		} );

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertFalse( $updated_action_fired );
		$this->assertArrayNotHasKey( 'Cookie', $api->calls[1]['args']['headers'] );
	}

	/** @return void */
	public function test_an_expires_attribute_in_the_past_deletes_the_cookie(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/' ] ),
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=stale; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT' ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertArrayNotHasKey( 'Cookie', $api->calls[2]['args']['headers'] );
	}

	/**
	 * Carries a short-lived `neighbor` cookie in the SAME response so this test
	 * discriminates: the old jar (pre-#686) kept everything forever, so both
	 * cookies would still be in the outgoing header and this assertion would
	 * fail on old code, not just pass trivially. Round-2 critic finding: this
	 * test previously passed unchanged under the old code too.
	 *
	 * @return void
	 */
	public function test_an_expires_attribute_in_the_future_does_not_delete_the_cookie(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->now       = 1_700_000_000;
		$far_future     = gmdate( 'D, d M Y H:i:s', $api->now + 3600 ) . ' GMT';
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => [ "testcookie=first; Expires={$far_future}", 'neighbor=temp; Max-Age=1' ] ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$api->now += 5;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/**
	 * Same discrimination as above: a short-lived `neighbor` cookie in the same
	 * response proves this test fails on the old always-keep-forever jar.
	 *
	 * @return void
	 */
	public function test_a_cookie_with_neither_max_age_nor_expires_is_a_session_cookie_that_never_expires_on_its_own(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => [ 'testcookie=first; Path=/', 'neighbor=temp; Max-Age=1' ] ] ),
			$this->response( 200 ),
		];

		$api->now = 1_700_000_000;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$api->now += 60 * 60 * 24 * 365;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_an_expired_cookie_is_not_sent_even_when_no_new_response_evicts_it(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Max-Age=1' ] ),
			$this->response( 200 ),
		];

		$api->now = 1_700_000_000;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$api->now += 5;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertArrayNotHasKey( 'Cookie', $api->calls[1]['args']['headers'] );
	}

	/** @return void */
	public function test_a_relative_location_carrying_a_scheme_in_its_query_is_followed_as_same_origin(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 307, [ 'Location' => '/challenge?return=https://provider.example/' ] ),
			$this->response( 200 ),
		];

		$response = $api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 200, $response['code'] );
		$this->assertCount( 2, $api->calls );
		$this->assertSame( 'https://api.example.test/challenge?return=https://provider.example/', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_a_query_only_location_resolves_against_the_current_path_not_the_parent_directory(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '?challenge=1' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h/v1/orders', $this->request_args() );

		$this->assertSame( 'https://h/v1/orders?challenge=1', $api->calls[1]['uri'] );
	}

	/**
	 * Control, not a regression check: the old resolver already handled a
	 * network-path reference correctly. Kept to pin the behaviour and prove
	 * the same-origin guard still refuses the other host after the rewrite —
	 * do not "fix" this test for failing to RED against the old code.
	 *
	 * @return void
	 */
	public function test_a_network_path_reference_resolves_to_the_other_host_and_is_then_refused_as_cross_origin(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '//other.example/x' ] ) ];

		$response = $api->request_for_test( 'https://h/a', $this->request_args() );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'woodev_api_challenge_redirect_cross_origin', $response->get_error_code() );
		$this->assertCount( 1, $api->calls );
	}

	/** @return void */
	public function test_a_relative_path_with_dot_segments_is_merged_and_normalised_against_the_base_path(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '../up' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h/a/b/c', $this->request_args() );

		$this->assertSame( 'https://h/a/up', $api->calls[1]['uri'] );
	}

	/**
	 * Control, not a regression check: the old `://`-substring heuristic
	 * already treated a leading-scheme Location as absolute. Kept to pin that
	 * this stays true under the RFC 3986 scheme-prefix detection — do not
	 * "fix" this test for failing to RED against the old code.
	 *
	 * @return void
	 */
	public function test_a_location_with_a_scheme_at_the_start_is_still_treated_as_absolute(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => 'https://h/abs' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h/v1/orders', $this->request_args() );

		$this->assertSame( 'https://h/abs', $api->calls[1]['uri'] );
	}

	/**
	 * Control, not a regression check: the old resolver already refused a
	 * genuinely cross-origin absolute Location. Kept to pin that the guard
	 * still bites after the rewrite — do not "fix" this test for failing to
	 * RED against the old code.
	 *
	 * @return void
	 */
	public function test_a_genuinely_cross_origin_absolute_location_is_still_refused(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => 'https://attacker.example/challenge?return=https://h/' ] ) ];

		$response = $api->request_for_test( 'https://h/v1/orders', $this->request_args() );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'woodev_api_challenge_redirect_cross_origin', $response->get_error_code() );
		$this->assertCount( 1, $api->calls );
	}

	/** @return void */
	public function test_max_age_with_a_fractional_value_is_ignored_and_a_valid_expires_still_governs(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->now       = 1_700_000_000;
		$future         = gmdate( 'D, d M Y H:i:s', $api->now + 3600 ) . ' GMT';
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => "testcookie=first; Max-Age=0.5; Expires={$future}" ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/**
	 * Advances the clock past what `+1` would mean if it were wrongly accepted
	 * as a literal 1-second Max-Age, so this discriminates: an incorrect
	 * accept-and-cast would evict the cookie by the second request.
	 *
	 * @return void
	 */
	public function test_max_age_with_a_leading_plus_sign_is_ignored(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/; Max-Age=+1' ] ),
			$this->response( 200 ),
		];

		$api->now = 1_700_000_000;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$api->now += 5;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/**
	 * Advances the clock past what `1e3` would mean if it were wrongly accepted
	 * as a literal 1000-second Max-Age, so this discriminates the same way.
	 *
	 * @return void
	 */
	public function test_max_age_in_exponential_notation_is_ignored(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/; Max-Age=1e3' ] ),
			$this->response( 200 ),
		];

		$api->now = 1_700_000_000;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$api->now += 1001;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_a_valid_max_age_still_wins_over_a_valid_expires(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->now       = 1_700_000_000;
		$future         = gmdate( 'D, d M Y H:i:s', $api->now + 3600 ) . ' GMT';
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => "testcookie=first; Max-Age=1; Expires={$future}" ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$api->now += 5;
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertArrayNotHasKey( 'Cookie', $api->calls[1]['args']['headers'] );
	}

	/** @return void */
	public function test_a_path_scoped_cookie_is_sent_only_under_its_path(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'clearance=token; Path=/challenge' ] ),
			$this->response( 200 ),
			$this->response( 200 ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://h/api/orders', $this->request_args() );
		$api->request_for_test( 'https://h/challenge', $this->request_args() );
		$api->request_for_test( 'https://h/challenge/x', $this->request_args() );
		$api->request_for_test( 'https://h/api/orders', $this->request_args() );

		$this->assertSame( 'clearance=token', $api->calls[1]['args']['headers']['Cookie'] );
		$this->assertSame( 'clearance=token', $api->calls[2]['args']['headers']['Cookie'] );
		$this->assertArrayNotHasKey( 'Cookie', $api->calls[3]['args']['headers'] );
	}

	/** @return void */
	public function test_a_cookie_with_no_path_attribute_gets_the_rfc_6265_default_path(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first' ] ),
			$this->response( 200 ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://h/api/orders', $this->request_args() );
		$api->request_for_test( 'https://h/api/other', $this->request_args() );
		$api->request_for_test( 'https://h/other', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
		$this->assertArrayNotHasKey( 'Cookie', $api->calls[2]['args']['headers'] );
	}

	/** @return void */
	public function test_the_same_cookie_name_under_two_different_paths_coexists(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'shared=one; Path=/a' ] ),
			$this->response( 200, [ 'Set-Cookie' => 'shared=two; Path=/b' ] ),
			$this->response( 200 ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://h/a', $this->request_args() );
		$api->request_for_test( 'https://h/b', $this->request_args() );
		$api->request_for_test( 'https://h/a', $this->request_args() );
		$api->request_for_test( 'https://h/b', $this->request_args() );

		$this->assertSame( 'shared=one', $api->calls[2]['args']['headers']['Cookie'] );
		$this->assertSame( 'shared=two', $api->calls[3]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_a_query_only_location_keeps_the_base_query_string_semantics_per_rfc_3986_section_5_3(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '?new=1' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h?old=1', $this->request_args() );

		$this->assertSame( 'https://h?new=1', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_a_fragment_only_location_keeps_the_base_query_when_the_base_path_is_empty(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '#f' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h?old=1', $this->request_args() );

		$this->assertSame( 'https://h?old=1#f', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_a_302_with_an_empty_location_header_follows_nothing(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 302, [ 'Location' => '' ] ) ];

		$response = $api->request_for_test( 'https://h?old=1', $this->request_args() );

		$this->assertSame( 302, $response['code'] );
		$this->assertCount( 1, $api->calls );
	}

	/**
	 * Dot-segment pins (RFC 3986 §5.2.4). Traced by hand against the spec for
	 * the round-2 critic's five listed cases: the implementation is already
	 * correct on every one. These are regression pins, not suspected defects.
	 *
	 * @return void
	 */
	public function test_dot_segments_a_trailing_single_dot_resolves_with_a_trailing_slash(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => 'b/.' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h/x/y', $this->request_args() );

		$this->assertSame( 'https://h/x/b/', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_dot_segments_a_trailing_double_dot_removes_the_prior_segment(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => 'b/..' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h/x/y', $this->request_args() );

		$this->assertSame( 'https://h/x/', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_dot_segments_attempting_to_escape_root_clamp_at_root(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '../../up' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h/a', $this->request_args() );

		$this->assertSame( 'https://h/up', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_dot_segments_a_reference_that_is_exactly_double_dot_removes_the_prior_segment(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => '..' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h/a/b/c', $this->request_args() );

		$this->assertSame( 'https://h/a/', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_dot_segments_a_double_slash_inside_the_path_is_preserved(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [ $this->response( 307, [ 'Location' => 'a//b' ] ), $this->response( 200 ) ];

		$api->request_for_test( 'https://h/x/y', $this->request_args() );

		$this->assertSame( 'https://h/x/a//b', $api->calls[1]['uri'] );
	}

	/** @return void */
	public function test_overlapping_same_name_cookies_are_all_sent_longest_path_first(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'shared=root; Path=/' ] ),
			$this->response( 200, [ 'Set-Cookie' => 'shared=specific; Path=/a' ] ),
			$this->response( 200 ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://h/x', $this->request_args() );
		$api->request_for_test( 'https://h/a', $this->request_args() );
		$api->request_for_test( 'https://h/a/x', $this->request_args() );
		$api->request_for_test( 'https://h/b', $this->request_args() );

		$this->assertSame( 'shared=specific; shared=root', $api->calls[2]['args']['headers']['Cookie'] );
		$this->assertSame( 'shared=root', $api->calls[3]['args']['headers']['Cookie'] );
	}

	/**
	 * Exercises the full persistence contract: exports the structured jar
	 * through the `..._updated` action, restores it into a FRESH instance
	 * through the `..._cookies` filter, and proves the restore is not a naive
	 * pass-through — it is path-scoped per request (`/a/x` gets both the `/a`
	 * and `/` cookie, longest path first; `/b` gets only `/`) and a restored
	 * entry whose deadline has passed by the time of the LATER restore (a
	 * different moment in wall-clock time than the original export — the
	 * entire point of persistence) is evicted rather than sent.
	 *
	 * @return void
	 */
	public function test_the_persistence_hooks_round_trip_path_and_evict_a_restored_expired_entry(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->now       = 1_700_000_000;
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => [ 'root=r; Path=/', 'narrow=n; Path=/a', 'stale=s; Max-Age=1' ] ] ),
		];

		$persisted = null;
		Functions\when( 'do_action' )->alias( static function ( $tag, $cookies = null ) use ( &$persisted ) {
			if ( 'woodev_challenge-test_api_challenge_redirect_cookies_updated' === $tag ) {
				$persisted = $cookies;
			}
		} );

		$api->request_for_test( 'https://h/api', $this->request_args() );

		$this->assertNotNull( $persisted );
		$this->assertArrayHasKey( 'stale', $persisted );

		$fresh      = new Testable_Api_Base_With_Challenge_Redirects();
		$fresh->now = $api->now + 10;

		Functions\when( 'apply_filters' )->alias( static function ( $tag, $value = null ) use ( $persisted ) {
			return 'woodev_challenge-test_api_challenge_redirect_cookies' === $tag ? $persisted : $value;
		} );

		$fresh->responses = [ $this->response( 200 ), $this->response( 200 ) ];

		$fresh->request_for_test( 'https://h/a/x', $this->request_args() );
		$fresh->request_for_test( 'https://h/b', $this->request_args() );

		$this->assertSame( 'narrow=n; root=r', $fresh->calls[0]['args']['headers']['Cookie'] );
		$this->assertSame( 'root=r', $fresh->calls[1]['args']['headers']['Cookie'] );
	}

	/**
	 * Pin, not a defect: a digit-only Max-Age far larger than any realistic
	 * value is still syntactically valid and is accepted as a far-future
	 * deadline, not misread as a deletion. This deliberately stays within
	 * PHP_INT_MAX — an actual overflow makes PHP's `(int)` cast emit "not
	 * representable as an int", which this suite's `failOnWarning="true"`
	 * turns into a test failure, so exercising the literal overflow path
	 * cannot be done here without changing production code, which this pin
	 * does not do.
	 *
	 * @return void
	 */
	public function test_max_age_as_a_large_but_in_range_integer_is_a_far_future_deadline_not_a_deletion(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/; Max-Age=99999999999' ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}

	/** @return void */
	public function test_max_age_of_negative_one_deletes_the_cookie_immediately(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/' ] ),
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=stale; Path=/; Max-Age=-1' ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertArrayNotHasKey( 'Cookie', $api->calls[2]['args']['headers'] );
	}

	/** @return void */
	public function test_max_age_with_an_empty_value_is_ignored_and_the_cookie_becomes_a_session_cookie(): void {
		$api            = new Testable_Api_Base_With_Challenge_Redirects();
		$api->responses = [
			$this->response( 200, [ 'Set-Cookie' => 'testcookie=first; Path=/; Max-Age=' ] ),
			$this->response( 200 ),
		];

		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );
		$api->request_for_test( 'https://api.example.test/v1/orders', $this->request_args() );

		$this->assertSame( 'testcookie=first', $api->calls[1]['args']['headers']['Cookie'] );
	}
}
