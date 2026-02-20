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
 * Подключаем фреймворк напрямую из папки woodev/
 * Путь: от этого файла → .. (fixtures) → .. (tests) → .. (корень проекта) → woodev/
 */
$framework_bootstrap = dirname( __DIR__, 2 ) . '/woodev/bootstrap.php';

if ( ! file_exists( $framework_bootstrap ) ) {
	return;
}

if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
	require_once $framework_bootstrap;
}

// Подключаем класс тестового плагина
require_once __DIR__ . '/class-woodev-test-plugin.php';

/**
 * Регистрируем тестовый плагин в бутстрапе фреймворка.
 */
add_action( 'plugins_loaded', 'woodev_register_test_plugin', 0 );

function woodev_register_test_plugin(): void {
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
}

/**
 * Фабричная функция — инициализирует тестовый плагин.
 *
 * @return Woodev_Test_Plugin
 */
function woodev_test_plugin_init(): Woodev_Test_Plugin {
	return Woodev_Test_Plugin::instance();
}

/**
 * Глобальный хелпер для доступа к тестовому плагину из тестов.
 *
 * @return Woodev_Test_Plugin
 */
function woodev_test_plugin(): Woodev_Test_Plugin {
	return Woodev_Test_Plugin::instance();
}
