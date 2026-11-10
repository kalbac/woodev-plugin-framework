<?php
/**
 * «Why is this product not syncing» reasons.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Diagnostics;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, in a fixed order, the reasons a link does not sync, each as a {@see Sync_Reason} (code and parameters). The order is the
 * order of §10.2: licence, account, key, card, switches, warehouse, rate limit, last error. The sentences are not written here: a
 * shared admin mapper turns each code into translated text.
 *
 * Marketplace-specific reasons (an oversize product without an oversize warehouse) are appended by the adapter after this list.
 *
 * @since 2.0.29
 */
final class Why_Not_Syncing {
	/**
	 * Collects the reasons.
	 *
	 * @since 2.0.29
	 *
	 * @param Product_Link        $link       Link.
	 * @param Account             $account    Account of the link.
	 * @param array<string,mixed> $sync_state Facts gathered by the caller: `license_ok` (bool, default true), `health`
	 *                                        (`Health_Result` status, default ok), `default_warehouse_id` (int|null),
	 *                                        `retry_after_seconds` (int|null), `last_error` (string|null).
	 * @return Sync_Reason[] Reasons, most fundamental first; empty when nothing blocks the sync.
	 */
	public static function reasons( Product_Link $link, Account $account, array $sync_state ): array {
		$reasons = [];
		if ( false === ( $sync_state['license_ok'] ?? true ) ) {
			$reasons[] = new Sync_Reason( Sync_Reason::LICENSE_INVALID );
		}

		if ( ! $account->is_enabled() ) {
			$reasons[] = new Sync_Reason( Sync_Reason::ACCOUNT_DISABLED );
		}

		$reasons = array_merge( $reasons, self::key_reasons( (string) ( $sync_state['health'] ?? Health_Result::OK ) ), self::card_reasons( $link ) );
		if ( ! $link->flag( 'sync_stock' ) ) {
			$reasons[] = new Sync_Reason( Sync_Reason::STOCK_SYNC_OFF );
		}

		if ( null === $link->warehouse_id() && empty( $sync_state['default_warehouse_id'] ) ) {
			$reasons[] = new Sync_Reason( Sync_Reason::NO_WAREHOUSE );
		}

		$retry_after = (int) ( $sync_state['retry_after_seconds'] ?? 0 );
		if ( $retry_after > 0 ) {
			$reasons[] = new Sync_Reason( Sync_Reason::RATE_LIMITED, [ 'seconds' => $retry_after ] );
		}

		$last_error = (string) ( $sync_state['last_error'] ?? '' );
		if ( '' !== $last_error ) {
			$reasons[] = new Sync_Reason( Sync_Reason::LAST_ERROR, [ 'message' => $last_error ] );
		}

		return $reasons;
	}

	/**
	 * Reasons that come from the credential health.
	 *
	 * @param string $health `Health_Result` status.
	 * @return Sync_Reason[]
	 */
	private static function key_reasons( string $health ): array {
		if ( Health_Result::ROLE_MISSING === $health ) {
			return [ new Sync_Reason( Sync_Reason::KEY_ROLE_MISSING ) ];
		}

		if ( in_array( $health, [ Health_Result::AUTH_FAILED, Health_Result::KEY_EXPIRED ], true ) ) {
			return [ new Sync_Reason( Sync_Reason::KEY_INVALID ) ];
		}

		return [];
	}

	/**
	 * Reasons that come from the state of the card.
	 *
	 * @param Product_Link $link Link.
	 * @return Sync_Reason[]
	 */
	private static function card_reasons( Product_Link $link ): array {
		if ( Product_Link::STATE_UNLINKED === $link->state() ) {
			return [ new Sync_Reason( Sync_Reason::NOT_LINKED ) ];
		}

		if ( ! $link->is_created() ) {
			return [ new Sync_Reason( Sync_Reason::CARD_PENDING, [ 'state' => $link->state() ] ) ];
		}

		return [];
	}
}
