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

	// Each test-fixture plugin is a stand-in for a real plugin that BUNDLES its own woodev/
	// framework copy: the platform-v2 resolver loads the selected plugin's
	// {plugin_dir}/woodev/class-plugin.php (woodev/class-framework-resolver.php). The fixtures
	// do not commit a copy, so link the repo's woodev/ into each one before WordPress loads the
	// plugins. (Unit tests use a testable resolver that overrides get_plugin_path(), so they
	// don't need this; only the real integration load path does.)
	woodev_link_framework_into_fixtures();

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

			// Simulate WooCommerce being activated so that Woodev_Helper::is_woocommerce_active()
			// returns true. In the WP test environment plugins are loaded via require_once, not
			// through WordPress activation, so active_plugins option is empty by default.
			$active_plugins = (array) get_option( 'active_plugins', [] );
			if ( ! in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) ) {
				$active_plugins[] = 'woocommerce/woocommerce.php';
				update_option( 'active_plugins', $active_plugins );
			}
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

/**
 * Links the repository's woodev/ framework copy into each integration test fixture.
 *
 * The platform-v2 resolver loads the selected plugin's bundled
 * {plugin_dir}/woodev/class-plugin.php; the fixtures don't commit that copy, so symlink the
 * repo's woodev/ into each one (idempotent; skipped once a real copy exists). Falls back to a
 * recursive copy if symlinks are unavailable.
 */
function woodev_link_framework_into_fixtures(): void {
	$repo_woodev  = dirname( __DIR__ ) . '/woodev';
	$fixtures_dir = dirname( __DIR__ ) . '/tests/_fixtures';

	$fixtures = [
		'woodev-test-plugin',
		'woodev-test-payment-gateway',
		'woodev-test-shipping-method',
	];

	foreach ( $fixtures as $fixture ) {
		$link = $fixtures_dir . '/' . $fixture . '/woodev';

		// Already provides a framework copy — nothing to do.
		if ( file_exists( $link . '/class-plugin.php' ) ) {
			continue;
		}

		// Remove an empty placeholder directory left in the working tree.
		if ( is_dir( $link ) && ! is_link( $link ) ) {
			@rmdir( $link );
		}

		if ( file_exists( $link ) ) {
			continue;
		}

		if ( @symlink( $repo_woodev, $link ) ) {
			continue;
		}

		// Symlinks unavailable: fall back to a recursive copy.
		woodev_recursive_copy( $repo_woodev, $link );
	}
}

/**
 * Recursively copies a directory (fallback for environments without symlink support).
 *
 * @param string $source source directory
 * @param string $target target directory
 */
function woodev_recursive_copy( string $source, string $target ): void {
	if ( ! is_dir( $source ) ) {
		return;
	}

	if ( ! is_dir( $target ) && ! mkdir( $target, 0777, true ) && ! is_dir( $target ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $items as $item ) {
		$dest = $target . DIRECTORY_SEPARATOR . $items->getSubPathName();

		if ( $item->isDir() ) {
			if ( ! is_dir( $dest ) ) {
				mkdir( $dest, 0777, true );
			}
		} else {
			copy( $item->getPathname(), $dest );
		}
	}
}
