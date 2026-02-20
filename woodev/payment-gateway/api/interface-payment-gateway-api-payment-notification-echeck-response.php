<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Payment_Notification_eCheck_Response' ) ) :

	/**
	 * WooCommerce Payment Gateway API Payment eCheck Notification Response
	 *
	 * Represents an IPN or redirect-back eCheck request response
	 */
	interface Woodev_Payment_Gateway_API_Payment_Notification_eCheck_Response extends Woodev_Payment_Gateway_API_Payment_Notification_Response {

		/**
		 * Returns the account type, one of 'checking' or 'savings', if available.
		 *
		 * @return string account type, one of 'checking' or 'savings'
		 */
		public function get_account_type();


		/**
		 * Returns the check number used, if available.
		 *
		 * @return int|null check number, or null
		 */
		public function get_check_number();
	}

endif;  // interface exists check