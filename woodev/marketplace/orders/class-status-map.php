<?php
/**
 * Canonical order statuses and their WooCommerce mapping.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * The adapter maps its raw statuses (Ozon ~13 FBS statuses; Wildberries `supplierStatus` x `wbStatus`) to the canonical
 * vocabulary below; this map turns a canonical status into a WooCommerce order status.
 *
 * `delivered` becomes `completed`, `cancelled` becomes `cancelled`, everything else takes the configured default. A
 * merchant-editable table is a v2.1 proposal.
 *
 * @since 2.0.29
 */
final class Status_Map {
	public const NEW               = 'new';
	public const AWAITING_SHIPMENT = 'awaiting_shipment';
	public const SHIPPED           = 'shipped';
	public const DELIVERING        = 'delivering';
	public const DELIVERED         = 'delivered';
	public const CANCELLED         = 'cancelled';
	public const ARBITRATION       = 'arbitration';

	/**
	 * Progress order of the canonical statuses, least progressed first. `arbitration` is the least settled state, so an
	 * order with a disputed member stays visible; `cancelled` is not on the ladder (a cancelled member is not live).
	 *
	 * @var string[]
	 */
	private const LADDER = [ self::ARBITRATION, self::NEW, self::AWAITING_SHIPMENT, self::SHIPPED, self::DELIVERING, self::DELIVERED ];

	/**
	 * Lists every canonical status.
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public static function canonical(): array {
		return array_merge( self::LADDER, [ self::CANCELLED ] );
	}

	/**
	 * Tells whether a string is a canonical status.
	 *
	 * @since 2.0.29
	 *
	 * @param string $status Status to test.
	 * @return bool
	 */
	public static function is_canonical( string $status ): bool {
		return in_array( $status, self::canonical(), true );
	}

	/**
	 * Returns the position of a live status on the progress ladder (0 is the least progressed).
	 *
	 * @since 2.0.29
	 *
	 * @param string $canonical Canonical status other than `cancelled`.
	 * @return int
	 * @throws \InvalidArgumentException For `cancelled` and for unknown statuses.
	 */
	public static function rank( string $canonical ): int {
		$position = array_search( $canonical, self::LADDER, true );
		if ( false === $position ) {
			throw new \InvalidArgumentException( 'Status has no place on the progress ladder.' );
		}

		return (int) $position;
	}

	/**
	 * Maps a canonical status to a WooCommerce order status (without the `wc-` prefix).
	 *
	 * @since 2.0.29
	 *
	 * @param string $canonical Canonical status.
	 * @param string $fallback  Status for everything that has no fixed mapping.
	 * @return string
	 */
	public function wc_status( string $canonical, string $fallback ): string {
		switch ( $canonical ) {
			case self::DELIVERED:
				return 'completed';
			case self::CANCELLED:
				return 'cancelled';
			default:
				return $fallback;
		}
	}
}
