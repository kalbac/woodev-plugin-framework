<?php
/**
 * Unit tests for Shipping_Plugin::location_provider_not_configured_message()
 * decision — the location-provider counterpart to
 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::add_not_configured_notices()}'s
 * per-shipping-method notice (issue #375/#377).
 *
 * `location_provider_not_configured_notice()` is a PUBLIC, PURE decision
 * (touches no `Woodev_Admin_Notice_Handler`, no admin hook) — instantiated
 * via `ReflectionClass::newInstanceWithoutConstructor()`, the same technique
 * {@see \Woodev\Tests\Unit\Shipping\ShippingPluginNeedsLocationProviderTest}
 * already uses for this class, extended here to also stand up a real
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry}
 * singleton the way {@see \Woodev\Tests\Unit\Shipping\Location\LocationProviderRegistryTest}
 * does, since the decision reads the registry's own `get_active_provider()`.
 * Called directly (no `ReflectionMethod::invoke()`/`setAccessible()`) — see
 * that method's own docblock for why it is `public` rather than `protected`.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';

/**
 * A minimal fake provider, parameterized by id/name/configured — distinct
 * class name from `Fake_Location_Provider` (a different namespace already,
 * but kept distinct on purpose in case both files ever load together).
 */
class Notice_Fake_Location_Provider extends Abstract_Location_Provider {

	private string $id;
	private string $name;
	private bool $configured;

	public function __construct( string $id, string $name, bool $configured ) {
		$this->id         = $id;
		$this->name       = $name;
		$this->configured = $configured;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	public function is_configured(): bool {
		return $this->configured;
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}
}

/**
 * Bare fixture implementing only what PHP requires to instantiate
 * Shipping_Plugin at all — never actually `new`'d, only reflected on, exactly
 * like `Bare_Shipping_Plugin_Fixture` in `ShippingPluginNeedsLocationProviderTest`
 * (a distinct class name here on purpose — both test files load in the same
 * PHPUnit process).
 */
class Notice_Bare_Shipping_Plugin_Fixture extends Shipping_Plugin {

	protected function get_shipping_method_classes(): array {
		return [];
	}

	public function get_api(): ?\Woodev\Framework\Shipping\Shipping_API {
		return null;
	}

	protected function get_file() {
		return __FILE__;
	}

	public function get_plugin_name() {
		return 'Notice Stub';
	}

	public function get_download_id() {
		return 0;
	}
}

/**
 * Same bare fixture, but opting IN to the Location Provider layer — every
 * scenario below except "the plugin never opted in" needs this.
 */
class Notice_Opted_In_Shipping_Plugin_Fixture extends Notice_Bare_Shipping_Plugin_Fixture {

	public function needs_location_provider(): bool {
		return true;
	}
}

/**
 * Records the notice registrations made by one plugin's own handler.
 */
class Notice_Recording_Admin_Notice_Handler {

	/** @var array<int, array{message: string, id: string, params: array<string, string>}> */
	public array $notices = [];

	/**
	 * @param array<string, string> $params Notice registration parameters.
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
 * Exposes the protected registration path and supplies a distinct handler for
 * each plugin, reproducing the fleet-wide duplication that the real plugins
 * otherwise produce.
 */
class Notice_Deduplication_Shipping_Plugin_Fixture extends Notice_Opted_In_Shipping_Plugin_Fixture {

	private ?Notice_Recording_Admin_Notice_Handler $notice_handler = null;

	public function set_notice_handler( Notice_Recording_Admin_Notice_Handler $notice_handler ): void {
		$this->notice_handler = $notice_handler;
	}

	public function publish_location_provider_not_configured_notice(): void {
		$this->add_location_provider_not_configured_notice();
	}

	public function get_admin_notice_handler() {
		return $this->notice_handler;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::location_provider_not_configured_notice
 */
final class ShippingPluginLocationProviderNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/wp-admin/admin.php?page=wc-settings' );
		Functions\when( 'admin_url' )->returnArg( 1 );

		Location_Provider_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		parent::tearDown();
	}

	/**
	 * @param \Woodev\Framework\Shipping\Location\Location_Provider[] $providers
	 */
	private function stub_providers_filter( array $providers ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( $providers ) {
				if ( Location_Provider_Registry::FILTER_PROVIDERS === $tag ) {
					return $providers;
				}

				return $default;
			}
		);
	}

	/**
	 * @return array{message: string, notice_id: string}|null
	 */
	private function decision( Shipping_Plugin $plugin ): ?array {
		return $plugin->location_provider_not_configured_notice();
	}

	public function test_no_notice_when_the_plugin_did_not_opt_into_the_location_provider_layer(): void {
		$plugin = ( new \ReflectionClass( Notice_Bare_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		$this->assertNull( $this->decision( $plugin ) );
	}

	public function test_no_notice_while_the_gate_is_closed_even_for_an_opted_in_plugin(): void {
		// declare_needed()/collect() never called — get_active_provider() is null.
		$plugin = ( new \ReflectionClass( Notice_Opted_In_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		$this->assertNull( $this->decision( $plugin ) );
	}

	public function test_no_notice_when_the_active_provider_is_configured(): void {
		$provider = new Notice_Fake_Location_Provider( 'configured-fixture', 'Configured', true );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'woodev_location_active_provider' === $name ? 'configured-fixture' : $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$plugin = ( new \ReflectionClass( Notice_Opted_In_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		// #375's own `test-list` target: a provider that honestly reports
		// is_configured() === true (declares nothing required, or everything
		// required is filled in) must produce NO notice.
		$this->assertNull( $this->decision( $plugin ) );
	}

	public function test_notice_when_the_active_provider_is_not_configured(): void {
		$provider = new Notice_Fake_Location_Provider( 'unconfigured-fixture', 'СДЭК тестовый', false );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'woodev_location_active_provider' === $name ? 'unconfigured-fixture' : $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$plugin = ( new \ReflectionClass( Notice_Opted_In_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		$notice = $this->decision( $plugin );

		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'СДЭК тестовый', $notice['message'], 'the provider\'s own name is interpolated' );
		$this->assertSame( 'location-provider-unconfigured-fixture-not-configured', $notice['notice_id'] );
	}

	public function test_only_one_plugin_registers_the_fleet_wide_unconfigured_provider_notice(): void {
		$provider = new Notice_Fake_Location_Provider( 'notice-dedup-fixture', 'СДЭК тестовый', false );

		Functions\when( 'add_action' )->justReturn( true );
		$this->stub_providers_filter( [ $provider ] );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'woodev_location_active_provider' === $name ? 'notice-dedup-fixture' : $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$first_handler  = new Notice_Recording_Admin_Notice_Handler();
		$second_handler = new Notice_Recording_Admin_Notice_Handler();
		$first_plugin   = ( new \ReflectionClass( Notice_Deduplication_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();
		$second_plugin  = ( new \ReflectionClass( Notice_Deduplication_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();
		$first_plugin->set_notice_handler( $first_handler );
		$second_plugin->set_notice_handler( $second_handler );

		$first_plugin->publish_location_provider_not_configured_notice();
		$second_plugin->publish_location_provider_not_configured_notice();

		$this->assertCount( 1, $first_handler->notices );
		$this->assertCount( 0, $second_handler->notices );
	}
}
