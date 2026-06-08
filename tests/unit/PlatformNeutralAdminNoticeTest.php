<?php
/**
 * Platform-neutral admin notice helper tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-admin-notice-handler.php';

/**
 * Admin notice handler test double that skips WordPress hook registration.
 */
class Testable_Platform_Neutral_Admin_Notice_Handler extends \Woodev_Admin_Notice_Handler {

	/**
	 * Constructs the handler without registering WordPress hooks.
	 *
	 * @param object $plugin Plugin test double.
	 */
	public function __construct( $plugin ) {
		$this->set_plugin( $plugin );
	}

	/**
	 * Sets the plugin instance for focused tests.
	 *
	 * @param object $plugin Plugin test double.
	 * @return void
	 */
	private function set_plugin( $plugin ): void {
		$property = new \ReflectionProperty( \Woodev_Admin_Notice_Handler::class, 'plugin' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $this, $plugin );
	}

	/**
	 * Seeds admin notices for JavaScript rendering tests.
	 *
	 * @param array<string,array<string,mixed>> $admin_notices Notice payload map.
	 * @return void
	 */
	public function seed_admin_notices( array $admin_notices ): void {
		$property = new \ReflectionProperty( \Woodev_Admin_Notice_Handler::class, 'admin_notices' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $this, $admin_notices );
	}

	/**
	 * Resets the static JavaScript render guard between tests.
	 *
	 * @return void
	 */
	public static function reset_js_render_state(): void {
		$property = new \ReflectionProperty( \Woodev_Admin_Notice_Handler::class, 'admin_notice_js_rendered' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, false );
	}
}

/**
 * Class PlatformNeutralAdminNoticeTest.
 */
class PlatformNeutralAdminNoticeTest extends TestCase {

	/**
	 * Clears queued JavaScript between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $woodev_queued_js;

		unset( $woodev_queued_js );
		Testable_Platform_Neutral_Admin_Notice_Handler::reset_js_render_state();

		parent::tearDown();
	}

	/**
	 * Admin notice dismiss JavaScript should queue through the platform-neutral helper.
	 *
	 * @return void
	 */
	public function test_render_admin_notice_js_queues_platform_neutral_helper_js(): void {
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-admin-notice-nonce' );
		Functions\when( 'esc_js' )->returnArg( 1 );

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_id_dasherized' )->once()->andReturn( 'test-plugin' );

		$handler = new Testable_Platform_Neutral_Admin_Notice_Handler( $plugin );
		$handler->seed_admin_notices(
			[
				'test-notice' => [
					'message'  => 'Notice text',
					'rendered' => false,
					'params'   => [],
				],
			]
		);

		$handler->render_admin_notice_js();

		global $woodev_queued_js;

		$this->assertIsString( $woodev_queued_js );
		$this->assertStringContainsString( 'js-woodev-plugin-framework-admin-notice', $woodev_queued_js );
		$this->assertStringContainsString( 'test-admin-notice-nonce', $woodev_queued_js );
		$this->assertStringContainsString( 'js-wc-test-plugin-admin-notice-placeholder', $woodev_queued_js );
		$this->assertNotFalse(
			has_action( 'admin_print_footer_scripts', [ \Woodev_Helper::class, 'print_js' ] )
		);
	}

	/**
	 * Base-owned admin notice handling should not call wc_enqueue_js() directly anymore.
	 *
	 * @return void
	 */
	public function test_admin_notice_handler_does_not_call_wc_enqueue_js_directly(): void {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-admin-notice-handler.php' );

		$this->assertIsString( $contents );
		$this->assertStringNotContainsString( 'wc_enqueue_js(', $contents );
	}
}
