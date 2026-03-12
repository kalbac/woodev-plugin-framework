<?php
/**
 * Integration: WooCommerce Integration Test
 *
 * Проверяем что фреймворк корректно работает в связке с WooCommerce:
 * WC активен, классы доступны, совместимость не нарушена.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

/**
 * Class WooCommerceIntegrationTest
 */
class WooCommerceIntegrationTest extends TestCase {

	/**
	 * WooCommerce должен быть загружен.
	 */
	public function test_woocommerce_is_loaded(): void {
		$this->assertTrue(
			defined( 'WC_VERSION' ),
			'WC_VERSION should be defined — WooCommerce must be active'
		);
	}

	/**
	 * WooCommerce версия должна удовлетворять минимальным требованиям фреймворка.
	 */
	public function test_woocommerce_version_meets_minimum(): void {
		$minimum = '5.6';

		$this->assertTrue(
			version_compare( WC_VERSION, $minimum, '>=' ),
			"WooCommerce version " . WC_VERSION . " must be >= {$minimum}"
		);
	}

	/**
	 * Основные WooCommerce классы должны быть доступны.
	 */
	public function test_woocommerce_core_classes_available(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce class should exist' );
		$this->assertTrue( class_exists( 'WC_Payment_Gateway' ), 'WC_Payment_Gateway should exist' );
		$this->assertTrue( class_exists( 'WC_Shipping_Method' ), 'WC_Shipping_Method should exist' );
		$this->assertTrue( class_exists( 'WC_Order' ), 'WC_Order should exist' );
	}

	/**
	 * Глобальная функция wc() должна быть доступна.
	 */
	public function test_wc_global_function_available(): void {
		$this->assertTrue(
			function_exists( 'wc' ),
			'wc() global function should be available'
		);
		$this->assertInstanceOf(
			\WooCommerce::class,
			wc(),
			'wc() should return WooCommerce instance'
		);
	}

	/**
	 * Фреймворк должен видеть WooCommerce через совместимость-хелпер.
	 */
	public function test_framework_detects_woocommerce(): void {
		$this->assertTrue(
			\Woodev_Helper::is_woocommerce_active(),
			'Woodev_Helper::is_woocommerce_active() should return true'
		);
	}

	/**
	 * Woodev_Plugin_Compatibility::is_wc_version_gte() должен корректно сравнивать версии WC.
	 */
	public function test_helper_wc_version_comparison(): void {
		$this->assertTrue(
			\Woodev_Plugin_Compatibility::is_wc_version_gte( '5.0' ),
			'is_wc_version_gte(5.0) should return true for current WC'
		);
		$this->assertFalse(
			\Woodev_Plugin_Compatibility::is_wc_version_gte( '999.0' ),
			'is_wc_version_gte(999.0) should return false'
		);
	}

	/**
	 * WooCommerce класс должен быть доступен как признак активности.
	 *
	 * Не используем is_plugin_active() — в wp-env WooCommerce устанавливается
	 * как 'woocommerce.latest-stable', а не 'woocommerce/woocommerce.php'.
	 */
	public function test_woocommerce_is_active_plugin(): void {
		$this->assertTrue(
			class_exists( 'WooCommerce' ),
			'WooCommerce class should exist — WC must be active'
		);
		$this->assertNotEmpty(
			WC_VERSION,
			'WC_VERSION constant should be defined and non-empty'
		);
	}
}
