<?php
/**
 * Milestone notice lifecycle tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-lifecycle.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

/** Lifecycle harness exposing the protected notice renderer. */
class Testable_Lifecycle_Milestone extends \Woodev_Lifecycle {

	/** @var object */
	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	protected function add_hooks() {}

	protected function get_plugin() {
		return $this->plugin;
	}

	public function render_notice( string $message ): string {
		return $this->generate_milestone_notice_message( $message );
	}

}

/** Milestone notice behavior tests. */
class LifecycleMilestoneTest extends TestCase {

	/** Set up WordPress escaping and translation functions. */
	protected function setUp(): void {
		parent::setUp();
		global $milestone_options;
		$milestone_options = [];
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( &$milestone_options ) {
				return $milestone_options[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$milestone_options ): bool {
				$milestone_options[ $name ] = $value;
				return true;
			}
		);
	}

	/** Existing review copy remains present and plugin fragments are escaped. */
	public function test_notice_with_reviews_url_keeps_review_copy(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( 'https://example.test/reviews' );
		$plugin->shouldReceive( 'get_support_url' )->andReturn( 'https://example.test/support' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Plugin' );
		$lifecycle = new Testable_Lifecycle_Milestone( $plugin );

		$message = $lifecycle->render_notice( '<done>' );

		$this->assertStringContainsString( '&lt;done&gt;', $message );
		$this->assertStringContainsString( 'leaving a review', $message );
		$this->assertStringContainsString( 'reach out to our support team', $message );
		$this->assertStringContainsString( 'https://example.test/reviews', $message );
	}

	/** Missing review link falls back to support copy. */
	public function test_notice_without_reviews_url_uses_support_copy(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_support_url' )->andReturn( 'https://example.test/support' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Plugin' );
		$lifecycle = new Testable_Lifecycle_Milestone( $plugin );

		$message = $lifecycle->render_notice( 'completed a task' );

		$this->assertStringContainsString( 'completed a task', $message );
		$this->assertStringContainsString( 'reach out to our support team', $message );
		$this->assertStringContainsString( 'https://example.test/support', $message );
	}

	/** Missing review and support links still leave a visible milestone notice. */
	public function test_notice_without_links_keeps_exclamation_and_fragment(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_support_url' )->andReturn( '' );
		$lifecycle = new Testable_Lifecycle_Milestone( $plugin );

		$message = $lifecycle->render_notice( 'completed a task' );

		$this->assertNotSame( '', $message );
		$this->assertStringContainsString( 'completed a task', $message );
	}

	/** Re-triggering a milestone ID cannot replace its original message. */
	public function test_repeated_trigger_keeps_first_message(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_id' )->andReturn( 'milestone-test' );
		$notice_handler = Mockery::mock();
		$notice_handler->shouldReceive( 'get_dismissed_notices' )->andReturn( [] );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $notice_handler );
		$lifecycle = new Testable_Lifecycle_Milestone( $plugin );

		$this->assertTrue( $lifecycle->trigger_milestone( 'first', 'first message' ) );
		$this->assertFalse( $lifecycle->trigger_milestone( 'first', 'replacement message' ) );
		$this->assertSame( [ 'first' => 'first message' ], get_option( 'woodev_milestone-test_milestone_messages', [] ) );
	}
	/** New gateway keys resolve in the current locale; historical strings stay intact. */
	public function test_gateway_keys_are_translated_at_display_time_and_legacy_strings_survive(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_id' )->andReturn( 'milestone-test' );
		$plugin->shouldReceive( 'get_reviews_url', 'get_support_url' )->andReturn( '' );
		$notice_handler = Mockery::mock();
		$notice_handler->shouldReceive( 'get_dismissed_notices' )->andReturn( [] );
		$notice_handler->shouldReceive( 'should_display_notice' )->andReturn( true );
		$notices = [];
		$notice_handler->shouldReceive( 'add_admin_notice' )->andReturnUsing( static function ( string $message ) use ( &$notices ): void { $notices[] = $message; } );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $notice_handler );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$lifecycle = new Testable_Lifecycle_Milestone( $plugin );

		$this->assertTrue( $lifecycle->trigger_milestone( 'payment-processed', 'payment-processed' ) );
		$this->assertSame( [ 'payment-processed' => 'payment-processed' ], get_option( 'woodev_milestone-test_milestone_messages' ) );
		Functions\when( '__' )->alias( static function ( string $message ): string { return 'you successfully processed a payment!' === $message ? 'вы успешно провели платёж!' : $message; } );
		$lifecycle->add_admin_notices();
		$this->assertStringContainsString( 'вы успешно провели платёж!', $notices[0] );

		Functions\when( '__' )->returnArg();
		$lifecycle->add_admin_notices();
		$this->assertStringContainsString( 'you successfully processed a payment!', $notices[1] );

		update_option( 'woodev_milestone-test_milestone_messages', [ 'refund-processed' => 'старое сообщение о возврате' ] );
		$lifecycle->add_admin_notices();
		$this->assertStringContainsString( 'старое сообщение о возврате', $notices[2] );
	}

}
