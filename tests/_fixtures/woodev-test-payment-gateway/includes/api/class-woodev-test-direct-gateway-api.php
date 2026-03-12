<?php

defined( 'ABSPATH' ) || exit;

class Woodev_Test_Direct_Payment_Gateway_API extends Woodev_API_Base implements Woodev_Payment_Gateway_API {

	private Woodev_Payment_Gateway_Direct $gateway;

	public function __construct( Woodev_Payment_Gateway_Direct $gateway ) {
		$this->set_request_content_type_header( 'application/json' );
		$this->set_request_accept_header( 'application/json' );

		$this->gateway = $gateway;
	}

	protected function get_plugin(): Woodev_Payment_Gateway_Plugin {
		return $this->gateway->get_plugin();
	}

	protected function get_new_request( $args = [] ) {
		// TODO: Implement get_new_request() method.
	}

	public function credit_card_authorization( WC_Order $order ) {
		// TODO: Implement credit_card_authorization() method.
	}

	public function credit_card_charge( WC_Order $order ) {
		// TODO: Implement credit_card_charge() method.
	}

	public function credit_card_capture( WC_Order $order ) {
		// TODO: Implement credit_card_capture() method.
	}

	public function check_debit( WC_Order $order ) {
		// TODO: Implement check_debit() method.
	}

	public function refund( WC_Order $order ) {
		// TODO: Implement refund() method.
	}

	public function void( WC_Order $order ) {
		// TODO: Implement void() method.
	}

	public function tokenize_payment_method( WC_Order $order ) {
		// TODO: Implement tokenize_payment_method() method.
	}

	public function update_tokenized_payment_method( WC_Order $order ) {
		// TODO: Implement update_tokenized_payment_method() method.
	}

	public function supports_update_tokenized_payment_method(): bool {
		return false;
	}

	public function remove_tokenized_payment_method( $token, $customer_id ) {
		// TODO: Implement remove_tokenized_payment_method() method.
	}

	public function supports_remove_tokenized_payment_method(): bool {
		return false;
	}

	public function get_tokenized_payment_methods( $customer_id ) {
		// TODO: Implement get_tokenized_payment_methods() method.
	}

	public function supports_get_tokenized_payment_methods(): bool {
		return false;
	}

	public function get_order() {
		// TODO: Implement get_order() method.
	}
}
