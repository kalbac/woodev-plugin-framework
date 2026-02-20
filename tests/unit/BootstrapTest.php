<?php
/**
 * Framework Bootstrap Test
 *
 * Проверяем что основные классы фреймворка существуют и загружаются.
 * Это smoke-тест — если он падает, что-то сломано на базовом уровне.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Class BootstrapTest
 */
class BootstrapTest extends TestCase {

	/**
	 * Основные классы фреймворка должны существовать.
	 */
	public function test_framework_classes_exist(): void {
		$this->assertTrue(
			class_exists( 'Woodev_Plugin_Bootstrap' ),
			'Woodev_Plugin_Bootstrap class should exist'
		);

		$this->assertTrue(
			class_exists( 'Woodev_Plugin' ),
			'Woodev_Plugin class should exist'
		);
	}

	/**
	 * Woodev_Plugin_Bootstrap должен быть singleton.
	 */
	public function test_bootstrap_is_singleton(): void {
		$instance1 = \Woodev_Plugin_Bootstrap::instance();
		$instance2 = \Woodev_Plugin_Bootstrap::instance();

		$this->assertSame(
			$instance1,
			$instance2,
			'Bootstrap should return the same instance'
		);
	}

	/**
	 * Bootstrap должен возвращать корректную версию фреймворка.
	 */
	public function test_bootstrap_returns_version(): void {
		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$this->assertNotEmpty(
			$bootstrap->get_framework_version(),
			'Framework version should not be empty'
		);

		// Версия должна быть в формате semver x.y.z
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+/',
			$bootstrap->get_framework_version(),
			'Framework version should be in semver format'
		);
	}
}
