<?php
/**
 * Per-account push webhook registration operations for the notifications settings section.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Intake\Notification_Account;
use Woodev\Framework\Marketplace\Intake\Push_Registrar;

defined( 'ABSPATH' ) || exit;

/**
 * Extends {@see Push_Registrar} with the four remote registration actions offered on the notifications settings tab
 * («Зарегистрировать URL», «Обновить типы», «Включить уведомления», «Проверить») and the marketplace's interpretation
 * of wanted event types and stored registration health.
 *
 * Only marketplaces that declare {@see \Woodev\Framework\Marketplace\Capabilities\Capability::PUSH_NOTIFICATIONS}
 * implement this contract.
 *
 * @since 2.1.2
 */
interface Push_Admin extends Push_Registrar {
	public const REGISTERED         = 'registered';
	public const UPDATED            = 'updated';
	public const ALREADY_REGISTERED = 'already_registered';
	public const ALREADY_CURRENT    = 'already_current';
	public const DISABLED           = 'disabled';
	public const NOT_REGISTERED     = 'not_registered';

	/**
	 * Registers our notification URL on the marketplace, or aligns its subscribed types when already registered.
	 *
	 * @since 2.1.2
	 *
	 * @param Notification_Account $account Account.
	 * @param string               $url     Notification URL of the account.
	 * @return string `self::REGISTERED`, `self::UPDATED`, `self::ALREADY_REGISTERED` or `self::DISABLED`.
	 * @throws \Throwable When the marketplace call fails.
	 */
	public function register( Notification_Account $account, string $url ): string;

	/**
	 * Updates the subscribed event types of an already-registered URL to match the account's current settings.
	 *
	 * @since 2.1.2
	 *
	 * @param Notification_Account $account Account.
	 * @param string               $url     Notification URL of the account.
	 * @return string `self::UPDATED`, `self::ALREADY_CURRENT` or `self::NOT_REGISTERED`.
	 * @throws \Throwable When the marketplace call fails.
	 */
	public function update_types( Notification_Account $account, string $url ): string;

	/**
	 * Re-enables delivery to our URL after the marketplace auto-disabled it.
	 *
	 * @since 2.1.2
	 *
	 * @param Notification_Account $account Account.
	 * @param string               $url     Notification URL of the account.
	 * @return bool True when enabled; false when our URL is not registered yet.
	 * @throws \Throwable When the marketplace call fails.
	 */
	public function enable( Notification_Account $account, string $url ): bool;

	/**
	 * Runs the marketplace's live check of our notification URL.
	 *
	 * @since 2.1.2
	 *
	 * @param Notification_Account $account Account.
	 * @param string               $url     Notification URL of the account.
	 * @return array{active:bool,errors:string[]}
	 * @throws \Throwable When the marketplace call fails.
	 */
	public function check( Notification_Account $account, string $url ): array;

	/**
	 * Lists the human-readable localized descriptions of the push event types this account wants given its switches.
	 *
	 * @since 2.1.2
	 *
	 * @param Notification_Account $account Account.
	 * @return string[] Empty when the account's current settings require no push events.
	 */
	public function wanted_descriptions( Notification_Account $account ): array;

	/**
	 * Formats the stored `notification_state` JSON of an account into a short localized summary line.
	 *
	 * @since 2.1.2
	 *
	 * @param array<string,mixed> $state Stored registration state (`Notification_Account::state()`), non-empty.
	 * @return string Localized status sentence.
	 */
	public function describe_state( array $state ): string;
}
