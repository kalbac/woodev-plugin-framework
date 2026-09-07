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
	 * THE ONLY FIXTURE DECLARING `FEATURE_BOX_PACKING`, deliberately (#567, rolled in from
	 * #771). Without it `Shipping_Method::init_form_fields()` never builds the
	 * `packing_algorithm` control, so «Алгоритм упаковки», its tooltip and the three labels
	 * from `Woodev_Packer_Dispatcher::get_algorithms()` are UNREACHABLE ON THE RIG — five
	 * merchant-facing strings the pre-release translation pass is meant to read on screen
	 * and cannot. #771's own headline example was exactly this select.
	 *
	 * THIS method and not the realistic courier one, and that is a measurement rather than a
	 * preference: `wp_woocommerce_shipping_zone_methods` on the rig carries
	 * `woodev_test_shipping` on two zones, while `woodev_realistic_shipping` is on none — so
	 * declaring it there would leave the setting just as unreachable until someone added the
	 * method to a zone first. This fixture is also the one mapped into the TESTS env, which
	 * is what lets the control be pinned by an integration test at all.
	 *
	 * Costs nothing at rate time: `calculate_rate()` is a final template that hands the packed
	 * result to `rate_package()`, and this fixture's `rate_package()` already accepts
	 * `?\Woodev_Packer_Result` and ignores it. `pack_package()` degrades to `null` when
	 * `Woodev_WC_Packer_Dispatcher` is absent or the cart holds nothing physical.
	 *
	 * @param int $instance_id ID экземпляра (для multi-instance методов).
	 */
	public function __construct( int $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'Woodev Test Shipping';
		$this->method_description = 'Test shipping method for Woodev Framework testing.';
		$this->supports           = [ 'shipping-zones', 'instance-settings', Shipping_Method::FEATURE_BOX_PACKING ];

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
	 *
	 * `'pickup'` since issue #709 (was `'courier'`): this method already carried a
	 * `Pickup_Field` slot, a `set_requires_pickup_methods()` backstop entry, and a
	 * `Selection_Scope::type_for_method()` answer — three of the four pickup
	 * declarations already agreed it was a pickup method; only this one, the
	 * framework's actual source of truth, disagreed. That mismatch was #709's own
	 * live reproduction on the rig (a pickup button beside a required, un-hidden
	 * address) and made #652 scenarios 3/4 untestable. See
	 * docs-internal/wiki/local-rig.md for the measured before/after.
	 */
	public function get_delivery_type(): string {
		return 'pickup';
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
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
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
