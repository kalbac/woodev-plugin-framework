<?php
/**
 * Consent — the opt-in option and the receiver DSN (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\{Consent, Event_Queue};

/**
 * @covers \Woodev\Framework\Error_Reporting\Consent
 */
final class ConsentTest extends ErrorReportingTestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	/** @var string DSN every plugin gets from the filter ('' = the filter leaves the value alone). */
	private string $dsn = '';

	/** @var array<string,string> DSN by plugin id: a plugin answering only for its own id. */
	private array $dsns = [];

	/** @var array<int,array{mixed,mixed}> Arguments the DSN filter was applied with: [ $value, $plugin_id ]. */
	private array $filter_calls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options      = [];
		$this->dsn          = '';
		$this->dsns         = [];
		$this->filter_calls = [];

		$this->install_scope( $this->make_two_plugin_scope() );

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
			function ( $tag, $value, $plugin_id = null ) {
				if ( 'woodev_error_reporting_dsn' !== $tag ) {
					return $value;
				}

				$this->filter_calls[] = [ $value, $plugin_id ];

				return $this->dsns[ $plugin_id ] ?? ( '' !== $this->dsn ? $this->dsn : $value );
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
		$this->assertNull( Consent::get_dsn( 'acme-delivery' ) );
		$this->assertFalse( Consent::is_available() );
	}

	public function test_the_filter_supplies_the_receiver(): void {
		$this->dsn = 'https://k@errors.example.ru/7';

		$this->assertTrue( Consent::is_available() );
		$this->assertSame( 'https://errors.example.ru/api/7/envelope/', Consent::get_dsn( 'acme-delivery' )->get_envelope_url() );
	}

	public function test_the_filter_gets_the_plugin_id_and_each_plugin_has_its_own_receiver(): void {
		$this->dsns = [
			'acme-delivery' => 'https://a@errors.example.ru/1',
			'beta-shipping' => 'https://b@errors.example.ru/2',
		];

		$this->assertSame( 'https://errors.example.ru/api/1/envelope/', Consent::get_dsn( 'acme-delivery' )->get_envelope_url() );
		$this->assertSame( 'https://errors.example.ru/api/2/envelope/', Consent::get_dsn( 'beta-shipping' )->get_envelope_url() );
		$this->assertContains( [ '', 'beta-shipping' ], $this->filter_calls, 'the owner id is the filter\'s second argument' );
	}

	public function test_a_plugin_without_a_dsn_has_no_receiver_while_another_does(): void {
		$this->dsns = [ 'beta-shipping' => 'https://b@errors.example.ru/2' ];

		$this->assertNull( Consent::get_dsn( 'acme-delivery' ) );
		$this->assertNotNull( Consent::get_dsn( 'beta-shipping' ) );
		$this->assertNull( Consent::get_dsn( '' ), 'an event naming no owner has no receiver' );
	}

	public function test_available_when_at_least_one_registered_plugin_has_a_dsn(): void {
		$this->assertFalse( Consent::is_available() );

		$this->dsns = [ 'beta-shipping' => 'https://b@errors.example.ru/2' ];
		$this->assertTrue( Consent::is_available() );

		$this->dsns = [ 'not-registered' => 'https://b@errors.example.ru/2' ];
		$this->assertFalse( Consent::is_available(), 'a DSN for a plugin that is not registered counts for nothing' );
	}

	public function test_a_site_has_no_receivers_before_the_reporter_knows_its_plugins(): void {
		$this->dsn = 'https://k@errors.example.ru/7';
		\Woodev\Framework\Error_Reporting\Error_Reporter::reset();

		$this->assertFalse( Consent::is_available() );
	}

	public function test_a_legacy_one_argument_filter_callback_keeps_working(): void {
		// A callback that predates #1302 knows nothing of the plugin id: it answers for every plugin.
		$this->dsn = 'https://k@errors.example.ru/7';

		$this->assertNotNull( Consent::get_dsn( 'acme-delivery' ) );
		$this->assertNotNull( Consent::get_dsn( 'beta-shipping' ) );
		$this->assertTrue( Consent::is_available() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_site_wide_constant_is_the_fallback_for_every_plugin(): void {
		define( 'WOODEV_ERROR_REPORTING_DSN', 'https://k@errors.example.ru/9' );

		$this->dsns = [ 'beta-shipping' => 'https://b@errors.example.ru/2' ];

		$this->assertSame( 'https://errors.example.ru/api/9/envelope/', Consent::get_dsn( 'acme-delivery' )->get_envelope_url(), 'no DSN of its own: the constant' );
		$this->assertSame( 'https://errors.example.ru/api/2/envelope/', Consent::get_dsn( 'beta-shipping' )->get_envelope_url(), 'its own DSN wins over the constant' );
		$this->assertContains( [ 'https://k@errors.example.ru/9', 'acme-delivery' ], $this->filter_calls, 'the filter receives the constant as its value' );
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
