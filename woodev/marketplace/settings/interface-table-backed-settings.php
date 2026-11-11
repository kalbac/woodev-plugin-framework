<?php
/**
 * Contract for settings section providers that store values in marketplace tables instead of `wp_options`.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by a {@see Settings_Section_Provider} whose fields live in a custom database table (for example,
 * {@see Accounts_Sections} over the `accounts` table) rather than individual WordPress options.
 *
 * {@see Settings_Handler} delegates `load()`, `save()` and cross-field `validate_values()` to every table-backed
 * provider for the setting ids it owns.
 *
 * @since 2.1.2
 */
interface Table_Backed_Settings {
	/**
	 * Tells whether this provider owns a given setting id.
	 *
	 * @since 2.1.2
	 *
	 * @param string $setting_id Setting id.
	 * @return bool
	 */
	public function handles( string $setting_id ): bool;

	/**
	 * Reads the current stored value of an owned setting id.
	 *
	 * @since 2.1.2
	 *
	 * @param string $setting_id Setting id.
	 * @return mixed
	 */
	public function read( string $setting_id );

	/**
	 * Persists a validated value for an owned setting id.
	 *
	 * @since 2.1.2
	 *
	 * @param string $setting_id Setting id.
	 * @param mixed  $value      Submitted value.
	 * @return void
	 */
	public function write( string $setting_id, $value ): void;

	/**
	 * Validates a batch of submitted setting values across fields (for example, duplicate account identifiers or
	 * coupled price-rule fields).
	 *
	 * @since 2.1.2
	 *
	 * @param array<string,mixed> $values Submitted `setting_id => value` map.
	 * @return array<string,string> `setting_id => localized error message` map, empty when valid.
	 */
	public function validate( array $values ): array;
}
