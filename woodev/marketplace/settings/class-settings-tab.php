<?php
/**
 * One tab of a marketplace plugin's settings page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Names a tab («Подключение», «Параметры», «Общие», …) and where its sections come from.
 *
 * The settings handler lists a tab for every `Settings_Tab` it was given ({@see Settings_Handler::get_settings_providers()}), in
 * order. The tab registers no settings of its own: the provider whose sections it shows registered them, so a field has one owner
 * and a tab is only a view over it.
 *
 * @since 2.1.5
 */
final class Settings_Tab implements Settings_Section_Provider {
	/**
	 * Short id; the tab id is built from it.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Localized label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Builds the tab's sections (`function (): Settings_Section[]`).
	 *
	 * @var callable
	 */
	private $sections;

	/**
	 * Creates the tab.
	 *
	 * @since 2.1.5
	 *
	 * @param string   $slug     Short id (`connection`, `parameters`, …).
	 * @param string   $label    Localized label.
	 * @param callable $sections `function (): Settings_Section[]`, called when the page or a route needs the sections.
	 */
	public function __construct( string $slug, string $label, callable $sections ) {
		$this->slug     = $slug;
		$this->label    = $label;
		$this->sections = $sections;
	}

	/**
	 * Short id of the tab.
	 *
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Label of the tab.
	 *
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * A tab owns no settings.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler $handler Settings handler.
	 * @return void
	 */
	public function register_settings( Settings_Handler $handler ): void {
	}

	/**
	 * The tab's sections.
	 *
	 * @since 2.1.5
	 *
	 * @return \Woodev\Framework\Settings\Settings_Section[]
	 */
	public function sections(): array {
		return array_values( (array) call_user_func( $this->sections ) );
	}
}
