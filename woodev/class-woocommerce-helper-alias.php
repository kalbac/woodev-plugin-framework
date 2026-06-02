<?php
/**
 * WooCommerce platform helper global compatibility alias.
 *
 * Provides the global-namespace Woodev_Woocommerce_Helper entry point that
 * existing 10+ plugins call. Mirrors the pattern used by
 * class-woocommerce-plugin-alias.php.
 *
 * @package Woodev\Framework
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Woocommerce_Helper', false ) ) {
	class_alias( \Woodev\Framework\Woocommerce_Helper::class, 'Woodev_Woocommerce_Helper' );
}
