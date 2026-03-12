<?php
/**
 * Woodev Test Direct Payment Gateway Class
 *
 * Минимальная реализация прямого платёжного шлюза на базе фреймворка.
 * Используется для тестирования direct payment gateway функционала.
 *
 * @package Woodev_Test_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Woodev_Test_Direct_Payment_Gateway
 *
 * Наследуется от базового класса фреймворка для прямых платёжных шлюзов.
 * В отличие от hosted-шлюза, обрабатывает платёж на стороне сайта без редиректа.
 */
class Woodev_Test_Direct_Payment_Gateway extends Woodev_Payment_Gateway_Direct {

	public function __construct() {

		parent::__construct(
			'woodev_test_direct_gateway',
			woodev_test_payment_gateway_plugin(),
			[
				'method_title'       => __( 'Test Direct Gateway', 'woodev-test-payment-gateway' ),
				'method_description' => __( 'Test Direct Payment Gateway Description', 'woodev-test-payment-gateway' ),
				'supports'           => [
					self::FEATURE_PAYMENT_FORM,
					self::FEATURE_REFUNDS,
					self::FEATURE_PRODUCTS,
				],
				'payment_type'       => self::PAYMENT_TYPE_CREDIT_CARD,
				'card_types'         => [ 'Visa', 'MC', 'MIR' ],
				'environments'       => [
					self::ENVIRONMENT_PRODUCTION => __( 'Production', 'woodev-test-payment-gateway' ),
					self::ENVIRONMENT_TEST       => __( 'Test', 'woodev-test-payment-gateway' ),
				],
				'countries'          => [ 'RU' ],
				'currencies'         => [ 'RUB', 'USD' ],
				'order_button_text'  => __( 'Pay with Test Direct Gateway', 'woodev-test-payment-gateway' ),
			]
		);
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
				'default'     => __( 'Pay with Test Direct Gateway', 'woodev-test-payment-gateway' ),
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function get_order_button_text() {
		if ( $this->order_button_text ) {
			return $this->order_button_text;
		}

		return parent::get_order_button_text();
	}

	/**
	 * @inheritDoc
	 */
	public function get_environment(): string {
		return self::ENVIRONMENT_PRODUCTION;
	}

	/**
	 * @inheritDoc
	 */
	public function get_api(): Woodev_Test_Direct_Payment_Gateway_API {

		if ( ! class_exists( 'Woodev_Test_Direct_Payment_Gateway_API' ) ) {
			require_once $this->get_plugin()->get_plugin_path() . '/includes/api/class-woodev-test-direct-gateway-api.php';
		}

		return new Woodev_Test_Direct_Payment_Gateway_API( $this );
	}
}
