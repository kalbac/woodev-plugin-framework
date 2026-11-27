<?php
/**
 * Marketplace domain — a shipment with what is in it.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * A shipment as it is now, with its units and its cargo places.
 *
 * @since 2.1.28
 */
final class Shipment_Contents {
	/**
	 * @var Shipment
	 */
	private $shipment;

	/**
	 * @var Shipment_Unit[]
	 */
	private $units;

	/**
	 * @var Shipment_Cargo_Place[]
	 */
	private $cargo_places;

	/**
	 * @since 2.1.28
	 *
	 * @param Shipment               $shipment     The shipment.
	 * @param Shipment_Unit[]        $units        Its units, each id once.
	 * @param Shipment_Cargo_Place[] $cargo_places Its cargo places.
	 */
	public function __construct( Shipment $shipment, array $units, array $cargo_places = [] ) {
		$by_id = [];

		foreach ( $units as $unit ) {
			$by_id[ $unit->id() ] = $unit;
		}

		$this->shipment     = $shipment;
		$this->units        = array_values( $by_id );
		$this->cargo_places = array_values( $cargo_places );
	}

	/**
	 * @since 2.1.28
	 *
	 * @return Shipment
	 */
	public function shipment(): Shipment {
		return $this->shipment;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return Shipment_Unit[]
	 */
	public function units(): array {
		return $this->units;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return Shipment_Cargo_Place[]
	 */
	public function cargo_places(): array {
		return $this->cargo_places;
	}

	/**
	 * Lists the requirements that keep the shipment from being handed over, with the unit each belongs to.
	 *
	 * @since 2.1.28
	 *
	 * @return array<int,array{unit:Shipment_Unit,requirement:Shipment_Requirement}>
	 */
	public function blockers(): array {
		$blockers = [];

		foreach ( $this->units as $unit ) {
			foreach ( $unit->requirements() as $requirement ) {
				if ( $requirement->blocks() ) {
					$blockers[] = [
						'unit'        => $unit,
						'requirement' => $requirement,
					];
				}
			}
		}

		return $blockers;
	}
}
