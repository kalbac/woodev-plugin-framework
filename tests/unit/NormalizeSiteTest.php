<?php
/**
 * Tests for the woodev_normalize_site() shared normalization primitive.
 *
 * Covers need-license-spec §4.2 steps 0-6: the one pure function applied
 * byte-identically client- and server-side to bind a signed claim to a site.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';

/**
 * Class NormalizeSiteTest.
 */
class NormalizeSiteTest extends TestCase {

	/**
	 * Aliases the WP helpers the function depends on to their native behavior.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// wp_parse_url on PHP 7.4+ is a thin parse_url wrapper (PHP_URL_* component -1 = full array).
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component );
			}
		);

		// untrailingslashit() strips a single trailing forward/back slash.
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $string ) {
				return rtrim( $string, '/\\' );
			}
		);
	}

	/**
	 * Successful normalizations.
	 *
	 * @dataProvider normalization_provider
	 *
	 * @param string $input    Raw URL.
	 * @param string $expected Normalized result.
	 * @return void
	 */
	public function test_normalizes( string $input, string $expected ): void {
		$this->assertSame( $expected, woodev_normalize_site( $input ) );
	}

	/**
	 * Normalization table from plan task s8-p1 step 1.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function normalization_provider(): array {
		return array(
			'scheme+host lowercased'   => array( 'HTTPS://Example.COM/', 'https://example.com' ),
			'default port dropped 443' => array( 'https://example.com:443/shop', 'https://example.com/shop' ),
			'default port dropped 80'  => array( 'http://example.com:80', 'http://example.com' ),
			'non-default port kept'    => array( 'https://example.com:8443/', 'https://example.com:8443' ),
			'path case preserved'      => array( 'https://example.com/Sub/Dir/', 'https://example.com/Sub/Dir' ),
			'query+fragment dropped'   => array( 'https://example.com/?a=1#f', 'https://example.com' ),
			'ipv6 brackets kept'       => array( 'https://[2001:DB8::1]/x', 'https://[2001:db8::1]/x' ),
			'idempotent'               => array( 'https://example.com', 'https://example.com' ),
		);
	}

	/**
	 * Normalizing an already-normalized value is a no-op (idempotence).
	 *
	 * @dataProvider normalization_provider
	 *
	 * @param string $input    Raw URL (unused — we re-normalize the expected output).
	 * @param string $expected The normalized value.
	 * @return void
	 */
	public function test_idempotent( string $input, string $expected ): void {
		$this->assertSame( $expected, woodev_normalize_site( woodev_normalize_site( $input ) ) );
	}

	/**
	 * FAIL cases — every one returns null, never throws.
	 *
	 * @dataProvider fail_provider
	 *
	 * @param string $input Raw URL that must fail to normalize.
	 * @return void
	 */
	public function test_fails( string $input ): void {
		$this->assertNull( woodev_normalize_site( $input ) );
	}

	/**
	 * URLs that must FAIL (return null).
	 *
	 * @return array<string, array{0: string}>
	 */
	public function fail_provider(): array {
		return array(
			'non http(s) scheme'      => array( 'ftp://x.com' ),
			'scheme-relative'         => array( '//no-scheme.com' ),
			'empty host'              => array( 'https://' ),
			'userinfo present'        => array( 'https://user:p@x.com' ),
			'non-url garbage'         => array( 'not a url' ),
			'idn raw non-ascii bytes' => array( 'https://пример.рф/' ),
			// Bracket handling applies ONLY to real IPv6 literals — anything else
			// bracket-like must FAIL (null), never be silently rewritten.
			'empty bracket host'      => array( 'https://[]/' ),
			'bracketed non-ipv6 host' => array( 'https://[example.com]/' ),
			'bracketed invalid ipv6'  => array( 'https://[2001:db8::zz]/' ),
			'bracketed ipv4 not ipv6' => array( 'https://[127.0.0.1]/' ),
		);
	}

	/**
	 * FAIL cases stay FAIL deterministically — a second evaluation of the same
	 * input never flips to a value (the null contract is stable, the function is
	 * pure). Output-side idempotence for success cases is test_idempotent.
	 *
	 * @dataProvider fail_provider
	 *
	 * @param string $input Raw URL that must fail to normalize.
	 * @return void
	 */
	public function test_fails_are_stable( string $input ): void {
		$this->assertNull( woodev_normalize_site( $input ) );
		$this->assertNull( woodev_normalize_site( $input ) );
	}
}
