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
	 * RIG DEMO settings handler — all supported control types for the wizard demo.
	 *
	 * Step «Подключение»: text+tooltip, select, radio, number+tooltip, range, toggle×2, richtext.
	 * Step «Доставка»:    multiselect, number×3, toggle, select.
	 *
	 * (Rig-only scaffolding — reverted before the framework PR.)
	 */
	class Woodev_Test_Settings extends \Woodev_Abstract_Settings {

		/**
		 * Registers all demo settings and their controls.
		 *
		 * @return void
		 */
		protected function register_settings() {

			// ----------------------------------------------------------------
			// Step: connection — Подключение
			// ----------------------------------------------------------------

			// 1. text + tooltip
			$this->register_setting(
				'api_key',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'        => 'API-ключ',
					'description' => 'Найдите ключ в кабинете перевозчика → Интеграции → API.',
				]
			);
			$this->register_control(
				'api_key',
				'text',
				[
					'tooltip' => 'Секретный ключ из личного кабинета перевозчика. Хранится в зашифрованном виде и не передаётся третьим лицам.',
				]
			);

			// 2. select
			$this->register_setting(
				'default_tariff',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'        => 'Тариф по умолчанию',
					'description' => 'Используется, если для зоны не задан отдельный тариф.',
					'options'     => [
						'courier' => 'Курьер до двери',
						'pickup'  => 'Пункт выдачи',
						'locker'  => 'Постамат',
					],
					'default'     => 'courier',
				]
			);
			$this->register_control( 'default_tariff', 'select' );

			// 3. radio — 3 options with sub-text labels
			$this->register_setting(
				'calc_mode',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'        => 'Режим расчёта стоимости',
					'description' => 'Как плагин считает цену доставки для покупателя.',
					'options'     => [
						'api'      => 'По тарифам перевозчика',
						'fixed'    => 'Фиксированная ставка',
						'freefrom' => 'Бесплатно от суммы заказа',
					],
					'default'     => 'api',
				]
			);
			$this->register_control(
				'calc_mode',
				'radio',
				[
					'options' => [
						'api'      => 'По тарифам перевозчика|Стоимость запрашивается у API в реальном времени',
						'fixed'    => 'Фиксированная ставка|Единая цена за доставку независимо от веса',
						'freefrom' => 'Бесплатно от суммы заказа|Порог задаётся ниже',
					],
				]
			);

			// 4. number + tooltip
			$this->register_setting(
				'free_shipping_threshold',
				\Woodev_Setting::TYPE_INTEGER,
				[
					'name'        => 'Бесплатная доставка от',
					'description' => 'Заказы дороже этой суммы получают бесплатную доставку выбранным тарифом.',
					'default'     => 5000,
				]
			);
			$this->register_control(
				'free_shipping_threshold',
				'number',
				[
					'tooltip' => 'Заказы дороже этой суммы получают бесплатную доставку выбранным тарифом.',
					'min'     => 0,
					'step'    => 100,
				]
			);

			// 5. range with min / max / step
			$this->register_setting(
				'markup_percent',
				\Woodev_Setting::TYPE_FLOAT,
				[
					'name'        => 'Наценка к тарифу перевозчика',
					'description' => 'Добавляется к цене доставки от API — например, на упаковку.',
					'default'     => 15,
				]
			);
			$this->register_control(
				'markup_percent',
				'range',
				[
					'min'  => 0,
					'max'  => 100,
					'step' => 5,
				]
			);

			// 6. toggle — COD (toggle group: два boolean подряд)
			$this->register_setting(
				'cod_enabled',
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'        => 'Наложенный платёж',
					'description' => 'Разрешить оплату при получении для этого способа доставки.',
					'default'     => false,
				]
			);
			$this->register_control( 'cod_enabled', 'toggle' );

			// 7. toggle — sandbox
			$this->register_setting(
				'sandbox_mode',
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'        => 'Тестовый режим (sandbox)',
					'description' => 'Запросы уходят на тестовый контур перевозчика, реальные отправления не создаются.',
					'default'     => false,
				]
			);
			$this->register_control( 'sandbox_mode', 'toggle' );

			// 8. richtext
			$this->register_setting(
				'checkout_notice',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'        => 'Примечание на странице оформления заказа',
					'description' => 'Покажется покупателю под выбором способа доставки.',
				]
			);
			$this->register_control( 'checkout_notice', 'richtext' );

			// ----------------------------------------------------------------
			// Step: delivery — Доставка
			// ----------------------------------------------------------------

			// 9. multiselect (is_multi)
			$this->register_setting(
				'delivery_methods',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'        => 'Доступные способы доставки',
					'description' => 'Будут предложены покупателю на странице оформления заказа.',
					'is_multi'    => true,
					'options'     => [
						'courier' => 'Курьер до двери',
						'pickup'  => 'Пункт выдачи',
						'locker'  => 'Постамат',
					],
					'default'     => [ 'courier', 'pickup', 'locker' ],
				]
			);
			$this->register_control( 'delivery_methods', 'multiselect' );

			// 10. number — вес упаковки (float)
			$this->register_setting(
				'box_weight',
				\Woodev_Setting::TYPE_FLOAT,
				[
					'name'        => 'Вес пустой упаковки',
					'description' => 'Прибавляется к весу товаров при расчёте тарифа.',
					'default'     => 0.3,
				]
			);
			$this->register_control(
				'box_weight',
				'number',
				[
					'min'  => 0,
					'step' => 0.1,
				]
			);

			// 11. number — длина коробки
			$this->register_setting(
				'box_length',
				\Woodev_Setting::TYPE_INTEGER,
				[
					'name'        => 'Длина коробки по умолчанию',
					'description' => 'Используются для расчёта тарифа, если у товара не заданы свои размеры.',
					'default'     => 30,
				]
			);
			$this->register_control(
				'box_length',
				'number',
				[
					'tooltip' => 'Используются для расчёта тарифа, если у товара не заданы свои размеры.',
					'min'     => 1,
				]
			);

			// 12. number — ширина коробки
			$this->register_setting(
				'box_width',
				\Woodev_Setting::TYPE_INTEGER,
				[
					'name'    => 'Ширина коробки по умолчанию',
					'default' => 20,
				]
			);
			$this->register_control( 'box_width', 'number', [ 'min' => 1 ] );

			// 13. toggle — показывать срок доставки
			$this->register_setting(
				'show_delivery_time',
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'        => 'Показывать срок доставки покупателю',
					'description' => 'Под способом доставки выводится ориентировочный срок из ответа API.',
					'default'     => true,
				]
			);
			$this->register_control( 'show_delivery_time', 'toggle' );

			// 14. select — регион расчёта
			$this->register_setting(
				'default_region',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'        => 'Регион расчёта по умолчанию',
					'description' => 'Откуда отправляются заказы — для предварительного расчёта.',
					'options'     => [
						'msk' => 'Москва',
						'spb' => 'Санкт-Петербург',
						'ekb' => 'Екатеринбург',
						'nsk' => 'Новосибирск',
					],
					'default'     => 'msk',
				]
			);
			$this->register_control( 'default_region', 'select' );
		}
	}

	/**
	 * RIG DEMO setup wizard — multi-step covering all control types.
	 *
	 * Logo URL: returns '' intentionally — the text-brand fallback renders the plugin
	 * name and is safe for offline rigs. A remote URL (e.g. https://woodev.ru/…/logo.png)
	 * would show the brand image only when the rig has internet access; esc_url_raw() strips
	 * data: URIs (they contain commas which WP sanitises away), so inline SVG is not viable.
	 * For a networked rig, replace '' with the HTTPS logo URL directly.
	 *
	 * (Rig-only scaffolding — reverted before the framework PR.)
	 */
	class Woodev_Test_Setup_Wizard extends \Woodev\Framework\Setup\Setup_Wizard {

		/**
		 * Registers all demo steps.
		 *
		 * @return void
		 */
		protected function register_steps(): void {

			// Content step: Welcome.
			$this->register_content_step(
				'welcome',
				'Добро пожаловать',
				static function (): string {
					return '<p>Этот мастер за пару минут подключит плагин к API перевозчика и задаст базовые параметры расчёта и упаковки. Все настройки можно изменить позже на странице плагина.</p>'
						. '<ul>'
						. '<li>Подключение к API перевозчика по ключу</li>'
						. '<li>Расчёт стоимости и упаковка заказов</li>'
						. '<li>Автоматический трекинг статусов доставки</li>'
						. '</ul>';
				},
				'Настройка занимает около двух минут.'
			);

			// Settings step: connection — text, select, radio, number, range, toggle×2, richtext.
			$this->register_step(
				'connection',
				'Подключение',
				[
					'api_key',
					'default_tariff',
					'calc_mode',
					'free_shipping_threshold',
					'markup_percent',
					'cod_enabled',
					'sandbox_mode',
					'checkout_notice',
				],
				null,
				'Укажите данные доступа к API. Их можно найти в личном кабинете перевозчика в разделе «Интеграции».'
			);

			// Settings step: delivery — multiselect, number×3, toggle, select.
			$this->register_step(
				'delivery',
				'Доставка',
				[
					'delivery_methods',
					'box_weight',
					'box_length',
					'box_width',
					'show_delivery_time',
					'default_region',
				],
				null,
				'Базовые правила упаковки и набор способов доставки, доступных покупателю. Их можно детально настроить позже.'
			);

			// Terminal finish step is auto-appended by the framework — not registered here.
		}

		/**
		 * Returns '' so the text-brand fallback renders safely on offline rigs.
		 *
		 * To show the Woodev logo on a networked rig, replace with:
		 *   return 'https://woodev.ru/wp-content/uploads/woodev-logo.png';
		 *
		 * Note: esc_url_raw() strips data: URIs (commas are sanitised), so inline
		 * SVG/PNG data URIs cannot be used here.
		 *
		 * @return string
		 */
		protected function get_header_image_url(): string {
			return '';
		}

		/**
		 * Finish-screen primary actions.
		 *
		 * @return array<int,array<string,string>>
		 */
		protected function get_finish_actions(): array {
			return [
				[
					'heading'     => 'Следующий шаг',
					'title'       => 'Страница настроек',
					'description' => 'Тонкая настройка тарифов, зон доставки и упаковки.',
					'actionLabel' => 'Перейти',
					'url'         => admin_url( 'admin.php?page=wc-settings' ),
				],
				[
					'heading'     => 'Документация',
					'title'       => 'Справочное руководство',
					'description' => 'Подробнее о возможностях плагина.',
					'actionLabel' => 'Читать',
					'url'         => 'https://woodev.ru/',
				],
			];
		}

		/**
		 * Finish-screen secondary "also" actions (settings / review icon buttons).
		 *
		 * @return array<int,array<string,string>>
		 */
		protected function get_finish_secondary_actions(): array {
			return [
				[
					'label' => 'Перейти к настройкам',
					'icon'  => 'settings',
					'url'   => admin_url( 'admin.php?page=wc-settings' ),
				],
				[
					'label' => 'Оставить отзыв',
					'icon'  => 'review',
					'url'   => 'https://woodev.ru/',
				],
			];
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
		 * Opts into the full-featured demo setup wizard.
		 *
		 * @return \Woodev\Framework\Setup\Setup_Wizard
		 */
		protected function build_setup_wizard_handler() {
			return new Woodev_Test_Setup_Wizard( $this );
		}

		/** @var Woodev_Test_Settings|null rig-demo settings handler. */
		private $woodev_test_settings;

		/**
		 * RIG DEMO settings handler (rig-only; reverted before the framework PR).
		 *
		 * @return Woodev_Test_Settings
		 */
		public function get_settings_handler() {
			if ( null === $this->woodev_test_settings ) {
				$this->woodev_test_settings = new Woodev_Test_Settings( self::PLUGIN_ID );
			}

			return $this->woodev_test_settings;
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
