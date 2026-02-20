<?php


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Woodev_Payment_Gateway_Helper' ) ) :

	/**
	 * Woodev Payment Gateway Helper Class
	 *
	 * The purpose of this class is to centralize common utility functions that
	 * are commonly used in Woodev payment gateway plugins
	 *
	 */
	class Woodev_Payment_Gateway_Helper {

		/** @var string the Visa card type ID * */
		const CARD_TYPE_VISA = 'visa';

		/** @var string the MasterCard card type ID * */
		const CARD_TYPE_MASTERCARD = 'mastercard';

		/** @var string the American Express card type ID * */
		const CARD_TYPE_AMEX = 'amex';

		/** @var string the Diners Club card type ID * */
		const CARD_TYPE_DINERSCLUB = 'dinersclub';

		/** @var string the Discover card type ID * */
		const CARD_TYPE_DISCOVER = 'discover';

		/** @var string the JCB card type ID * */
		const CARD_TYPE_JCB = 'jcb';

		/** @var string the CarteBleue card type ID * */
		const CARD_TYPE_CARTEBLEUE = 'cartebleue';

		/** @var string the Maestro card type ID * */
		const CARD_TYPE_MAESTRO = 'maestro';

		/** @var string the Laser card type ID * */
		const CARD_TYPE_LASER = 'laser';

		/** @var string the Laser card type ID * */
		const CARD_TYPE_MIR = 'mir';


		/**
		 * Perform standard luhn check.  Algorithm:
		 *
		 * 1. Double the value of every second digit beginning with the second-last right-hand digit.
		 * 2. Add the individual digits comprising the products obtained in step 1 to each of the other digits in the original number.
		 * 3. Subtract the total obtained in step 2 from the next higher number ending in 0.
		 * 4. This number should be the same as the last digit (the check digit). If the total obtained in step 2 is a number ending in zero (30, 40 etc.), the check digit is 0.
		 *
		 * @param string $account_number the credit card number to check
		 *
		 * @return bool true if $account_number passes the check, false otherwise
		 */
		public static function luhn_check( $account_number ) {

			for ( $sum = 0, $i = 0, $ix = strlen( $account_number ); $i < $ix - 1; $i ++ ) {

				$weight = substr( $account_number, $ix - ( $i + 2 ), 1 ) * ( 2 - ( $i % 2 ) );
				$sum    += $weight < 10 ? $weight : $weight - 9;

			}

			return substr( $account_number, $ix - 1 ) == ( ( 10 - $sum % 10 ) % 10 );
		}


		/**
		 * Normalize a card type to a standard type ID and account for variations.
		 *
		 * @param string $card_type the card type to normalize
		 *
		 * @return string
		 */
		public static function normalize_card_type( $card_type ) {

			$card_types = self::get_card_types();

			$card_type = strtolower( $card_type );

			// stop here if the provided card type is already normalized
			if ( in_array( $card_type, array_keys( $card_types ) ) ) {
				return $card_type;
			}

			$variations = wp_list_pluck( $card_types, 'variations' );

			// if the provided card type matches a known variation, return the normalized card type
			foreach ( $variations as $valid_type => $vars ) {

				if ( in_array( $card_type, $vars ) ) {
					$card_type = $valid_type;
					break;
				}
			}

			// otherwise, let it through unaltered
			return $card_type;
		}


		/**
		 * Determines the credit card type from a given account number (only first 4 required).
		 *
		 *
		 * @param string $account_number the credit card account number
		 *
		 * @return string the credit card type
		 */
		public static function card_type_from_account_number( $account_number ) {

			$types = array(
				self::CARD_TYPE_VISA       => '/^4/',
				self::CARD_TYPE_MASTERCARD => '/^(5[1-5]|2[2-7])/',
				self::CARD_TYPE_AMEX       => '/^3[47]/',
				self::CARD_TYPE_DINERSCLUB => '/^(36|38|30[0-5])/',
				self::CARD_TYPE_DISCOVER   => '/^(6011|65|64[4-9]|622)/',
				self::CARD_TYPE_JCB        => '/^35/',
				self::CARD_TYPE_MAESTRO    => '/^(5018|5020|5038|6304|6759|676[1-3])/',
				self::CARD_TYPE_LASER      => '/^(6706|6771|6709)/',
			);

			foreach ( $types as $type => $pattern ) {

				if ( 1 === preg_match( $pattern, $account_number ) ) {
					return $type;
				}
			}

			return null;
		}

		/**
		 * Translates a loan type name to a full name
		 *
		 * @param string $type the loan type
		 *
		 * @return string the kind of loan name, ei 'Installment', 'Credit'
		 */
		public static function get_loan_type_name( $type ) {

			$types = array(
				'credit'      => esc_html__( 'Loan', 'woodev-plugin-framework' ),
				'installment' => esc_html__( 'Installment', 'woodev-plugin-framework' )
			);

			$name = isset( $types[ $type ] ) ? $types[ $type ] : $type;

			return apply_filters( 'wc_payment_gateway_loan_type_to_name', $name, $type );
		}


		/**
		 * Translates a credit card type or bank account name to a full name,
		 * e.g. 'mastercard' => 'MasterCard' or 'savings' => 'eCheck'
		 *
		 * @param string $payment_type the credit card or bank type, ie 'mastercard', 'amex', 'checking'
		 *
		 * @return string the credit card or bank account name, ie 'MasterCard', 'American Express', 'Checking Account'
		 */
		public static function payment_type_to_name( $payment_type ) {

			// normalize for backwards compatibility with gateways that pass the card type directly from Woodev_Payment_Gateway::get_card_types()
			$type = self::normalize_card_type( $payment_type );

			// known payment type names, excluding credit cards
			$payment_types = array(
				'paypal'   => esc_html__( 'PayPal', 'woodev-plugin-framework' ),
				'checking' => esc_html__( 'Checking Account', 'woodev-plugin-framework' ),
				'savings'  => esc_html__( 'Savings Account', 'woodev-plugin-framework' ),
				'card'     => esc_html__( 'Credit / Debit Card', 'woodev-plugin-framework' ),
				'bank'     => esc_html__( 'Bank Account', 'woodev-plugin-framework' ),
				'loan'     => esc_html__( 'Loan', 'woodev-plugin-framework' )
			);

			// add the credit card names
			$payment_types = array_merge( wp_list_pluck( self::get_card_types(), 'name' ), $payment_types );

			if ( isset( $payment_types[ $type ] ) ) {
				$name = $payment_types[ $type ];
			} elseif ( '' === $type ) {
				$name = esc_html_x( 'Account', 'payment method type', 'woodev-plugin-framework' );
			} else {
				$name = ucwords( str_replace( '-', ' ', $type ) );
			}

			/**
			 * Payment Gateway Type to Name Filter.
			 *
			 * Allow actors to modify the name returned given a payment type.
			 *
			 * @param string $name nice payment type name, e.g. American Express
			 * @param string $type payment type, e.g. amex
			 */
			return apply_filters( 'wc_payment_gateway_payment_type_to_name', $name, $type );
		}


		/**
		 * Gets the known card types and their variations.
		 *
		 * Returns the card types in the format:
		 *
		 * 'mastercard' {
		 *     'name'       => 'MasterCard',
		 *     'variations' => array( 'mc' ),
		 * }
		 *
		 * @return array
		 */
		public static function get_card_types() {

			return array(
				self::CARD_TYPE_VISA       => array(
					'name'       => esc_html_x( 'Visa', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array(),
				),
				self::CARD_TYPE_MASTERCARD => array(
					'name'       => esc_html_x( 'MasterCard', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array( 'mc', 'master-card' ),
				),
				self::CARD_TYPE_AMEX       => array(
					'name'       => esc_html_x( 'American Express', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array( 'americanexpress' ),
				),
				self::CARD_TYPE_DINERSCLUB => array(
					'name'       => esc_html_x( 'Diners Club', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array( 'diners' ),
				),
				self::CARD_TYPE_DISCOVER   => array(
					'name'       => esc_html_x( 'Discover', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array( 'disc' ),
				),
				self::CARD_TYPE_JCB        => array(
					'name'       => esc_html_x( 'JCB', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array(),
				),
				self::CARD_TYPE_CARTEBLEUE => array(
					'name'       => esc_html_x( 'CarteBleue', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array(),
				),
				self::CARD_TYPE_MAESTRO    => array(
					'name'       => esc_html_x( 'Maestro', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array(),
				),
				self::CARD_TYPE_LASER      => array(
					'name'       => esc_html_x( 'Laser', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array(),
				),
				self::CARD_TYPE_MIR        => array(
					'name'       => esc_html_x( 'MIR', 'credit card type', 'woodev-plugin-framework' ),
					'variations' => array(),
				)
			);
		}
	}

endif;