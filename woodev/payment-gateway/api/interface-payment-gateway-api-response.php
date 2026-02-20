<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! interface_exists( 'Woodev_Payment_Gateway_API_Response' ) ) :

	/**
	 * WooCommerce Direct Payment Gateway API Response
	 */
interface Woodev_Payment_Gateway_API_Response extends Woodev_API_Response {

	/**
	 * Checks if the transaction was successful.
	 *
	 * @since 1.0.0
	 *
	 * @return bool true if approved, false otherwise
	 */
	public function transaction_approved();


	/**
	 * Returns true if the transaction was held, for instance due to AVS/CSC
	 * Fraud Settings.  This indicates that the transaction was successful, but did not pass a fraud check and should be reviewed.
	 *
	 * @since 1.0.0
	 *
	 * @return bool true if the transaction was held, false otherwise
	 */
	public function transaction_held();


	/**
	 * Gets the response status message, or null if there is no status message associated with this transaction.
	 *
	 * @since 1.0.0
	 *
	 * @return string status message
	 */
	public function get_status_message();


	/**
	 * Gets the response status code, or null if there is no status code associated with this transaction.
	 *
	 * @since 1.0.0
	 *
	 * @return string status code
	 */
	public function get_status_code();


	/**
	 * Gets the response transaction id, or null if there is no transaction id associated with this transaction.
	 *
	 * @since 1.0.0
	 *
	 * @return string transaction id
	 */
	public function get_transaction_id();


	/**
	 * Gets the payment type: 'credit-card', 'echeck', etc...
	 *
	 * @return string
	 */
	public function get_payment_type();


	/**
	 * Returns a message appropriate for a frontend user.  This should be used
	 * to provide enough information to a user to allow them to resolve an
	 * issue on their own, but not enough to help nefarious folks fishing for info.
	 *
	 * @see Woodev_Payment_Gateway_API_Response_Message_Helper
	 *
	 * @return string user message, if there is one
	 */
	public function get_user_message();
}

endif;