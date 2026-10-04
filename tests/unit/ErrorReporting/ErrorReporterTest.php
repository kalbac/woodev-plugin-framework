<?php
/**
 * Error_Reporter — install-once, consent/DSN gating, handler chaining, enqueue-only requests (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\Dispatcher;
use Woodev\Framework\Error_Reporting\Error_Reporter;
use Woodev\Framework\Error_Reporting\Event_Queue;
use Woodev\Framework\Framework_Plugin_Loader_Definition;

/**
 * @covers \Woodev\Framework\Error_Reporting\Error_Reporter
 */
final class ErrorReporterTest extends ErrorReportingTestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	/** @var array<string,mixed> */
	private array $transients = [];

	/** @var string */
	private string $dsn = 'https://k@errors.example.ru/7';

	/** @var array<int,array{string,array}> wp_remote_post calls — must stay empty: the request never sends */
	private array $posts = [];

	/** @var array<int,array{int,string}> wp_schedule_single_event calls */
	private array $scheduled = [];

	/** @var array<int,array{string,callable}> add_action calls */
	private array $actions = [];

	/** @var array<int,mixed> set_exception_handler arguments */
	private array $handlers_set = [];

	/** @var array<int,mixed> register_shutdown_function arguments */
	private array $shutdowns = [];

	/** @var int restore_exception_handler calls */
	private int $restored = 0;

	/** @var mixed what set_exception_handler returns as «the previous handler» */
	private $previous_handler = null;

	/** @var array<string,mixed>|null what error_get_last returns */
	private ?array $last_error = null;

	protected function setUp(): void {
		parent::setUp();

		Error_Reporter::reset();

		global $wp_version;
		$wp_version = '6.8';

		$this->options          = [ 'woodev_error_reporting_enabled' => 'yes' ];
		$this->transients       = [];
		$this->dsn              = 'https://k@errors.example.ru/7';
		$this->posts            = [];
		$this->scheduled        = [];
		$this->actions          = [];
		$this->handlers_set     = [];
		$this->shutdowns        = [];
		$this->restored         = 0;
		$this->previous_handler = null;
		$this->last_error       = null;

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'woodev_error_reporting_dsn' === $tag ? $this->dsn : $value;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) {
				$this->actions[] = [ $hook, $callback ];

				return true;
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
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				foreach ( $this->scheduled as $entry ) {
					if ( $entry[1] === $hook ) {
						return $entry[0];
					}
				}

				return false;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $when, $hook ) {
				$this->scheduled[] = [ $when, $hook ];

				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( self::HOME . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( $value, '/' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $flags = 0 ) {
				return json_encode( $data, $flags );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = [ $url, $args ];

				return [];
			}
		);
		Functions\when( 'set_exception_handler' )->alias(
			function ( $handler ) {
				$this->handlers_set[] = $handler;

				return $this->previous_handler;
			}
		);
		Functions\when( 'register_shutdown_function' )->alias(
			function ( $callback ) {
				$this->shutdowns[] = $callback;
			}
		);
		Functions\when( 'restore_exception_handler' )->alias(
			function () {
				++$this->restored;

				return true;
			}
		);
		Functions\when( 'error_get_last' )->alias(
			function () {
				return $this->last_error;
			}
		);
	}

	protected function tearDown(): void {
		Error_Reporter::reset();

		parent::tearDown();
	}

	/**
	 * The resolver's registered-plugin array for acme-delivery.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function registry(): array {
		$errors     = [];
		$definition = Framework_Plugin_Loader_Definition::from_array(
			[
				'plugin_id'         => 'acme-delivery',
				'plugin_name'       => 'Acme',
				'plugin_version'    => '1.4.0',
				'framework_version' => '2.0.1',
				'plugin_file'       => self::OURS . '/acme-delivery.php',
				'platform'          => Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
				'download_id'       => 77,
				'main_class'        => 'Acme_Plugin',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
			],
			$errors
		);

		return [ $definition->to_legacy_plugin() ];
	}

	/**
	 * @return array<int,array<string,mixed>> Events waiting in the queue option.
	 */
	private function queued(): array {
		$queue = $this->options[ Event_Queue::OPTION ] ?? [];

		return is_array( $queue ) ? $queue : [];
	}

	public function test_without_a_receiver_nothing_is_hooked_and_nothing_is_queued(): void {
		$this->dsn = '';

		$this->assertFalse( Error_Reporter::install( $this->registry() ) );
		$this->assertSame( [], $this->handlers_set );
		$this->assertSame( [], $this->shutdowns );
		$this->assertFalse( Error_Reporter::capture( $this->make_exception( self::OURS . '/a.php', 1 ), 'acme-delivery' ) );
		$this->assertSame( [], $this->queued() );
		$this->assertSame( [], $this->scheduled );
	}

	public function test_without_consent_nothing_is_hooked_and_nothing_is_queued(): void {
		$this->options = [];

		$this->assertFalse( Error_Reporter::install( $this->registry() ) );
		$this->assertSame( [], $this->handlers_set );
		$this->assertSame( [], $this->shutdowns );
		$this->assertFalse( Error_Reporter::capture( $this->make_exception( self::OURS . '/a.php', 1 ) ) );
		$this->assertSame( [], $this->queued() );
	}

	public function test_install_always_wires_the_cron_callback_even_without_consent(): void {
		$this->options = [];

		Error_Reporter::install( $this->registry() );

		$this->assertContains( [ Dispatcher::HOOK, [ Dispatcher::class, 'run' ] ], $this->actions, 'a withdrawn consent must still let the cron run clear the queue' );
	}

	public function test_withdrawing_consent_mid_request_stops_queueing(): void {
		Error_Reporter::install( $this->registry() );
		$this->options['woodev_error_reporting_enabled'] = 'no';

		$this->assertFalse( Error_Reporter::capture( $this->make_exception( self::OURS . '/a.php', 1 ) ) );
		$this->assertSame( [], $this->queued() );
	}

	public function test_install_hooks_the_handlers_exactly_once_per_request(): void {
		$this->assertTrue( Error_Reporter::install( $this->registry() ) );
		$this->assertTrue( Error_Reporter::is_installed() );
		$this->assertFalse( Error_Reporter::install( $this->registry() ), 'the static guard makes a second call a no-op' );
		$this->assertFalse( Error_Reporter::install( $this->registry() ) );

		$this->assertSame( [ [ Error_Reporter::class, 'handle_exception' ] ], $this->handlers_set );
		$this->assertSame( [ [ Error_Reporter::class, 'handle_shutdown' ] ], $this->shutdowns );
	}

	public function test_an_uncaught_exception_of_ours_is_queued_without_any_http_and_chained_to_the_previous_handler(): void {
		$seen                   = [];
		$this->previous_handler = static function ( \Throwable $e ) use ( &$seen ): void {
			$seen[] = $e;
		};
		Error_Reporter::install( $this->registry() );

		$e = $this->make_exception( self::OURS . '/a.php', 12, [], 'boom' );

		Error_Reporter::handle_exception( $e );

		$this->assertSame( [ $e ], $seen, 'the previous handler still gets the exception' );
		$this->assertSame( 0, $this->restored, 'the handler never restores the exception handler: that re-enters it' );
		$this->assertSame( [], $this->posts, 'the failing request does no network I/O' );

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'acme-delivery@1.4.0', $queued[0]['release'] );
		$this->assertFalse( $queued[0]['exception']['values'][0]['mechanism']['handled'] );
		$this->assertSame( [ [ $this->scheduled[0][0], Dispatcher::HOOK ] ], $this->scheduled );
	}

	public function test_with_no_previous_handler_the_exception_is_rethrown_without_restoring(): void {
		Error_Reporter::install( $this->registry() );

		$e = $this->make_exception( self::OURS . '/a.php', 12 );

		try {
			Error_Reporter::handle_exception( $e );
			$this->fail( 'the exception must not be swallowed' );
		} catch ( \Throwable $thrown ) {
			$this->assertSame( $e, $thrown );
		}

		$this->assertSame( 0, $this->restored );
		$this->assertCount( 1, $this->queued() );
		$this->assertSame( [], $this->posts );
	}

	public function test_a_re_entered_handler_rethrows_at_once_instead_of_reporting_again(): void {
		Error_Reporter::install( $this->registry() );
		$e = $this->make_exception( self::OURS . '/a.php', 12 );

		try {
			Error_Reporter::handle_exception( $e );
		} catch ( \Throwable $first ) {
			unset( $first );
		}

		$this->expectExceptionObject( $e );

		try {
			Error_Reporter::handle_exception( $e ); // What a recursing PHP would do.
		} finally {
			$this->assertCount( 1, $this->queued(), 'no second report from the re-entry' );
		}
	}

	public function test_a_foreign_uncaught_exception_is_chained_but_not_queued(): void {
		$seen                   = 0;
		$this->previous_handler = static function () use ( &$seen ): void {
			++$seen;
		};
		Error_Reporter::install( $this->registry() );

		Error_Reporter::handle_exception( $this->make_exception( self::OTHER . '/a.php', 1 ) );

		$this->assertSame( 1, $seen );
		$this->assertSame( [], $this->queued() );
		$this->assertSame( [], $this->scheduled );
	}

	public function test_a_failing_queue_never_breaks_the_handler(): void {
		Functions\when( 'update_option' )->alias(
			static function () {
				throw new \RuntimeException( 'database exploded' );
			}
		);
		$seen                   = 0;
		$this->previous_handler = static function () use ( &$seen ): void {
			++$seen;
		};
		Error_Reporter::install( $this->registry() );

		Error_Reporter::handle_exception( $this->make_exception( self::OURS . '/a.php', 1 ) );

		$this->assertSame( 1, $seen );
	}

	public function test_manual_capture_attributes_by_plugin_id_and_the_repeat_is_not_queued_twice(): void {
		Error_Reporter::install( $this->registry() );
		$e = $this->make_exception( self::OTHER . '/lib.php', 5, [], 'carrier 502' );

		$this->assertTrue( Error_Reporter::capture( $e, 'acme-delivery' ) );
		$this->assertFalse( Error_Reporter::capture( $e, 'acme-delivery' ), 'same signature already pending' );
		$this->assertCount( 1, $this->queued() );
		$this->assertTrue( $this->queued()[0]['exception']['values'][0]['mechanism']['handled'] );
		$this->assertSame( [], $this->posts );
		$this->assertCount( 1, $this->scheduled, 'one cron event, scheduled once' );
	}

	public function test_the_event_filter_can_drop_an_event_before_it_is_queued(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( 'woodev_error_reporting_event' === $tag ) {
					return false;
				}

				return 'woodev_error_reporting_dsn' === $tag ? $this->dsn : $value;
			}
		);
		Error_Reporter::install( $this->registry() );

		$this->assertFalse( Error_Reporter::capture( $this->make_exception( self::OURS . '/a.php', 1 ) ) );
		$this->assertSame( [], $this->queued() );
	}

	public function test_manual_capture_of_a_foreign_error_without_a_hint_queues_nothing(): void {
		Error_Reporter::install( $this->registry() );

		$this->assertFalse( Error_Reporter::capture( $this->make_exception( self::OTHER . '/lib.php', 5 ) ) );
		$this->assertSame( [], $this->queued() );
	}

	public function test_capture_before_install_is_a_quiet_no_op(): void {
		$this->assertFalse( Error_Reporter::capture( $this->make_exception( self::OURS . '/a.php', 1 ) ) );
		$this->assertSame( [], $this->queued() );
	}

	public function test_one_request_queues_at_most_five_distinct_events(): void {
		Error_Reporter::install( $this->registry() );

		$queued = 0;
		for ( $i = 0; $i < 30; $i++ ) {
			$queued += Error_Reporter::capture( $this->make_exception( self::OURS . '/a.php', $i + 1, [], 'e' . $i ) ) ? 1 : 0;
		}

		$this->assertSame( Error_Reporter::MAX_PER_REQUEST, $queued );
		$this->assertCount( Error_Reporter::MAX_PER_REQUEST, $this->queued() );
	}

	/**
	 * @dataProvider fatal_types
	 */
	public function test_only_fatal_error_types_are_queued_at_shutdown( int $type, bool $expected ): void {
		$this->assertSame( $expected, Error_Reporter::is_fatal( $type ) );

		Error_Reporter::install( $this->registry() );
		$this->last_error = [
			'type'    => $type,
			'message' => 'something',
			'file'    => self::OURS . '/a.php',
			'line'    => 3,
		];

		Error_Reporter::handle_shutdown();

		$this->assertCount( $expected ? 1 : 0, $this->queued() );
		$this->assertSame( [], $this->posts );
	}

	/**
	 * @return array<string,array{int,bool}>
	 */
	public function fatal_types(): array {
		return [
			'E_ERROR'             => [ E_ERROR, true ],
			'E_PARSE'             => [ E_PARSE, true ],
			'E_CORE_ERROR'        => [ E_CORE_ERROR, true ],
			'E_COMPILE_ERROR'     => [ E_COMPILE_ERROR, true ],
			'E_RECOVERABLE_ERROR' => [ E_RECOVERABLE_ERROR, true ],
			'E_WARNING'           => [ E_WARNING, false ],
			'E_NOTICE'            => [ E_NOTICE, false ],
			'E_DEPRECATED'        => [ E_DEPRECATED, false ],
			'E_USER_ERROR'        => [ E_USER_ERROR, false ],
			'E_USER_WARNING'      => [ E_USER_WARNING, false ],
		];
	}

	public function test_a_fatal_in_a_foreign_file_and_no_last_error_queue_nothing(): void {
		Error_Reporter::install( $this->registry() );

		Error_Reporter::handle_shutdown();
		$this->last_error = [
			'type'    => E_ERROR,
			'message' => 'boom',
			'file'    => self::OTHER . '/a.php',
			'line'    => 3,
		];
		Error_Reporter::handle_shutdown();

		$this->assertSame( [], $this->queued() );
	}

	public function test_an_uncaught_exception_is_not_queued_a_second_time_as_a_fatal(): void {
		Error_Reporter::install( $this->registry() );

		try {
			Error_Reporter::handle_exception( $this->make_exception( self::OURS . '/a.php', 9 ) );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$this->last_error = [
			'type'    => E_ERROR,
			'message' => 'Uncaught RuntimeException: boom in ' . self::OURS . '/a.php:9',
			'file'    => self::OURS . '/a.php',
			'line'    => 9,
		];
		Error_Reporter::handle_shutdown();

		$this->assertCount( 1, $this->queued() );
	}

	public function test_a_different_uncaught_fatal_after_a_seen_exception_is_still_queued(): void {
		Error_Reporter::install( $this->registry() );

		try {
			Error_Reporter::handle_exception( $this->make_exception( self::OURS . '/a.php', 9 ) );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		// The previous handler threw ANOTHER exception: a different throw site.
		$this->last_error = [
			'type'    => E_ERROR,
			'message' => 'Uncaught LogicException: other in ' . self::OURS . '/b.php:4',
			'file'    => self::OURS . '/b.php',
			'line'    => 4,
		];
		Error_Reporter::handle_shutdown();

		$this->assertCount( 2, $this->queued() );
	}

	public function test_the_queued_event_has_the_site_hash_and_no_site_address_and_no_message(): void {
		Error_Reporter::install( $this->registry() );
		Error_Reporter::capture( $this->make_exception( self::OURS . '/a.php', 1, [], 'see ' . self::HOME . '/x' ) );

		$body = $this->encode( $this->queued()[0] );

		$this->assertStringNotContainsString( 'shop.example.ru', $body );
		$this->assertStringNotContainsString( 'see ', $body );
		$expected = substr( hash( 'sha256', Error_Reporter::SITE_SALT . self::HOME ), 0, 16 );
		$this->assertStringContainsString( '"server_name":"' . $expected . '"', $body );
		$this->assertStringContainsString( '"wp_version":"6.8"', $body );
	}
}
