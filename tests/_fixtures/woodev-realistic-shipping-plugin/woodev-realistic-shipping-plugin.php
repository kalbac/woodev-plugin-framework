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
		'framework_version' => '2.0.0',
		'plugin_file'       => WOODEV_REALISTIC_SHIPPING_FILE,
		'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.3',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'Woodev_Realistic_Shipping_Plugin',
		'callback'          => 'woodev_realistic_shipping_plugin_init',
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
	// Card #734: this carrier's OWN pickup point source — see that file's header for why a
	// second fixture carrier exists and why its Краснодар entry holds exactly one point.
	require_once $plugin_path . '/includes/class-realistic-point-source.php';

	woodev_realistic_shipping_plugin();
}

/**
 * Registers this fixture with the framework bootstrap on a LIVE WordPress boot (card #734).
 *
 * Until s112 this fixture was driven only from PHPUnit, where the test calls
 * `register_loader_definition()` itself — so on a real site nothing ever registered it and
 * its shipping methods did not appear at all. The rig needs it registered, because it is now
 * the SECOND carrier: its own pickup handler, its own point source, its own REST route.
 *
 * Mixed-fleet probe, same shape and same reason as the sibling fixture's: if an outdated (v1)
 * vendored framework copy won the class rendezvous it has no `register_loader_definition()`,
 * so probe for the method and stay dormant rather than fatal.
 */
$woodev_realistic_shipping_framework_dir = defined( 'WOODEV_FRAMEWORK_DIR' )
	? WOODEV_FRAMEWORK_DIR
	: dirname( __DIR__, 2 );

$woodev_realistic_shipping_bootstrap_file = $woodev_realistic_shipping_framework_dir . '/woodev/bootstrap.php';

if ( file_exists( $woodev_realistic_shipping_bootstrap_file ) ) {

	if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
		require_once $woodev_realistic_shipping_bootstrap_file;
	}

	$woodev_realistic_shipping_bootstrap = Woodev_Plugin_Bootstrap::instance();

	if ( method_exists( $woodev_realistic_shipping_bootstrap, 'register_loader_definition' ) ) {
		$woodev_realistic_shipping_bootstrap->register_loader_definition(
			woodev_realistic_shipping_plugin_loader_definition()
		);
	}
}

