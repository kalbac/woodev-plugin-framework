<?php
/**
 * Source-assertion test for the opt-in competitor handler wiring in Woodev_Plugin (s28).
 *
 * Verifies (without loading the class — the engine test defines a Mockery
 * Woodev_Plugin, so requiring the real class in the same suite would redeclare)
 * that base Woodev_Plugin:
 *   - declares the init_competitor_handler() initializer and calls it in __construct;
 *   - exposes get_competitor_notification_handler() defaulting to null (opt-out);
 *   - exposes run_competitor_notices() guarded by is_admin() + null handler;
 *   - registers the current_screen hook (admin-only trigger, never the front end).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

class CompetitorWiringTest extends TestCase {

	/** @var string */
	private string $source;

	protected function setUp(): void {
		parent::setUp();
		$this->source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-plugin.php' );
	}

	public function test_init_competitor_handler_is_declared(): void {
		$this->assertStringContainsString( 'protected function init_competitor_handler()', $this->source );
	}

	public function test_init_competitor_handler_is_called_in_construct(): void {
		$this->assertStringContainsString( '$this->init_competitor_handler();', $this->source );
	}

	public function test_get_competitor_notification_handler_defaults_to_null(): void {
		$this->assertMatchesRegularExpression(
			'/function get_competitor_notification_handler\(\)\s*\{\s*return null;\s*\}/',
			$this->source
		);
	}

	public function test_run_competitor_notices_is_guarded(): void {
		$this->assertStringContainsString( 'public function run_competitor_notices()', $this->source );
		$this->assertStringContainsString( '! is_admin() || null === $this->competitor_handler', $this->source );
	}

	public function test_current_screen_hook_is_registered(): void {
		$this->assertStringContainsString(
			"add_action( 'current_screen', array( \$this, 'run_competitor_notices' ) )",
			$this->source
		);
	}
}
