<?php
/**
 * Plugin Name: Woodev Yandex Pilot Fixture
 * Description: Yandex-shaped Platform v2 shipping pilot fixture. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-yandex-pilot
 *
 * @package Woodev_Yandex_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

defined( 'WOODEV_YANDEX_PILOT_VERSION' ) || define( 'WOODEV_YANDEX_PILOT_VERSION', '1.0.0' );
defined( 'WOODEV_YANDEX_PILOT_FILE' ) || define( 'WOODEV_YANDEX_PILOT_FILE', __FILE__ );

/**
 * Returns the Platform v2 loader definition for the yandex-shaped pilot fixture.
 *
 * Mirrors the edostavka pilot's loader definition (spec 7), but the yandex plugin
 * registers under the framework id `yandex_delivery` so its REST namespace resolves
 * to the installed-site contract `yandex-delivery` ({@see get_id_dasherized()}).
 *
 * @return array<string,mixed>
 */
function woodev_yandex_pilot_plugin_loader_definition(): array {
	return [
		'plugin_id'         => 'yandex_delivery',
		'plugin_name'       => 'Woodev Yandex Pilot Fixture',
		'plugin_version'    => WOODEV_YANDEX_PILOT_VERSION,
		'framework_version' => '2.0.0',
		'plugin_file'       => WOODEV_YANDEX_PILOT_FILE,
		'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.3',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'Woodev_Yandex_Pilot_Shipping_Plugin',
		'callback'          => 'woodev_yandex_pilot_plugin_init',
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
 * Loads the yandex-shaped pilot fixture classes through an include-based callback.
 *
 * Constructs the plugin first (which loads the framework shipping bases), then pulls
 * in the framework PVZ collaborators the fixture extends — these live under
 * `pickup/` and are NOT auto-included by {@see Shipping_Plugin::includes()} — before
 * declaring the fixture's pickup method, warehouse store, map provider and source.
 *
 * @return void
 */
function woodev_yandex_pilot_plugin_init(): void {
	$plugin_path = dirname( __FILE__ );

	require_once $plugin_path . '/class-yandex-pilot-shipping-plugin.php';

	$plugin = woodev_yandex_pilot_plugin();

	$framework = $plugin->get_shipping_framework_path();

	require_once $framework . '/pickup/class-pickup-point.php';
	require_once $framework . '/pickup/class-pickup-selection.php';
	require_once $framework . '/pickup/interface-pickup-point-source.php';
	require_once $framework . '/pickup/class-warehouse.php';
	require_once $framework . '/pickup/interface-warehouse-store.php';
	require_once $framework . '/pickup/class-abstract-warehouse-store.php';

	require_once $plugin_path . '/class-yandex-pilot-map-provider.php';
	require_once $plugin_path . '/class-yandex-pilot-warehouse-store.php';
	require_once $plugin_path . '/class-yandex-pilot-pickup-method.php';
}
