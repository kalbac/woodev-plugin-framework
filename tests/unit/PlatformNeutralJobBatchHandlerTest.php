<?php
/**
 * Platform-neutral job batch handler tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/utilities/class-woodev-job-batch-handler.php';

/**
 * Minimal wrapper exposing the protected render_js() method.
 */
class Testable_Platform_Neutral_Job_Batch_Handler extends \Woodev_Job_Batch_Handler {

	/**
	 * Avoid parent construction for isolated helper tests.
	 */
	public function __construct() {}

	/**
	 * Sets the mocked job handler.
	 *
	 * @param object $job_handler Mocked handler.
	 * @return void
	 */
	public function set_job_handler( $job_handler ): void {
		$this->job_handler = $job_handler;
	}

	/**
	 * Exposes the protected renderer for testing.
	 *
	 * @return void
	 */
	public function render_js_public(): void {
		$this->render_js();
	}

	/**
	 * Exposes job status formatting for testing.
	 *
	 * @param object $job Job status source.
	 * @return object
	 */
	public function process_job_status_public( $job ) {
		return $this->process_job_status( $job );
	}

	/**
	 * Requires an explicit capability from the consumer plugin.
	 *
	 * @return string
	 */
	protected function get_required_capability(): string {
		return 'manage_woocommerce';
	}

	/**
	 * Tracks whether ajax_process_batch() tried to process a job.
	 *
	 * @var bool
	 */
	public $processed_batch = false;

	/**
	 * Job returned by the test batch processor.
	 *
	 * @var object|null
	 */
	public $batch_job;

	/**
	 * Test double for the batch processor.
	 *
	 * @param string $job_id Job identifier.
	 * @return object
	 */
	public function process_batch( $job_id ) {
		$this->processed_batch = true;

		return $this->batch_job ?: (object) [
			'progress' => 1,
			'total'    => 1,
		];
	}
}

/**
 * Class PlatformNeutralJobBatchHandlerTest.
 */
class PlatformNeutralJobBatchHandlerTest extends TestCase {

	/**
	 * Batch handler inline JavaScript should be queued without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_render_js_queues_inline_script_without_woocommerce_helpers(): void {
		global $woodev_queued_js;

		$woodev_queued_js = null;

		$job_handler = Mockery::mock();
		$job_handler->shouldReceive( 'get_identifier' )->andReturn( 'test_job' );

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_create_nonce' )->alias(
			static function ( string $action ): string {
				return 'nonce-' . $action;
			}
		);
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'has_action' )->justReturn( false );

		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_print_footer_scripts', [ 'Woodev_Helper', 'print_js' ], 25 );

		Functions\expect( 'add_action' )
			->once()
			->with( 'wp_print_footer_scripts', [ 'Woodev_Helper', 'print_js' ], 25 );

		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$handler->set_job_handler( $job_handler );

		$handler->render_js_public();

		$this->assertStringContainsString(
			'window.test_job_batch_handler = new Woodev_Job_Batch_Handler( {"id":"test_job","process_nonce":"nonce-test_job_process_batch","cancel_nonce":"nonce-test_job_cancel_job"} );',
			$woodev_queued_js
		);
	}

	/**
	 * A zero-item job has already completed in the background handler, so the
	 * batch response must report 100% rather than throw a division-by-zero error.
	 *
	 * @return void
	 */
	public function test_process_job_status_marks_zero_total_completed_job_as_complete(): void {
		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$job     = (object) [
			'status'   => 'completed',
			'progress' => 0,
			'total'    => 0,
		];

		$result = $handler->process_job_status_public( $job );

		$this->assertSame( '100.00', $result->percentage );
	}

	/**
	 * The public AJAX path returns a completed zero-item job at 100%, avoiding an
	 * uncaught DivisionByZeroError and a 500 response.
	 *
	 * @return void
	 */
	public function test_ajax_process_batch_returns_completed_zero_total_job(): void {
		$job_handler = Mockery::mock();
		$job_handler->shouldReceive( 'get_identifier' )->andReturn( 'test_job' );
		$job_handler->shouldReceive( 'get_job' )->once()->with( 'd41d8cd98f00b204e9800998ecf8427e' )->andReturn( (object) [ 'id' => 'd41d8cd98f00b204e9800998ecf8427e' ] );

		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$handler->set_job_handler( $job_handler );
		$handler->batch_job = (object) [
			'status'   => 'completed',
			'progress' => 0,
			'total'    => 0,
		];

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		Functions\expect( 'wp_send_json_success' )->once()->with(
			Mockery::on(
				static function ( array $job ): bool {
					return 'completed' === $job['status'] && '100.00' === $job['percentage'];
				}
			)
		);

		$_POST['job_id'] = 'd41d8cd98f00b204e9800998ecf8427e';
		$handler->ajax_process_batch();
		unset( $_POST['job_id'] );
	}

