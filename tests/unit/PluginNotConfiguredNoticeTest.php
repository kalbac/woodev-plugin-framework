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
require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-admin-notice-handler.php';

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

	/** @var Not_Configured_Recording_Handler|\Woodev_Admin_Notice_Handler|null */
	public $handler = null;

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
 * A neutral plugin whose settings tab declares a capability of its own.
 */
class Not_Configured_Declared_Capability_Plugin_Fixture extends Not_Configured_Plugin_Fixture {

	public string $declared = 'edit_others_posts';

	public function get_settings_providers(): array {
		return [
			\Woodev\Framework\Settings\Settings_Provider::create(
				'acme_plugin',
				'Acme',
				\Mockery::mock( \Woodev_Abstract_Settings::class ),
				[],
				[ 'capability' => $this->declared ]
			),
		];
	}
}

/**
 * A WooCommerce-dependent plugin (its settings need `manage_woocommerce`).
 */
class Not_Configured_Woocommerce_Plugin_Fixture extends \Woodev\Framework\Woocommerce_Plugin {

	public bool $configured = false;

	/** @var \Woodev_Admin_Notice_Handler|null */
	public $handler = null;

	public function is_configured(): bool {
		return $this->configured;
	}

	public function get_id() {
		return 'acme_wc_plugin';
	}

	public function get_plugin_name() {
		return 'Acme WC';
	}

	public function get_admin_notice_handler() {
		return $this->handler;
	}

	protected function get_file() {
		return __FILE__;
	}
}

/**
 * The REAL notice handler, built without WordPress hook registration — so its own capability gate runs.
 */
class Not_Configured_Real_Notice_Handler extends \Woodev_Admin_Notice_Handler {

	public function __construct( $plugin ) {
		$property = new \ReflectionProperty( \Woodev_Admin_Notice_Handler::class, 'plugin' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $this, $plugin );
	}

	/**
	 * @return string[] ids of the notices the handler decided to display.
	 */
	public function queued_notice_ids(): array {
		$property = new \ReflectionProperty( \Woodev_Admin_Notice_Handler::class, 'admin_notices' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		return array_keys( $property->getValue( $this ) );
	}
}

/**
 * @covers \Woodev_Plugin::is_configured
 * @covers \Woodev_Plugin::not_configured_notice
 * @covers \Woodev_Plugin::add_not_configured_notice
 * @covers \Woodev_Plugin::add_delayed_admin_notices
 */
final class PluginNotConfiguredNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);
	}

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
	/**
	 * Stubs the current user: only the listed capabilities are granted.
	 *
	 * @param string[] $granted Capabilities the user holds.
	 */
	private function user_with( array $granted ): void {
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) use ( $granted ): bool {
				return in_array( $capability, $granted, true );
			}
		);
	}

	/**
	 * Registers the plugin's notice through the REAL handler and reports whether it was queued.
	 */
	private function is_notice_shown( $plugin ): bool {
		$handler         = new Not_Configured_Real_Notice_Handler( $plugin );
		$plugin->handler = $handler;
		$plugin->add_delayed_admin_notices();

		return in_array( $plugin->get_id_dasherized() . '-not-configured', $handler->queued_notice_ids(), true );
	}

	public function test_a_neutral_plugin_notice_asks_for_manage_options(): void {
		$plugin = $this->plugin( false );

		$plugin->add_delayed_admin_notices();

		$this->assertSame( 'manage_options', $plugin->handler->notices[0]['params']['capability'] );
	}

	public function test_a_neutral_plugin_notice_reaches_a_manage_options_only_admin_through_the_real_handler(): void {
		$this->user_with( [ 'manage_options' ] );

		$this->assertTrue( $this->is_notice_shown( $this->plugin( false, Not_Configured_Plugin_Fixture::class ) ) );
	}

	public function test_a_neutral_plugin_notice_is_hidden_from_a_shop_manager_who_cannot_open_its_settings(): void {
		$this->user_with( [ 'manage_woocommerce' ] );

		$this->assertFalse( $this->is_notice_shown( $this->plugin( false, Not_Configured_Plugin_Fixture::class ) ) );
	}

	public function test_a_declared_settings_capability_is_the_notice_capability(): void {
		$plugin = $this->plugin( false, Not_Configured_Declared_Capability_Plugin_Fixture::class );

		$plugin->add_delayed_admin_notices();

		$this->assertSame( 'edit_others_posts', $plugin->handler->notices[0]['params']['capability'] );
	}

	public function test_other_notices_keep_the_shop_manager_gate(): void {
		$this->user_with( [ 'manage_woocommerce' ] );

		$handler = new Not_Configured_Real_Notice_Handler( $this->plugin( true ) );

		$this->assertTrue( $handler->should_display_notice( 'some-other-notice', [ 'dismissible' => false ] ) );

		$this->user_with( [ 'manage_options' ] );

		$this->assertFalse( $handler->should_display_notice( 'some-other-notice', [ 'dismissible' => false ] ) );
	}

	public function test_a_woocommerce_plugin_notice_reaches_a_shop_manager_but_not_a_manage_options_only_admin(): void {

		/** @var Not_Configured_Woocommerce_Plugin_Fixture $plugin */
		$plugin = ( new \ReflectionClass( Not_Configured_Woocommerce_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		$this->user_with( [ 'manage_woocommerce' ] );
		$this->assertTrue( $this->is_notice_shown( $plugin ) );

		$this->user_with( [ 'manage_options' ] );
		$this->assertFalse( $this->is_notice_shown( $plugin ) );
	}
}
