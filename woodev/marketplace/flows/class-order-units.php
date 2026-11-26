<?php
/**
 * The unit records of a grouped marketplace order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;

defined( 'ABSPATH' ) || exit;

/**
 * The units of a WooCommerce order that stands for several remote orders: every one, live or cancelled, in the order meta
 * {@see self::META}, on both order storages. The WooCommerce lines hold the live units only ({@see Wc_Order_Store}); the screens
 * list the units from here, and an adapter may build its {@see \Woodev\Framework\Marketplace\Admin\Order\Posting_Unit}s from
 * {@see self::of()} (its unit id is the member's external id).
 *
 * A live record follows the marketplace. A cancelled one is final: it keeps the goods, price, status and time it had when the
 * unit was first seen cancelled, and no later write changes or removes it.
 *
 * An order of a one-order marketplace has no records.
 *
 * @since 2.1.28
 */
final class Order_Units {
	public const META = '_woodev_mp_units';

	/**
	 * Returns the units of an order.
	 *
	 * @since 2.1.28
	 *
	 * @param \WC_Order $order The order.
	 * @return Order_Unit[] In the order of their ids; empty for an order of a one-order marketplace.
	 */
	public static function of( \WC_Order $order ): array {
		$units = [];

		foreach ( (array) $order->get_meta( self::META ) as $stored ) {
			$unit = Order_Unit::from_array( $stored );

			if ( null !== $unit ) {
				$units[] = $unit;
			}
		}

		return $units;
	}

	/**
	 * Applies a refreshed grouped order to the records.
	 *
	 * @since 2.1.28
	 *
	 * @param Order_Unit[] $stored        Records the order holds.
	 * @param Order_DTO    $order         The folded order, with its units ({@see Order_DTO::units()}).
	 * @param callable     $product_id_of `function ( Order_Line $line ): int`, the shop product a line sells; 0 for none.
	 * @return Order_Unit[] The records after the refresh, in the order of their ids.
	 */
	public static function refreshed( array $stored, Order_DTO $order, callable $product_id_of ): array {
		$records = [];

		// Keyed with a prefix: marketplace ids such as "11" would otherwise become integer keys.
		foreach ( $stored as $unit ) {
			$records[ '#' . $unit->id() ] = $unit;
		}

		foreach ( $order->units() as $member ) {
			$key = '#' . $member->external_id();

			if ( isset( $records[ $key ] ) && $records[ $key ]->is_cancelled() ) {
				continue;
			}

			$records[ $key ] = self::capture( $member, $product_id_of );
		}

		ksort( $records, SORT_STRING );

		return array_values( $records );
	}

	/**
	 * Returns the units that are cancelled now and were not before, a unit first seen already cancelled included.
	 *
	 * @since 2.1.28
	 *
	 * @param Order_Unit[] $before Records before a refresh.
	 * @param Order_Unit[] $after  Records after it.
	 * @return Order_Unit[]
	 */
	public static function newly_cancelled( array $before, array $after ): array {
		$cancelled = [];

		foreach ( $before as $unit ) {
			if ( $unit->is_cancelled() ) {
				$cancelled[ '#' . $unit->id() ] = true;
			}
		}

		return array_values(
			array_filter(
				$after,
				static function ( Order_Unit $unit ) use ( $cancelled ): bool {
					return $unit->is_cancelled() && ! isset( $cancelled[ '#' . $unit->id() ] );
				}
			)
		);
	}

	/**
	 * Takes the record of one member.
	 *
	 * @param Order_DTO $member        Member snapshot.
	 * @param callable  $product_id_of `function ( Order_Line $line ): int`.
	 * @return Order_Unit
	 */
	private static function capture( Order_DTO $member, callable $product_id_of ): Order_Unit {
		$lines = array_map(
			static function ( Order_Line $line ) use ( $product_id_of ): array {
				$product_id = (int) $product_id_of( $line );
				$name       = $line->name();

				if ( '' === $name && $product_id > 0 ) {
					$product = wc_get_product( $product_id );
					$name    = $product instanceof \WC_Product ? $product->get_name() : '';
				}

				return [
					'product_id' => $product_id,
					'offer_id'   => $line->seller_sku(),
					'name'       => $name,
					'quantity'   => $line->quantity(),
					'unit_minor' => $line->unit_price()->minor(),
					'currency'   => $line->unit_price()->currency(),
				];
			},
			$member->lines()
		);

		$cancelled_at = '';

		if ( $member->is_cancelled() ) {
			$changed      = $member->updated_at_instant();
			$cancelled_at = gmdate( 'Y-m-d\TH:i:s\Z', null === $changed ? time() : $changed->getTimestamp() );
		}

		return new Order_Unit( $member->external_id(), $member->status_raw(), $cancelled_at, $lines );
	}
}
