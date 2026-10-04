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

	public function test_browser_events_spend_a_daily_budget_of_their_own(): void {
		$this->filters['woodev_error_reporting_daily_cap']         = 2;
		$this->filters['woodev_error_reporting_browser_daily_cap'] = 3;
		$limiter = new Rate_Limiter();

		// The browser spends its three…
		$this->assertTrue( $limiter->allow( 'js-1', true ) );
		$this->assertTrue( $limiter->allow( 'js-2', true ) );
		$this->assertTrue( $limiter->allow( 'js-3', true ) );
		$this->assertFalse( $limiter->allow( 'js-4', true ) );

		// …and the PHP budget is untouched.
		$this->assertTrue( $limiter->allow( 'php-1' ) );
		$this->assertTrue( $limiter->allow( 'php-2' ) );
		$this->assertFalse( $limiter->allow( 'php-3' ) );

		$this->assertSame( 3, $this->store[ 'woodev_er_day_js_' . gmdate( 'Ymd' ) ] );
		$this->assertSame( 2, $this->store[ 'woodev_er_day_' . gmdate( 'Ymd' ) ] );
	}

	public function test_the_critics_repro_twenty_browser_sends_leave_the_php_budget_alone(): void {
		$limiter = new Rate_Limiter();

		for ( $i = 1; $i <= 20; ++$i ) {
			$limiter->allow( 'js-' . $i, true );
		}

		$this->assertTrue( $limiter->allow( 'a-php-signature' ) );
	}

	public function test_the_browser_daily_cap_defaults_to_ten_and_zero_sends_none(): void {
		$limiter = new Rate_Limiter();
		$sent    = 0;

		for ( $i = 0; $i < 30; $i++ ) {
			$sent += $limiter->allow( 'js-' . $i, true ) ? 1 : 0;
		}

		$this->assertSame( 10, $sent );

		$this->store                                                = [];
		$this->filters['woodev_error_reporting_browser_daily_cap'] = 0;

		$this->assertFalse( ( new Rate_Limiter() )->allow( 'js-x', true ) );
	}

	public function test_the_site_wide_browser_intake_is_capped_per_hour_and_filterable(): void {
		$limiter = new Rate_Limiter();
		$taken   = 0;

		for ( $i = 0; $i < 50; $i++ ) {
			$taken += $limiter->allow_browser_intake() ? 1 : 0;
		}

		$this->assertSame( 30, $taken );
		$this->assertSame( 30, $this->store[ 'woodev_er_js_in_' . gmdate( 'YmdH' ) ] );

		$this->store                                                 = [];
		$this->filters['woodev_error_reporting_browser_hourly_cap'] = 2;
		$taken                                                       = 0;

		for ( $i = 0; $i < 5; $i++ ) {
			$taken += $limiter->allow_browser_intake() ? 1 : 0;
		}

		$this->assertSame( 2, $taken );

		$this->store                                                 = [];
		$this->filters['woodev_error_reporting_browser_hourly_cap'] = 0;

		$this->assertFalse( $limiter->allow_browser_intake() );
	}
}
