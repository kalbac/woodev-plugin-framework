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
	];
}

/**
 * Best-effort resolves the display name of the plugin whose outdated (v1) framework
 * copy won the Woodev_Plugin_Bootstrap class rendezvous (B-1 / OB-1).
 *
 * Runs only on the mixed-fleet dormant path, so it relies on WordPress core +
 * reflection alone and never references a framework class — the loaded runtime is the
 * legacy v1 copy. Returns '' when the owner cannot be determined; the caller then
 * falls back to generic wording.
 *
 * @return string Conflicting plugin display name, or '' if undeterminable.
 */
function woodev_test_plugin_conflicting_framework_plugin_name(): string {
	if ( ! class_exists( 'Woodev_Plugin_Bootstrap', false ) || ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'wp_normalize_path' ) || ! function_exists( 'get_plugins' ) ) {
		return '';
	}

	try {
		$framework_file = ( new ReflectionClass( 'Woodev_Plugin_Bootstrap' ) )->getFileName();
	} catch ( ReflectionException $e ) {
		return '';
	}

	$plugins_dir = constant( 'WP_PLUGIN_DIR' );

	if ( ! is_string( $framework_file ) || '' === $framework_file || ! is_string( $plugins_dir ) || '' === $plugins_dir ) {
		return '';
	}

	$framework_file = wp_normalize_path( $framework_file );
	$plugins_dir    = wp_normalize_path( $plugins_dir );

	if ( 0 !== strpos( $framework_file, $plugins_dir . '/' ) ) {
		return '';
	}

	$relative = ltrim( substr( $framework_file, strlen( $plugins_dir ) ), '/' );
	$slug     = strstr( $relative . '/', '/', true );

	if ( ! is_string( $slug ) || '' === $slug ) {
		return '';
	}

	foreach ( get_plugins() as $plugin_file => $plugin_data ) {
		if ( 0 === strpos( (string) $plugin_file, $slug . '/' ) && ! empty( $plugin_data['Name'] ) ) {
			return (string) $plugin_data['Name'];
		}
	}

	return '';
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
			$this_plugin_name = esc_html__( 'Woodev Framework Test Plugin', 'woodev-plugin-framework' );

			// Best-effort: name the plugin whose outdated (v1) framework copy won the class
			// rendezvous. Uses ONLY WordPress core + reflection — never a framework class,
			// because here the loaded framework runtime is the legacy v1 copy (B-1 / OB-1).
			$conflicting_plugin_name = woodev_test_plugin_conflicting_framework_plugin_name();

			if ( '' !== $conflicting_plugin_name ) {
				$message = sprintf(
					/* translators: 1: this plugin name, 2: the conflicting plugin name. */
					esc_html__( 'Плагин %1$s не запущен: на сайте активен плагин %2$s с устаревшей версией фреймворка Woodev, которая мешает его загрузке. Обновите плагин %2$s до последней версии.', 'woodev-plugin-framework' ),
					'<strong>' . $this_plugin_name . '</strong>',
					'<strong>' . esc_html( $conflicting_plugin_name ) . '</strong>'
				);
			} else {
				$message = sprintf(
					/* translators: %s — this plugin name. */
					esc_html__( 'Плагин %s не запущен: на сайте активен другой плагин Woodev с устаревшей версией фреймворка. Обновите все плагины Woodev до последней версии.', 'woodev-plugin-framework' ),
					'<strong>' . $this_plugin_name . '</strong>'
				);
			}

			echo '<div class="error"><p>';
			echo wp_kses( $message, [ 'strong' => [] ] );
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
	 * Minimal setup wizard (one content step) so the woodev/v1 setup routes
	 * register for integration coverage.
	 */
	class Woodev_Test_Setup_Wizard extends \Woodev\Framework\Setup\Setup_Wizard {

		/**
		 * Registers a single content step.
		 *
		 * @return void
		 */
		protected function register_steps(): void {
			$this->register_content_step(
				'welcome',
				'Welcome',
				static function (): string {
					return '<p>Welcome</p>';
				}
			);
		}
	}

	/**
	 * Minimal settings handler for the «Карьер» reference provider.
	 */
	class Woodev_Test_Settings extends \Woodev_Abstract_Settings {

		/**
		 * Registers the reference settings.
		 *
		 * @return void
		 */
		protected function register_settings() {
			// Section «Общие» (general).
			$this->register_setting( 'api_key', \Woodev_Setting::TYPE_STRING, [ 'name' => 'API-ключ', 'default' => '' ] );
			$this->register_setting( 'mode', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Режим', 'options' => [ 'test' => 'Тест', 'live' => 'Боевой' ], 'default' => 'test' ] );
			$this->register_control( 'api_key', \Woodev_Control::TYPE_TEXT, [ 'tooltip' => 'Длинная подсказка для проверки того, что тултип отображается в портале и не обрезается за правым краем экрана, перенося текст на несколько строк.' ] );
			$this->register_control( 'mode', \Woodev_Control::TYPE_SELECT );

			// Section «Форма заказа» (order) — exercises every control type.
			$this->register_setting( 'enabled', \Woodev_Setting::TYPE_BOOLEAN, [ 'name' => 'Включить интеграцию', 'default' => true ] );
			$this->register_setting( 'markup', \Woodev_Setting::TYPE_INTEGER, [ 'name' => 'Наценка, %', 'default' => 15 ] );
			$this->register_setting( 'calc_type', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Тип расчёта', 'options' => [ 'fixed' => 'Фиксированная ставка', 'dynamic' => 'По тарифу перевозчика' ], 'default' => 'dynamic' ] );
			$this->register_setting( 'methods', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Способы доставки', 'is_multi' => true, 'options' => [ 'pickup' => 'Самовывоз', 'courier' => 'Курьер', 'post' => 'Почта', 'postamat' => 'Постамат' ], 'default' => [ 'pickup', 'courier' ] ] );
			$this->register_setting( 'max_weight', \Woodev_Setting::TYPE_INTEGER, [ 'name' => 'Макс. вес, кг', 'default' => 30 ] );
			$this->register_setting( 'comment', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Комментарий', 'default' => '' ] );
			$this->register_setting( 'note', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Описание для клиента', 'default' => '' ] );

			$this->register_control( 'enabled', \Woodev_Control::TYPE_TOGGLE, [ 'description' => 'Запросы идут к перевозчику.' ] );
			$this->register_control( 'markup', \Woodev_Control::TYPE_RANGE, [ 'min' => 0, 'max' => 100, 'step' => 5 ] );
			$this->register_control( 'calc_type', \Woodev_Control::TYPE_RADIO );
			$this->register_control( 'methods', \Woodev_Control::TYPE_MULTISELECT );
			$this->register_control( 'max_weight', \Woodev_Control::TYPE_NUMBER );
			$this->register_control( 'comment', \Woodev_Control::TYPE_TEXTAREA );
			$this->register_control( 'note', \Woodev_Control::TYPE_RICHTEXT );

			// Section «Прочее» (misc) — the remaining control types.
			$this->register_setting( 'manager_email', \Woodev_Setting::TYPE_EMAIL, [ 'name' => 'E-mail менеджера', 'default' => '' ] );
			$this->register_setting( 'secret', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Секретный токен', 'default' => '' ] );
			$this->register_setting( 'brand_color', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Цвет бренда', 'default' => '#06aedd' ] );
			$this->register_setting( 'start_date', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Дата старта', 'default' => '' ] );

			$this->register_control( 'manager_email', \Woodev_Control::TYPE_EMAIL );
			$this->register_control( 'secret', \Woodev_Control::TYPE_PASSWORD );
			$this->register_control( 'brand_color', \Woodev_Control::TYPE_COLOR );
			$this->register_control( 'start_date', \Woodev_Control::TYPE_DATE );
		}
	}

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

		/**
		 * Opts into the minimal setup wizard so the woodev/v1 setup routes register.
		 *
		 * @return \Woodev\Framework\Setup\Setup_Wizard
		 */
		protected function build_setup_wizard_handler() {
			return new Woodev_Test_Setup_Wizard( $this );
		}

		/** @var Woodev_Test_Settings|null reference settings handler */
		private $settings_handler;

		/**
		 * Lazily builds the reference settings handler (shared by wizard + page).
		 *
		 * @return Woodev_Test_Settings
		 */
		public function get_settings_handler() {
			if ( null === $this->settings_handler ) {
				$this->settings_handler = new Woodev_Test_Settings( $this->get_id() );
			}

			return $this->settings_handler;
		}

		/**
		 * Contributes the «Карьер» reference tab to the settings page.
		 *
		 * @return \Woodev\Framework\Settings\Settings_Provider[]
		 */
		public function get_settings_providers(): array {
			return [
				\Woodev\Framework\Settings\Settings_Provider::create(
					'quarry',
					'Карьер',
					$this->get_settings_handler(),
					[
						\Woodev\Framework\Settings\Settings_Section::create( 'general', 'Общие', [ 'api_key', 'mode' ], 'Основные параметры подключения к API перевозчика.' ),
						\Woodev\Framework\Settings\Settings_Section::create( 'order', 'Форма заказа', [ 'enabled', 'markup', 'calc_type', 'methods', 'max_weight', 'comment', 'note' ], 'Как тариф и способы доставки отображаются покупателю при оформлении.' ),
						\Woodev\Framework\Settings\Settings_Section::create( 'misc', 'Прочее', [ 'manager_email', 'secret', 'brand_color', 'start_date' ] ),
					],
					[
						'legacy_page' => 'wc-settings&tab=shipping&section=quarry',
					]
				),
			];
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
