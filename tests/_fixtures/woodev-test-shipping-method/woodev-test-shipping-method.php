<?php
/**
 * Plugin Name: Woodev Test Shipping Method
 * Description: Fixture shipping method for Woodev Framework testing. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-test-shipping-method
 *
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * WC requires at least: 5.6
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

/**
 * Определяем корневую директорию фреймворка.
 *
 * В wp-env контейнере: WOODEV_FRAMEWORK_DIR задаётся через config в .wp-env.json
 * и записывается в wp-config.php. Путь внутри контейнера: /var/www/html/woodev-framework
 *
 * Локально (unit-тесты): поднимаемся на два уровня из tests/_fixtures/woodev-test-shipping-method/
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
 * Регистрируем тестовый плагин метода доставки в бутстрапе фреймворка.
 */
Woodev_Plugin_Bootstrap::instance()->register_plugin(
	'1.4.0',
	'Woodev Test Shipping Method Plugin',
	__FILE__,
	'woodev_test_shipping_method_plugin_init',
	[
		'minimum_wc_version'   => '5.6',
		'minimum_wp_version'   => '5.9',
		'backwards_compatible' => '1.4.0',
		'load_shipping_method' => true,
	]
);

/**
 * Фабричная функция — инициализирует тестовый плагин метода доставки.
 */
function woodev_test_shipping_method_plugin_init(): void {

	if ( ! class_exists( 'Woodev_Test_Shipping_Method_Plugin' ) ) {

		/**
		 * Class Woodev_Test_Shipping_Method_Plugin
		 */
		final class Woodev_Test_Shipping_Method_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin {

			/** @var Woodev_Test_Shipping_Method_Plugin|null единственный экземпляр */
			protected static $instance;

			/** @var string уникальный идентификатор плагина */
			const PLUGIN_ID = 'woodev-test-shipping-method';

			/** @var string версия плагина */
			const VERSION = '1.0.0';

			/**
			 * Конструктор.
			 */
			public function __construct() {
				parent::__construct(
					self::PLUGIN_ID,
					self::VERSION,
					[
						'text_domain'      => 'woodev-test-shipping-method',
						'shipping_methods' => [
							'woodev_test_shipping' => 'Woodev_Test_Shipping_Method',
						],
					]
				);
			}

			/**
			 * Singleton.
			 *
			 * @return Woodev_Test_Shipping_Method_Plugin
			 */
			public static function instance(): Woodev_Test_Shipping_Method_Plugin {
				return self::$instance ??= new self();
			}

			/**
			 * Инициализация плагина — подключаем класс метода доставки.
			 */
			public function init_plugin(): void {
				require_once $this->get_plugin_path() . '/class-woodev-test-shipping-method.php';
			}

			/**
			 * @inheritDoc
			 */
			protected function get_file(): string {
				return __FILE__;
			}

			/**
			 * @inheritDoc
			 */
			public function get_plugin_name(): string {
				return 'Woodev Test Shipping Method Plugin';
			}

			/**
			 * @inheritDoc
			 */
			public function get_download_id(): int {
				return 0;
			}

			/**
			 * @inheritDoc
			 */
			protected function get_shipping_method_classes(): array {
				return [
					'woodev_test_shipping' => 'Woodev_Test_Shipping_Method',
				];
			}

			/**
			 * @inheritDoc
			 */
			public function get_api(): ?\Woodev\Framework\Shipping\Shipping_API {
				return null;
			}
		}
	}

	/**
	 * Глобальный хелпер для доступа к тестовому плагину из тестов.
	 *
	 * @return Woodev_Test_Shipping_Method_Plugin
	 */
	function woodev_test_shipping_method_plugin(): Woodev_Test_Shipping_Method_Plugin {
		return Woodev_Test_Shipping_Method_Plugin::instance();
	}

	woodev_test_shipping_method_plugin();
}
