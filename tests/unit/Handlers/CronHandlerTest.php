<?php
/**
 * Cron handler tests.
 *
 * @package Woodev\Tests\Unit\Handlers
 */

namespace Woodev\Tests\Unit\Handlers;

use Woodev\Tests\Unit\TestCase;

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
	// -----------------------------------------------------------------------
	// Issue #400 — the framework fired its OWN deprecation notice on a live admin path.
	//
	// `validate_license( $license, $deprecated = false, $ajax = false )` opens with
	// `if ( $deprecated ) { _deprecated_argument( … ) }`. This handler passed `true` there.
	// With `WP_DEBUG_DISPLAY` on, the notice prints BEFORE `wp_send_json_*` and corrupts the
	// JSON body, so the licence screen shows a parse error instead of a licence status.
	// -----------------------------------------------------------------------

	/**
	 * Builds a plugin whose licence instance records the arguments it is called with.
	 *
	 * @param string|false $license what `get_license()` returns.
	 * @param array        $seen    receives the `validate_license()` argument list.
	 * @return \Woodev_Plugin&\Mockery\MockInterface
	 */
	private function make_plugin_with_license( $license, array &$seen ) {
		$instance = Mockery::mock();
		$instance->shouldReceive( 'get_license' )->andReturn( $license );
		$instance->shouldReceive( 'validate_license' )->andReturnUsing(
			static function ( ...$args ) use ( &$seen ): void {
				$seen = $args;
			}
		);

		$plugin = $this->make_plugin();
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $instance );

		return $plugin;
	}

	public function test_ajax_verify_license_does_not_pass_the_deprecated_argument(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( '_deprecated_argument' )->never();

		$seen   = [];
		$plugin = $this->make_plugin_with_license( 'KEY-123', $seen );

		( new Cron_Handler( $plugin ) )->ajax_verify_license();

		// Positionally: ( $license, $deprecated, $ajax ). The middle one is the whole issue;
		// `$ajax = true` is asserted alongside it because passing `false` there would be a
		// different regression — `validate_license()` would stop answering over AJAX at all.
		$this->assertSame( [ 'KEY-123', false, true ], $seen );
	}

	/**
	 * `get_license()` returns `string|false`. The old expression reached for
	 * `__return_empty_string()` — a WordPress CALLBACK helper, invoked for its return value —
	 * to say "or empty string"; the cast says the same thing. Pinned so the simplification is
	 * not mistaken for a behaviour change.
	 */
	public function test_ajax_verify_license_passes_an_empty_string_when_no_licence_is_stored(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( '_deprecated_argument' )->never();

		$seen   = [];
		$plugin = $this->make_plugin_with_license( false, $seen );

		( new Cron_Handler( $plugin ) )->ajax_verify_license();

		$this->assertSame( [ '', false, true ], $seen );
	}

	/**
	 * The control for both: the capability gate still refuses, and nothing reaches the licence
	 * instance. Without it, `assertSame` on `$seen` above would also pass for a handler that
	 * had stopped calling `validate_license()` at all.
	 */
	public function test_control_ajax_verify_license_refuses_without_the_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_send_json_error' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_send_json_error' );
			}
		);

		$seen   = [];
		$plugin = $this->make_plugin_with_license( 'KEY-123', $seen );

		$this->expectException( \RuntimeException::class );

		try {
			( new Cron_Handler( $plugin ) )->ajax_verify_license();
		} finally {
			$this->assertSame( [], $seen, 'the licence instance must not be reached' );
		}
	}
}
