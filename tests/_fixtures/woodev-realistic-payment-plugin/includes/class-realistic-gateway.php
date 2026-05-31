<?php
/**
 * Realistic hosted payment gateway fixture.
 *
 * @package Woodev_Realistic_Payment_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hosted-style fixture payment gateway.
 */
final class Woodev_Realistic_Gateway extends Abstract_Woodev_Realistic_Gateway {

	/** Gateway ID. */
	const GATEWAY_ID = 'woodev_realistic';

	/**
	 * Gets the gateway ID.
	 *
	 * @return string
	 */
	public static function get_gateway_id(): string {
		return self::GATEWAY_ID;
	}

	/**
	 * Gets the hosted pay page URL.
	 *
	 * @param \WC_Order|null $order Order instance.
	 * @return string
	 */
	public function get_hosted_pay_page_url( $order = null ) {
		return 'https://example.test/realistic-hosted-pay';
	}

	/**
	 * Builds a deterministic fixture transaction response.
	 *
	 * @param array<string,mixed> $request_response_data Raw response data.
	 * @return array<string,mixed>
	 */
	protected function get_transaction_response( $request_response_data ) {
		return (array) $request_response_data;
	}
}
