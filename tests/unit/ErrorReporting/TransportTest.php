<?php
/**
 * Dsn and Transport — the Sentry envelope shape (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\Dsn;
use Woodev\Framework\Error_Reporting\Transport;

/**
 * @covers \Woodev\Framework\Error_Reporting\Dsn
 * @covers \Woodev\Framework\Error_Reporting\Transport
 */
final class TransportTest extends ErrorReportingTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $flags = 0 ) {
				return json_encode( $data, $flags );
			}
		);
	}

	public function test_a_dsn_maps_to_the_envelope_endpoint(): void {
		$dsn = Dsn::parse( 'https://abc123@errors.example.ru/7' );

		$this->assertSame( 'https://errors.example.ru/api/7/envelope/', $dsn->get_envelope_url() );
		$this->assertSame( 'abc123', $dsn->get_public_key() );
		$this->assertSame(
			'Sentry sentry_version=7, sentry_client=woodev-error-reporter/1.0.0, sentry_key=abc123',
			$dsn->get_auth_header( 'woodev-error-reporter/1.0.0' )
		);
	}

	public function test_a_dsn_keeps_port_and_path_prefix_and_drops_a_legacy_secret(): void {
		$dsn = Dsn::parse( 'http://pub:hush@10.0.0.5:8000/glitch/sub/12' );

		$this->assertSame( 'http://10.0.0.5:8000/glitch/sub/api/12/envelope/', $dsn->get_envelope_url() );
		$this->assertStringNotContainsString( 'hush', $dsn->get_auth_header( 'c' ) );
	}

	/**
	 * @dataProvider invalid_dsns
	 */
	public function test_an_unusable_dsn_is_rejected( string $raw ): void {
		$this->assertNull( Dsn::parse( $raw ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function invalid_dsns(): array {
		return [
			'empty'         => [ '' ],
			'no key'        => [ 'https://errors.example.ru/7' ],
			'no project'    => [ 'https://k@errors.example.ru' ],
			'bad project'   => [ 'https://k@errors.example.ru/a b' ],
			'wrong scheme'  => [ 'ftp://k@errors.example.ru/7' ],
			'not a url'     => [ 'just text' ],
		];
	}

	public function test_the_envelope_is_three_newline_delimited_json_lines(): void {
		$event = $this->make_builder()->from_throwable( $this->make_exception( self::OURS . '/a.php', 1, [], 'Ошибка' ) );

		$lines = explode( "\n", ( new Transport() )->build_envelope( $event ) );

		$this->assertCount( 4, $lines, 'header, item header, payload and a trailing newline' );
		$this->assertSame( '', $lines[3] );

		$header = json_decode( $lines[0], true );
		$item   = json_decode( $lines[1], true );

		$this->assertSame( $event['event_id'], $header['event_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $header['sent_at'] );
		$this->assertSame( 'event', $item['type'] );
		$this->assertSame( strlen( $lines[2] ), $item['length'], 'length is bytes, not characters' );
		$this->assertSame( $event, json_decode( $lines[2], true ) );
	}

	public function test_send_posts_non_blocking_with_the_auth_header_and_never_throws(): void {
		$event = $this->make_builder()->from_throwable( $this->make_exception( self::OURS . '/a.php', 1 ) );
		$dsn   = Dsn::parse( 'https://abc123@errors.example.ru/7' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( $url, $args ) {
					$this->assertSame( 'https://errors.example.ru/api/7/envelope/', $url );
					$this->assertFalse( $args['blocking'] );
					$this->assertLessThanOrEqual( 5, $args['timeout'] );
					$this->assertSame( 0, $args['redirection'] );
					$this->assertSame( 'application/x-sentry-envelope', $args['headers']['Content-Type'] );
					$this->assertStringStartsWith( 'Sentry sentry_version=7, sentry_client=woodev-error-reporter/', $args['headers']['X-Sentry-Auth'] );
					$this->assertStringContainsString( 'sentry_key=abc123', $args['headers']['X-Sentry-Auth'] );

					return [];
				}
			);
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->assertTrue( ( new Transport() )->send( $event, $dsn ) );
	}

	public function test_a_transport_failure_is_a_plain_false(): void {
		$event = $this->make_builder()->from_throwable( $this->make_exception( self::OURS . '/a.php', 1 ) );

		Functions\when( 'wp_remote_post' )->justReturn( new \stdClass() );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertFalse( ( new Transport() )->send( $event, Dsn::parse( 'https://k@errors.example.ru/7' ) ) );
	}
}
