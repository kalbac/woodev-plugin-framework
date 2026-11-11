<?php
/**
 * Marketplace-specific copy, field specs and push administration for settings and diagnostics.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the framework's settings sections, key-expiry notices and diagnostics report ask of one marketplace
 * beyond its domain policies: the marketplace's display name, the labels/validators of its credential fields, its
 * cabinet instructions, its health hints, and — when push notifications are supported — its default IP allowlist and
 * per-account {@see Push_Admin}.
 *
 * @since 2.1.2
 */
interface Settings_Descriptor {
	/**
	 * Short human-readable marketplace name shown in section titles, tooltips and diagnostics headings
	 * (for example, `Ozon`, `Wildberries`, `Яндекс Маркет`).
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function marketplace_name(): string;

	/**
	 * Returns the UI and validation specification for a credential field declared by
	 * {@see \Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter::credential_fields()}.
	 *
	 * @since 2.1.2
	 *
	 * @param string $field_key Field key (`identifier()` or one of `secrets()`).
	 * @return Credential_Field_Spec
	 */
	public function field_spec( string $field_key ): Credential_Field_Spec;

	/**
	 * Localized plain-text instruction telling the merchant where to obtain credentials in the seller cabinet.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function credentials_help(): string;

	/**
	 * Localized plain-text advice shown when an account's key is expiring or has expired.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function key_renewal_advice(): string;

	/**
	 * Localized plain-text message shown when stored secrets cannot be decrypted (e.g. after WordPress salts changed).
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function unreadable_secrets_message(): string;

	/**
	 * Localized plain-text troubleshooting hint for a non-`ok` health status (`auth_failed`, `role_missing`,
	 * `key_expired`, `unreachable`, `unknown`). Shown in diagnostics only when debug logging is enabled.
	 *
	 * @since 2.1.2
	 *
	 * @param string $status Health status code, or `unknown` when never checked.
	 * @return string
	 */
	public function health_hint( string $status ): string;

	/**
	 * Default newline-separated IP/CIDR allowlist for push notifications, or '' when none applies.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function default_ip_allowlist(): string;

	/**
	 * Returns the push administration service for an account, or null when push notifications are unsupported or the
	 * account does not exist.
	 *
	 * @since 2.1.2
	 *
	 * @param int $account_id Account id.
	 * @return Push_Admin|null
	 */
	public function push_admin( int $account_id ): ?Push_Admin;
}