	/**
	 * Malformed IDs must stop at the AJAX boundary and never enter the batch
	 * processor or persistence lookup.
	 *
	 * @return void
	 */
	public function test_ajax_process_batch_rejects_malformed_job_id(): void {
		$job_handler = Mockery::mock();
		$job_handler->shouldReceive( 'get_identifier' )->andReturn( 'test_job' );
		$job_handler->shouldNotReceive( 'get_job' );

		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$handler->set_job_handler( $job_handler );

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$_POST['job_id'] = 'not-a-job-id';
		$handler->ajax_process_batch();
		unset( $_POST['job_id'] );

		$this->assertFalse( $handler->processed_batch );
	}

	/**
	 * A correctly shaped but absent ID must stop before the batch processor.
	 *
	 * @return void
	 */
	public function test_ajax_process_batch_rejects_missing_job_id(): void {
		$job_handler = Mockery::mock();
		$job_handler->shouldReceive( 'get_identifier' )->andReturn( 'test_job' );
		$job_handler->shouldReceive( 'get_job' )->once()->with( 'd41d8cd98f00b204e9800998ecf8427e' )->andReturn( null );

		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$handler->set_job_handler( $job_handler );

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$_POST['job_id'] = 'd41d8cd98f00b204e9800998ecf8427e';
		$handler->ajax_process_batch();
		unset( $_POST['job_id'] );

		$this->assertFalse( $handler->processed_batch );
	}

	/**
	 * A nonce does not authorize a user without the consumer-selected capability
	 * to process a job batch.
	 *
	 * @return void
	 */
	public function test_ajax_process_batch_rejects_user_without_required_capability(): void {
		$job_handler = Mockery::mock();
		$job_handler->shouldReceive( 'get_identifier' )->andReturn( 'test_job' );

		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$handler->set_job_handler( $job_handler );

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_send_json_success' )->justReturn( null );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );
		Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::type( 'array' ), 403 );

		$_POST['job_id'] = 'job-1';
		$handler->ajax_process_batch();
		unset( $_POST['job_id'] );

		$this->assertFalse( $handler->processed_batch );
	}

	/**
	 * A nonce does not authorize a user without the consumer-selected capability
	 * to delete a job.
	 *
	 * @return void
	 */
	public function test_ajax_cancel_job_rejects_user_without_required_capability(): void {
		$job_handler = Mockery::mock();
		$job_handler->shouldReceive( 'get_identifier' )->andReturn( 'test_job' );
		$job_handler->shouldNotReceive( 'delete_job' );

		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$handler->set_job_handler( $job_handler );

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_send_json_success' )->justReturn( null );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );
		Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::type( 'array' ), 403 );

		$_POST['job_id'] = 'job-1';
		$handler->ajax_cancel_job();
		unset( $_POST['job_id'] );
	}

	/**
	 * Cancel requests must reject malformed IDs without reaching persistence.
	 *
	 * @return void
	 */
	public function test_ajax_cancel_job_rejects_malformed_job_id(): void {
		$job_handler = Mockery::mock();
		$job_handler->shouldReceive( 'get_identifier' )->andReturn( 'test_job' );
		$job_handler->shouldNotReceive( 'get_job' );
		$job_handler->shouldNotReceive( 'delete_job' );

		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$handler->set_job_handler( $job_handler );

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$_POST['job_id'] = 'not-a-job-id';
		$handler->ajax_cancel_job();
		unset( $_POST['job_id'] );
	}

	/**
	 * Cancel requests must reject a well-formed ID that has no stored job.
	 *
	 * @return void
	 */
	public function test_ajax_cancel_job_rejects_missing_job_id(): void {
		$job_handler = Mockery::mock();
		$job_handler->shouldReceive( 'get_identifier' )->andReturn( 'test_job' );
		$job_handler->shouldReceive( 'get_job' )->once()->with( 'd41d8cd98f00b204e9800998ecf8427e' )->andReturn( null );
		$job_handler->shouldNotReceive( 'delete_job' );

		$handler = new Testable_Platform_Neutral_Job_Batch_Handler();
		$handler->set_job_handler( $job_handler );

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );

		$_POST['job_id'] = 'd41d8cd98f00b204e9800998ecf8427e';
		$handler->ajax_cancel_job();
		unset( $_POST['job_id'] );
	}
}
