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

use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Framework\Shipping\Shipping_Rate;

/**
 * Class Woodev_Test_Shipping_Method
 *
 * Наследуется от базового класса фреймворка для методов доставки.
 */
class Woodev_Test_Shipping_Method extends Shipping_Method {

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
	 * @inheritDoc
	 */
	public static function get_method_id(): string {
		return self::METHOD_ID;
	}

	/**
	 * @inheritDoc
	 */
	public function get_delivery_type(): string {
		return 'courier';
	}

	/**
	 * @inheritDoc
	 */
	protected function get_method_form_fields(): array {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	protected function calculate_rate( array $package ): ?Shipping_Rate {
		return new Shipping_Rate(
			$this->id,
			$this->get_rate_id(),
			$this->method_title,
			'0'
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function get_plugin(): Shipping_Plugin {
		return \Woodev_Test_Shipping_Method_Plugin::instance();
	}
}
