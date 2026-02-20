<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Create_Payment_Token_Response' ) ) :

	/**
	 * WooCommerce Direct Payment Gateway API Create Payment Token Response
	 */
	interface Woodev_Payment_Gateway_API_Create_Payment_Token_Response extends Woodev_Payment_Gateway_API_Response {

		/**
		 * Returns the payment token.
		 *
		 * @return Woodev_Payment_Gateway_Payment_Token payment token
		 * @since 1.0.0
		 *
		 */
		public function get_payment_token();
	}

endif;