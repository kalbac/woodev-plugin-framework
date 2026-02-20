<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Get_Tokenized_Payment_Methods_Response' ) ) :

	/**
	 * WooCommerce Direct Payment Gateway API Create Payment Token Response
	 */
	interface Woodev_Payment_Gateway_API_Get_Tokenized_Payment_Methods_Response extends Woodev_Payment_Gateway_API_Response {

		/**
		 * Returns any payment tokens.
		 *
		 * @return Woodev_Payment_Gateway_Payment_Token[] array of Woodev_Payment_Gateway_Payment_Token payment tokens, keyed by the token ID
		 * @since 1.0.0
		 *
		 */
		public function get_payment_tokens();
	}

endif; 