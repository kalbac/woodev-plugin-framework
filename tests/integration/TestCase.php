<?php
/**
 * Base Integration Test Case
 *
 * Все интеграционные тесты наследуются от этого класса.
 * Требует запущенного wp-env.
 * Имеет доступ к реальному WordPress и WooCommerce.
 */

namespace Woodev\Tests\Integration;

use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Инициализация перед каждым тестом.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Убеждаемся что фреймворк инициализирован
		if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
			$this->fail( 'Woodev Framework is not loaded. Make sure wp-env is running.' );
		}
	}

	/**
	 * Хелпер: получить экземпляр тестового плагина.
	 */
	protected function get_test_plugin(): \Woodev_Plugin {
		return woodev_test_plugin();
	}
}
