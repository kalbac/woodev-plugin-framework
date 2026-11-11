<?php
/**
 * Notification URL of an account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * The address the marketplace is asked to POST to:
 * `…/wp-json/woodev/v1/marketplace/{marketplace id}/notifications/{account_id}/{token}`.
 *
 * @since 2.0.30
 */
final class Notification_Url {
	/**
	 * Builds the URL of an account.
	 *
	 * @since 2.0.30
	 *
	 * @param string               $marketplace_id The adapter's id.
	 * @param Notification_Account $account        Account.
	 * @return string
	 */
	public static function for_account( string $marketplace_id, Notification_Account $account ): string {
		return rest_url( Notifications_Controller::REST_NAMESPACE . Notifications_Controller::base( $marketplace_id ) . '/' . $account->id() . '/' . $account->token() );
	}
}
