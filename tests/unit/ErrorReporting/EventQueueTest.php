<?php
/**
 * Event_Queue — the bounded, non-autoloaded store of pending events (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\Event_Queue;

/**
 * @covers \Woodev\Framework\Error_Reporting\Event_Queue
 */
final class EventQueueTest extends ErrorReportingTestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	/** @var array<int,array{string,mixed}> update_option calls: name, autoload */
	private array $updates = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];
		$this->updates = [];

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				$this->updates[]        = [ $name, $autoload ];

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);
	}

	/**
	 * @param int $line Throw-site line: makes the signature unique.
	 * @return array<string,mixed>
	 */
	private function event( int $line ): array {
		return $this->make_builder()->from_throwable( $this->make_exception( self::OURS . '/a.php', $line ), 'acme-delivery' );
	}

	public function test_an_event_is_stored_in_a_non_autoloaded_option(): void {
		$this->assertTrue( Event_Queue::push( $this->event( 1 ) ) );

		$this->assertCount( 1, Event_Queue::all() );
		$this->assertSame( [ [ Event_Queue::OPTION, false ] ], $this->updates, 'the third update_option argument is false: no autoload' );
	}

	public function test_the_queue_is_bounded_and_the_oldest_is_dropped_first(): void {
		for ( $i = 1; $i <= Event_Queue::LIMIT + 5; $i++ ) {
			Event_Queue::push( $this->event( $i ) );
		}

		$all = Event_Queue::all();

		$this->assertCount( Event_Queue::LIMIT, $all );
		$this->assertSame( 6, $all[0]['exception']['values'][0]['stacktrace']['frames'][0]['lineno'] );
		$this->assertSame( Event_Queue::LIMIT + 5, $all[ Event_Queue::LIMIT - 1 ]['exception']['values'][0]['stacktrace']['frames'][0]['lineno'] );
	}

	public function test_an_identical_pending_event_is_not_stored_again(): void {
		$this->assertTrue( Event_Queue::push( $this->event( 1 ) ) );
		$this->assertFalse( Event_Queue::push( $this->event( 1 ) ) );

		$this->assertCount( 1, Event_Queue::all() );
		$this->assertCount( 1, $this->updates, 'no second option write for a repeat' );
	}

	public function test_remove_drops_the_given_ids_and_keeps_events_that_arrived_meanwhile(): void {
		$first = $this->event( 1 );
		Event_Queue::push( $first );
		Event_Queue::push( $this->event( 2 ) );

		Event_Queue::remove( [ $first['event_id'] ] );

		$this->assertCount( 1, Event_Queue::all() );
	}

	public function test_removing_everything_deletes_the_option(): void {
		$first = $this->event( 1 );
		Event_Queue::push( $first );

		Event_Queue::remove( [ $first['event_id'] ] );

		$this->assertArrayNotHasKey( Event_Queue::OPTION, $this->options );
	}

	public function test_clear_deletes_the_option_and_a_corrupt_value_reads_as_empty(): void {
		Event_Queue::push( $this->event( 1 ) );
		Event_Queue::clear();
		$this->assertSame( [], Event_Queue::all() );

		$this->options[ Event_Queue::OPTION ] = 'garbage';
		$this->assertSame( [], Event_Queue::all() );
	}

	/**
	 * @param int $line Makes the signature unique.
	 * @return array<string,mixed> A browser event.
	 */
	private function browser_event( int $line ): array {
		$event = $this->make_browser_builder()->from_payload(
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

		$this->assertNotNull( $event );

		return $event;
	}

	public function test_an_event_knows_whether_it_came_from_the_browser(): void {
		$this->assertTrue( Event_Queue::is_browser( $this->browser_event( 1 ) ) );
		$this->assertFalse( Event_Queue::is_browser( $this->event( 1 ) ) );
		$this->assertFalse( Event_Queue::is_browser( [] ) );
	}

	public function test_browser_events_have_a_share_of_their_own_and_are_refused_beyond_it(): void {
		$pushed = 0;

		for ( $i = 1; $i <= Event_Queue::LIMIT + 5; ++$i ) {
			$pushed += Event_Queue::push( $this->browser_event( $i ) ) ? 1 : 0;
		}

		$this->assertSame( Event_Queue::BROWSER_LIMIT, $pushed );
		$this->assertCount( Event_Queue::BROWSER_LIMIT, Event_Queue::all() );
		$this->assertSame( 1, Event_Queue::all()[0]['exception']['values'][0]['stacktrace']['frames'][0]['lineno'], 'the oldest stays: refusing is not evicting' );
	}

	public function test_a_php_event_survives_any_number_of_browser_events(): void {
		$php = $this->event( 7 );
		Event_Queue::push( $php );

		for ( $i = 1; $i <= 40; ++$i ) {
			Event_Queue::push( $this->browser_event( $i ) );
		}

		$ids = array_column( Event_Queue::all(), 'event_id' );

		$this->assertContains( $php['event_id'], $ids );
	}

	public function test_a_browser_event_is_refused_when_php_events_fill_the_queue(): void {
		for ( $i = 1; $i <= Event_Queue::LIMIT; ++$i ) {
			Event_Queue::push( $this->event( $i ) );
		}

		$before = Event_Queue::all();

		$this->assertFalse( Event_Queue::push( $this->browser_event( 1 ) ) );
		$this->assertSame( $before, Event_Queue::all() );
	}

	public function test_a_php_event_evicts_the_oldest_browser_event_before_any_php_one(): void {
		for ( $i = 1; $i <= 5; ++$i ) {
			Event_Queue::push( $this->browser_event( $i ) );
		}

		// 15 PHP events fill it to the limit (5 browser + 15 PHP); then one more PHP event arrives.
		for ( $i = 1; $i <= 15; ++$i ) {
			Event_Queue::push( $this->event( $i ) );
		}

		$this->assertCount( Event_Queue::LIMIT, Event_Queue::all() );

		$extra = $this->event( 99 );
		Event_Queue::push( $extra );

		$all     = Event_Queue::all();
		$browser = array_values( array_filter( $all, [ Event_Queue::class, 'is_browser' ] ) );

		$this->assertCount( Event_Queue::LIMIT, $all );
		$this->assertCount( 4, $browser, 'the oldest browser event was evicted, no PHP event' );
		$this->assertSame( 2, $browser[0]['exception']['values'][0]['stacktrace']['frames'][0]['lineno'] );
		$this->assertSame( $extra['event_id'], $all[ Event_Queue::LIMIT - 1 ]['event_id'] );
		$this->assertCount( 16, array_filter( $all, static fn( array $e ): bool => ! Event_Queue::is_browser( $e ) ) );
	}
}
