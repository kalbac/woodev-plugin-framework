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
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
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
 * Drives the shutdown callback from the active job-processing step.
 */
class Shutdown_Wiring_Background_Job_Handler extends Testable_Background_Job_Handler {

	/** @var object|null */
	public $failed_job;

	/** @var string */
	public $failure_reason = '';

	/**
	 * Supplies a fatal error to the production shutdown handler.
	 *
	 * @return array|null
	 */
	protected function get_last_error(): ?array {
		return [
			'type'    => E_ERROR,
			'message' => 'Simulated fatal error',
		];
	}

	/**
	 * Simulates a fatal error while the queue loop's current job is active.
	 *
	 * @param object   $job             Active job.
	 * @param int|null $items_per_batch Unused item cap.
	 * @return object
	 */
	public function process_job( $job, $items_per_batch = null ) {
		$this->handle_shutdown();

		return $job;
	}

	/**
	 * Records the job selected by the shutdown callback.
	 *
	 * @param object|string $job    Job to fail.
	 * @param string        $reason Failure reason.
	 * @return object
	 */
	public function fail_job( $job, $reason = '' ) {
		$this->failed_job    = $job;
		$this->failure_reason = $reason;

		return $job;
	}
}

/**
 * Exposes `maybe_handle()`'s nonce guard without WordPress's real process-lock
 * machinery (`get_transient()`/`wp_rand()`/`usleep()`), so tests can drive it
 * deterministically.
 */
class Nonce_Guard_Testable_Background_Job_Handler extends Testable_Background_Job_Handler {

	/** @return bool */
	protected function is_process_running(): bool {
		return false;
	}
}

/**
 * Class BackgroundJobHandlerTest.
 */
class BackgroundJobHandlerTest extends TestCase {

	/**
	 * Defines `WC()` for the ONE code path that calls it — `maybe_handle()`'s
	 * `WC()->session` guard (card #782).
	 *
	 * Deliberately NOT in `setUp()`. Brain Monkey/Patchwork's `Functions\when( 'WC' )`
	 * defines the function for the whole PHP process and it cannot be undone, which
	 * would leak `function_exists( 'WC' )` === true into every other test class run
	 * afterwards (gotcha `brain-monkey-function-pollution`). Every caller is
	 * `@runInSeparateProcess`, so the definition lives and dies in a child process.
	 *
	 * @param object|null $session the value `WC()->session` resolves to.
	 * @return void
	 */
	private function define_wc( ?object $session ): void {
		$wc          = new \stdClass();
		$wc->session = $session;

		Functions\when( 'WC' )->justReturn( $wc );
	}

	/**
	 * Card #782: `WC()->session` is only initialized by WooCommerce for frontend
	 * requests, so in the CRON/background context `maybe_handle()` runs in it can be
	 * `null`. When it is, the nonce-filter lift-and-restore must be skipped entirely
	 * (a no-op `remove_filter()`/`add_filter()` pair was the silent defect) while
	 * `check_ajax_referer()` still runs.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_maybe_handle_skips_the_nonce_lift_when_there_is_no_wc_session(): void {
		$this->define_wc( null );

		Functions\when( 'wp_die' )->justReturn( null );

		Functions\expect( 'remove_filter' )->never();
		Functions\expect( 'add_filter' )->never();
		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'test_job', 'nonce' )
			->andReturn( true );

		$handler = new Nonce_Guard_Testable_Background_Job_Handler();
		$handler->set_jobs( [ (object) [ 'id' => 'job1', 'status' => 'queued' ] ] );

		$handler->maybe_handle();
	}

	/**
	 * Card #782 regression guard: when a session DOES exist, the original
	 * lift-and-restore behaviour must be unchanged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_maybe_handle_lifts_and_restores_the_nonce_filter_when_a_wc_session_exists(): void {
		$session = new \stdClass();
		$this->define_wc( $session );

		$is_our_callback = static function ( $callback ) use ( $session ): bool {
			return is_array( $callback )
				&& $session === $callback[0]
				&& 'maybe_update_nonce_user_logged_out' === $callback[1];
		};

		Functions\when( 'wp_die' )->justReturn( null );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		Functions\expect( 'remove_filter' )
			->once()
			->with( 'nonce_user_logged_out', Mockery::on( $is_our_callback ) );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'nonce_user_logged_out', Mockery::on( $is_our_callback ), 10, 2 );

		$handler = new Nonce_Guard_Testable_Background_Job_Handler();
		$handler->set_jobs( [ (object) [ 'id' => 'job1', 'status' => 'queued' ] ] );

		$handler->maybe_handle();
	}

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
	 * A fatal simulated while handle() processes a queued job is reported against
	 * that organically selected job, rather than a hand-built shutdown target.
	 *
	 * @return void
	 */
	public function test_handle_routes_active_job_to_shutdown_handler_after_fatal_error(): void {
		$job = (object) [
			'id'     => 'active-job',
			'status' => 'processing',
		];

		Functions\when( 'wp_die' )->justReturn( null );

		$handler = new Shutdown_Wiring_Background_Job_Handler();
		$handler->set_jobs( [ $job ] );

		$handler->handle_public();

		$this->assertSame( $job, $handler->failed_job );
		$this->assertSame( 'Simulated fatal error', $handler->failure_reason );
	}

	/**
	 * Invalid default job data must use the exception type caught by the batch
	 * AJAX boundary instead of leaking a generic exception as a 500 response.
	 *
	 * @return void
	 */
	public function test_process_job_throws_plugin_exception_for_missing_data_key(): void {
		$handler = new class extends \Woodev_Background_Job_Handler {
			/** @return void */
			public function __construct() {}

			/** @return void */
			protected function process_item( $item, $job ) {}
		};

		$this->expectException( \Woodev_Plugin_Exception::class );

		$handler->process_job(
			(object) [
				'id'     => 'missing-data',
				'status' => 'processing',
			]
		);
	}

	/**
	 * A scalar data value must use the same AJAX-caught exception type as a
	 * missing data key.
	 *
	 * @return void
	 */
	public function test_process_job_throws_plugin_exception_for_non_array_data(): void {
		$handler = new class extends \Woodev_Background_Job_Handler {
			/** @return void */
			public function __construct() {}

			/** @return void */
			protected function process_item( $item, $job ) {}
		};

		$this->expectException( \Woodev_Plugin_Exception::class );

		$handler->process_job(
			(object) [
				'id'     => 'scalar-data',
				'status' => 'processing',
				'data'   => 'not-an-array',
			]
		);
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
