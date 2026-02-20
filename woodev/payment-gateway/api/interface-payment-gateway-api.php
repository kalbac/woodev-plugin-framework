<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API' ) ) :

	/**
	 * WooCommerce Payment Gateway API
	 */
	interface Woodev_Payment_Gateway_API {

		/**
		 * Perform a credit card authorization for the given order
		 *
		 * If the gateway does not support credit card authorizations, this method can be a no-op.
		 *
		 * @param WC_Order $order the order
		 *
		 * @return Woodev_Payment_Gateway_API_Response credit card charge response
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 * @since 1.0.0
		 *
		 */
		public function credit_card_authorization( WC_Order $order );


		/**
		 * Perform a credit card charge for the given order
		 *
		 * If the gateway does not support credit card charges, this method can be a no-op.
		 *
		 * @param WC_Order $order the order
		 *
		 * @return Woodev_Payment_Gateway_API_Response credit card charge response
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 * @since 1.0.0
		 *
		 */
		public function credit_card_charge( WC_Order $order );


		/**
		 * Perform a credit card capture for a given authorized order
		 *
		 * If the gateway does not support credit card capture, this method can be a no-op.
		 *
		 * @param WC_Order $order the order
		 *
		 * @return Woodev_Payment_Gateway_API_Response credit card capture response
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 * @since 1.0.0
		 *
		 */
		public function credit_card_capture( WC_Order $order );


		/**
		 * Perform an eCheck debit (ACH transaction) for the given order
		 *
		 * If the gateway does not support check debits, this method can be a no-op.
		 *
		 * @param WC_Order $order the order
		 *
		 * @return Woodev_Payment_Gateway_API_Response check debit response
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 * @since 1.0.0
		 *
		 */
		public function check_debit( WC_Order $order );


		/**
		 * Perform a refund for the given order
		 *
		 * If the gateway does not support refunds, this method can be a no-op.
		 *
		 * @param WC_Order $order order object
		 *
		 * @return Woodev_Payment_Gateway_API_Response refund response
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 */
		public function refund( WC_Order $order );


		/**
		 * Perform a void for the given order
		 *
		 * If the gateway does not support voids, this method can be a no-op.
		 *
		 * @param WC_Order $order order object
		 *
		 * @return Woodev_Payment_Gateway_API_Response void response
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 */
		public function void( WC_Order $order );


		/**
		 * Creates a payment token for the given order
		 *
		 * If the gateway does not support tokenization, this method can be a no-op.
		 *
		 * @param WC_Order $order the order
		 *
		 * @return Woodev_Payment_Gateway_API_Create_Payment_Token_Response payment method tokenization response
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 * @since 1.0.0
		 *
		 */
		public function tokenize_payment_method( WC_Order $order );


		/**
		 * Updates a tokenized payment method.
		 *
		 * @param WC_Order $order order object
		 *
		 * @return Woodev_Payment_Gateway_API_Response
		 * @throws Woodev_Plugin_Exception
		 */
		public function update_tokenized_payment_method( WC_Order $order );


		/**
		 * Determines if this API supports updating tokenized payment methods.
		 *
		 * @return bool
		 * @see Woodev_Payment_Gateway_API::update_tokenized_payment_method()
		 *
		 */
		public function supports_update_tokenized_payment_method();


		/**
		 * Removes the tokenized payment method.  This method should not be invoked
		 * unless supports_remove_tokenized_payment_method() returns true, otherwise the results are undefined.
		 *
		 * @param string $token the payment method token
		 * @param string $customer_id unique customer id for gateways that support it
		 *
		 * @return Woodev_Payment_Gateway_API_Response remove tokenized payment method response
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 * @since 1.0.0
		 * @see Woodev_Payment_Gateway_API::supports_remove_tokenized_payment_method()
		 *
		 */
		public function remove_tokenized_payment_method( $token, $customer_id );


		/**
		 * Returns true if this API supports a "remove tokenized payment method"
		 * request.  If this method returns true, then remove_tokenized_payment_method() is considered safe to call.
		 *
		 * @return boolean true if this API supports a "remove tokenized payment method" request, false otherwise
		 * @see Woodev_Payment_Gateway_API::remove_tokenized_payment_method()
		 *
		 * @since 1.0.0
		 */
		public function supports_remove_tokenized_payment_method();


		/**
		 * Returns all tokenized payment methods for the customer.  This method
		 * should not be invoked unless supports_get_tokenized_payment_methods()
		 * return true, otherwise the results are undefined
		 *
		 * @param string $customer_id unique customer id
		 *
		 * @return Woodev_Payment_Gateway_API_Get_Tokenized_Payment_Methods_Response response containing any payment tokens for the customer
		 * @throws Woodev_Payment_Gateway_Exception network timeouts, etc
		 * @see Woodev_Payment_Gateway_API::supports_get_tokenized_payment_methods()
		 *
		 * @since 1.0.0
		 */
		public function get_tokenized_payment_methods( $customer_id );


		/**
		 * Returns true if this API supports a "get tokenized payment methods"
		 * request.  If this method returns true, then get_tokenized_payment_methods() is considered safe to call.
		 *
		 * @return boolean true if this API supports a "get tokenized payment methods" request, false otherwise
		 * @see Woodev_Payment_Gateway_API::get_tokenized_payment_methods()
		 *
		 * @since 1.0.0
		 */
		public function supports_get_tokenized_payment_methods();


		/**
		 * Returns the most recent request object
		 *
		 * @return Woodev_Payment_Gateway_API_Request the most recent request object
		 * @since 1.0.0
		 *
		 */
		public function get_request();


		/**
		 * Returns the most recent response object
		 *
		 * @return Woodev_Payment_Gateway_API_Response the most recent response object
		 * @since 1.0.0
		 *
		 */
		public function get_response();


		/**
		 * Returns the WC_Order object associated with the request, if any
		 *
		 * @return WC_Order
		 */
		public function get_order();

	}

endif;