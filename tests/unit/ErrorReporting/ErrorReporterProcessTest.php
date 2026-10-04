<?php
/**
 * Error_Reporter under REAL PHP dispatch — child processes, not mocks (#130 round 2).
 *
 * The unit tests call `handle_exception()` directly with `restore_exception_handler()` mocked,
 * which is exactly how round 1 shipped a handler that recursed under a real uncaught exception.
 * These tests run `fixtures/runner.php` in a separate PHP and read what PHP itself printed and
 * which exit status it returned.
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Woodev\Framework\Error_Reporting\Error_Reporter
 * @covers \Woodev\Framework\Error_Reporting\Event_Builder
 * @covers \Woodev\Framework\Error_Reporting\Event_Queue
 */
final class ErrorReporterProcessTest extends TestCase {

	/**
	 * @param string $scenario Scenario name understood by fixtures/runner.php.
	 * @return array{out:string,exit:int,queue:array<int,array<string,mixed>>}
	 */
	private function run_scenario( string $scenario ): array {
		$command = [
			PHP_BINARY,
			'-d', 'display_errors=1',
			'-d', 'html_errors=0',
			'-d', 'log_errors=0',
			'-d', 'error_reporting=-1',
			__DIR__ . '/fixtures/runner.php',
			$scenario,
		];

		$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$this->assertIsResource( $process, 'could not start a child PHP' );

		$out = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$status = proc_get_status( $process );
		$code   = proc_close( $process );
		$exit   = $status['running'] ? $code : (int) $status['exitcode'];
		$exit   = -1 === $exit ? $code : $exit;

		$queue = [];
		if ( 1 === preg_match( '/^QUEUE:(.*)$/m', $out, $match ) ) {
			$queue = (array) json_decode( $match[1], true );
		}

		return [
			'out'   => $out,
			'exit'  => $exit,
			'queue' => $queue,
		];
	}

	public function test_an_uncaught_exception_of_ours_is_queued_and_php_still_dies_with_its_own_fatal_and_exit_255(): void {
		$run = $this->run_scenario( 'ours' );

		$this->assertSame( 255, $run['exit'] );
		$this->assertStringContainsString( 'Fatal error: Uncaught RuntimeException: Иван Иванов', $run['out'], 'the ORIGINAL fatal, with its own message, reaches the merchant\'s log' );
		$this->assertStringContainsString( 'Stack trace:', $run['out'] );
		$this->assertStringNotContainsString( 'Maximum call stack', $run['out'] );
		$this->assertStringNotContainsString( 'HTTP-CALLED', $run['out'], 'the failing request does no network I/O' );

		$this->assertCount( 1, $run['queue'], 'reported exactly once: the «Uncaught» fatal at shutdown is not a second report' );
		$event = $run['queue'][0];
		$this->assertSame( 'acme-delivery@1.4.0', $event['release'] );
		$this->assertSame( 'RuntimeException', $event['exception']['values'][0]['type'] );
		$this->assertFalse( $event['exception']['values'][0]['mechanism']['handled'] );

		$functions = array_column( $event['exception']['values'][0]['stacktrace']['frames'], 'function' );
		$this->assertContains( 'Acme_Fixture\\Boom->explode', $functions );
		$this->assertContains( 'Acme_Fixture\\Boom->start', $functions );
	}

