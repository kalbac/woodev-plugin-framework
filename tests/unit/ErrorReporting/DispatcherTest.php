<?php
/**
 * Dispatcher — the only place that sends: consent at send time, dedupe, cap, lock (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\Consent;
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
		// DELETE … WHERE option_name = %s AND option_value = %s — the ownership-checked release.
		if ( 0 === strpos( $query['sql'], 'DELETE' ) ) {
			if ( ( $this->rows[ $query['args'][0] ] ?? null ) !== $query['args'][1] ) {
				return 0;
			}

			unset( $this->rows[ $query['args'][0] ] );

			return 1;
		}

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
		Functions\when( 'wp_cache_delete' )->justReturn( true );
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

	/**
	 * Calls the private release the way a (possibly paused) lock holder would.
	 *
	 * @param string $lock The row value that holder believes it wrote.
	 * @return void
	 */
	private function release_lock( string $lock ): void {
		$release = new \ReflectionMethod( Dispatcher::class, 'release_lock' );

		if ( PHP_VERSION_ID < 80100 ) {
			$release->setAccessible( true );
		}

		$release->invoke( null, $lock );
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

	public function test_a_drain_that_throws_still_schedules_a_retry_for_the_retained_event(): void {
		$this->enqueue( 1 );
		Functions\when( 'wp_remote_post' )->alias(
			static function () {
				throw new \RuntimeException( 'an HTTP hook threw' );
			}
		);

		$this->assertSame( 0, Dispatcher::run() );

		$this->assertCount( 1, Event_Queue::all(), 'the event was not removed' );
		$this->assertCount( 1, $this->scheduled, 'WP-Cron consumed the event that brought us here: the retained one needs a new drain' );
		$this->assertSame( Dispatcher::HOOK, $this->scheduled[0][1] );
	}

	public function test_a_lock_denied_run_schedules_another_drain_while_events_wait(): void {
		$this->enqueue( 1 );
		$this->db->rows[ Dispatcher::LOCK_OPTION ] = time() . '|someone-else'; // A crashed run's fresh lock.

		$this->assertSame( 0, Dispatcher::run() );

		$this->assertCount( 1, $this->scheduled, 'the cron event that brought us here was consumed: a new one waits for the TTL to pass' );
		$this->assertSame( Dispatcher::HOOK, $this->scheduled[0][1] );

		Dispatcher::run();
		$this->assertCount( 1, $this->scheduled, 'not scheduled twice' );
	}

	public function test_a_lock_denied_run_with_an_empty_queue_schedules_nothing(): void {
		$this->db->rows[ Dispatcher::LOCK_OPTION ] = time() . '|someone-else';

		Dispatcher::run();

		$this->assertSame( [], $this->scheduled );
	}

	public function test_the_lock_row_carries_a_random_owner_token_and_only_the_owner_releases_it(): void {
		$this->enqueue( 1 );
		$seen = null;

		Functions\when( 'wp_remote_post' )->alias(
			function () use ( &$seen ) {
				$seen = $this->db->rows[ Dispatcher::LOCK_OPTION ] ?? null;

				return [];
			}
		);

		Dispatcher::run();

		$this->assertMatchesRegularExpression( '/^\d+\|[0-9a-f]{16}$/', (string) $seen );

		$this->db->rows[ Dispatcher::LOCK_OPTION ] = time() . '|successor';
		$this->release_lock( ( time() - 400 ) . '|original-owner' );
		$this->assertArrayHasKey( Dispatcher::LOCK_OPTION, $this->db->rows, 'a paused original owner must not delete its successor\'s lock' );

		$this->release_lock( $this->db->rows[ Dispatcher::LOCK_OPTION ] );
		$this->assertSame( [], $this->db->rows );
	}

	public function test_a_paused_owner_waking_up_after_a_takeover_cannot_overlap_the_successor(): void {
		// The critic's interleaving: A paused past the TTL, B took the lock over, A resumes and
		// releases «its» lock, C enters while B is still draining. Played here from B's side.
		$this->enqueue( 1 );
		$this->db->rows[ Dispatcher::LOCK_OPTION ] = ( time() - Dispatcher::LOCK_TTL - 10 ) . '|owner-a';
		$this->transients[ 'woodev_er_day_' . gmdate( 'Ymd' ) ] = 19;

		$competing = null;
		$started   = false;

		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$competing, &$started ) {
				if ( ! $started && 0 === strpos( $key, 'woodev_er_day_' ) ) {
					$started = true;

					$this->release_lock( ( time() - Dispatcher::LOCK_TTL - 10 ) . '|owner-a' ); // A wakes up.

					$competing = Dispatcher::run(); // C arrives.
				}

				return $this->transients[ $key ] ?? false;
			}
		);

		$this->assertSame( 1, Dispatcher::run() );

		$this->assertSame( 0, $competing, 'C finds B\'s lock intact' );
		$this->assertCount( 1, $this->posts, 'one post, not two' );
		$this->assertSame( 20, $this->transients[ 'woodev_er_day_' . gmdate( 'Ymd' ) ], 'the daily counter moved once' );
	}

	public function test_a_run_that_lost_its_lock_stops_posting_and_leaves_the_rest_for_the_new_owner(): void {
		$this->enqueue( 1 );
		$this->enqueue( 2 );
		$this->enqueue( 3 );

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = [ $url, $args ];
				// Taken over while this post is in flight.
				$this->db->rows[ Dispatcher::LOCK_OPTION ] = time() . '|successor';

				return [];
			}
		);

		$this->assertSame( 1, Dispatcher::run() );

		$this->assertCount( 1, $this->posts, 'no second post under a lock we no longer hold' );
		$this->assertCount( 2, Event_Queue::all(), 'only the handled event left the queue' );
		$this->assertSame( [ Dispatcher::LOCK_OPTION => $this->db->rows[ Dispatcher::LOCK_OPTION ] ], $this->db->rows );
		$this->assertStringEndsWith( '|successor', $this->db->rows[ Dispatcher::LOCK_OPTION ], 'the successor\'s lock survives our release' );
	}

	public function test_consent_withdrawn_during_a_drain_stops_the_remaining_posts_and_drops_them(): void {
		$this->enqueue( 1 );
		$this->enqueue( 2 );
		$this->enqueue( 3 );

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = [ $url, $args ];
				Consent::set_enabled( false ); // The merchant unticks the box in another request.

				return [];
			}
		);

		$this->assertSame( 1, Dispatcher::run() );

		$this->assertCount( 1, $this->posts, 'consent is re-read before EACH post' );
		$this->assertSame( [], Event_Queue::all() );
	}

	public function test_consent_is_re_read_past_the_object_cache_before_each_post(): void {
		$this->enqueue( 1 );
		$this->enqueue( 2 );

		$deleted = [];
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key, $group ) use ( &$deleted ) {
				$deleted[] = $group . ':' . $key;

				return true;
			}
		);

		Dispatcher::run();

		$this->assertSame( 2, count( array_keys( $deleted, 'options:alloptions', true ) ), 'the cached option set is dropped once per event' );
		$this->assertContains( 'options:woodev_error_reporting_enabled', $deleted );
	}

	public function test_browser_events_cannot_spend_the_php_events_budget_at_drain_time(): void {
		$this->filters['woodev_error_reporting_daily_cap']         = 1;
		$this->filters['woodev_error_reporting_browser_daily_cap'] = 2;

		for ( $line = 1; $line <= 3; ++$line ) {
			Event_Queue::push( $this->browser_event( $line ) );
		}

		$this->enqueue( 1 ); // One PHP event, queued AFTER the browser ones.

		// Two browser sends (their budget), then the PHP event still gets its own.
		$this->assertSame( 3, Dispatcher::run() );
		$this->assertCount( 3, $this->posts );
		$this->assertSame( [], Event_Queue::all() );
	}

	/**
	 * @param int $line Makes the signature unique.
	 * @return array<string,mixed>
	 */
	private function browser_event( int $line ): array {
		return (array) $this->make_browser_builder()->from_payload(
			[
				'source' => 'error',
				'type'   => 'Error',
				'frames' => [
					[
						'url'  => self::OUR_URL . '/assets/js/map.js',
						'line' => $line,
					],
				],
			]
		);
	}
}
