<?php
/**
 * Dispatcher — the only place that sends: consent at send time, dedupe, cap, lock (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\Dispatcher;
use Woodev\Framework\Error_Reporting\Event_Queue;

/**
 * Just enough of `$wpdb` for the drain lock: an options table with `INSERT IGNORE`, a select and a
 * compare-and-set `UPDATE`.
 */
final class Fake_Lock_Wpdb {

	/** @var string */
	public $options = 'wp_options';

	/** @var array<string,string> option_name => option_value */
	public array $rows = [];

	/** @var int How many INSERT IGNORE statements ran. */
	public int $inserts = 0;

	/**
	 * @param string $sql  Query with %s placeholders.
	 * @param mixed  ...$args Arguments.
	 * @return array{sql:string,args:array<int,string>}
	 */
	public function prepare( string $sql, ...$args ): array {
		return [
			'sql'  => $sql,
			'args' => $args,
		];
	}

	/**
	 * @param array{sql:string,args:array<int,string>} $query Prepared query.
	 * @return int Rows affected.
	 */
	public function query( array $query ): int {
		if ( 0 === strpos( $query['sql'], 'INSERT IGNORE' ) ) {
			++$this->inserts;

			if ( isset( $this->rows[ $query['args'][0] ] ) ) {
				return 0;
			}

			$this->rows[ $query['args'][0] ] = $query['args'][1];

			return 1;
		}

		// UPDATE … SET option_value = %s WHERE option_name = %s AND option_value = %s
		if ( ( $this->rows[ $query['args'][1] ] ?? null ) !== $query['args'][2] ) {
			return 0;
		}

		$this->rows[ $query['args'][1] ] = $query['args'][0];

		return 1;
	}

	/**
	 * @param array{sql:string,args:array<int,string>} $query Prepared query.
	 * @return string|null
	 */
	public function get_var( array $query ): ?string {
		return $this->rows[ $query['args'][0] ] ?? null;
	}

	/**
	 * @param string              $table Table.
	 * @param array<string,mixed> $where Where.
	 * @return int
	 */
	public function delete( string $table, array $where ): int {
		unset( $this->rows[ $where['option_name'] ] );

		return 1;
	}
}

/**
 * @covers \Woodev\Framework\Error_Reporting\Dispatcher
 */
