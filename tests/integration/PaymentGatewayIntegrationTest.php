<?php
/**
 * Integration: Payment Gateway Integration Test
 *
 * Проверяем что тестовый платёжный шлюз на базе фреймворка
 * корректно регистрируется и работает в реальном WooCommerce.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

/**
 * Class PaymentGatewayIntegrationTest
 */
class PaymentGatewayIntegrationTest extends TestCase {

	/**
	 * Хелпер-функция тестового шлюза должна существовать.
	 */
	public function test_payment_gateway_plugin_loaded(): void {
		$this->assertTrue(
			function_exists( 'woodev_test_payment_gateway_plugin' ),
			'woodev_test_payment_gateway_plugin() should exist after plugin load'
		);
	}

	/**
	 * Экземпляр плагина должен быть Woodev_Payment_Gateway_Plugin.
	 */
	public function test_payment_gateway_plugin_instance(): void {
		$plugin = woodev_test_payment_gateway_plugin();

		$this->assertInstanceOf(
			\Woodev_Payment_Gateway_Plugin::class,
			$plugin,
			'Payment gateway plugin should be instance of Woodev_Payment_Gateway_Plugin'
		);
	}

	/**
	 * Плагин должен возвращать корректный ID.
	 */
	public function test_payment_gateway_plugin_id(): void {
		$plugin = woodev_test_payment_gateway_plugin();

		$this->assertSame(
			'woodev-test-payment-gateway',
			$plugin->get_id()
		);
	}

	/**
	 * Плагин должен иметь хотя бы один зарегистрированный шлюз.
	 */
	public function test_payment_gateway_plugin_has_gateways(): void {
		$plugin   = woodev_test_payment_gateway_plugin();
		$gateways = $plugin->get_gateways();

		$this->assertNotEmpty(
			$gateways,
			'Payment gateway plugin should have at least one gateway registered'
		);
	}

	/**
	 * Шлюз должен быть экземпляром Woodev_Payment_Gateway.
	 */
	public function test_gateway_is_woodev_payment_gateway(): void {
		$plugin   = woodev_test_payment_gateway_plugin();
		$gateways = $plugin->get_gateways();

		foreach ( $gateways as $gateway ) {
			$this->assertInstanceOf(
				\Woodev_Payment_Gateway::class,
				$gateway,
				'Each gateway should be instance of Woodev_Payment_Gateway'
			);
		}
	}

	/**
	 * WooCommerce должен знать о нашем шлюзе.
	 */
	public function test_gateway_registered_with_woocommerce(): void {
		$wc_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		$found = false;
		foreach ( $wc_gateways as $gateway ) {
			if ( $gateway instanceof \Woodev_Payment_Gateway ) {
				$found = true;
				break;
			}
		}

		// Шлюз может быть недоступен если не включён в настройках — проверяем регистрацию
		$all_gateways = WC()->payment_gateways()->payment_gateways();
		$woodev_found = false;
		foreach ( $all_gateways as $gateway ) {
			if ( $gateway instanceof \Woodev_Payment_Gateway ) {
				$woodev_found = true;
				break;
			}
		}

		$this->assertTrue(
			$woodev_found,
			'Woodev payment gateway should be registered with WooCommerce'
		);
	}

	/**
	 * Плагин должен корректно возвращать версию фреймворка.
	 */
	public function test_payment_gateway_plugin_version(): void {
		$plugin = woodev_test_payment_gateway_plugin();

		$this->assertNotEmpty(
			$plugin->get_version(),
			'Plugin version should not be empty'
		);
	}
}
