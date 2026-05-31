<?php
/**
 * Realistic shipping fixture method base.
 *
 * @package Woodev_Realistic_Shipping_Fixture
 */

defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Framework\Shipping\Shipping_Rate;

/**
 * Shared fixture method base mirroring production shipping plugins with method subclasses.
 */
abstract class Abstract_Woodev_Realistic_Shipping_Method extends Shipping_Method {

	/**
	 * Gets the fixture plugin instance.
	 *
	 * @return Shipping_Plugin
	 */
	protected function get_plugin(): Shipping_Plugin {
		return woodev_realistic_shipping_plugin();
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
