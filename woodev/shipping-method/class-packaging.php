<?php
/**
 * Packing policy shared by rates and order export.
 *
 * @since 2.0.2
 */
namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Converts box declarations and prices allocated parcels without repricing carrier quotes.
 *
 * @since 2.0.2
 */
final class Packaging {

	/**
	 * @since 2.0.2
	 * @param array $declarations box rows in store units.
	 * @return \Woodev_Packer_Box_Implementation[]
	 */
	public static function to_boxes( array $declarations ): array {
		$boxes = [];
		foreach ( $declarations as $box ) {
			if ( ! $box['enabled'] ) {
				continue;
			}
			$boxes[] = new \Woodev_Packer_Box_Implementation(
				(float) wc_get_dimension( $box['length'], 'cm' ),
				(float) wc_get_dimension( $box['width'], 'cm' ),
				(float) wc_get_dimension( $box['height'], 'cm' ),
				(float) wc_get_weight( $box['box_weight'], 'kg' ),
				$box['max_weight'] > 0 ? (float) wc_get_weight( $box['max_weight'], 'kg' ) : null,
				$box['id'],
				$box['name'],
				[
					'origin' => $box['origin'] ?? 'store',
					'cost_mode' => $box['cost_mode'] ?? 'merchant',
					'cost' => $box['cost'],
					'charge_carrier' => $box['charge_carrier'] ?? false,
				]
			);
		}
		return $boxes;
	}

	/**
	 * Framework box surcharge: once per allocated parcel, percentage of that parcel only.
	 *
	 * @since 2.0.2
	 * @param \Woodev_Packer_Result $packed parcels.
	 * @param array                 $contents WooCommerce cart lines keyed by their cart key.
	 * @return float
	 */
	public static function get_cost( \Woodev_Packer_Result $packed, array $contents ): float {
		$lines = [];
		foreach ( $contents as $key => $line ) {
			$lines[ (string) ( $line['key'] ?? $key ) ] = $line;
		}
		$total = 0.0;
		foreach ( $packed->get_packages() as $parcel ) {
			$box = $parcel->get_box_details();
			if ( '' === $parcel->get_box_id() || 'carrier' === ( $box['cost_mode'] ?? '' ) ) {
				continue;
			}
			$cost = str_replace( ',', '.', trim( (string) ( $box['cost'] ?? '' ) ) );
			if ( ! Settings\Boxes_Settings::is_valid_cost( $cost ) || '' === $cost ) {
				continue;
			}
			if ( '%' !== substr( $cost, -1 ) ) {
				$total += (float) $cost;
				continue;
			}
			$value = 0.0;
			foreach ( $parcel->get_items() as $item ) {
				$line = $lines[ $item['key'] ] ?? [];
				$unit_value = self::unit_value( $line );
				$value += max( 0.0, $unit_value ) * $item['quantity'];
			}
			$total += $value * (float) substr( $cost, 0, -1 ) / 100;
		}
		return $total;
	}

	/**
	 * Per-line values used by percentage costs, for rate-cache identity.
	 *
	 * @since 2.0.2
	 * @param array $contents cart lines.
	 * @return array<string,float>
	 */
	public static function get_value_context( array $contents ): array {
		$values = [];
		foreach ( $contents as $key => $line ) {
			if ( is_array( $line ) ) {
				$values[ (string) ( $line['key'] ?? $key ) ] = self::unit_value( $line );
			}
		}
		ksort( $values );
		return $values;
	}

	/**
	 * @param array $line cart line.
	 * @return float contents value per unit after line discounts, before taxes.
	 */
	private static function unit_value( array $line ): float {
		if ( isset( $line['line_total'] ) ) {
			return (float) $line['line_total'] / max( 1, (int) ( $line['quantity'] ?? 1 ) );
		}
		$product = $line['data'] ?? null;
		return $product instanceof \WC_Product && method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;
	}

	/**
	 * Carrier-priced boxes that must be passed to the carrier's own quote request.
	 *
	 * @since 2.0.2
	 * @param \Woodev_Packer_Result|null $packed parcels.
	 * @return array<int,array{id:string,count:int}>
	 */
	public static function get_carrier_boxes( ?\Woodev_Packer_Result $packed ): array {
		$counts = [];
		foreach ( null === $packed ? [] : $packed->get_packages() as $parcel ) {
			$details = $parcel->get_box_details();
			if ( 'carrier' === $parcel->get_box_origin() && 'carrier' === ( $details['cost_mode'] ?? '' ) && ! empty( $details['charge_carrier'] ) ) {
				$id = $parcel->get_box_id();
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
			}
		}
		$result = [];
		foreach ( $counts as $id => $count ) {
			$result[] = [
				'id' => $id,
				'count' => $count,
			];
		}
		return $result;
	}
}
