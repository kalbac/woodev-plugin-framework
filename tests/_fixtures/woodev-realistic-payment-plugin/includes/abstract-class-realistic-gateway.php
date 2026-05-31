<?php
/**
 * Realistic payment fixture gateway base.
 *
 * @package Woodev_Realistic_Payment_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared fixture gateway base mirroring production payment plugins with a concrete
 * gateway subclass extending a hosted gateway base.
 */
abstract class Abstract_Woodev_Realistic_Gateway extends Woodev_Payment_Gateway_Hosted {

	/**
	 * Gets fixture settings fields.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_method_form_fields() {
		return [];
	}

	/**
	 * Gets the fixture plugin instance.
	 *
	 * @return Woodev_Realistic_Payment_Plugin
	 */
	protected function get_plugin() {
		return woodev_realistic_payment_plugin();
	}
}
