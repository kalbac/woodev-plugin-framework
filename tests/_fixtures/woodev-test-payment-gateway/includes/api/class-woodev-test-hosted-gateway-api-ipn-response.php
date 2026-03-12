<?php

defined( 'ABSPATH' ) || exit;

class Woodev_Test_Hosted_Payment_Gateway_API_IPN_Response implements Woodev_Payment_Gateway_API_Payment_Notification_Response {

	private Woodev_Payment_Gateway_Hosted $gateway;

	private array $response;

	public function __construct( $response, Woodev_Payment_Gateway_Hosted $gateway ) {
		$this->gateway  = $gateway;
		$this->response = ! empty( $response ) && is_array( $response ) ? $response : [];
	}

	public function get_gateway(): Woodev_Payment_Gateway_Hosted {
		return $this->gateway;
	}

	public function to_string() {
		return print_r( $this->response, true );
	}

	public function to_string_safe() {
		return wp_json_encode( $this->response );
	}

	public function get_order_id() {
		return $this->response['order_id'] ?? null;
	}

	public function transaction_cancelled(): bool {
		return false;
	}

	public function get_account_number() {
		return null;
	}

	public function is_ipn(): bool {
		return true;
	}

	public function transaction_approved(): bool {
		try {
			return ! empty( $this->get_order_id() );
		} catch ( Exception $e ) {
			return false;
		}
	}

	public function transaction_held(): bool {
		return false;
	}

	public function get_status_message(): string {
		return 'Payment status message';
	}

	/**
	 * @inheritDoc
	 */
	public function get_status_code() {
		// TODO: Implement get_status_code() method.
	}

	/**
	 * @inheritDoc
	 */
	public function get_transaction_id() {
		// TODO: Implement get_transaction_id() method.
	}

	/**
	 * @inheritDoc
	 */
	public function get_payment_type() {
		// TODO: Implement get_payment_type() method.
	}

	/**
	 * @inheritDoc
	 */
	public function get_user_message(): string {
		return 'User message';
	}
}