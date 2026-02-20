<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Customer_Response' ) ) :

	/**
	 * WooCommerce Direct Payment Gateway API Customer Response
	 */
	interface Woodev_Payment_Gateway_API_Customer_Response extends Woodev_Payment_Gateway_API_Response {

		/**
		 * Returns the customer ID.
		 *
		 * @return string customer ID returned by the gateway
		 */
		public function get_customer_id();

	}

endif;