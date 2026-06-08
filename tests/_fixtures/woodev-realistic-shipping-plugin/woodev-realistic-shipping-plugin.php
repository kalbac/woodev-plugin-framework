<?php
/**
 * Plugin Name: Woodev Realistic Shipping Fixture
 * Description: Realistic-shaped Platform v2 shipping fixture. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-realistic-shipping
 *
 * @package Woodev_Realistic_Shipping_Fixture
 */

defined( 'ABSPATH' ) || exit;

defined( 'WOODEV_REALISTIC_SHIPPING_VERSION' ) || define( 'WOODEV_REALISTIC_SHIPPING_VERSION', '1.0.0' );
defined( 'WOODEV_REALISTIC_SHIPPING_FILE' ) || define( 'WOODEV_REALISTIC_SHIPPING_FILE', __FILE__ );

/**
 * Returns the Platform v2 loader definition for the fixture plugin.
 *
 * @return array<string,mixed>
 */
function woodev_realistic_shipping_plugin_loader_definition(): array {
	return [
		'plugin_id'         => 'woodev-realistic-shipping',
		'plugin_name'       => 'Woodev Realistic Shipping Fixture',
		'plugin_version'    => WOODEV_REALISTIC_SHIPPING_VERSION,
		'framework_version' => '1.4.1',
		'plugin_file'       => WOODEV_REALISTIC_SHIPPING_FILE,
		'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.3',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'Woodev_Realistic_Shipping_Plugin',
		'callback'          => 'woodev_realistic_shipping_plugin_init',
		'capabilities'      => [
			\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_SHIPPING_METHOD,
		],
		'supported_features' => [
			'hpos'   => true,
			'blocks' => [
				'cart'     => true,
				'checkout' => true,
			],
		],
	];
}

/**
 * Loads the realistic shipping fixture classes through an include-based callback.
 *
 * @return void
 */
function woodev_realistic_shipping_plugin_init(): void {
	$plugin_path = dirname( __FILE__ );

	require_once $plugin_path . '/includes/class-realistic-shipping-plugin.php';
	require_once $plugin_path . '/includes/abstract-class-realistic-shipping-method.php';
	require_once $plugin_path . '/includes/class-realistic-shipping-method.php';
	require_once $plugin_path . '/includes/class-realistic-pickup-shipping-method.php';

	woodev_realistic_shipping_plugin();
}
