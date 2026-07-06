<?php
/**
 * Plugin Name: Woodev Test Shipping Method
 * Description: Fixture shipping method for Woodev Framework testing. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-test-shipping-method
 *
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * WC requires at least: 7.0
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
 * Возвращает явное определение загрузчика Platform v2 для тестового метода доставки.
 *
 * @return array<string,mixed>
 */
function woodev_test_shipping_method_plugin_loader_definition(): array {
	return [
		'plugin_id'         => 'woodev-test-shipping-method',
		'plugin_name'       => 'Woodev Test Shipping Method Plugin',
		'plugin_version'    => '1.0.0',
		'framework_version' => '1.4.0',
		'plugin_file'       => __FILE__,
		'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.3',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'Woodev_Test_Shipping_Method_Plugin',
		'callback'          => 'woodev_test_shipping_method_plugin_init',
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
function woodev_test_shipping_method_conflicting_framework_plugin_name(): string {
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
 * Регистрируем тестовый плагин метода доставки в бутстрапе фреймворка.
 *
 * Mixed-fleet probe (B-1): на сайте, где соседствуют v2-переписанный и ещё-v1 плагин,
 * WordPress грузит плагины по алфавиту, и первая vendored-копия, определившая
 * Woodev_Plugin_Bootstrap, выигрывает rendezvous. Если выиграла легаси (v1) копия — у неё
 * нет register_loader_definition(). Зондируем метод: если его нет, остаёмся в спячке,
 * показываем предупреждение и выходим — никакого фатала.
 */
$woodev_test_shipping_method_bootstrap = Woodev_Plugin_Bootstrap::instance();
if ( ! method_exists( $woodev_test_shipping_method_bootstrap, 'register_loader_definition' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			$this_plugin_name = esc_html__( 'Woodev Test Shipping Method Plugin', 'woodev-plugin-framework' );

			// Best-effort: name the plugin whose outdated (v1) framework copy won the class
			// rendezvous. Uses ONLY WordPress core + reflection — never a framework class,
			// because here the loaded framework runtime is the legacy v1 copy (B-1 / OB-1).
			$conflicting_plugin_name = woodev_test_shipping_method_conflicting_framework_plugin_name();

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
$woodev_test_shipping_method_bootstrap->register_loader_definition( woodev_test_shipping_method_plugin_loader_definition() );

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

			// -----------------------------------------------------------------
			// Checkout handler seam — demo fields for Task 12 fixture wiring.
			// -----------------------------------------------------------------

			/**
			 * Cached checkout handler instance.
			 *
			 * @since 2.0.2
			 *
			 * @var \Woodev\Framework\Shipping\Checkout\Checkout_Handler|null
			 */
			private ?\Woodev\Framework\Shipping\Checkout\Checkout_Handler $checkout_handler_instance = null;

			/**
			 * Returns a Checkout_Handler configured with a small demo field set.
			 *
			 * Three fields are wired to exercise every major branch of the layer:
			 *  1. `billing_state`        — root select with static regions, RU/BY/KZ/UZ takeover.
			 *  2. `billing_city`         — dependent suggest filtered by region + query string.
			 *  3. `carrier_pickup_point` — hidden pickup-slot required when the fixture
			 *                              shipping method is chosen.
			 *
			 * Domain data (regions, cities, method ids) lives here in the fixture;
			 * the framework stays generic.
			 *
			 * @since 2.0.2
			 *
			 * @return \Woodev\Framework\Shipping\Checkout\Checkout_Handler
			 */
			public function get_checkout_handler(): \Woodev\Framework\Shipping\Checkout\Checkout_Handler {

				if ( null !== $this->checkout_handler_instance ) {
					return $this->checkout_handler_instance;
				}

				$fields = \Woodev\Framework\Shipping\Checkout\Checkout_Fields::from_array(
					[
						// 1. Root region select — takes over `billing_state` for CIS countries.
						\Woodev\Framework\Shipping\Checkout\Field::create( 'billing_state' )
							->set_type( 'select' )
							->set_label( 'Регион' )
							->set_section( 'billing' )
							->set_required( true )
							->set_source(
								static function ( array $context ): array {
									if ( 'RU' !== ( $context['country'] ?? '' ) ) {
										return [];
									}
									return [
										[ 'value' => '77', 'label' => 'Москва' ],
										[ 'value' => '78', 'label' => 'Санкт-Петербург' ],
										[ 'value' => '23', 'label' => 'Краснодарский край' ],
									];
								},
								'options'
							)
							->set_takeover_condition(
								static function ( array $context ): bool {
									return in_array( $context['country'] ?? '', [ 'RU', 'BY', 'KZ', 'UZ' ], true );
								}
							),

						// 2. Dependent city suggest — driven by parent region + free-text query.
						\Woodev\Framework\Shipping\Checkout\Presets\Dependent_Select::create( 'billing_city', 'billing_state' )
							->set_label( 'Город' )
							->set_section( 'billing' )
							->set_required( true )
							->set_source(
								static function ( array $context ): array {
									$cities_by_region = [
										'77' => [ 'Москва', 'Зеленоград', 'Троицк' ],
										'78' => [ 'Санкт-Петербург', 'Кронштадт', 'Пушкин' ],
										'23' => [ 'Краснодар', 'Сочи', 'Новороссийск' ],
									];

									$region = (string) ( $context['parent'] ?? '' );
									$query  = mb_strtolower( (string) ( $context['q'] ?? '' ) );

									$candidates = $cities_by_region[ $region ] ?? [];

									if ( '' === $query ) {
										return array_map(
											static fn( string $c ) => [ 'value' => $c, 'label' => $c ],
											$candidates
										);
									}

									$result = [];
									foreach ( $candidates as $city ) {
										if ( false !== mb_stripos( $city, $query ) ) {
											$result[] = [ 'value' => $city, 'label' => $city ];
										}
									}
									return $result;
								},
								'suggest'
							),

						// 3. Hidden pickup-point slot — required when the fixture method is chosen.
						\Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create(
							'carrier_pickup_point',
							[ \Woodev_Test_Shipping_Method::METHOD_ID ]
						),
					]
				);

				$handler = new \Woodev\Framework\Shipping\Checkout\Checkout_Handler(
					$fields,
					self::PLUGIN_ID
				);
				$handler->set_requires_pickup_methods( [ \Woodev_Test_Shipping_Method::METHOD_ID ] );

				$this->checkout_handler_instance = $handler;
				return $this->checkout_handler_instance;
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
