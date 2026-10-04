<?php
/**
 * Rate_Limiter — per-signature dedupe and the daily cap (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\Rate_Limiter;

/**
 * @covers \Woodev\Framework\Error_Reporting\Rate_Limiter
 */
final class RateLimiterTest extends ErrorReportingTestCase {

	/** @var array<string,mixed> transient store */
	private array $store = [];

	/** @var array<string,int> transient lifetimes by key */
	private array $ttl = [];

	/** @var array<string,mixed> filter overrides by tag */
	private array $filters = [];

	protected function setUp(): void {
		parent::setUp();

		$this->store   = [];
		$this->ttl     = [];
		$this->filters = [];

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $expiration ) {
				$this->store[ $key ] = $value;
				$this->ttl[ $key ]   = $expiration;

				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $this->filters[ $tag ] ?? $value;
			}
		);
	}

	public function test_the_same_error_is_sent_once_per_window(): void {
		$limiter = new Rate_Limiter();

		$this->assertTrue( $limiter->allow( 'sig-a' ) );
		$this->assertFalse( $limiter->allow( 'sig-a' ) );
		$this->assertTrue( $limiter->allow( 'sig-b' ), 'a different error is not suppressed' );
	}

	public function test_the_dedupe_window_defaults_to_six_hours_and_is_filterable(): void {
		$limiter = new Rate_Limiter();
		$limiter->allow( 'sig-a' );

		$this->assertSame( 6 * HOUR_IN_SECONDS, $this->ttl[ 'woodev_er_sig_sig-a' ] );

		$this->filters['woodev_error_reporting_dedupe_hours'] = 1;
		$limiter->allow( 'sig-b' );

		$this->assertSame( HOUR_IN_SECONDS, $this->ttl[ 'woodev_er_sig_sig-b' ] );
	}

	public function test_the_daily_cap_stops_further_distinct_errors(): void {
		$this->filters['woodev_error_reporting_daily_cap'] = 2;
		$limiter = new Rate_Limiter();

		$this->assertTrue( $limiter->allow( 'one' ) );
		$this->assertTrue( $limiter->allow( 'two' ) );
		$this->assertFalse( $limiter->allow( 'three' ) );
	}

	public function test_a_zero_cap_sends_nothing(): void {
		$this->filters['woodev_error_reporting_daily_cap'] = 0;

		$this->assertFalse( ( new Rate_Limiter() )->allow( 'one' ) );
	}

	public function test_the_default_cap_is_twenty_a_day(): void {
		$limiter = new Rate_Limiter();
		$sent    = 0;

		for ( $i = 0; $i < 30; $i++ ) {
			$sent += $limiter->allow( 'sig-' . $i ) ? 1 : 0;
		}

		$this->assertSame( 20, $sent );
	}

	public function test_a_refused_report_does_not_consume_the_cap_or_the_window(): void {
		$this->filters['woodev_error_reporting_daily_cap'] = 1;
		$limiter = new Rate_Limiter();

		$limiter->allow( 'one' );
		$limiter->allow( 'one' );

		$this->assertSame( 1, $this->store[ 'woodev_er_day_' . gmdate( 'Ymd' ) ] );
	}

	public function test_the_signature_is_type_plus_throw_site_plus_engine_message_hash(): void {
		$builder = $this->make_builder();
		$limiter = new Rate_Limiter();
		$base    = $builder->from_throwable( $this->make_exception( self::OURS . '/a.php', 10, [], 'same' ) );

		$this->assertSame( $limiter->signature( $base ), $limiter->signature( $builder->from_throwable( $this->make_exception( self::OURS . '/a.php', 10, [], 'same' ) ) ) );
		$this->assertNotSame( $limiter->signature( $base ), $limiter->signature( $builder->from_throwable( $this->make_exception( self::OURS . '/a.php', 11, [], 'same' ) ) ) );
		$this->assertNotSame( $limiter->signature( $base ), $limiter->signature( $builder->from_throwable( $this->make_exception( self::OURS . '/b.php', 10, [], 'same' ) ) ) );
		$this->assertSame( $limiter->signature( $base ), $limiter->signature( $builder->from_throwable( $this->make_exception( self::OURS . '/a.php', 10, [], 'other' ) ) ), 'an exception message is not sent, so it cannot tell two events apart' );

		$fatal = static function ( string $message ) use ( $builder ): array {
			return $builder->from_fatal(
				[
					'type'    => E_ERROR,
					'message' => $message,
					'file'    => self::OURS . '/a.php',
					'line'    => 10,
				]
			);
		};
		$this->assertNotSame( $limiter->signature( $fatal( 'Call to undefined function a()' ) ), $limiter->signature( $fatal( 'Call to undefined function b()' ) ) );
	}
}
