<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Payment_Notification_Loans_Response' ) ) :
	/**
	 * WooCommerce Payment Gateway API Payment Loans Notification Response
	 *
	 * Represents an IPN or redirect-back Loans request response
	 */
	interface Woodev_Payment_Gateway_API_Payment_Notification_Loans_Response extends Woodev_Payment_Gateway_API_Payment_Notification_Response {

		/**
		 * Returns the loan type, one of 'credit' or 'installment', if available.
		 *
		 * @return string loan type
		 */
		public function get_loan_type();


		/**
		 * Returns the loan total amount, if available.
		 *
		 * @return int|null loan amount, or null
		 */
		public function get_credit_amount();


		/**
		 * Returns the loan first payment amount, if available.
		 *
		 * @return int|null loan first payment, or null
		 */
		public function get_first_payment();
	}

endif;