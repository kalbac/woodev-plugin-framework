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
 * Замени Woodev_Payment_Gateway на реальное имя базового класса из твоего фреймворка.
 */
class Woodev_Test_Payment_Gateway extends Woodev_Payment_Gateway {

	/** @var string идентификатор шлюза */
	const GATEWAY_ID = 'woodev_test_gateway';

	/**
	 * Инициализация.
	 */
	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = 'Woodev Test Gateway';
		$this->method_description = 'Test payment gateway for Woodev Framework testing.';
		$this->supports           = [ 'products', 'refunds' ];

		parent::__construct();
	}

	/**
	 * Обрабатываем платёж — всегда успешно в тестах.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		$order->payment_complete();

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}
}
