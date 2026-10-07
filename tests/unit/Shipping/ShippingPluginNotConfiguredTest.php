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

		public ?Shipping_Not_Configured_Recording_Handler $handler = null;

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
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::is_configured
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::get_not_configured_notice_url
	 */
	final class ShippingPluginNotConfiguredTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'admin_url' )->alias(
				static function ( $path = '' ) {
					return 'https://shop.test/wp-admin/' . $path;
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
			$plugin = $this->plugin( false );

			$plugin->publish_not_configured_notice();

			$this->assertCount( 1, $plugin->handler->notices );

			$notice = $plugin->handler->notices[0];

			$this->assertSame( 'acme-carrier-not-configured', $notice['id'] );
			$this->assertStringContainsString( 'Acme Carrier не настроен.', $notice['message'] );
			$this->assertStringContainsString(
				'href="https://shop.test/wp-admin/admin.php?page=woodev-settings&tab=acme-carrier"',
				$notice['message']
			);
			$this->assertSame( 'notice-warning', $notice['params']['notice_class'] );
			$this->assertFalse( $notice['params']['dismissible'] );
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
