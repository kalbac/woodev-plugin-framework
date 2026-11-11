<?php
/**
 * The moment an account first wrote to the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Some decisions are only safe until the account's first non-read request — going back to an earlier plugin generation is the
 * one that asked for it. The boundary is `accounts.settings.first_remote_write_at`, written once, **before** that request
 * leaves, so a write with an uncertain outcome counts.
 *
 * @since 2.1.0
 */
final class First_Remote_Write {
	public const SETTING = 'first_remote_write_at';

	/**
	 * Builds the callback an API client calls before its first write: it records the moment, and refuses the write when the
	 * moment cannot be recorded.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Account_Store $accounts Accounts.
	 * @param callable|null      $clock    `function (): int`; the system clock when null.
	 * @return callable `function ( int $account_id ): void`.
	 */
	public static function recorder( Wpdb_Account_Store $accounts, ?callable $clock = null ): callable {
		$clock = $clock ?? 'time';

		return static function ( int $account_id ) use ( $accounts, $clock ): void {
			if ( $account_id <= 0 ) {
				return; // An unsaved account (a connection test typed into the form) writes nothing.
			}

			$recorded = $accounts->update_settings(
				$account_id,
				static function ( array $settings ) use ( $clock ): array {
					if ( '' === (string) ( $settings[ self::SETTING ] ?? '' ) ) {
						$settings[ self::SETTING ] = gmdate( 'Y-m-d\TH:i:s\Z', (int) call_user_func( $clock ) );
					}

					return $settings;
				}
			);

			if ( ! $recorded ) {
				throw new \RuntimeException( 'The first remote write of the account could not be recorded; nothing was sent.' );
			}
		};
	}

	/**
	 * Returns when the account first wrote to the marketplace, or null.
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account Account.
	 * @return string|null ISO 8601 time.
	 */
	public static function at( Account $account ): ?string {
		$at = (string) $account->setting( self::SETTING, '' );

		return '' === $at ? null : $at;
	}
}
