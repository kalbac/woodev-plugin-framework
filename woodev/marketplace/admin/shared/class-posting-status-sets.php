<?php
/**
 * The posting status sets the analytics uses, from the adapter or by default.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Orders\Status_Map;

defined( 'ABSPATH' ) || exit;

/**
 * Reads {@see Posting_Status_Groups} when the adapter implements it, and falls back to the framework's canonical words otherwise
 * (`new`, `awaiting_shipment` wait on the shop; `cancelled` is rejected). No marketplace's own codes live here.
 *
 * @since 2.1.13
 */
final class Posting_Status_Sets {
	/**
	 * Waiting statuses of an adapter without the contract.
	 */
	public const DEFAULT_WAITING = [ Status_Map::NEW, Status_Map::AWAITING_SHIPMENT ];

	/**
	 * Rejected statuses of an adapter without the contract.
	 */
	public const DEFAULT_REJECTED = [ Status_Map::CANCELLED ];

	/**
	 * @since 2.1.13
	 *
	 * @param Posting_Status_Groups|null $groups The adapter's groups.
	 * @return string[]
	 */
	public static function waiting( ?Posting_Status_Groups $groups ): array {
		return null === $groups ? self::DEFAULT_WAITING : self::clean( $groups->waiting_posting_statuses() );
	}

	/**
	 * @since 2.1.13
	 *
	 * @param Posting_Status_Groups|null $groups The adapter's groups.
	 * @return string[]
	 */
	public static function rejected( ?Posting_Status_Groups $groups ): array {
		return null === $groups ? self::DEFAULT_REJECTED : self::clean( $groups->rejected_posting_statuses() );
	}

	/**
	 * @param mixed $codes What the adapter returned.
	 * @return string[] Distinct non-empty strings.
	 */
	private static function clean( $codes ): array {
		$codes = array_filter(
			is_array( $codes ) ? $codes : [],
			static function ( $code ): bool {
				return is_string( $code ) && '' !== $code;
			}
		);

		return array_values( array_unique( $codes ) );
	}
}
