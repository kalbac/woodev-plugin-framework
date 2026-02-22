<?php
/**
 * PHPUnit Bootstrap File
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$test_suite = getenv( 'TEST_SUITE' ) ?: 'unit';

if ( 'integration' === $test_suite ) {
	bootstrap_integration_tests();
} else {
	bootstrap_unit_tests();
}

function bootstrap_unit_tests(): void {
	echo "Running Unit Tests (Brain Monkey)\n";
}

function bootstrap_integration_tests(): void {
	echo "Running Integration Tests (WP_UnitTestCase)\n";

	$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

	if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
		echo "ERROR: WordPress test library not found at {$wp_tests_dir}\n";
		echo "Make sure wp-env is running: npx wp-env start\n";
		exit( 1 );
	}

	require_once $wp_tests_dir . '/includes/functions.php';

	// Загружаем все три тестовых плагина
	tests_add_filter( 'muplugins_loaded', function() {
		$fixtures_dir = dirname( __DIR__ ) . '/tests/_fixtures';

		// 1. Общий плагин — базовый функционал фреймворка
		require_once $fixtures_dir . '/woodev-test-plugin/woodev-test-plugin.php';

		// 2. Тестовый платёжный шлюз
		require_once $fixtures_dir . '/woodev-test-payment-gateway/woodev-test-payment-gateway.php';

		// 3. Тестовый метод доставки
		require_once $fixtures_dir . '/woodev-test-shipping-method/woodev-test-shipping-method.php';
	} );

	require_once $wp_tests_dir . '/includes/bootstrap.php';
}
