<?php
/**
 * Integration: Plugin Bootstrap Test
 *
 * Проверяем что тестовый плагин корректно загружается в реальном WordPress.
 * Требует запущенного wp-env.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

/**
 * Class PluginBootstrapTest
 */
class PluginBootstrapTest extends TestCase {

	/**
	 * Тестовый плагин должен быть загружен.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue(
			function_exists( 'woodev_test_plugin' ),
			'woodev_test_plugin() helper function should exist'
		);
	}

	/**
	 * Экземпляр плагина должен быть валидным.
	 */
	public function test_plugin_instance_is_valid(): void {
		$plugin = $this->get_test_plugin();

		$this->assertInstanceOf(
			\Woodev_Plugin::class,
			$plugin,
			'Test plugin should be instance of Woodev_Plugin'
		);
	}

	/**
	 * Плагин должен возвращать корректный ID.
	 */
	public function test_plugin_returns_correct_id(): void {
		$plugin = $this->get_test_plugin();

		$this->assertSame(
			'woodev-test-plugin',
			$plugin->get_id(),
			'Plugin ID should match'
		);
	}

	/**
	 * Плагин должен иметь доступ к файловой системе.
	 */
	public function test_plugin_path_exists(): void {
		$plugin = $this->get_test_plugin();

		$this->assertDirectoryExists(
			$plugin->get_plugin_path(),
			'Plugin path should exist'
		);
	}

	/**
	 * WordPress должен быть загружен и функционировать.
	 */
	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( defined( 'ABSPATH' ), 'ABSPATH should be defined' );
		$this->assertTrue( defined( 'WPINC' ), 'WPINC should be defined' );
		$this->assertTrue( function_exists( 'add_action' ), 'add_action() should exist' );
		$this->assertTrue( function_exists( 'get_option' ), 'get_option() should exist' );
	}

	/**
	 * Фреймворк должен возвращать корректную версию.
	 */
	public function test_framework_version_is_set(): void {
		$this->assertNotEmpty(
			\Woodev_Plugin::VERSION,
			'Framework VERSION constant should be set'
		);
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+/',
			\Woodev_Plugin::VERSION,
			'Framework VERSION should follow semver format'
		);
	}

	/**
	 * Bootstrap должен быть singleton.
	 */
	public function test_bootstrap_is_singleton(): void {
		$instance1 = \Woodev_Plugin_Bootstrap::instance();
		$instance2 = \Woodev_Plugin_Bootstrap::instance();

		$this->assertSame(
			$instance1,
			$instance2,
			'Bootstrap should always return the same instance'
		);
	}

	/**
	 * Плагин должен корректно возвращать URL.
	 */
	public function test_plugin_url_is_valid(): void {
		$plugin = $this->get_test_plugin();
		$url    = $plugin->get_plugin_url();

		$this->assertNotEmpty( $url, 'Plugin URL should not be empty' );
		$this->assertStringStartsWith( 'http', $url, 'Plugin URL should start with http' );
	}

	/**
	 * WordPress минимальная версия должна удовлетворять требованиям фреймворка.
	 */
	public function test_wordpress_version_meets_minimum(): void {
		global $wp_version;
		$minimum = '5.9';

		$this->assertTrue(
			version_compare( $wp_version, $minimum, '>=' ),
			"WordPress version {$wp_version} must be >= {$minimum}"
		);
	}
}
