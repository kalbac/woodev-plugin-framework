<?php
/**
 * Credential check contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that an account's credentials work. The check is adapter code (Ozon: `/v1/roles`; Wildberries: `GET /ping`
 * per host, token type matters).
 *
 * @since 2.0.29
 */
interface Credential_Health {
	/**
	 * Runs one cheap authenticated call.
	 *
	 * Never disables the account and never throws for an API failure: the failure is the returned status.
	 *
	 * @since 2.0.29
	 *
	 * @param Account $account Account to check.
	 * @return Health_Result
	 */
	public function check( Account $account ): Health_Result;
}
