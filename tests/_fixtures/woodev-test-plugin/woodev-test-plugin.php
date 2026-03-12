<?php
/**
 * Plugin Name: Woodev Framework Test Plugin
 * Plugin URI:  https://github.com/woodev/plugin-framework
 * Description: Fixture plugin for Woodev Framework testing. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-test-plugin
 *
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * WC requires at least: 5.6
 *
 * @package Woodev_Test_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Определяем корневую директорию фреймворка.
 *
 * В wp-env контейнере: WOODEV_FRAMEWORK_DIR задаётся через config в .wp-env.json
 * и записывается в wp-config.php. Путь внутри контейнера: /var/www/html/woodev-framework
 *
 * Локально (unit-тесты): поднимаемся на два уровня из tests/_fixtures/woodev-test-plugin/
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
	'Woodev Test Plugin',
	__FILE__,
	'woodev_test_plugin_init',
	[
		'minimum_wc_version'   => '5.6',
		'minimum_wp_version'   => '5.9',
		'backwards_compatible' => '1.4.0',
	]
);

/**
 * Фабричная функция — инициализирует тестовый плагин.
 *
 */
function woodev_test_plugin_init() {

	/**
	 * Class Woodev_Test_Plugin
	 */
	class Woodev_Test_Plugin extends Woodev_Plugin {

		/** @var string уникальный идентификатор плагина */
		const PLUGIN_ID = 'woodev-test-plugin';

		/** @var string версия плагина */
		const VERSION = '1.0.0';

		/** @var Woodev_Test_Plugin единственный экземпляр */
		protected static $instance;

		/**
		 * Инициализация плагина.
		 */
		public function __construct() {
			parent::__construct(
				self::PLUGIN_ID,
				self::VERSION,
				[
					'text_domain' => 'woodev-test-plugin',
				]
			);
		}

		/**
		 * Singleton.
		 *
		 * @return Woodev_Test_Plugin
		 */
		public static function instance(): Woodev_Test_Plugin {
			return self::$instance ??= new self();
		}

		/**
		 * Возвращает URL до папки плагина.
		 *
		 * @return string
		 */
		public function get_plugin_url(): string {
			return plugin_dir_url( $this->get_plugin_path() );
		}

		protected function get_file(): string {
			return __FILE__;
		}

		public function get_plugin_name(): string {
			return 'Woodev Framework Test Plugin';
		}

		public function get_download_id(): int {
			return 0;
		}
	}

	/**
	 * Глобальный хелпер для доступа к тестовому плагину из тестов.
	 *
	 * @return Woodev_Test_Plugin
	 */
	function woodev_test_plugin(): Woodev_Test_Plugin {
		return Woodev_Test_Plugin::instance();
	}

	woodev_test_plugin();
}
