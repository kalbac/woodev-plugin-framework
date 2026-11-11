<?php
/**
 * Contributor of sections and fields to a marketplace plugin's settings handler.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Settings\Settings_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Registers settings on a {@see Settings_Handler} and builds its {@see Settings_Section} cards in display order.
 *
 * @since 2.1.2
 */
interface Settings_Section_Provider {
	/**
	 * Registers this provider's fields on the handler.
	 *
	 * @since 2.1.2
	 *
	 * @param Settings_Handler $handler Settings handler.
	 * @return void
	 */
	public function register_settings( Settings_Handler $handler ): void;

	/**
	 * Builds the sections this provider contributes to the settings page.
	 *
	 * @since 2.1.2
	 *
	 * @return Settings_Section[]
	 */
	public function sections(): array;
}
