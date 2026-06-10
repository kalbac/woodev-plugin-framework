<?php
/**
 * Plugin Name: Woodev Framework Test Plugin
 * Plugin URI:  https://github.com/woodev/plugin-framework
 * Description: Fixture plugin for Woodev Framework testing. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-test-plugin
 *
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * WC requires at least: 7.0
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
 * Возвращает явное определение загрузчика Platform v2 для тестового плагина.
 *
 * @return array<string,mixed>
 */
function woodev_test_plugin_loader_definition(): array {
	return [
		'plugin_id'         => 'woodev-test-plugin',
		'plugin_name'       => 'Woodev Test Plugin',
		'plugin_version'    => '1.0.0',
		'framework_version' => '1.4.0',
		'plugin_file'       => __FILE__,
		'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.3',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'Woodev_Test_Plugin',
		'callback'          => 'woodev_test_plugin_init',
		'capabilities'      => [
			\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_WORDPRESS_PLUGIN,
		],
	];
}

/**
 * Регистрируем тестовый плагин в бутстрапе фреймворка.
 *
 * Mixed-fleet probe (B-1): на сайте, где соседствуют v2-переписанный и ещё-v1 плагин,
 * WordPress грузит плагины по алфавиту, и первая vendored-копия, определившая
 * Woodev_Plugin_Bootstrap, выигрывает rendezvous. Если выиграла легаси (v1) копия — у неё
 * нет register_loader_definition(). Зондируем метод: если его нет, остаёмся в спячке,
 * показываем предупреждение и выходим — никакого фатала.
 */
$woodev_test_plugin_bootstrap = Woodev_Plugin_Bootstrap::instance();
if ( ! method_exists( $woodev_test_plugin_bootstrap, 'register_loader_definition' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="error"><p>';
			echo wp_kses(
				sprintf(
					/* translators: %s — plugin name. */
					esc_html__( 'Плагин %s не запущен: загруженная версия фреймворка устарела. Обновите плагин или фреймворк.', 'woodev-plugin-framework' ),
					'<strong>' . esc_html__( 'Woodev Framework Test Plugin', 'woodev-plugin-framework' ) . '</strong>'
				),
				[ 'strong' => [] ]
			);
			echo '</p></div>';
		}
	);
	return;
}
$woodev_test_plugin_bootstrap->register_loader_definition( woodev_test_plugin_loader_definition() );

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
