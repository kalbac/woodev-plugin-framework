<?php
/**
 * Refreshes the stored registration state of an account's notification URL.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the marketplace what it now says about our URL and stores the answer in the account row, so every screen reads it
 * without a remote call. The settings buttons and the diagnostics page share this one path.
 *
 * @since 2.1.0
 */
final class Registration_Refresher {
	/**
	 * Accounts.
	 *
	 * @var Notification_Account_Reader
	 */
	private $accounts;

	/**
	 * The adapter's id, which the URL carries.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Creates the refresher.
	 *
	 * @since 2.1.0
	 *
	 * @param Notification_Account_Reader $accounts       Accounts.
	 * @param string                      $marketplace_id The adapter's id.
	 */
	public function __construct( Notification_Account_Reader $accounts, string $marketplace_id ) {
		$this->accounts       = $accounts;
		$this->marketplace_id = $marketplace_id;
	}

	/**
	 * Reads what the marketplace says about our URL and stores it with the time it was asked.
	 *
	 * @since 2.1.0
	 *
	 * @param Push_Registrar       $registrar The marketplace's registry for the account.
	 * @param Notification_Account $account   Account.
	 * @return void
	 * @throws \Throwable When the marketplace could not be asked; the stored state is left as it was.
	 */
	public function refresh( Push_Registrar $registrar, Notification_Account $account ): void {
		$state = $registrar->registration_of( Notification_Url::for_account( $this->marketplace_id, $account ) );

		$this->accounts->save_state(
			$account->id(),
			null === $state ? [] : array_merge( $state, [ 'checked_at' => time() ] )
		);
	}
}
