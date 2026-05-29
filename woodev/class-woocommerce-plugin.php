<?php
/**
 * WooCommerce platform plugin base.
 *
 * @package Woodev\Framework
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Woocommerce_Plugin', false ) ) :

	/**
	 * Base class for plugins that require WooCommerce runtime behavior.
	 *
	 * The class is intentionally thin at this step. Runtime WooCommerce ownership
	 * moves here in later Platform v2 phases after resolver and loader contracts
	 * are covered by tests.
	 *
	 * @since 2.0.0
	 */
	abstract class Woodev_Woocommerce_Plugin extends Woodev_Plugin {}

endif;
