<?php
/**
 * The shipping side of the "not configured" contract: {@see \Woodev\Framework\Shipping\Shipping_Plugin::is_configured()}
 * derives from the carrier integration (no method class, no zone instance involved), is the one override
 * point of a carrier, and the notice links to the carrier's own tab on the Woodev settings page.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace {

	if ( ! class_exists( 'WC_Integration', false ) ) {
		/**
		 * Minimal WooCommerce integration base — same shape as the stub in the sibling tests.
		 */
		class WC_Integration {

			/** @var string */
			public $id;

			/** @var array */
			public $form_fields = [];

			/** @var string */
			public $method_title = '';

			/** @var string */
			public $method_description = '';

			/** @var array */
			public $settings = [];
		}
	}
}

namespace Woodev\Tests\Unit\Shipping {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Settings\Shipping_Integration;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/class-admin-notice-handler.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/class-phone-mask-patterns.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';

	/**
	 * Records the notice registrations made by the plugin's own handler.
	 */
	class Shipping_Not_Configured_Recording_Handler {

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
	 * A carrier with no shipping method registered and no zone instance — a fresh install.
	 */
	class Shipping_Not_Configured_Plugin_Fixture extends Shipping_Plugin {

		public ?Shipping_Integration $integration = null;

		/** @var Shipping_Not_Configured_Recording_Handler|\Woodev_Admin_Notice_Handler|null */
		public $handler = null;

		public function get_id() {
			return 'acme-carrier';
		}

		public function get_integration_handler(): ?Shipping_Integration {
			return $this->integration;
		}

		public function get_admin_notice_handler() {
			return $this->handler;
		}

		/** Public wrapper: the registration path the base plugin runs on admin_footer. */
		public function publish_not_configured_notice(): void {
			$this->add_not_configured_notice();
		}

		protected function get_shipping_method_classes(): array {
			return [];
		}

		public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
			return null;
		}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Acme Carrier';
		}

