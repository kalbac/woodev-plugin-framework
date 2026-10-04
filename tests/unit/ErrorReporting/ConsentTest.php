<?php
/**
 * Consent — the opt-in option and the receiver DSN (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\Consent;
use Woodev\Framework\Error_Reporting\Event_Queue;

/**
 * @covers \Woodev\Framework\Error_Reporting\Consent
 */
final class ConsentTest extends ErrorReportingTestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	/** @var string */
	private string $dsn = '';

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];
		$this->dsn     = '';

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'woodev_error_reporting_dsn' === $tag && '' !== $this->dsn ? $this->dsn : $value;
			}
		);
	}

	public function test_consent_is_off_by_default(): void {
		$this->assertFalse( Consent::is_enabled() );
		$this->assertSame( 'woodev_error_reporting_enabled', Consent::OPTION );
	}

	public function test_set_enabled_stores_yes_or_no(): void {
		Consent::set_enabled( true );
		$this->assertSame( 'yes', $this->options['woodev_error_reporting_enabled'] );
		$this->assertTrue( Consent::is_enabled() );

		Consent::set_enabled( false );
		$this->assertSame( 'no', $this->options['woodev_error_reporting_enabled'] );
		$this->assertFalse( Consent::is_enabled() );
	}

	public function test_withdrawing_consent_deletes_the_pending_reports(): void {
		$this->options[ Event_Queue::OPTION ] = [ [ 'event_id' => 'x' ] ];

		Consent::set_enabled( true );
		$this->assertArrayHasKey( Event_Queue::OPTION, $this->options, 'ticking the box keeps the queue' );

		Consent::set_enabled( false );
		$this->assertArrayNotHasKey( Event_Queue::OPTION, $this->options );
	}

	public function test_there_is_no_built_in_receiver(): void {
		$this->assertNull( Consent::get_dsn() );
		$this->assertFalse( Consent::is_available() );
	}

	public function test_the_filter_supplies_the_receiver(): void {
		$this->dsn = 'https://k@errors.example.ru/7';

		$this->assertTrue( Consent::is_available() );
		$this->assertSame( 'https://errors.example.ru/api/7/envelope/', Consent::get_dsn()->get_envelope_url() );
	}

	public function test_a_garbage_dsn_counts_as_no_receiver(): void {
		$this->dsn = 'not a dsn';

		$this->assertFalse( Consent::is_available() );
	}

	public function test_active_needs_both_the_consent_and_the_receiver(): void {
		$this->assertFalse( Consent::is_active(), 'neither' );

		Consent::set_enabled( true );
		$this->assertFalse( Consent::is_active(), 'consent without a receiver' );

		$this->dsn = 'https://k@errors.example.ru/7';
		$this->assertTrue( Consent::is_active() );

		Consent::set_enabled( false );
		$this->assertFalse( Consent::is_active(), 'a receiver without consent' );
	}

	public function test_the_state_handed_to_the_page(): void {
		$this->dsn = 'https://k@errors.example.ru/7';
		Consent::set_enabled( true );

		$this->assertSame(
			[
				'enabled'   => true,
				'available' => true,
			],
			Consent::get_state()
		);
	}
}
