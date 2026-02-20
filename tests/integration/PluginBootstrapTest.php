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
}
