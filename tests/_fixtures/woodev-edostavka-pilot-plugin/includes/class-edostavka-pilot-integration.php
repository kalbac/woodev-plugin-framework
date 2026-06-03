<?php
/**
 * Edostavka-shaped pilot fixture settings/integration object.
 *
 * @package Woodev_Edostavka_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds the installed-site settings option name for the edostavka-shaped pilot fixture.
 *
 * Models the SHAPE of the production edostavka integration's settings storage without
 * implementing any real WooCommerce integration behavior (YAGNI fixture).
 */
final class Woodev_Edostavka_Pilot_Integration {

	/** Installed-site settings option key preserved by the eventual rewrite. */
	const SETTINGS_OPTION_NAME = 'woocommerce_edostavka_settings';

	/**
	 * Gets the installed-site settings option name.
	 *
	 * @return string
	 */
	public static function get_settings_option_name(): string {
		return self::SETTINGS_OPTION_NAME;
	}
}
