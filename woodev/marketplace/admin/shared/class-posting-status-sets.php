<?php
/**
 * The posting status sets the analytics uses, from the adapter or by default.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Reads {@see Posting_Status_Groups}. Without the contract the classification is UNKNOWN, never guessed from the framework's
 * canonical words: the codes are the marketplace's own, so a guess would claim «no stuck postings» or «no rejections» the framework
 * cannot know. No marketplace's own codes live here.
 *
 * @since 2.1.13
 */
final class Posting_Status_Sets {
	/**
	 * @since 2.1.13
	 *
	 * @param Posting_Status_Groups|null $groups The adapter's groups.
	 * @return string[]|null The waiting statuses; null when the adapter does not say.
	 */
	public static function waiting( ?Posting_Status_Groups $groups ): ?array {
		return null === $groups ? null : self::clean( $groups->waiting_posting_statuses() );
	}

	/**
	 * @since 2.1.13
	 *
	 * @param Posting_Status_Groups|null $groups The adapter's groups.
	 * @return string[] The rejected statuses; none when the adapter does not say (only cancelled orders are cancellations then).
	 */
	public static function rejected( ?Posting_Status_Groups $groups ): array {
		return null === $groups ? [] : self::clean( $groups->rejected_posting_statuses() );
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
