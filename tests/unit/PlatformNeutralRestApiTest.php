<?php
/**
 * Platform-neutral REST API registration tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WP_REST_Controller', false ) ) {
		class WP_REST_Controller {

			/** @var string */
			protected $namespace;

			/** @var string */
			protected $rest_base;

			public function get_public_item_schema() {
				return [];
			}

			public function add_additional_fields_schema( $schema ) {
				return $schema;
			}

			public function get_endpoint_args_for_item_schema( $method = null ) {
				return [];
			}
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Actions;
	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;
	use Mockery;

	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-plugin-rest-api-settings.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/class-plugin-rest-api.php';

	/**
	 * Class PlatformNeutralRestApiTest.
	 */
	class PlatformNeutralRestApiTest extends TestCase {

		/**
		 * Mocks WooCommerce active-plugin detection.
		 *
		 * @param bool $active Whether WooCommerce should be considered active.
		 * @return void
		 */
		private function mock_woocommerce_active( bool $active ): void {
			Functions\when( 'get_option' )->alias(
				static function ( string $name, $default = false ) use ( $active ) {
					if ( 'active_plugins' !== $name ) {
						return $default;
					}

					return $active ? [ 'woocommerce/woocommerce.php' ] : [];
				}
			);
			Functions\when( 'is_multisite' )->justReturn( false );
		}

		/**
		 * Base construction should not register WooCommerce REST hooks when WooCommerce is absent.
		 *
		 * @return void
		 */
		public function test_rest_api_handler_skips_woocommerce_hooks_without_woocommerce(): void {
			$this->mock_woocommerce_active( false );

			Filters\expectAdded( 'woocommerce_rest_prepare_system_status' )->never();
			Actions\expectAdded( 'rest_api_init' )->never();

			new \Woodev_REST_API( Mockery::mock( \Woodev_Plugin::class ) );
		}

		/**
		 * WooCommerce plugins should keep the existing WC REST hooks when WooCommerce is active.
		 *
		 * @return void
		 */
		public function test_rest_api_handler_registers_woocommerce_hooks_with_woocommerce(): void {
			$this->mock_woocommerce_active( true );

			Filters\expectAdded( 'woocommerce_rest_prepare_system_status' )->once();
			Actions\expectAdded( 'rest_api_init' )->once();

			new \Woodev_REST_API( Mockery::mock( \Woodev_Plugin::class ) );
		}

		/**
		 * Settings permission checks should not fatal if WooCommerce REST helpers are unavailable.
		 *
		 * @return void
		 */
		public function test_settings_permissions_fall_back_without_woocommerce_rest_helper(): void {
			$settings = Mockery::mock( \Woodev_Abstract_Settings::class );
			$settings->shouldReceive( 'get_id' )->once()->andReturn( 'test-plugin' );

			Functions\expect( 'current_user_can' )
				->once()
				->with( 'manage_woocommerce' )
				->andReturn( true );

			$controller = new \Woodev_REST_API_Settings( $settings );

			$this->assertTrue( $controller->get_items_permissions_check( Mockery::mock() ) );
		}
	}
}
