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
}
