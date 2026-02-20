<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Authorization_Response' ) ) :

	/**
	 * WooCommerce Direct Payment Gateway API Authorization Response
	 *
	 * Represents a Payment Gateway Credit Card Authorization response.  This should
	 * also be used as the parent class for credit card charge (authorization + capture) responses.
	 */
	interface Woodev_Payment_Gateway_API_Authorization_Response extends Woodev_Payment_Gateway_API_Response {

		/**
		 * The authorization code is returned from the credit card processor to
		 * indicate that the charge will be paid by the card issuer.
		 *
		 * @return string credit card authorization code
		 * @since 1.0.0
		 *
		 */
		public function get_authorization_code();


		/**
		 * Returns the result of the AVS check.
		 *
		 * @return string result of the AVS check, if any
		 * @since 1.0.0
		 *
		 */
		public function get_avs_result();


		/**
		 * Returns the result of the CSC check.
		 *
		 * @return string result of CSC check
		 * @since 1.0.0
		 *
		 */
		public function get_csc_result();


		/**
		 * Returns true if the CSC check was successful.
		 *
		 * @return boolean true if the CSC check was successful
		 * @since 1.0.0
		 *
		 */
		public function csc_match();
	}

endif;