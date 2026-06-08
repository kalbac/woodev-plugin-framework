<?php
/**
 * Edostavka-shaped pilot fixture shipping method.
 *
 * @package Woodev_Edostavka_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Framework\Shipping\Shipping_Rate;

/**
 * Edostavka-shaped fixture shipping method. The method ID is the installed-site contract 'edostavka'.
 */
final class Woodev_Edostavka_Pilot_Shipping_Method extends Shipping_Method {

	/** Method ID — installed-site contract preserved by the eventual rewrite. */
	const METHOD_ID = 'edostavka';

	/**
	 * Initializes the fixture method.
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( int $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'Woodev Edostavka Pilot';
		$this->method_description = 'Edostavka-shaped method for Platform v2 fixture testing.';
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
		return 'pickup-point';
	}

	/**
	 * Gets the fixture plugin instance.
	 *
	 * @return Shipping_Plugin
	 */
	protected function get_plugin(): Shipping_Plugin {
		return woodev_edostavka_pilot_plugin();
	}

	/**
	 * Gets fixture settings fields.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_method_form_fields(): array {
		return [];
	}

	/**
	 * Calculates a deterministic fixture rate.
	 *
	 * @param array<string,mixed> $package Shipping package.
	 * @return Shipping_Rate|null
	 */
	protected function calculate_rate( array $package ): ?Shipping_Rate {
		return new Shipping_Rate(
			$this->id,
			$this->get_rate_id(),
			$this->method_title,
			'0'
		);
	}
}