		public function get_download_id() {
			return 0;
		}
	}

	/**
	 * The one-line override a carrier plugs its own settings answer in with.
	 */
	class Shipping_Not_Configured_Override_Fixture extends Shipping_Not_Configured_Plugin_Fixture {

		public bool $own_answer = false;

		public function is_configured(): bool {
			return $this->own_answer;
		}
	}

	/**
	 * A carrier whose credentials live on the composite `woodev-settings` tab (the normal v2 case).
	 */
	class Shipping_Not_Configured_Tab_Fixture extends Shipping_Not_Configured_Plugin_Fixture {

		/** @var string|null capability the tab contribution declares */
		public ?string $tab_capability = null;

		protected function get_tab_settings_providers(): array {
			return [
				\Woodev\Framework\Settings\Settings_Provider::create(
					'acme-carrier',
					'Acme',
					\Mockery::mock( \Woodev_Abstract_Settings::class ),
					[],
					null === $this->tab_capability ? [] : [ 'capability' => $this->tab_capability ]
				),
			];
		}
	}

	/**
	 * The REAL notice handler without WordPress hook registration, so its own capability gate runs.
	 */
	class Shipping_Not_Configured_Real_Notice_Handler extends \Woodev_Admin_Notice_Handler {

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
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::is_configured
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::get_not_configured_notice_url
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::get_not_configured_notice_capability
	 */
	final class ShippingPluginNotConfiguredTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'admin_url' )->alias(
				static function ( $path = '' ) {
					return 'https://shop.test/wp-admin/' . $path;
				}
			);
			Functions\when( 'add_query_arg' )->alias(
				static function ( $args, $url ) {
					return $url . '?' . http_build_query( $args );
				}
			);
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults ) {
					return array_merge( $defaults, (array) $args );
				}
			);
		}

		private function plugin( ?bool $integration_configured, string $class = Shipping_Not_Configured_Plugin_Fixture::class ): Shipping_Not_Configured_Plugin_Fixture {

			/** @var Shipping_Not_Configured_Plugin_Fixture $plugin */
			$plugin          = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
			$plugin->handler = new Shipping_Not_Configured_Recording_Handler();

			if ( null !== $integration_configured ) {
				$integration = \Mockery::mock( Shipping_Integration::class );
				$integration->shouldReceive( 'is_configured' )->andReturn( $integration_configured );
				$plugin->integration = $integration;
			}

			return $plugin;
		}

		public function test_a_carrier_without_an_integration_needs_no_keys(): void {
			$plugin = $this->plugin( null );

			$this->assertTrue( $plugin->is_configured() );
			$this->assertNull( $plugin->not_configured_notice() );
		}

		public function test_default_derives_from_the_integration_both_ways(): void {
			$this->assertFalse( $this->plugin( false )->is_configured() );
			$this->assertTrue( $this->plugin( true )->is_configured() );
		}

		public function test_fresh_install_without_any_method_is_warned_with_a_link_to_the_carrier_tab(): void {

			/** @var Shipping_Not_Configured_Tab_Fixture $plugin */
			$plugin = $this->plugin( false, Shipping_Not_Configured_Tab_Fixture::class );

			$plugin->publish_not_configured_notice();

			$this->assertCount( 1, $plugin->handler->notices );

			$notice = $plugin->handler->notices[0];

			$this->assertSame( 'acme-carrier-not-configured', $notice['id'] );
			$this->assertStringContainsString( 'Плагин <strong>Acme Carrier</strong> не настроен.', $notice['message'] );
			$this->assertStringContainsString(
				'href="https://shop.test/wp-admin/admin.php?page=woodev-settings&tab=acme-carrier"',
				$notice['message']
			);
			$this->assertSame( 'notice-warning', $notice['params']['notice_class'] );
			$this->assertFalse( $notice['params']['dismissible'] );
		}

		public function test_an_integration_backed_carrier_links_to_the_integration_settings_not_to_an_empty_tab(): void {

			// an integration, no composite-tab contribution: the credentials are edited on the integration page
			$plugin = $this->plugin( false );

			$plugin->publish_not_configured_notice();

			$message = $plugin->handler->notices[0]['message'];

			$this->assertStringContainsString( 'href="' . $plugin->get_settings_url() . '"', $message );
			$this->assertStringContainsString( 'page=wc-settings&tab=integration&section=acme-carrier', $message );
			$this->assertStringNotContainsString( 'woodev-settings', $message );
		}

		public function test_a_tab_backed_carrier_links_to_the_composite_tab_even_with_an_integration_handler(): void {

			/** @var Shipping_Not_Configured_Tab_Fixture $plugin */
			$plugin = $this->plugin( false, Shipping_Not_Configured_Tab_Fixture::class );

			$this->assertNotNull( $plugin->get_integration_handler() );

			$plugin->publish_not_configured_notice();

			$this->assertStringContainsString( 'page=woodev-settings&tab=acme-carrier', $plugin->handler->notices[0]['message'] );
			$this->assertStringNotContainsString( 'wc-settings', $plugin->handler->notices[0]['message'] );
		}

		public function test_the_shipping_notice_asks_for_the_carrier_tab_capability(): void {

			/** @var Shipping_Not_Configured_Tab_Fixture $plugin */
			$plugin = $this->plugin( false, Shipping_Not_Configured_Tab_Fixture::class );

			$plugin->publish_not_configured_notice();
			$this->assertSame( 'manage_woocommerce', $plugin->handler->notices[0]['params']['capability'], 'a carrier is a WooCommerce plugin' );

			$plugin                 = $this->plugin( false, Shipping_Not_Configured_Tab_Fixture::class );
			$plugin->tab_capability = 'manage_options';
			$plugin->publish_not_configured_notice();
			$this->assertSame( 'manage_options', $plugin->handler->notices[0]['params']['capability'], 'a capability the tab declares wins' );
		}

		public function test_the_real_handler_shows_the_shipping_notice_to_a_shop_manager_only(): void {

			/** @var Shipping_Not_Configured_Tab_Fixture $plugin */
			$plugin          = $this->plugin( false, Shipping_Not_Configured_Tab_Fixture::class );
			$plugin->handler = new Shipping_Not_Configured_Real_Notice_Handler( $plugin );

			Functions\when( 'current_user_can' )->alias(
				static function ( $capability ): bool {
					return 'manage_woocommerce' === $capability;
				}
			);
			$plugin->publish_not_configured_notice();
			$this->assertSame( [ 'acme-carrier-not-configured' ], $plugin->handler->queued_notice_ids(), 'shop manager' );

			$plugin          = $this->plugin( false, Shipping_Not_Configured_Tab_Fixture::class );
			$plugin->handler = new Shipping_Not_Configured_Real_Notice_Handler( $plugin );

			Functions\when( 'current_user_can' )->alias(
				static function ( $capability ): bool {
					return 'manage_options' === $capability;
				}
			);
			$plugin->publish_not_configured_notice();
			$this->assertSame( [], $plugin->handler->queued_notice_ids(), 'a manage_options-only admin cannot open the WooCommerce tab' );
		}

		public function test_a_configured_carrier_shows_no_notice(): void {
			$plugin = $this->plugin( true );

			$plugin->publish_not_configured_notice();

			$this->assertSame( [], $plugin->handler->notices );
		}

		public function test_the_carrier_override_wins_over_the_integration(): void {
			/** @var Shipping_Not_Configured_Override_Fixture $plugin */
			$plugin = $this->plugin( true, Shipping_Not_Configured_Override_Fixture::class );

			$plugin->own_answer = false;
			$this->assertFalse( $plugin->is_configured(), 'the carrier answer is false although the integration says true' );
			$this->assertIsArray( $plugin->not_configured_notice() );

			$plugin->own_answer = true;
			$this->assertTrue( $plugin->is_configured() );
			$this->assertNull( $plugin->not_configured_notice() );
		}

		public function test_the_old_per_method_loop_is_gone(): void {
			$this->assertFalse( method_exists( Shipping_Plugin::class, 'add_not_configured_notices' ) );
		}
	}
}
