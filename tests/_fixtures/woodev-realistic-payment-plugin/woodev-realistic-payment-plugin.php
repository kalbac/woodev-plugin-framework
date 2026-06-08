<?php
/**
 * Plugin Name: Woodev Realistic Payment Fixture
 * Description: Realistic-shaped Platform v2 payment gateway fixture. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-realistic-payment
 *
 * @package Woodev_Realistic_Payment_Fixture
 */

defined( 'ABSPATH' ) || exit;

defined( 'WOODEV_REALISTIC_PAYMENT_VERSION' ) || define( 'WOODEV_REALISTIC_PAYMENT_VERSION', '1.0.0' );
defined( 'WOODEV_REALISTIC_PAYMENT_FILE' ) || define( 'WOODEV_REALISTIC_PAYMENT_FILE', __FILE__ );

/**
 * Returns the Platform v2 loader definition for the fixture plugin.
 *
 * @return array<string,mixed>
 */
function woodev_realistic_payment_plugin_loader_definition(): array {
	return [
		'plugin_id'         => 'woodev-realistic-payment',
		'plugin_name'       => 'Woodev Realistic Payment Fixture',
		'plugin_version'    => WOODEV_REALISTIC_PAYMENT_VERSION,
		'framework_version' => '1.4.1',
		'plugin_file'       => WOODEV_REALISTIC_PAYMENT_FILE,
		'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.3',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'Woodev_Realistic_Payment_Plugin',
		'callback'          => 'woodev_realistic_payment_plugin_init',
		'capabilities'      => [
			\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_PAYMENT_GATEWAY,
		],
		'supported_features' => [
			'hpos' => true,
		],
	];
}

/**
 * Loads the realistic payment fixture classes through an include-based callback.
 *
 * The concrete gateway class graph is included after the plugin is constructed
 * because the payment gateway base classes it extends are loaded by the payment
 * gateway plugin base during construction.
 *
 * @return void
 */
function woodev_realistic_payment_plugin_init(): void {
	$plugin_path = dirname( __FILE__ );

	require_once $plugin_path . '/includes/class-realistic-payment-plugin.php';

	woodev_realistic_payment_plugin();

	require_once $plugin_path . '/includes/abstract-class-realistic-gateway.php';
	require_once $plugin_path . '/includes/class-realistic-gateway.php';
}
