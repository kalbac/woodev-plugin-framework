<?php
/**
 * Cron handler tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Handlers\Cron_Handler;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/class-cron-handler.php';

/**
 * Class CronHandlerTest.
 */
class CronHandlerTest extends TestCase {

	/**
	 * Builds a minimal plugin test double.
	 *
	 * @return \Woodev_Plugin&\Mockery\MockInterface
	 */
	private function make_plugin() {
		return Mockery::mock( \Woodev_Plugin::class );
	}

	/**
	 * Constructing the handler registers all four cron-related hooks with their exact names.
	 *
	 * @return void
	 */
	public function test_constructor_registers_all_cron_hooks(): void {
		$plugin = $this->make_plugin();

		$filters = [];
		$actions = [];

		Functions\when( 'add_filter' )->alias(
			static function ( $hook ) use ( &$filters ): bool {
				$filters[] = $hook;

				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$actions ): bool {
				$actions[] = $hook;

				return true;
			}
		);

		new Cron_Handler( $plugin );

		// the cron_schedules filter must be registered
		$this->assertContains( 'cron_schedules', $filters );

		// the three actions, with their exact installed-site names
		$this->assertContains( 'wp', $actions );
		$this->assertContains( 'woodev_weekly_scheduled_events', $actions );
		$this->assertContains( 'wp_ajax_woodev_verify_license', $actions );
	}

	/**
	 * add_schedules() returns an array containing the 'weekly' key.
	 *
	 * @return void
	 */
	public function test_add_schedules_adds_weekly_key(): void {
		$plugin = $this->make_plugin();

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );

		if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
			define( 'WEEK_IN_SECONDS', 604800 );
		}

		$handler = new Cron_Handler( $plugin );

		$schedules = $handler->add_schedules( [] );

		$this->assertArrayHasKey( 'weekly', $schedules );
		$this->assertSame( WEEK_IN_SECONDS, $schedules['weekly']['interval'] );
	}

	/**
	 * add_schedules() preserves an existing 'weekly' key.
	 *
	 * @return void
	 */
	public function test_add_schedules_preserves_existing_weekly(): void {
		$plugin = $this->make_plugin();

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );

		if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
			define( 'WEEK_IN_SECONDS', 604800 );
		}

		$handler = new Cron_Handler( $plugin );

		$existing  = [ 'weekly' => [ 'interval' => 1, 'display' => 'custom' ] ];
		$schedules = $handler->add_schedules( $existing );

		$this->assertSame( $existing['weekly'], $schedules['weekly'] );
	}
}
