<?php
/**
 * Optional pending outcome for hosted payment notifications.
 *
 * @since 2.0.16
 */

namespace Woodev\Framework\Payment_Gateway\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Opts a response into intermediate-status handling.
 *
 * Responses implementing only the original notification interface retain the
 * default (not pending) behavior; no new method is required of existing plugins.
 *
 * @since 2.0.16
 */
interface Pending_Notification_Response extends \Woodev_Payment_Gateway_API_Payment_Notification_Response {
	/**
	 * Reports an intermediate state that must leave the order status unchanged.
	 *
	 * @since 2.0.16
	 * @return bool Whether the transaction is still pending.
	 */
	public function transaction_pending(): bool;
}
