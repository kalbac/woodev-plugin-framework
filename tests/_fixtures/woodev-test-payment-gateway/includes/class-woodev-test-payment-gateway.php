<?php
/**
 * Woodev Test Payment Gateway Class
 *
 * Минимальная реализация платёжного шлюза на базе фреймворка.
 * Используется для тестирования payment gateway функционала.
 *
 * @package Woodev_Test_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Woodev_Test_Payment_Gateway
 *
 * Наследуется от базового класса фреймворка для платёжных шлюзов.
 */
class Woodev_Test_Payment_Gateway extends Woodev_Payment_Gateway {


	public function __construct() {

		parent::__construct(
			'woodev_test_gateway',
			woodev_test_payment_gateway_plugin(),
			[
				'method_title'      => 'Test Gateway',
				'description'       => 'Test Regular Payment Gateway',
				'supports'          => [
					self::FEATURE_PAYMENT_FORM,
					self::FEATURE_REFUNDS
				],
				'payment_type'      => self::PAYMENT_TYPE_CREDIT_CARD,
				'card_types'        => [ 'Visa', 'MC', 'MIR' ],
				'environments'      => [
					self::ENVIRONMENT_PRODUCTION => __( 'Production', 'woodev-test-payment-gateway' ),
					self::ENVIRONMENT_TEST       => __( 'Test', 'woodev-test-payment-gateway' ),
				],
				'countries'         => [ 'RU' ],
				'currencies'        => [ 'RUB', 'USD' ],
				'order_button_text' => __( 'Pay with Test Gateway', 'woodev-test-payment-gateway' ),
			]
		);
	}

	protected function get_method_form_fields(): array {
		return [
			'order_button_text'    => [
				'title'       => __( 'Button text', 'woodev-test-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Button text on the checkout page.', 'woodev-test-payment-gateway' ),
				'default'     => __( 'Pay with Test Gateway', 'woodev-test-payment-gateway' ),
				'desc_tip'    => true,
			]
		];
	}

	protected function get_order_button_text() {
		if ( $this->order_button_text ) {
			return $this->order_button_text;
		}

		return parent::get_order_button_text();
	}

	public function get_environment(): string {
		return self::ENVIRONMENT_PRODUCTION;
	}
}
