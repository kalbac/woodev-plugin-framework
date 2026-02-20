<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Payment_Notification_Credit_Card_Response' ) ) :

	/**
	 * WooCommerce Payment Gateway API Payment Credit Card Notification Response
	 *
	 * Represents an IPN or redirect-back credit card request response
	 */
	interface Woodev_Payment_Gateway_API_Payment_Notification_Credit_Card_Response extends Woodev_Payment_Gateway_API_Payment_Notification_Response, Woodev_Payment_Gateway_API_Authorization_Response {

		/**
		 * Returns the card type, if available, i.e., 'visa', 'mastercard', etc.
		 *
		 * @return string|null card type or null if not available
		 * @see Woodev_Payment_Gateway_Helper::payment_type_to_name()
		 *
		 */
		public function get_card_type();


		/**
		 * Returns the card expiration month with leading zero, if available.
		 *
		 * @return string|null card expiration month or null if not available
		 */
		public function get_exp_month();


		/**
		 * Returns the card expiration year with four digits, if available.
		 *
		 * @return string|null card expiration year or null if not available
		 */
		public function get_exp_year();
	}

endif;