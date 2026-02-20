<?php
/**
 * Woodev Test Shipping Method Class
 *
 * Минимальная реализация метода доставки на базе фреймворка.
 * Используется для тестирования shipping method функционала.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Woodev_Test_Shipping_Method
 *
 * Наследуется от базового класса фреймворка для методов доставки.
 * Замени Woodev_Shipping_Method на реальное имя базового класса из твоего фреймворка.
 */
class Woodev_Test_Shipping_Method extends Woodev_Shipping_Method {

	/** @var string идентификатор метода доставки */
	const METHOD_ID = 'woodev_test_shipping';

	/**
	 * Инициализация.
	 *
	 * @param int $instance_id ID экземпляра (для multi-instance методов).
	 */
	public function __construct( int $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'Woodev Test Shipping';
		$this->method_description = 'Test shipping method for Woodev Framework testing.';
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		parent::__construct( $instance_id );
	}

	/**
	 * Рассчитываем стоимость доставки — всегда бесплатно в тестах.
	 *
	 * @param array $package Пакет товаров.
	 */
	public function calculate_shipping( $package = [] ): void {
		$this->add_rate( [
			'id'       => $this->get_rate_id(),
			'label'    => $this->method_title,
			'cost'     => 0,
			'calc_tax' => 'per_order',
		] );
	}
}
