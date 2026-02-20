<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Payment_Notification_Response' ) ) :

	/**
	 * WooCommerce Payment Gateway API Payment Notification Response
	 *
	 * Represents an IPN or redirect-back request response
	 */
	interface Woodev_Payment_Gateway_API_Payment_Notification_Response extends Woodev_Payment_Gateway_API_Response {

		/**
		 * Returns the order id associated with this response.
		 *
		 * @return int|null the order id associated with this response, or null if it could not be determined
		 * @throws Exception if there was a serious error finding the order id
		 */
		public function get_order_id();


		/**
		 * Returns true if the transaction was cancelled, false otherwise.
		 *
		 * @return bool true if cancelled, false otherwise
		 */
		public function transaction_cancelled();


		/**
		 * Returns the card PAN or checking account number, if available.
		 *
		 * @return string|null PAN or account number or null if not available
		 */
		public function get_account_number();


		/**
		 * Determines if this is an IPN response.
		 *
		 * Intentionally commented out to prevent fatal errors in older plugins
		 *
		 * @return bool
		 */
		public function is_ipn();
	}

endif;