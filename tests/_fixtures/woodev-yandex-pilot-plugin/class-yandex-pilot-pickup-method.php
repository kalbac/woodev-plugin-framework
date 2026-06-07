<?php
/**
 * Yandex-shaped pilot fixture pickup method, source and selection wiring.
 *
 * Proves the Platform v2 pickup abstraction ({@see Shipping_Method_Pickup} +
 * {@see Pickup_Point_Source} + {@see Pickup_Selection}) fits the yandex reference
 * plugin: the base method below resolves the two abstract PVZ seams to a yandex
 * source and the yandex session-only selection store, and carries the yandex
 * order-meta prefix + session-key installed-site contract strings. Two thin
 * subclasses expose the two yandex method ids.
 *
 * @package Woodev_Yandex_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Shipping_Method_Pickup;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Pickup_Point_Source;
use Woodev\Framework\Shipping\Pickup\Pickup_Selection;

/**
 * Yandex-shaped fixture pickup point source.
 *
 * The sourcing seam: normalizes a carrier search into framework {@see Pickup_Point}
 * value objects, preserving the raw query on the `raw` escape hatch.
 */
final class Woodev_Yandex_Pilot_Point_Source implements Pickup_Point_Source {

	/**
	 * Searches for yandex pickup points matching the given query.
	 *
	 * @param array<string,mixed> $params Pickup point search parameters.
	 * @return Pickup_Point[]
	 */
	public function search( array $params ): array {
		return [
			Pickup_Point::from_array(
				[
					'code' => 'YND-001',
					'name' => 'Yandex PVZ',
					'lat'  => 55.751244,
					'lng'  => 37.618423,
					'raw'  => $params,
				]
			),
		];
	}
}

/**
 * Yandex-shaped fixture pickup method base.
 *
 * Resolves the two abstract pickup seams and holds the yandex order-meta and
 * session-key installed-site contract strings. Abstract: the two yandex method
 * ids are provided by the concrete subclasses below.
 */
abstract class Woodev_Yandex_Pilot_Pickup_Method extends Shipping_Method_Pickup {

	/** Order-meta prefix — installed-site contract preserved by the eventual rewrite. */
	const META_PREFIX = '_yandex_delivery_';

	/** Chosen-pickup-point session key — installed-site contract preserved by the rewrite. */
	const SESSION_KEY = 'chosen_yandex_pickup_point';

	/**
	 * Initializes the fixture method.
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( int $instance_id = 0 ) {
		$this->id                 = static::get_method_id();
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'Woodev Yandex Pilot';
		$this->method_description = 'Yandex-shaped pickup method for Platform v2 fixture testing.';
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		parent::__construct( $instance_id );
	}

	/**
	 * Gets the carrier's normalizing pickup-point source.
	 *
	 * @return Pickup_Point_Source
	 */
	protected function get_point_source(): Pickup_Point_Source {
		return new Woodev_Yandex_Pilot_Point_Source();
	}

	/**
	 * Gets the carrier's session-only selection store, keyed by the yandex session key.
	 *
	 * @return Pickup_Selection
	 */
	protected function get_pickup_selection(): Pickup_Selection {
		return new Pickup_Selection( self::SESSION_KEY );
	}

	/**
	 * Gets the fixture plugin instance.
	 *
	 * @return Shipping_Plugin
	 */
	protected function get_plugin(): Shipping_Plugin {
		return woodev_yandex_pilot_plugin();
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

/**
 * Yandex express pickup method. Method ID is the installed-site contract
 * 'yandex_delivery_express'.
 */
final class Woodev_Yandex_Pilot_Express_Method extends Woodev_Yandex_Pilot_Pickup_Method {

	/** Method ID — installed-site contract preserved by the eventual rewrite. */
	const METHOD_ID = 'yandex_delivery_express';

	/**
	 * Gets the method ID.
	 *
	 * @return string
	 */
	public static function get_method_id(): string {
		return self::METHOD_ID;
	}
}

/**
 * Yandex other-day pickup method. Method ID is the installed-site contract
 * 'yandex_delivery_other_day'.
 */
final class Woodev_Yandex_Pilot_Other_Day_Method extends Woodev_Yandex_Pilot_Pickup_Method {

	/** Method ID — installed-site contract preserved by the eventual rewrite. */
	const METHOD_ID = 'yandex_delivery_other_day';

	/**
	 * Gets the method ID.
	 *
	 * @return string
	 */
	public static function get_method_id(): string {
		return self::METHOD_ID;
	}
}
