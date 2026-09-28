<?php
/**
 * Woodev Location-Aware Pickup Point Source
 *
 * Optional extension of the plugin seam for a carrier whose single-point lookup depends
 * on the destination, not only on the point id.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

use Woodev\Framework\Shipping\Location\Location_Record;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Location_Aware_Point_Source' ) ) :

	/**
	 * A {@see Point_Source} whose detail lookup can use the destination record (#959, spec D3).
	 *
	 * The list route hands a source the destination through {@see Point_Query::get_record()} and
	 * {@see Point_Query::get_resolved_identity()}. {@see Point_Source::fetch_details()} takes only
	 * an id, so a carrier whose detail eligibility or lookup depends on the destination (a
	 * point addressed as `city + code`, a per-city tariff zone) had no way to see it on the
	 * detail and could disagree with its own list. Implement this in ADDITION to
	 * {@see Point_Source} to receive it.
	 *
	 * Only the ADMIN detail route calls it today — the public routes have no explicit
	 * destination to give — and only when a record was named and the plugin's location
	 * resolver answered for it; every other case falls back to
	 * {@see Point_Source::fetch_details()}, which therefore stays the one method a source
	 * must implement.
	 *
	 * @since 2.0.2
	 */
	interface Location_Aware_Point_Source extends Point_Source {

		/**
		 * Fetches one point for an explicit destination.
		 *
		 * `$record` and `$resolved_identity` are exactly what
		 * {@see Point_Query::get_record()} / {@see Point_Query::get_resolved_identity()} carry
		 * on the list for the same request — `$resolved_identity` is this plugin's OWN carrier
		 * identity for the record, opaque to the framework, and `null` when the carrier does
		 * not serve the locality.
		 *
		 * @since 2.0.2
		 *
		 * @param string          $point_id          Carrier point id.
		 * @param Location_Record $record            The destination record.
		 * @param mixed           $resolved_identity This plugin's carrier identity for `$record`.
		 *
		 * @return Pickup_Point|null Null when the point is unknown.
		 *
		 * @throws \Woodev_API_Exception On a carrier transport, authentication, or API error —
		 *                                the same contract as {@see Point_Source::fetch_details()}.
		 */
		public function fetch_details_for( string $point_id, Location_Record $record, $resolved_identity ): ?Pickup_Point;
	}

endif;
