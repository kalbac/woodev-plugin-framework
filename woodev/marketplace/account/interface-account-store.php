<?php
/**
 * Account persistence contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes seller accounts. Implementations must never write the merchant's on/off switch on their own:
 * a failed health check updates the health, not `is_enabled` (v1 disabled accounts on a timeout).
 *
 * @since 2.0.29
 */
interface Account_Store {
	/**
	 * Loads one account.
	 *
	 * @since 2.0.29
	 *
	 * @param int $id Account id.
	 * @return Account|null Null when it does not exist.
	 */
	public function get( int $id ): ?Account;

	/**
	 * Lists the accounts of one adapter.
	 *
	 * @since 2.0.29
	 *
	 * @param string $adapter_id   Adapter id.
	 * @param bool   $enabled_only Skip accounts the merchant switched off.
	 * @return Account[]
	 */
	public function all( string $adapter_id, bool $enabled_only ): array;

	/**
	 * Inserts or updates an account.
	 *
	 * @since 2.0.29
	 *
	 * @param Account $account Account to save; id 0 inserts.
	 * @return int Saved account id.
	 */
	public function save( Account $account ): int;

	/**
	 * Records the outcome of a credential check without touching the merchant's switch.
	 *
	 * @since 2.0.29
	 *
	 * @param int           $id     Account id.
	 * @param Health_Result $result Check outcome.
	 * @return void
	 */
	public function set_health( int $id, Health_Result $result ): void;
}
