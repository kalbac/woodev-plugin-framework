<?php
/**
 * Plugin Name: Woodev Test Shipping Method
 * Description: Fixture shipping method for Woodev Framework testing. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-test-shipping-method
 *
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * WC requires at least: 5.6
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-woodev-test-shipping-method.php';

/**
 * Регистрируем метод доставки в WooCommerce.
 */
add_filter( 'woocommerce_shipping_methods', function( array $methods ): array {
	$methods['woodev_test_shipping'] = 'Woodev_Test_Shipping_Method';
	return $methods;
} );

/**
 * Глобальный хелпер для доступа к методу доставки из тестов.
 *
 * @return Woodev_Test_Shipping_Method
 */
function woodev_test_shipping_method(): Woodev_Test_Shipping_Method {
	return new Woodev_Test_Shipping_Method();
}
