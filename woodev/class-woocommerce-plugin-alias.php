<?php
/**
 * WooCommerce platform plugin global compatibility alias.
 *
 * @package Woodev\Framework
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Woocommerce_Plugin', false ) ) {
	class_alias( \Woodev\Framework\Woocommerce_Plugin::class, 'Woodev_Woocommerce_Plugin' );
}
