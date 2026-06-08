<?php
/**
 * Realistic courier shipping method fixture.
 *
 * @package Woodev_Realistic_Shipping_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Courier-style fixture shipping method.
 */
final class Woodev_Realistic_Shipping_Method extends Abstract_Woodev_Realistic_Shipping_Method {

	/** Method ID. */
	const METHOD_ID = 'woodev_realistic_shipping';

	/**
	 * Initializes the fixture method.
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( int $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'Woodev Realistic Shipping';
		$this->method_description = 'Realistic courier method for Platform v2 fixture testing.';
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		parent::__construct( $instance_id );
	}

	/**
	 * Gets the method ID.
	 *
	 * @return string
	 */
	public static function get_method_id(): string {
		return self::METHOD_ID;
	}

	/**
	 * Gets the delivery type.
	 *
	 * @return string
	 */
	public function get_delivery_type(): string {
		return 'courier';
	}
}