final class DispatcherTest extends ErrorReportingTestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	/** @var array<string,mixed> */
	private array $transients = [];

	/** @var string */
	private string $dsn = 'https://k@errors.example.ru/7';

	/** @var array<int,array{string,array}> */
	private array $posts = [];

	/** @var array<int,array{int,string}> */
	private array $scheduled = [];

	/** @var array<string,mixed> filter overrides */
	private array $filters = [];

	/** @var bool Whether the next wp_remote_post fails. */
	private bool $fail = false;

	/** @var Fake_Lock_Wpdb */
	private Fake_Lock_Wpdb $db;

	protected function setUp(): void {
		parent::setUp();

		$this->options    = [ 'woodev_error_reporting_enabled' => 'yes' ];
		$this->transients = [];
		$this->dsn        = 'https://k@errors.example.ru/7';
		$this->posts      = [];
		$this->scheduled  = [];
		$this->filters    = [];
		$this->fail       = false;
		$this->db         = new Fake_Lock_Wpdb();

		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

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
				if ( 'woodev_error_reporting_dsn' === $tag ) {
					return $this->dsn;
				}

				return $this->filters[ $tag ] ?? $value;
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
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $flags = 0 ) {
				return json_encode( $data, $flags );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function () {
				return $this->fail;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = [ $url, $args ];

				return [];
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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	/**
	 * @param int $line Throw-site line: makes the signature unique.
	 * @return void
	 */
	private function enqueue( int $line ): void {
		Event_Queue::push( $this->make_builder()->from_throwable( $this->make_exception( self::OURS . '/a.php', $line ), 'acme-delivery' ) );
	}

	public function test_scheduling_adds_one_single_event_only_when_none_waits(): void {
		Dispatcher::schedule();
		Dispatcher::schedule();

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( Dispatcher::HOOK, $this->scheduled[0][1] );
		$this->assertGreaterThan( time(), $this->scheduled[0][0] );
	}

	public function test_a_drain_posts_every_queued_event_once_with_a_short_timeout_and_empties_the_queue(): void {
		$this->enqueue( 1 );
		$this->enqueue( 2 );

		$this->assertSame( 2, Dispatcher::run() );

		$this->assertCount( 2, $this->posts );
		$this->assertSame( 'https://errors.example.ru/api/7/envelope/', $this->posts[0][0] );
		$this->assertLessThanOrEqual( 5, $this->posts[0][1]['timeout'] );
		$this->assertSame( [], Event_Queue::all() );
		$this->assertSame( [], $this->db->rows, 'the lock is released' );
	}

	public function test_a_signature_seen_inside_the_window_is_dropped_not_sent(): void {
		$this->enqueue( 1 );
		Dispatcher::run();

		$this->enqueue( 1 ); // The same error again, queued by a later request.
		$this->assertSame( 0, Dispatcher::run() );

		$this->assertCount( 1, $this->posts );
		$this->assertSame( [], Event_Queue::all(), 'a deduped event leaves the queue too' );
	}

	public function test_the_daily_cap_is_applied_at_drain_time(): void {
		$this->filters['woodev_error_reporting_daily_cap'] = 2;

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->enqueue( $i );
		}

		$this->assertSame( 2, Dispatcher::run() );
		$this->assertCount( 2, $this->posts );
		$this->assertSame( [], Event_Queue::all() );
	}

	public function test_consent_withdrawn_before_the_drain_sends_nothing_and_clears_the_queue(): void {
		$this->enqueue( 1 );
		$this->options['woodev_error_reporting_enabled'] = 'no';

		$this->assertSame( 0, Dispatcher::run() );

		$this->assertSame( [], $this->posts );
		$this->assertSame( [], Event_Queue::all() );
	}

	public function test_a_receiver_removed_before_the_drain_sends_nothing(): void {
		$this->enqueue( 1 );
		$this->dsn = '';

		$this->assertSame( 0, Dispatcher::run() );

		$this->assertSame( [], $this->posts );
		$this->assertSame( [], Event_Queue::all() );
	}

	public function test_a_held_lock_stops_a_second_drain_from_sending(): void {
		$this->enqueue( 1 );
		$this->db->rows[ Dispatcher::LOCK_OPTION ] = (string) time(); // Another cron run is draining.

		$this->assertSame( 0, Dispatcher::run() );

		$this->assertSame( [], $this->posts );
		$this->assertCount( 1, Event_Queue::all(), 'the other run owns the queue' );
		$this->assertArrayHasKey( Dispatcher::LOCK_OPTION, $this->db->rows, 'a lock we did not take is not ours to release' );
	}

	public function test_two_consecutive_runs_do_not_double_send(): void {
		$this->enqueue( 1 );

		$this->assertSame( 1, Dispatcher::run() );
		$this->assertSame( 0, Dispatcher::run() );
		$this->assertCount( 1, $this->posts );
	}

	public function test_an_abandoned_lock_is_taken_over(): void {
		$this->enqueue( 1 );
		$this->db->rows[ Dispatcher::LOCK_OPTION ] = (string) ( time() - Dispatcher::LOCK_TTL - 10 );

		$this->assertSame( 1, Dispatcher::run() );
		$this->assertSame( [], $this->db->rows );
	}

	public function test_a_failed_post_stops_the_batch_and_drops_it(): void {
		$this->enqueue( 1 );
		$this->enqueue( 2 );
		$this->enqueue( 3 );
		$this->fail = true;

		$this->assertSame( 0, Dispatcher::run() );

		$this->assertCount( 1, $this->posts, 'no further timeouts once the receiver is unreachable' );
		$this->assertSame( [], Event_Queue::all() );
	}

	public function test_an_exception_inside_the_drain_is_swallowed_and_the_lock_released(): void {
		$this->enqueue( 1 );
		Functions\when( 'wp_remote_post' )->alias(
			static function () {
				throw new \RuntimeException( 'network exploded' );
			}
		);

		$this->assertSame( 0, Dispatcher::run() );
		$this->assertSame( [], $this->db->rows );
	}
}
