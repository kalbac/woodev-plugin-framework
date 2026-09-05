<?php
/**
 * Async request tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/utilities/class-woodev-async-request.php';

/**
 * Exposes the protected request-building seam for direct assertions, and skips
 * the real constructor's `add_action()` hook registration.
 */
class Testable_Async_Request extends \Woodev_Async_Request {

	/**
	 * Avoid the constructor's real hook registration in isolated unit tests.
	 */
	public function __construct() {
		$this->identifier = 'test_async_request';
	}

	/**
	 * Exposes `get_request_args()` for direct assertions.
	 *
	 * @param string $url The request URL.
	 * @return array
	 */
	public function get_request_args_public( string $url ): array {
		return $this->get_request_args( $url );
	}

	/** @return void */
	protected function handle(): void {}
}

/**
 * Class AsyncRequestTest.
 */
class AsyncRequestTest extends TestCase {

	/**
	 * Card #784: WordPress core declares `https_local_ssl_verify` as a
	 * two-argument filter (`$default`, `$url`) at all three of its own call
	 * sites (`wp-includes/cron.php:993`, `class-wp-http-curl.php:122`,
	 * `class-wp-http-streams.php:109`). Passing only the default silently drops
	 * the URL a site owner would need to scope an override to our loopback
	 * request instead of every request on the site.
	 *
	 * @return void
	 */
	public function test_get_request_args_applies_the_ssl_verify_filter_with_the_request_url(): void {
		$request = new Testable_Async_Request();

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'https_local_ssl_verify', false, 'https://example.test/wp-admin/admin-ajax.php?action=test' )
			->andReturn( true );

		$args = $request->get_request_args_public( 'https://example.test/wp-admin/admin-ajax.php?action=test' );

		$this->assertTrue( $args['sslverify'] );
	}

	/**
	 * Regression guard for #784: `dispatch()` must forward the SAME url it built
	 * via `get_query_url()`/`add_query_arg()` — the one it actually requests —
	 * into `get_request_args()`, not a different or stale value.
	 *
	 * @return void
	 */
	public function test_dispatch_forwards_its_own_request_url_into_the_ssl_verify_filter(): void {
		$request = new Testable_Async_Request();

		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-value' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin-ajax.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );

		$captured_url = null;

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $default = null, $url = null ) use ( &$captured_url ) {
				if ( 'https_local_ssl_verify' === $tag ) {
					$captured_url = $url;
				}

				return $default;
			}
		);

		Functions\when( 'wp_safe_remote_get' )->alias(
			static function ( $url, $args ) {
				return [
					'requested_url' => $url,
					'args'          => $args,
				];
			}
		);

		$result = $request->dispatch();

		$this->assertNotNull( $captured_url );
		$this->assertSame( $result['requested_url'], $captured_url );
	}
}
