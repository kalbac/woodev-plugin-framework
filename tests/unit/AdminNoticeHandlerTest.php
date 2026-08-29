<?php
/**
 * Tests for Woodev_Admin_Notice_Handler — issue #653.
 *
 * The class had zero unit tests of its own (PlatformNeutralAdminNoticeTest and
 * PhpVersionMatrixNoticeTest exercise other things) despite sitting on the path of
 * every admin screen of every production plugin. These tests pin its CURRENT
 * behaviour before the type-hint / docblock modernisation pass, including the
 * dismissed-state user-meta keys, which are an installed-site data contract under
 * ADR-005 and must not change by so much as a character.
 *
 * Measured: the file has zero `apply_filters()` calls (only one `do_action()`, in
 * `dismiss_notice()`), so the #599/#613 filter-degradation coverage this task asked
 * for does not apply here — pinned instead as the dismiss-action test below.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-admin-notice-handler.php';

/**
 * Admin notice handler test double that skips WordPress hook registration,
 * the same shape as `Testable_Platform_Neutral_Admin_Notice_Handler` in
 * PlatformNeutralAdminNoticeTest.php.
 */
class Testable_Admin_Notice_Handler extends \Woodev_Admin_Notice_Handler {

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
	 * Resets the static render guards between tests — they are shared across every
	 * instance of the real class, so they leak between test methods otherwise.
	 *
	 * @return void
	 */
	public static function reset_static_render_state(): void {
		foreach ( [ 'admin_notice_placeholder_rendered', 'admin_notice_js_rendered' ] as $prop_name ) {
			$property = new \ReflectionProperty( \Woodev_Admin_Notice_Handler::class, $prop_name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, false );
		}
	}
}

/**
 * Class AdminNoticeHandlerTest.
 */
class AdminNoticeHandlerTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_REQUEST['messageid'] );
		Testable_Admin_Notice_Handler::reset_static_render_state();

		parent::tearDown();
	}

	/**
	 * @param string $id Plugin id the mock reports.
	 * @return Mockery\MockInterface
	 */
	private function plugin( string $id = 'test-plugin' ) {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_id' )->andReturn( $id );
		$plugin->shouldReceive( 'get_id_dasherized' )->andReturn( str_replace( '_', '-', $id ) );

		return $plugin;
	}

	/**
	 * @param object|null $plugin Plugin test double, defaults to a bare mock.
	 * @return Testable_Admin_Notice_Handler
	 */
	private function handler( $plugin = null ): Testable_Admin_Notice_Handler {
		return new Testable_Admin_Notice_Handler( $plugin ?? $this->plugin() );
	}

	/**
	 * In-memory user-meta fake, keyed [user_id][meta_key] => value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fake_user_meta_store(): array {
		return [];
	}

	/**
	 * Wires get_user_meta()/update_user_meta() to a shared in-memory array.
	 *
	 * @param array<int, array<string, mixed>> $store Backing store, by reference.
	 * @return void
	 */
	private function stub_user_meta( array &$store ): void {
		Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key, $single = false ) use ( &$store ) {
				return $store[ $user_id ][ $key ] ?? '';
			}
		);

		Functions\when( 'update_user_meta' )->alias(
			static function ( $user_id, $key, $value ) use ( &$store ) {
				$store[ $user_id ][ $key ] = $value;

				return true;
			}
		);
	}

	// ---- should_display_notice() -------------------------------------------------

	public function test_should_display_notice_returns_false_when_user_cannot_manage_woocommerce(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$handler = $this->handler();

		$this->assertFalse( $handler->should_display_notice( 'any-id' ) );
	}

	public function test_should_display_notice_returns_true_on_settings_page_when_always_show_on_settings_default(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$plugin = $this->plugin();
		$plugin->shouldReceive( 'is_plugin_settings' )->andReturn( true );

		$handler = $this->handler( $plugin );

		$this->assertTrue( $handler->should_display_notice( 'any-id' ) );
	}

	public function test_should_display_notice_ignores_settings_page_when_always_show_on_settings_is_false(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$plugin = $this->plugin();
		$plugin->shouldReceive( 'is_plugin_settings' )->andReturn( true );

		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$handler = $this->handler( $plugin );

		$this->assertTrue(
			$handler->should_display_notice(
				'any-id',
				[
					'always_show_on_settings' => false,
				]
			)
		);
	}

	public function test_should_display_notice_returns_true_for_non_dismissible_even_when_dismissed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$plugin = $this->plugin();
		$plugin->shouldReceive( 'is_plugin_settings' )->andReturn( false );

		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$handler = $this->handler( $plugin );
		$handler->dismiss_notice( 'sticky-id', 7 );

		$this->assertTrue(
			$handler->should_display_notice(
				'sticky-id',
				[
					'dismissible'             => false,
					'always_show_on_settings' => false,
				]
			)
		);
	}

	public function test_should_display_notice_returns_false_when_dismissible_and_already_dismissed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$plugin = $this->plugin();
		$plugin->shouldReceive( 'is_plugin_settings' )->andReturn( false );

		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$handler = $this->handler( $plugin );
		$handler->dismiss_notice( 'dismissible-id', 7 );

		$this->assertFalse(
			$handler->should_display_notice(
				'dismissible-id',
				[
					'always_show_on_settings' => false,
				]
			)
		);
	}

	public function test_should_display_notice_returns_true_when_dismissible_and_not_yet_dismissed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$plugin = $this->plugin();
		$plugin->shouldReceive( 'is_plugin_settings' )->andReturn( false );

		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$handler = $this->handler( $plugin );

		$this->assertTrue(
			$handler->should_display_notice(
				'never-dismissed-id',
				[
					'always_show_on_settings' => false,
				]
			)
		);
	}

	// ---- dismiss_notice() / is_notice_dismissed() / undismiss_notice() -----------

	public function test_dismiss_notice_survives_a_reload_a_fresh_handler_instance_sees_it(): void {
		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );

		$first = $this->handler( $this->plugin() );
		$first->dismiss_notice( 'reload-id', 42 );

		// A "reload" is a brand new handler instance/request against the same
		// persisted store — the whole point of storing dismissal in user meta.
		$second = $this->handler( $this->plugin() );

		$this->assertTrue( $second->is_notice_dismissed( 'reload-id', 42 ) );
	}

	public function test_is_notice_dismissed_false_when_never_dismissed(): void {
		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );

		$handler = $this->handler();

		$this->assertFalse( $handler->is_notice_dismissed( 'never-touched', 42 ) );
	}

	public function test_dismiss_notice_defaults_to_the_current_user_when_user_id_omitted(): void {
		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );
		Functions\when( 'get_current_user_id' )->justReturn( 99 );

		$handler = $this->handler();
		$handler->dismiss_notice( 'current-user-id' );

		$this->assertTrue( $handler->is_notice_dismissed( 'current-user-id', 99 ) );
		$this->assertFalse( $handler->is_notice_dismissed( 'current-user-id', 100 ), 'must not leak to another user' );
	}

	public function test_undismiss_notice_clears_the_dismissed_state(): void {
		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );

		$handler = $this->handler();
		$handler->dismiss_notice( 'toggle-id', 7 );
		$this->assertTrue( $handler->is_notice_dismissed( 'toggle-id', 7 ) );

		$handler->undismiss_notice( 'toggle-id', 7 );
		$this->assertFalse( $handler->is_notice_dismissed( 'toggle-id', 7 ) );
	}

	public function test_dismiss_notice_fires_the_dismiss_action_with_message_id_and_user_id(): void {
		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );

		$plugin = $this->plugin( 'acme' );

		Actions\expectDone( 'woodev_acme_dismiss_notice' )
			->once()
			->with( 'promo-id', 7 );

		$this->handler( $plugin )->dismiss_notice( 'promo-id', 7 );
	}

	// ---- frozen dismissed-state user-meta key (ADR-005 installed-site data contract)

	public function test_dismiss_notice_writes_the_frozen_user_meta_key(): void {
		$captured_key = null;

		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->alias(
			static function ( $user_id, $key, $value ) use ( &$captured_key ) {
				$captured_key = $key;

				return true;
			}
		);

		$this->handler( $this->plugin( 'acme' ) )->dismiss_notice( 'x', 7 );

		$this->assertSame( '_woodev_plugin_framework_acme_dismissed_messages', $captured_key );
	}

	public function test_get_dismissed_notices_reads_the_frozen_user_meta_key(): void {
		$captured_key = null;

		Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key, $single = false ) use ( &$captured_key ) {
				$captured_key = $key;

				return [];
			}
		);

		$this->handler( $this->plugin( 'acme' ) )->get_dismissed_notices( 7 );

		$this->assertSame( '_woodev_plugin_framework_acme_dismissed_messages', $captured_key );
	}

	// ---- add_admin_notice() parameter handling ------------------------------------

	public function test_add_admin_notice_stores_and_renders_when_should_display_is_true(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();

		$plugin = $this->plugin( 'acme' );
		$plugin->shouldReceive( 'is_plugin_settings' )->andReturn( true );

		$handler = $this->handler( $plugin );
		$handler->add_admin_notice( 'Hello there', 'greeting' );

		ob_start();
		$handler->render_admin_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Hello there', $html );
		$this->assertStringContainsString( 'data-message-id="greeting"', $html );
	}

	public function test_add_admin_notice_does_not_store_when_should_display_is_false(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$handler = $this->handler();
		$handler->add_admin_notice( 'Should not appear', 'blocked' );

		ob_start();
		$handler->render_admin_notices();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'Should not appear', $html );
		$this->assertStringNotContainsString( 'data-message-id', $html );
	}

	public function test_add_admin_notice_applies_default_params(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();

		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$plugin = $this->plugin();
		$plugin->shouldReceive( 'is_plugin_settings' )->andReturn( false );

		$handler = $this->handler( $plugin );
		// no $params: dismissible, always_show_on_settings and notice_class all default.
		$handler->add_admin_notice( 'msg', 'default-params-id' );

		ob_start();
		$handler->render_admin_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'is-dismissible', $html, 'dismissible defaults to true' );
		$this->assertStringContainsString( 'updated', $html, 'notice_class defaults to "updated"' );
	}

	// ---- render_delayed_admin_notices() --------------------------------------------

	public function test_render_delayed_admin_notices_renders_hidden_and_skips_the_placeholder(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();

		$plugin = $this->plugin( 'acme' );
		$plugin->shouldReceive( 'is_plugin_settings' )->andReturn( true );

		$handler = $this->handler( $plugin );
		$handler->add_admin_notice( 'delayed', 'delayed-id' );

		ob_start();
		$handler->render_delayed_admin_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'style="display:none;"', $html );
		$this->assertStringNotContainsString( 'admin-notice-placeholder', $html );
	}

	// ---- handle_dismiss_notice() AJAX entry point ----------------------------------

	public function test_handle_dismiss_notice_verifies_nonce_and_dismisses_the_sanitized_message_id(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $value ) => strtolower( (string) $value ) );

		$store = $this->fake_user_meta_store();
		$this->stub_user_meta( $store );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$_REQUEST['messageid'] = 'AJAX-ID';

		$handler = $this->handler();
		$handler->handle_dismiss_notice();

		$this->assertTrue( $handler->is_notice_dismissed( 'ajax-id', 7 ) );
	}
}
