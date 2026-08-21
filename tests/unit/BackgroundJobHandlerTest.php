<?php
/**
 * Background job handler tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/utilities/class-woodev-async-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/utilities/class-woodev-background-job-handler.php';

/**
 * Minimal handler for terminal-status tests.
 */
class Testable_Background_Job_Handler extends \Woodev_Background_Job_Handler {

	/** @var array<int, object> */
	private $jobs = [];

	/** @var int */
	public $shutdown_handler_registrations = 0;

	/**
	 * Avoid runtime hooks in the isolated unit tests.
	 */
	public function __construct() {
		$this->identifier = 'test_job';
	}

	/**
	 * Seeds queued jobs for a handle() invocation.
	 *
	 * @param array<int, object> $jobs Jobs.
	 * @return void
	 */
	public function set_jobs( array $jobs ): void {
		$this->jobs = $jobs;
	}

	/**
	 * Exposes the protected queue loop.
	 *
	 * @return void
	 */
	public function handle_public(): void {
		$this->handle();
	}

	/**
	 * Records the callback registration without adding a real test-process handler.
	 *
	 * @return void
	 */
	protected function register_shutdown_handler(): void {
		++$this->shutdown_handler_registrations;
	}

	/** @return void */
	protected function lock_process(): void {}

	/** @return Testable_Background_Job_Handler */
	protected function unlock_process() {
		return $this;
	}

	/** @return bool */
	protected function time_exceeded(): bool {
		return false;
	}

	/** @return bool */
	protected function memory_exceeded(): bool {
		return false;
	}

	/** @return bool */
	protected function is_queue_empty(): bool {
		return empty( $this->jobs );
	}

	/**
	 * Returns the next test job.
	 *
	 * @param string|null $id Unused job identifier.
	 * @return object|null
	 */
	public function get_job( $id = null ) {
		return array_shift( $this->jobs );
	}

	/**
	 * Marks the job complete without touching WordPress persistence.
	 *
	 * @param object   $job             Job.
	 * @param int|null $items_per_batch Unused item cap.
	 * @return object
	 */
	public function process_job( $job, $items_per_batch = null ) {
		$job->status = 'completed';

		return $job;
	}

	/** @return void */
	protected function complete(): void {}

	/**
	 * No item processing is needed for terminal-status tests.
	 *
	 * @param mixed  $item Item.
	 * @param object $job  Job.
	 * @return void
	 */
	protected function process_item( $item, $job ) {}
}

/**
 * Class BackgroundJobHandlerTest.
 */
class BackgroundJobHandlerTest extends TestCase {

	/**
	 * Multiple jobs in one request receive one shutdown callback, which follows
	 * only the current job if a later job causes a fatal error.
	 *
	 * @return void
	 */
	public function test_handle_registers_one_shutdown_callback_for_multiple_jobs(): void {
		$handler = new Testable_Background_Job_Handler();
		$handler->set_jobs(
			[
				(object) [ 'id' => 'first', 'status' => 'queued' ],
				(object) [ 'id' => 'second', 'status' => 'queued' ],
				(object) [ 'id' => 'third', 'status' => 'queued' ],
			]
		);

		Functions\when( 'wp_die' )->justReturn( null );

		$handler->handle_public();

		$this->assertSame( 1, $handler->shutdown_handler_registrations );
	}

	/**
	 * A fatal while a later job is active must never rewrite an earlier completed
	 * job or emit a second job-failed action for it.
	 *
	 * @return void
	 */
	public function test_fail_job_preserves_completed_job_after_later_fatal_error(): void {
		global $wpdb;

		$wpdb          = Mockery::mock();
		$wpdb->options = 'options';
		$wpdb->shouldNotReceive( 'update' );

		Functions\when( 'current_time' )->justReturn( '2026-08-21 12:00:00' );
		Functions\expect( 'do_action' )->never();

		$completed_job = (object) [
			'id'     => 'completed-job',
			'status' => 'completed',
		];
		$later_job     = (object) [
			'id'     => 'fatal-job',
			'status' => 'processing',
		];

		$handler = new Testable_Background_Job_Handler();
		$result  = $handler->fail_job( $completed_job, 'Fatal error in ' . $later_job->id );

		$this->assertSame( $completed_job, $result );
		$this->assertSame( 'completed', $completed_job->status );
		$this->assertObjectNotHasProperty( 'failure_reason', $completed_job );
	}
}
