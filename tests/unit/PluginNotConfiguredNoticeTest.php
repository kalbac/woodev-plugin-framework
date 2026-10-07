<?php
/**
 * The "not configured" contract of every Woodev plugin: {@see \Woodev_Plugin::is_configured()}
 * (default `true`) and the warning notice the base plugin shows while it answers `false`.
 *
 * The decision ({@see \Woodev_Plugin::not_configured_notice()}) and the registration
 * ({@see \Woodev_Plugin::add_delayed_admin_notices()}) are exercised on a bare
 * `Woodev_Plugin` subclass built with `newInstanceWithoutConstructor()` — no shipping method, no
 * gateway, no zone instance exists anywhere, which is exactly a fresh install.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

/**
 * Records the notice registrations made by the plugin's own handler.
 */
class Not_Configured_Recording_Handler {

	/** @var array<int, array{message: string, id: string, params: array<string, mixed>}> */
	public array $notices = [];

	/**
	 * @param array<string, mixed> $params Notice registration parameters.
	 */
	public function add_admin_notice( string $message, string $id, array $params = [] ): void {
		$this->notices[] = [
			'message' => $message,
			'id'      => $id,
			'params'  => $params,
		];
	}
}

/**
 * A bare plugin: nothing registered, settings answer injected.
 */
class Not_Configured_Plugin_Fixture extends \Woodev_Plugin {

	public bool $configured = true;

	public string $settings_url = 'https://shop.test/wp-admin/admin.php?page=wc-settings&tab=integration&section=acme';

	public ?Not_Configured_Recording_Handler $handler = null;

	public function is_configured(): bool {
		return $this->configured;
	}

	public function get_id() {
		return 'acme_plugin';
	}

	public function get_plugin_name() {
		return 'Acme';
	}

	public function get_settings_url( $plugin_id = null ) {
		return $this->settings_url;
	}

	public function get_admin_notice_handler() {
		return $this->handler;
	}

	protected function get_file() {
		return __FILE__;
	}
}

/**
 * A plugin that keeps the contract's default.
 */
class Not_Configured_Default_Plugin_Fixture extends Not_Configured_Plugin_Fixture {

	public function is_configured(): bool {
		return \Woodev_Plugin::is_configured();
	}
}

/**
 * @covers \Woodev_Plugin::is_configured
 * @covers \Woodev_Plugin::not_configured_notice
 * @covers \Woodev_Plugin::add_not_configured_notice
 * @covers \Woodev_Plugin::add_delayed_admin_notices
 */
final class PluginNotConfiguredNoticeTest extends TestCase {

	private function plugin( bool $configured, string $class = Not_Configured_Plugin_Fixture::class ): Not_Configured_Plugin_Fixture {

		/** @var Not_Configured_Plugin_Fixture $plugin */
		$plugin             = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
		$plugin->configured = $configured;
		$plugin->handler    = new Not_Configured_Recording_Handler();

		return $plugin;
	}

	public function test_a_plugin_that_overrides_nothing_is_configured(): void {
		$plugin = $this->plugin( false, Not_Configured_Default_Plugin_Fixture::class );

		$this->assertTrue( $plugin->is_configured() );
		$this->assertNull( $plugin->not_configured_notice() );

		$plugin->add_delayed_admin_notices();

		$this->assertSame( [], $plugin->handler->notices );
	}

	public function test_a_configured_plugin_shows_no_notice(): void {
		$plugin = $this->plugin( true );

		$plugin->add_delayed_admin_notices();

		$this->assertNull( $plugin->not_configured_notice() );
		$this->assertSame( [], $plugin->handler->notices );
	}

	public function test_fresh_install_is_warned_with_no_methods_gateways_or_instances(): void {
		$plugin = $this->plugin( false );

		$plugin->add_delayed_admin_notices();

		$this->assertCount( 1, $plugin->handler->notices );

		$notice = $plugin->handler->notices[0];

		$this->assertSame( 'acme-plugin-not-configured', $notice['id'] );
		$this->assertStringContainsString( 'Acme не настроен.', $notice['message'] );
		$this->assertStringContainsString( 'href="' . $plugin->settings_url . '"', $notice['message'] );
		$this->assertSame( 'notice-warning', $notice['params']['notice_class'] );
	}

	public function test_the_notice_is_not_dismissible_because_it_clears_itself(): void {
		$plugin = $this->plugin( false );

		$plugin->add_delayed_admin_notices();

		$this->assertFalse( $plugin->handler->notices[0]['params']['dismissible'] );
	}

	public function test_the_override_is_honoured_in_both_directions(): void {
		$plugin = $this->plugin( false );
		$this->assertIsArray( $plugin->not_configured_notice() );

		$plugin->configured = true;
		$this->assertNull( $plugin->not_configured_notice() );
	}

	public function test_a_plugin_without_a_settings_url_still_warns_without_a_broken_link(): void {
		$plugin               = $this->plugin( false );
		$plugin->settings_url = '';

		$notice = $plugin->not_configured_notice();

		$this->assertIsArray( $notice );
		$this->assertStringNotContainsString( '<a ', $notice['message'] );
		$this->assertStringContainsString( 'Acme не настроен.', $notice['message'] );
	}

	public function test_a_plugin_whose_notice_handler_is_absent_does_not_fatal(): void {
		$plugin          = $this->plugin( false );
		$plugin->handler = null;

		$plugin->add_delayed_admin_notices();

		$this->addToAssertionCount( 1 );
	}
}
