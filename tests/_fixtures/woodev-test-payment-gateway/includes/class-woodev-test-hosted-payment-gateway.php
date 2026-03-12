<?php
/**
 * Woodev Test Hosted Payment Gateway Class
 *
 * Минимальная реализация платёжного шлюза с редирект оплатой на базе фреймворка.
 * Используется для тестирования payment gateway функционала.
 *
 * @package Woodev_Test_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Woodev_Test_Hosted_Payment_Gateway
 *
 * Наследуется от базового класса фреймворка для hosted-платёжных шлюзов.
 */
class Woodev_Test_Hosted_Payment_Gateway extends Woodev_Payment_Gateway_Hosted {

	public function __construct() {

		parent::__construct(
			'woodev_test_hosted_gateway',
			woodev_test_payment_gateway_plugin(),
			[
				'method_title'       => __( 'Test Hosted Gateway', 'woodev-test-payment-gateway' ),
				'method_description' => __( 'Test Hosted Gateway Description', 'woodev-test-payment-gateway' ),
				'supports'           => [
					self::FEATURE_PRODUCTS,
					self::FEATURE_DETAILED_CUSTOMER_DECLINE_MESSAGES,
				],
				'payment_type'       => self::PAYMENT_TYPE_LOANS,
				'environments'       => [
					self::ENVIRONMENT_PRODUCTION => __( 'Production', 'woodev-test-payment-gateway' ),
					self::ENVIRONMENT_TEST       => __( 'Test', 'woodev-test-payment-gateway' ),
				],
				'countries'          => [ 'RU' ],
				'currencies'         => [ 'EUR', 'RUB', 'USD' ],
				'order_button_text'  => __( 'Pay with Test Hosted Gateway', 'woodev-test-payment-gateway' ),
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_hosted_pay_page_url( $order = null ) {
		try {

			if ( ! $order instanceof WC_Order ) {
				throw new Exception( 'Order is not valid' );
			}

			return 'https://example.com';

		} catch ( Exception $e ) {
			$this->get_plugin()->log( $e->getMessage() );
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function get_transaction_response( $request_response_data ): Woodev_Test_Hosted_Payment_Gateway_API_IPN_Response {

		require_once $this->get_plugin()->get_plugin_path() . '/includes/api/class-woodev-test-hosted-gateway-api-ipn-response.php';

		return new Woodev_Test_Hosted_Payment_Gateway_API_IPN_Response( $request_response_data, $this );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_method_form_fields(): array {
		return [
			'order_button_text' => [
				'title'       => __( 'Button text', 'woodev-test-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Button text on the checkout page.', 'woodev-test-payment-gateway' ),
				'default'     => __( 'Pay with Test Hosted Gateway', 'woodev-test-payment-gateway' ),
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_api(): Woodev_Test_Hosted_Payment_Gateway_API {

		if ( ! class_exists( 'Woodev_Test_Hosted_Payment_Gateway_API' ) ) {
			require_once $this->get_plugin()->get_plugin_path() . '/includes/api/class-woodev-test-hosted-gateway-api.php';
		}

		return new Woodev_Test_Hosted_Payment_Gateway_API( $this );
	}
}
