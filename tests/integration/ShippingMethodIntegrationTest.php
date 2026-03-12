<?php
/**
 * Integration: Shipping Method Integration Test
 *
 * Проверяем что тестовый метод доставки на базе фреймворка
 * корректно регистрируется и работает в реальном WooCommerce.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

/**
 * Class ShippingMethodIntegrationTest
 */
class ShippingMethodIntegrationTest extends TestCase {

	/**
	 * Хелпер-функция тестового плагина доставки должна существовать.
	 */
	public function test_shipping_plugin_loaded(): void {
		$this->assertTrue(
			function_exists( 'woodev_test_shipping_method_plugin' ),
			'woodev_test_shipping_method_plugin() should exist after plugin load'
		);
	}

	/**
	 * Экземпляр плагина должен быть Woodev\Framework\Shipping\Shipping_Plugin.
	 */
	public function test_shipping_plugin_instance(): void {
		$plugin = woodev_test_shipping_method_plugin();

		$this->assertInstanceOf(
			\Woodev\Framework\Shipping\Shipping_Plugin::class,
			$plugin,
			'Shipping plugin should be instance of Woodev\\Framework\\Shipping\\Shipping_Plugin'
		);
	}

	/**
	 * Плагин должен возвращать корректный ID.
	 */
	public function test_shipping_plugin_id(): void {
		$plugin = woodev_test_shipping_method_plugin();

		$this->assertSame(
			'woodev-test-shipping-method',
			$plugin->get_id()
		);
	}

	/**
	 * Метод доставки должен быть зарегистрирован в WooCommerce.
	 *
	 * Проверяем через фильтр woocommerce_shipping_methods что наш класс
	 * присутствует в списке зарегистрированных методов доставки.
	 */
	public function test_shipping_method_registered_with_woocommerce(): void {
		$methods = apply_filters( 'woocommerce_shipping_methods', [] );

		$this->assertArrayHasKey(
			'woodev_test_shipping',
			$methods,
			'woodev_test_shipping method should be registered via woocommerce_shipping_methods filter'
		);
		$this->assertSame(
			'Woodev_Test_Shipping_Method',
			$methods['woodev_test_shipping'],
			'Registered class should be Woodev_Test_Shipping_Method'
		);
	}

	/**
	 * Плагин доставки должен корректно возвращать версию.
	 */
	public function test_shipping_plugin_version(): void {
		$plugin = woodev_test_shipping_method_plugin();

		$this->assertNotEmpty(
			$plugin->get_version(),
			'Shipping plugin version should not be empty'
		);
	}

	/**
	 * Плагин должен корректно возвращать путь к директории.
	 */
	public function test_shipping_plugin_path_exists(): void {
		$plugin = woodev_test_shipping_method_plugin();

		$this->assertDirectoryExists(
			$plugin->get_plugin_path(),
			'Shipping plugin path should exist on filesystem'
		);
	}
}
