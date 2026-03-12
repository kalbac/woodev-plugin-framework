<?php
/**
 * Plugin Name: Woodev Test Payment Gateway
 * Description: Fixture payment gateway for Woodev Framework testing. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-test-payment-gateway
 *
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * WC requires at least: 5.6
 *
 * @package Woodev_Test_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Определяем корневую директорию фреймворка.
 *
 * В wp-env контейнере: WOODEV_FRAMEWORK_DIR задаётся через config в .wp-env.json
 * и записывается в wp-config.php. Путь внутри контейнера: /var/www/html/woodev-framework
 *
 * Локально (unit-тесты): поднимаемся на два уровня из tests/_fixtures/woodev-test-payment-gateway/
 * к корню проекта, где лежит папка woodev/.
 */
if ( defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
	$framework_dir = WOODEV_FRAMEWORK_DIR;
} else {
	$framework_dir = dirname( __DIR__, 2 );
}

$framework_bootstrap = $framework_dir . '/woodev/bootstrap.php';

if ( ! file_exists( $framework_bootstrap ) ) {
	return;
}

if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
	require_once $framework_bootstrap;
}

/**
 * Регистрируем тестовый плагин в бутстрапе фреймворка.
 */

Woodev_Plugin_Bootstrap::instance()->register_plugin(
	'1.4.0',
	'Woodev Test Payment Gateway Plugin',
	__FILE__,
	'woodev_test_payment_gateway_plugin_init',
	[
		'minimum_wc_version'   => '5.6',
		'minimum_wp_version'   => '5.9',
		'backwards_compatible' => '1.4.0',
		'is_payment_gateway'   => true
	]
);

function woodev_test_payment_gateway_plugin_init(): void {

	if( ! class_exists( 'Woodev_Test_Payment_Gateway_Plugin' ) ) {
		final class Woodev_Test_Payment_Gateway_Plugin extends Woodev_Payment_Gateway_Plugin {


			/** @var string уникальный идентификатор плагина */
			const PLUGIN_ID = 'woodev-test-payment-gateway-plugin';

			/** @var string версия плагина */
			const VERSION = '1.0.0';

			public function __construct() {

				parent::__construct(
					self::PLUGIN_ID,
					self::VERSION,
					[
						'text_domain' => 'woodev-test-payment-gateway-plugin',
						'gateways'    => [
							'woodev_test_gateway'        => Woodev_Test_Payment_Gateway::class,
							'woodev_test_hosted_gateway' => Woodev_Test_Hosted_Payment_Gateway::class,
							'woodev_test_direct_gateway' => Woodev_Test_Direct_Payment_Gateway::class
						],
						'currencies'  => [ 'RUB', 'USD' ]
					]
				);
			}

			public static function instance(): Woodev_Test_Payment_Gateway_Plugin {
				return self::$instance ??= new self();
			}

			public function init_plugin() {
				require_once $this->get_plugin_path() . '/includes/class-woodev-test-payment-gateway.php';
				require_once $this->get_plugin_path() . '/includes/class-woodev-test-hosted-payment-gateway.php';
				require_once $this->get_plugin_path() . '/includes/class-woodev-test-direct-payment-gateway.php';
			}

			protected function get_file(): string {
				return __FILE__;
			}

			public function get_plugin_name(): string {
				return 'Woodev Test Payment Gateway Plugin';
			}

			public function get_download_id(): int {
				return 0;
			}

			public function get_documentation_url(): string {
				return 'https://woodev.ru';
			}

			public function get_settings_url( $plugin_id = null ): string {
				return add_query_arg( [
					'page' => 'wc-settings',
				], admin_url( 'admin.php' ) );
			}
		}
	}

	function woodev_test_payment_gateway_plugin(): Woodev_Test_Payment_Gateway_Plugin {
		return Woodev_Test_Payment_Gateway_Plugin::instance();
	}

	woodev_test_payment_gateway_plugin();
}
