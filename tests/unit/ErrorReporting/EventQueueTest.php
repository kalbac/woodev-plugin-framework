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
}
