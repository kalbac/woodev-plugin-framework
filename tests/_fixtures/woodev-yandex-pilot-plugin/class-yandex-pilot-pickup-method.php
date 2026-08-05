<?php
/**
 * Yandex-shaped pilot fixture pickup method and source wiring.
 *
 * Proves the Platform v2 pickup abstraction ({@see Shipping_Method_Pickup} +
 * {@see Point_Source}) fits the yandex reference plugin: the base method below
 * resolves the abstract PVZ seam to a yandex source and carries the yandex
 * order-meta prefix + session-key installed-site contract strings (the session key
 * documents a contract string the eventual yandex rewrite must preserve; nothing in
 * this fixture consumes it anymore — the §8 checkout field layer owns the chosen
 * point during checkout). Two thin subclasses expose the two yandex method ids.
 *
 * @package Woodev_Yandex_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Shipping_Method_Pickup;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;

/**
 * Yandex-shaped fixture pickup point source.
 *
 * The sourcing seam: normalizes a carrier fetch into framework {@see Pickup_Point}
 * value objects. Yandex loads a whole locality in one call, so this source declares
 * the bulk strategy.
 */
final class Woodev_Yandex_Pilot_Point_Source implements Point_Source {

	/**
	 * Gets the loading strategy this source supports.
	 *
	 * @return string
	 */
	public function get_strategy(): string {
		return self::STRATEGY_BULK;
	}

	/**
	 * Fetches yandex pickup points matching the given query.
	 *
	 * Filters out a null {@see Pickup_Point::from_array()} result — the same rule
	 * {@see \Woodev\Framework\Shipping\Abstract_Shipping_API::to_pickup_points()} applies:
	 * one malformed point from the carrier must not break the whole list.
	 * `array_values()` re-indexes after the filter: `array_filter()` preserves keys, and a
	 * gap-keyed result would serialize as a JSON object instead of the list the map expects.
	 *
	 * @param Point_Query $query What to fetch.
	 * @return Pickup_Point[]
	 */
	public function fetch_points( Point_Query $query ): array {
		return array_values(
			array_filter( array_map( [ Pickup_Point::class, 'from_array' ], self::get_fixture_payloads() ) )
		);
	}

	/**
	 * Fetches one yandex pickup point's full detail.
	 *
	 * The fixture's bulk source already knows everything about its one point, so this
	 * simply looks it up among the same raw payloads {@see fetch_points()} normalizes.
	 *
	 * @param string $point_id Carrier point id.
	 * @return Pickup_Point|null
	 */
	public function fetch_details( string $point_id ): ?Pickup_Point {
		foreach ( self::get_fixture_payloads() as $payload ) {
			$point = Pickup_Point::from_array( $payload );

			if ( null !== $point && $point_id === $point->get_id() ) {
				return $point;
			}
		}

		return null;
	}

	/**
	 * The fixture's raw carrier-shaped pickup point payloads.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_fixture_payloads(): array {
		return [
			[
				'id'      => 'YND-001',
				'name'    => 'Yandex PVZ',
				'lat'     => 55.751244,
				'lng'     => 37.618423,
				'address' => 'Москва, ул. Тестовая, 1',
				'type'    => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи' ],
			],
		];
	}
}

/**
 * Yandex-shaped fixture pickup method base.
 *
 * Resolves the abstract pickup source seam and holds the yandex order-meta and
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
	 * @return Point_Source
	 */
	protected function get_point_source(): Point_Source {
		return new Woodev_Yandex_Pilot_Point_Source();
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
	 * @param array<string,mixed>        $package Shipping package.
	 * @param \Woodev_Packer_Result|null $packed  Packed parcels, or null.
	 * @return Shipping_Rate|null
	 */
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
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
