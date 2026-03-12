<?php
/**
 * PHPUnit Bootstrap File
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$test_suite = getenv( 'TEST_SUITE' ) ?: 'unit';

if ( 'integration' === $test_suite ) {
	bootstrap_integration_tests();
} else {
	// Unit tests need ABSPATH defined (no WordPress loaded).
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	bootstrap_unit_tests();
}

function bootstrap_unit_tests(): void {
	echo "Running Unit Tests (Brain Monkey)\n";
}

function bootstrap_integration_tests(): void {
	echo "Running Integration Tests (WP_UnitTestCase)\n";

	// Prefer composer-managed wp-phpunit, fall back to wp-env container path.
	$composer_wp_phpunit = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	$wp_tests_dir       = getenv( 'WP_TESTS_DIR' )
		?: ( is_dir( $composer_wp_phpunit ) ? $composer_wp_phpunit : '/wordpress-phpunit' );

	if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
		echo "ERROR: WordPress test library not found at {$wp_tests_dir}\n";
		echo "Make sure wp-env is running: npx wp-env start\n";
		exit( 1 );
	}

	require_once $wp_tests_dir . '/includes/functions.php';

	// Активируем WooCommerce перед загрузкой наших тестовых плагинов.
	// Это гарантирует что WC_VERSION определена и WC классы доступны.
	tests_add_filter( 'muplugins_loaded', function() {

		// Загружаем WooCommerce.
		$wc_plugin = WP_PLUGIN_DIR . '/woocommerce.latest-stable/woocommerce.php';
		if ( ! file_exists( $wc_plugin ) ) {
			$wc_plugin = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}
		if ( file_exists( $wc_plugin ) ) {
			require_once $wc_plugin;
		}

		$fixtures_dir = dirname( __DIR__ ) . '/tests/_fixtures';

		// 1. Общий плагин — базовый функционал фреймворка.
		require_once $fixtures_dir . '/woodev-test-plugin/woodev-test-plugin.php';

		// 2. Тестовый платёжный шлюз.
		require_once $fixtures_dir . '/woodev-test-payment-gateway/woodev-test-payment-gateway.php';

		// 3. Тестовый метод доставки.
		require_once $fixtures_dir . '/woodev-test-shipping-method/woodev-test-shipping-method.php';
	} );

	require_once $wp_tests_dir . '/includes/bootstrap.php';
}