	public function test_no_exception_text_or_argument_value_reaches_the_queued_event(): void {
		// Without the random event id and the timestamp: «999» can turn up in them by chance.
		$queue = array_map(
			static fn( array $event ): array => array_diff_key( $event, [ 'event_id' => true, 'timestamp' => true ] ),
			$this->run_scenario( 'ours' )['queue']
		);
		$json  = (string) json_encode( $queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		foreach ( [ 'Иван', '999', 'Ленина', 'token', 'hunter2-secret', 'shop.example.ru' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, "«{$needle}» must not be in the payload" );
		}

		$this->assertStringNotContainsString( dirname( __DIR__, 3 ), $json, 'no absolute path of this machine' );
	}

	public function test_a_foreign_exception_is_not_queued_and_php_still_dies_with_its_own_fatal(): void {
		$run = $this->run_scenario( 'foreign' );

		$this->assertSame( 255, $run['exit'] );
		$this->assertStringContainsString( 'Fatal error: Uncaught RuntimeException: foreign failure', $run['out'] );
		$this->assertSame( [], $run['queue'] );
	}

	public function test_a_previous_handler_is_chained_once_and_the_page_ends_the_way_that_handler_decides(): void {
		$run = $this->run_scenario( 'previous' );

		$this->assertSame( 0, $run['exit'], 'the previous handler swallowed it, so PHP exits normally' );
		$this->assertSame( 1, substr_count( $run['out'], 'PREVIOUS-HANDLER:RuntimeException' ) );
		$this->assertStringNotContainsString( 'Fatal error', $run['out'] );
		$this->assertCount( 1, $run['queue'] );
	}

	public function test_an_error_thrown_by_the_previous_handler_does_not_recurse(): void {
		$run = $this->run_scenario( 'previous_throws' );

		$this->assertSame( 255, $run['exit'] );
		$this->assertSame( 1, substr_count( $run['out'], 'PREVIOUS-HANDLER:' ), 'the handler chain ran once, not in a loop' );
		$this->assertStringContainsString( 'Fatal error: Uncaught LogicException: the previous handler failed', $run['out'] );
		$this->assertStringNotContainsString( 'Maximum call stack', $run['out'] );
		$this->assertCount( 1, $run['queue'], 'the original is queued once; the LogicException comes from a foreign handler outside our plugins, so it is not ours to report' );
	}

	public function test_a_later_handler_that_replaces_ours_is_caught_by_the_shutdown_fatal_instead(): void {
		$run = $this->run_scenario( 'replaced' );

		$this->assertSame( 255, $run['exit'] );
		$this->assertCount( 1, $run['queue'] );
		$this->assertSame( 'E_ERROR', $run['queue'][0]['exception']['values'][0]['type'] );
		$this->assertSame( 'Uncaught RuntimeException', $run['queue'][0]['exception']['values'][0]['value'], 'only the exception class survives from an «Uncaught» fatal' );
		$this->assertStringNotContainsString( 'Иван', (string) json_encode( $run['queue'], JSON_UNESCAPED_UNICODE ) );
	}

	public function test_an_anonymous_exception_class_leaks_neither_its_path_nor_its_message(): void {
		$run  = $this->run_scenario( 'anonymous' );
		$json = (string) json_encode( $run['queue'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$this->assertCount( 1, $run['queue'] );
		// PHP names an anonymous subclass after its parent only from 8.0.x on; older runtimes say «class@anonymous».
		$this->assertMatchesRegularExpression( '/^(RuntimeException|class)@anonymous$/', $run['queue'][0]['exception']['values'][0]['type'] );
		$this->assertStringNotContainsString( 'anon secret text', $json );
		$this->assertStringNotContainsString( '\\u0000', $json );
		$this->assertStringNotContainsString( dirname( __DIR__, 3 ), $json );
	}

	public function test_an_engine_fatal_in_our_code_is_queued_with_php_s_own_message(): void {
		$run = $this->run_scenario( 'engine_fatal' );

		$this->assertSame( 255, $run['exit'] );
		$this->assertCount( 1, $run['queue'] );

		$exception = $run['queue'][0]['exception']['values'][0];
		$this->assertSame( 'E_ERROR', $exception['type'] );
		$this->assertStringStartsWith( 'Allowed memory size of', $exception['value'] );
		$this->assertSame( 'plugins/acme-plugin/boom.php', $exception['stacktrace']['frames'][0]['filename'] );
	}

	public function test_a_near_full_heap_oom_keeps_the_original_fatal_and_queues_without_a_second_fatal(): void {
		$run = $this->run_scenario( 'oom_heap' );

		$this->assertSame( 255, $run['exit'] );
		$this->assertSame( 1, substr_count( $run['out'], 'Fatal error:' ), 'only PHP\'s own fatal: the shutdown handler must not die a second time' );
		$this->assertStringContainsString( 'Allowed memory size of', $run['out'] );
		$this->assertStringContainsString( 'boom.php', $run['out'], 'the original fatal names the original file' );

		$this->assertCount( 1, $run['queue'], 'the emergency reserve leaves room to enqueue' );
		$exception = $run['queue'][0]['exception']['values'][0];
		$this->assertSame( 'E_ERROR', $exception['type'] );
		$this->assertStringStartsWith( 'Allowed memory size of', $exception['value'] );
		$this->assertSame( 'plugins/acme-plugin/boom.php', $exception['stacktrace']['frames'][0]['filename'] );
	}

	public function test_a_manual_capture_queues_and_the_page_carries_on(): void {
		$run = $this->run_scenario( 'captured' );

		$this->assertSame( 0, $run['exit'] );
		$this->assertStringContainsString( 'PAGE-CONTINUES', $run['out'] );
		$this->assertStringNotContainsString( 'HTTP-CALLED', $run['out'] );
		$this->assertCount( 1, $run['queue'] );
		$this->assertTrue( $run['queue'][0]['exception']['values'][0]['mechanism']['handled'] );
	}
}
