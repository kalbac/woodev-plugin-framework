<?php
/**
 * Plugin Name: Woodev Edostavka Pilot Fixture
 * Description: Edostavka-shaped Platform v2 shipping pilot fixture. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-edostavka-pilot
 *
 * @package Woodev_Edostavka_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

defined( 'WOODEV_EDOSTAVKA_PILOT_VERSION' ) || define( 'WOODEV_EDOSTAVKA_PILOT_VERSION', '1.0.0' );
defined( 'WOODEV_EDOSTAVKA_PILOT_FILE' ) || define( 'WOODEV_EDOSTAVKA_PILOT_FILE', __FILE__ );

/**
 * Returns the Platform v2 loader definition for the edostavka-shaped pilot fixture.
 *
 * @return array<string,mixed>
 */
function woodev_edostavka_pilot_plugin_loader_definition(): array {
	return [
		'plugin_id'         => 'woodev-edostavka-pilot',
		'plugin_name'       => 'Woodev Edostavka Pilot Fixture',
		'plugin_version'    => WOODEV_EDOSTAVKA_PILOT_VERSION,
		'framework_version' => '1.4.1',
		'plugin_file'       => WOODEV_EDOSTAVKA_PILOT_FILE,
		'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.3',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'Woodev_Edostavka_Pilot_Plugin',
		'callback'          => 'woodev_edostavka_pilot_plugin_init',
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
 * Loads the edostavka-shaped pilot fixture classes through an include-based callback.
 *
 * @return void
 */
function woodev_edostavka_pilot_plugin_init(): void {
	$plugin_path = dirname( __FILE__ );

	require_once $plugin_path . '/includes/class-edostavka-pilot-integration.php';
	require_once $plugin_path . '/includes/class-edostavka-pilot-plugin.php';

	woodev_edostavka_pilot_plugin();

	require_once $plugin_path . '/includes/class-edostavka-pilot-shipping-method.php';
}
