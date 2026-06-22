<?php
/**
 * WooCommerce setup wizard.
 *
 * @package Woodev\Framework\Setup
 */

namespace Woodev\Framework\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Thin WooCommerce specialization of the neutral setup wizard.
 *
 * Raises the required capability to manage_woocommerce, only wires its hooks
 * when WooCommerce is active, and exposes ready-made WC-readiness helpers so a
 * plugin's step callbacks may safely call WooCommerce functions.
 *
 * @since 2.0.2
 */
abstract class Woocommerce_Setup_Wizard extends Setup_Wizard {

	/**
	 * WooCommerce capability.
	 *
	 * @since 2.0.2
	 *
	 * @var string
	 */
	protected string $required_capability = 'manage_woocommerce';

	/**
	 * Wires base-owned hooks only when WooCommerce is active.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function add_hooks(): void {
		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		parent::add_hooks();
	}

	/**
	 * Whether WooCommerce is active in this request.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	protected function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether at least one WooCommerce shipping zone (beyond "rest of world")
	 * is configured. Useful as a readiness check step for shipping plugins.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	protected function are_shipping_zones_configured(): bool {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return false;
		}

		return ! empty( \WC_Shipping_Zones::get_zones() );
	}
}
