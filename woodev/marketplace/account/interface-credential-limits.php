<?php
/**
 * Credentials that pass the check and open only part of what the plugin does.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: the {@see Credential_Health} of a marketplace whose key may be accepted with part of its rights implements it, and
 * the settings screen shows such an account's connection line as a warning, not as a success. The line itself is the `ok`
 * result's message, which says what the key cannot do.
 *
 * @since 2.1.28
 */
interface Credential_Limits extends Credential_Health {
	/**
	 * Tells whether the account's credentials open only part of what the plugin does.
	 *
	 * Asked on every load of the settings screen, so it is answered from the account's stored data, without a remote call. It is
	 * asked only about credentials whose check answered `ok`.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account, with its credentials.
	 * @return bool
	 */
	public function has_limits( Account $account ): bool;
}
