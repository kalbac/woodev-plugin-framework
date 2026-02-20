<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Payment_Gateway_Payment_Token' ) ) :


	/**
	 * WooCommerce Payment Gateway Token
	 *
	 * Represents a credit card or check payment token
	 */
	class Woodev_Payment_Gateway_Payment_Token {


		/** @var string payment gateway token ID */
		protected $id;

		/**
		 * @var array associated token data
		 */
		protected $data;

		/**
		 * @var string payment type image url
		 */
		protected $img_url;


		/**
		 * Initialize a payment token with associated $data which is expected to
		 * have the following members:
		 *
		 * default      - boolean optional indicates this is the default payment token
		 * type         - string one of 'credit_card' or 'echeck' ('check' for backwards compatibility)
		 * last_four    - string last four digits of account number
		 * card_type    - string credit card type: visa, mc, amex, disc, diners, jcb, etc (credit card only)
		 * exp_month    - string optional expiration month MM (credit card only)
		 * exp_year     - string optional expiration year YYYY (credit card only)
		 * account_type - string one of 'checking' or 'savings' (checking gateway only)
		 *
		 * @param string $id the payment gateway token ID
		 * @param array $data associated data
		 *
		 * @since 1.0.0
		 */
		public function __construct( $id, $data ) {

			if ( isset( $data['type'] ) && 'credit_card' == $data['type'] ) {

				// normalize the provided card type to adjust for possible abbreviations if set
				if ( isset( $data['card_type'] ) && $data['card_type'] ) {

					$data['card_type'] = Woodev_Payment_Gateway_Helper::normalize_card_type( $data['card_type'] );

					// otherwise, get the payment type from the account number
				} elseif ( isset( $data['account_number'] ) ) {

					$data['card_type'] = Woodev_Payment_Gateway_Helper::card_type_from_account_number( $data['account_number'] );
				}
			}

			// remove account number so it's not saved to the token
			unset( $data['account_number'] );

			$this->id   = $id;
			$this->data = $data;
		}


		/**
		 * Gets the payment token string.
		 *
		 * @return string payment token string
		 * @deprecated 1.1.8
		 *
		 * @since 1.0.0
		 */
		public function get_token() {

			wc_deprecated_function( __METHOD__, '1.1.8', __CLASS__ . '::get_id()' );

			return $this->get_id();
		}


		/**
		 * Returns the payment token string
		 *
		 * @return string payment token string
		 */
		public function get_id() {
			return $this->id;
		}


		/**
		 * Returns true if this payment token is default
		 *
		 * @return boolean true if this payment token is default
		 * @since 1.0.0
		 */
		public function is_default() {
			return isset( $this->data['default'] ) && $this->data['default'];
		}


		/**
		 * Makes this payment token the default or a non-default one
		 *
		 * @param boolean $default true or false
		 *
		 * @since 1.0.0
		 */
		public function set_default( $default ) {
			$this->data['default'] = $default;
		}


		/**
		 * Returns true if this payment token represents a credit card
		 *
		 * @return boolean true if this payment token represents a credit card
		 * @since 1.0.0
		 */
		public function is_credit_card() {
			return 'credit_card' == $this->data['type'];
		}


		/**
		 * Determines if this payment token represents an eCheck.
		 *
		 * @return bool
		 * @deprecated since 1.1.8
		 *
		 * @since 1.0.0
		 */
		public function is_check() {

			wc_deprecated_function( __METHOD__, '1.1.8', __CLASS__ . '::is_echeck()' );

			return $this->is_echeck();
		}


		/**
		 * Returns true if this payment token represents an eCheck
		 *
		 * @return boolean true if this payment token represents an eCheck
		 */
		public function is_echeck() {
			return ! $this->is_credit_card();
		}


		/**
		 * Returns the payment type, one of 'credit_card' or 'echeck'
		 *
		 * @return string the payment type
		 * @since 1.0.0
		 */
		public function get_type() {
			return $this->data['type'];
		}


		/**
		 * Returns the card type ie visa, mc, amex, disc, diners, mir, etc
		 *
		 * Credit card gateway only
		 *
		 * @return string the payment type
		 * @since 1.0.0
		 */
		public function get_card_type() {
			return isset( $this->data['card_type'] ) ? $this->data['card_type'] : null;
		}


		/**
		 * Set the card type
		 *
		 * Credit Card gateway only
		 *
		 * @param string $card_type
		 */
		public function set_card_type( $card_type ) {
			$this->data['card_type'] = $card_type;
		}


		/**
		 * Determines the credit card type from the full account number.
		 *
		 * @param string $account_number the credit card account number
		 *
		 * @return string the credit card type
		 * @see Woodev_Payment_Gateway_Helper::card_type_from_account_number()
		 *
		 * @since 1.0.0
		 * @deprecated 1.1.8
		 */
		public static function type_from_account_number( $account_number ) {

			wc_deprecated_function( __METHOD__, '1.1.8', __CLASS__, '::card_type_from_account_number()' );

			return Woodev_Payment_Gateway_Helper::card_type_from_account_number( $account_number );
		}


		/**
		 * Returns the bank account type, one of 'checking' or 'savings'
		 *
		 * eCheck gateway only
		 *
		 * @return string the payment type
		 * @since 1.0.0
		 */
		public function get_account_type() {
			return isset( $this->data['account_type'] ) ? $this->data['account_type'] : null;
		}


		/**
		 * Set the account type
		 *
		 * eCheck gateway only
		 *
		 * @param string $account_type
		 */
		public function set_account_type( $account_type ) {
			$this->data['account_type'] = $account_type;
		}


		/**
		 * Returns the full payment type, ie Visa, MasterCard, American Express, Discover, Diners, MIR, eCheck, etc
		 *
		 * @return string the payment type
		 * @since 1.0.0
		 */
		public function get_type_full() {

			if ( $this->is_credit_card() ) {
				$type = $this->get_card_type() ? $this->get_card_type() : 'card';
			} else {
				$type = $this->get_account_type() ? $this->get_account_type() : 'bank';
			}

			return Woodev_Payment_Gateway_Helper::payment_type_to_name( $type );
		}


		/**
		 * Returns the last four digits of the credit card or check account number
		 *
		 * @return string last four of account
		 * @since 1.0.0
		 */
		public function get_last_four() {
			return isset( $this->data['last_four'] ) ? $this->data['last_four'] : null;
		}


		/**
		 * Set the account last four
		 *
		 * @param string $last_four
		 */
		public function set_last_four( $last_four ) {
			$this->data['last_four'] = $last_four;
		}


		/**
		 * Returns the expiration month of the credit card.  This should only be called for credit card tokens
		 *
		 * @return string expiration month as a two-digit number
		 * @since 1.0.0
		 */
		public function get_exp_month() {
			return isset( $this->data['exp_month'] ) ? $this->data['exp_month'] : null;
		}


		/**
		 * Set the expiration month
		 *
		 * @param string $month
		 */
		public function set_exp_month( $month ) {
			$this->data['exp_month'] = $month;
		}


		/**
		 * Returns the expiration year of the credit card.  This should only be called for credit card tokens
		 *
		 * @return string expiration year as a four-digit number
		 */
		public function get_exp_year() {
			return isset( $this->data['exp_year'] ) ? $this->data['exp_year'] : null;
		}


		/**
		 * Set the expiration year
		 *
		 * @param string $year
		 */
		public function set_exp_year( $year ) {
			$this->data['exp_year'] = $year;
		}


		/**
		 * Returns the expiration date in the format MM/YY, suitable for use in order notes or other customer-facing areas
		 *
		 * @return string formatted expiration date
		 * @since 1.0.0
		 */
		public function get_exp_date() {

			return $this->get_exp_month() . '/' . substr( $this->get_exp_year(), - 2 );
		}


		/**
		 * Set the full image URL based on the token payment type.  Note that this
		 * is available for convenience during a single request and will not be
		 * included in persistent storage
		 *
		 * @param string $url the full image URL
		 *
		 * @since 1.0.0
		 * @see Woodev_Payment_Gateway_Payment_Token::get_image_url()
		 */
		public function set_image_url( $url ) {
			$this->img_url = $url;
		}


		/**
		 * Get the full image URL based on teh token payment type.
		 *
		 * @return string the full image URL
		 * @since 1.0.0
		 * @see Woodev_Payment_Gateway_Payment_Token::set_image_url()
		 */
		public function get_image_url() {
			return $this->img_url;
		}


		/**
		 * Gets the payment method nickname.
		 *
		 * @return string
		 */
		public function get_nickname() {
			return isset( $this->data['nickname'] ) ? $this->data['nickname'] : '';
		}


		/**
		 * Sets the payment method nickname.
		 *
		 * @param string $value nickname value
		 */
		public function set_nickname( $value ) {
			$this->data['nickname'] = $value;
		}


		/**
		 * Gets the billing address hash.
		 *
		 * @return string
		 */
		public function get_billing_hash() {
			return isset( $this->data['billing_hash'] ) ? $this->data['billing_hash'] : '';
		}


		/**
		 * Sets the billing hash.
		 *
		 * @param string $value billing hash
		 */
		public function set_billing_hash( $value ) {
			$this->data['billing_hash'] = $value;
		}


		/**
		 * Returns a representation of this token suitable for persisting to a datastore
		 *
		 * @return mixed datastore representation of token
		 * @since 1.0.0
		 */
		public function to_datastore_format() {
			return $this->data;
		}

	}


endif;
