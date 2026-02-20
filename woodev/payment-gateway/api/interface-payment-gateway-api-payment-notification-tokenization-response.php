<?php

defined( 'ABSPATH' ) or exit;

if ( ! interface_exists( 'Woodev_Payment_Gateway_Payment_Notification_Tokenization_Response' ) ) :


	/**
	 * WooCommerce Payment Gateway API Payment Credit Card Notification Response
	 *
	 * Represents an IPN or redirect-back credit card request response
	 */
	interface Woodev_Payment_Gateway_Payment_Notification_Tokenization_Response extends Woodev_Payment_Gateway_API_Create_Payment_Token_Response {


		/**
		 * Gets the overall result message for a new payment method tokenization and/or customer creation.
		 *
		 * @return string
		 */
		public function get_tokenization_message();


		/**
		 * Gets the result message for a new customer creation.
		 *
		 * @return string
		 */
		public function get_customer_created_message();


		/**
		 * Gets the result message for a new payment method tokenization.
		 *
		 * @return string
		 */
		public function get_payment_method_tokenized_message();


		/**
		 * Gets the result code for a new customer creation.
		 *
		 * @return string
		 */
		public function get_customer_created_code();


		/**
		 * Gets the result code for a new payment method tokenization.
		 *
		 * @return string
		 */
		public function get_payment_method_tokenized_code();


		/**
		 * Determines whether a new customer was created.
		 *
		 * @return bool
		 */
		public function customer_created();


		/**
		 * Determines whether a new payment method was tokenized.
		 *
		 * @return bool
		 */
		public function payment_method_tokenized();


		/**
		 * Determines whether the overall payment tokenization was successful.
		 *
		 * Gateways can check that the payment method was tokenized, and if a new customer was created, that was successful.
		 *
		 * @return bool
		 */
		public function tokenization_successful();


		/**
		 * Determines whether the customer was successfully created.
		 *
		 * @return bool
		 */
		public function customer_creation_successful();


		/**
		 * Determines whether the payment method was successfully tokenized.
		 *
		 * @return bool
		 */
		public function payment_method_tokenization_successful();


		/**
		 * Gets any payment tokens that were edited on the hosted pay page.
		 *
		 * @return array|Woodev_Payment_Gateway_Payment_Token[]
		 */
		public function get_edited_payment_tokens();


		/**
		 * Gets any payment tokens that were deleted on the hosted pay page.
		 *
		 * @return array|Woodev_Payment_Gateway_Payment_Token[]
		 */
		public function get_deleted_payment_tokens();


	}


endif;
