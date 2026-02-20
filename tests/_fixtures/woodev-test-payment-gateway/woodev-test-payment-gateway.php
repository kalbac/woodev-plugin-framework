<?php
/**
 * Plugin Name: Woodev Test Payment Gateway
 * Description: Fixture payment gateway for Woodev Framework testing. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-test-payment-gateway
 *
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * WC requires at least: 5.6
 *
 * @package Woodev_Test_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-woodev-test-payment-gateway.php';

/**
 * Регистрируем платёжный шлюз в WooCommerce.
 */
add_filter( 'woocommerce_payment_gateways', function( array $gateways ): array {
	$gateways[] = 'Woodev_Test_Payment_Gateway';
	return $gateways;
} );

/**
 * Глобальный хелпер для доступа к шлюзу из тестов.
 *
 * @return Woodev_Test_Payment_Gateway
 */
function woodev_test_payment_gateway(): Woodev_Test_Payment_Gateway {
	return new Woodev_Test_Payment_Gateway();
}
